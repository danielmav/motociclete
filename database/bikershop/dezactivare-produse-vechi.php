#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Dezactivează pe BikerShop (PrestaShop 9) produsele VECHI ale brandurilor Dainese / AGV / TCX /
 * MOMO Design: cele care NU mai sunt în stocul fizic al magazinului ȘI NU se mai pot aduce de la
 * furnizor.
 *
 * Surse de adevăr (tabele din baza BikerShop):
 *   - `dainese2026_b2b`  = catalogul furnizorului (feedul B2B Dainese): `cod` (referința
 *                          produsului), `cod_globe` (referință+mărime = referința combinației),
 *                          `ean`, `cantitate`.
 *   - `stocuri2024`      = stocul fizic al magazinului: `codprodus` (referință+mărime), `cod_bare`
 *                          (EAN), `stoc`.
 *
 * Un produs e PĂSTRAT dacă oricare din condiții e adevărată (verificate pe referința produsului,
 * pe referințele tuturor combinațiilor și pe EAN-uri):
 *   1. referința/combinația există în feedul B2B (`cod` sau `cod_globe`) — indiferent de
 *      `cantitate` (0 poate fi temporar); cu --supplier-qty se cere `cantitate > 0`;
 *   2. EAN-ul produsului/combinației există în feedul B2B;
 *   3. referința/combinația (sau un `codprodus` care ÎNCEPE cu referința) e în `stocuri2024`
 *      cu `stoc > 0`, sau EAN-ul e în `cod_bare` cu `stoc > 0`.
 * Altfel produsul e VECHI și se dezactivează.
 *
 * Dezactivare (per produs, în tranzacție):
 *   - `ps_product` + `ps_product_shop` (toate shop-urile): active = 0, visibility = 'none';
 *   - DELETE din `ps_product_supplier` (toate rândurile produsului) → fără furnizor, modulul
 *     supplierpricing NU îl mai reactivează (SUPPLIERPRICING_DISABLE_NO_SUPPLIER_PRODUCTS).
 *   - invalidare cache Redis (modulul teamwant_redis) dacă PrestaShop e pe disc (--ps-root).
 *
 * Rulare:
 *   php dezactivare-produse-vechi.php [opțiuni]
 *   Implicit = DRY-RUN: nu scrie nimic, doar listează produsele care AR FI dezactivate și scrie
 *   raportul logs/raport-<ts>.csv (de verificat înainte de --apply).
 *
 * Opțiuni:
 *   --apply              scrie efectiv; produce logs/dezactivare-<ts>.log + logs/rollback-<ts>.sql
 *   --brand=a,b          doar brandurile date (dainese, agv, tcx, momo); implicit toate 4
 *   --include-inactive   procesează și produsele deja inactive (le curăță visibility + furnizorii)
 *   --supplier-qty       „disponibil la furnizor" = cantitate > 0 în B2B (implicit: doar prezența)
 *   --only=ID|REF        un singur produs (id_product sau referință)
 *   --limit=N            maxim N produse dezactivate (rulări în tranșe)
 *   --show-kept          listează și produsele păstrate, cu motivul (verificarea regulilor)
 *   --no-redis           nu invalida cache-ul Redis
 *   --ps-root=PATH       rădăcina PrestaShop (implicit /home2/bikershop/public_html dacă există);
 *                        de aici se citesc credențialele DB (app/config/parameters.php) și se
 *                        încarcă PrestaShop pentru Redis. Fără PrestaShop pe disc (rulare locală)
 *                        credențialele vin din `.env`-ul portalului (BIKERSHOP_*).
 *
 * Copia canonică e în repo-ul portalului (database/bikershop/); pe serverul bikershop se copiază
 * cu scp în ~/tools/dezactivare-produse-vechi/ și se rulează cu /usr/local/bin/ea-php84.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

// ---------------------------------------------------------------------------
// Argumente
// ---------------------------------------------------------------------------
const MANUFACTURERS = [
    24  => 'DAINESE',
    120 => 'AGV',
    140 => 'TCX',
    141 => 'MOMO Design',
];
const BRAND_ALIASES = [
    'dainese' => 24,
    'agv'     => 120,
    'tcx'     => 140,
    'momo'    => 141,
    'momodesign' => 141,
];
const ID_SHOP_MAIN = 1;
const LANG_RO      = 1;
const PREFIX_MIN   = 8; // lungimea minimă a referinței pentru potrivirea „codprodus începe cu"

$opt = [
    'apply'            => false,
    'brands'           => array_keys(MANUFACTURERS),
    'include_inactive' => false,
    'supplier_qty'     => false,
    'only'             => null,
    'limit'            => 0,
    'show_kept'        => false,
    'redis'            => true,
    'ps_root'          => is_dir('/home2/bikershop/public_html') ? '/home2/bikershop/public_html' : null,
];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--apply') {
        $opt['apply'] = true;
    } elseif ($a === '--include-inactive') {
        $opt['include_inactive'] = true;
    } elseif ($a === '--supplier-qty') {
        $opt['supplier_qty'] = true;
    } elseif ($a === '--show-kept') {
        $opt['show_kept'] = true;
    } elseif ($a === '--no-redis') {
        $opt['redis'] = false;
    } elseif (str_starts_with($a, '--brand=')) {
        $ids = [];
        foreach (explode(',', substr($a, 8)) as $b) {
            $b = strtolower(trim($b));
            if ($b === '') {
                continue;
            }
            if (!isset(BRAND_ALIASES[$b])) {
                fwrite(STDERR, "Brand necunoscut: {$b} (accept: " . implode(', ', array_keys(BRAND_ALIASES)) . ")\n");
                exit(2);
            }
            $ids[BRAND_ALIASES[$b]] = true;
        }
        $opt['brands'] = array_keys($ids);
    } elseif (str_starts_with($a, '--only=')) {
        $opt['only'] = strtoupper(trim(substr($a, 7)));
    } elseif (str_starts_with($a, '--limit=')) {
        $opt['limit'] = max(0, (int) substr($a, 8));
    } elseif (str_starts_with($a, '--ps-root=')) {
        $opt['ps_root'] = rtrim(substr($a, 10), '/');
    } else {
        fwrite(STDERR, "Opțiune necunoscută: {$a}\n");
        exit(2);
    }
}

// ---------------------------------------------------------------------------
// Fișiere de lucru: logs/ + tmp/ lângă script
// ---------------------------------------------------------------------------
$baseDir = __DIR__;
$logDir  = $baseDir . '/logs';
$tmpDir  = $baseDir . '/tmp';
@mkdir($logDir, 0775, true);
@mkdir($tmpDir, 0775, true);

$ts       = date('Ymd-His');
$logFile  = $logDir . "/dezactivare-{$ts}.log";
$rbFile   = $logDir . "/rollback-{$ts}.sql";
$csvFile  = $logDir . "/raport-{$ts}" . ($opt['apply'] ? '' : '-dryrun') . '.csv';
$lockFile = $tmpDir . '/dezactivare.lock';

$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "O altă rulare e în curs (lock {$lockFile}).\n");
    exit(1);
}

$logHandle = $opt['apply'] ? fopen($logFile, 'a') : null;
function logln(string $line = ''): void
{
    global $logHandle;
    echo $line, "\n";
    if ($logHandle) {
        fwrite($logHandle, date('H:i:s') . ' ' . $line . "\n");
    }
}

$rollback = [];
/** Scrie liniile de rollback imediat (o rulare întreruptă nu trebuie să piardă istoricul). */
function rollback_flush(): void
{
    global $rollback, $rbFile, $opt;
    if (!$opt['apply'] || !$rollback) {
        return;
    }
    if (!is_file($rbFile)) {
        file_put_contents($rbFile, "-- Rollback pentru rularea dezactivare-produse-vechi din " . date('c') . "\n");
    }
    file_put_contents($rbFile, implode("\n", $rollback) . "\n", FILE_APPEND);
    $rollback = [];
}

// ---------------------------------------------------------------------------
// Conexiune DB: parameters.php din PrestaShop (server) sau .env-ul portalului (local)
// ---------------------------------------------------------------------------
function db_config_from_prestashop(string $root): ?array
{
    $file = $root . '/app/config/parameters.php';
    if (!is_file($file)) {
        return null;
    }
    $cfg = (require $file)['parameters'] ?? null;
    if (!is_array($cfg) || empty($cfg['database_host'])) {
        return null;
    }
    [$host, $port] = array_pad(explode(':', (string) $cfg['database_host'], 2), 2, null);
    return [
        'host'   => $host,
        'port'   => $cfg['database_port'] ?: ($port ?: 3306),
        'name'   => $cfg['database_name'],
        'user'   => $cfg['database_user'],
        'pass'   => (string) $cfg['database_password'],
        'prefix' => $cfg['database_prefix'] ?? 'ps_',
    ];
}

function db_config_from_env(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $env = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v);
        if ($v !== '' && ($v[0] === '"' || $v[0] === "'") && str_ends_with($v, $v[0])) {
            $v = substr($v, 1, -1);
        }
        $env[trim($k)] = $v;
    }
    if (empty($env['BIKERSHOP_HOST']) || empty($env['BIKERSHOP_NAME'])) {
        return null;
    }
    return [
        'host'   => $env['BIKERSHOP_HOST'],
        'port'   => $env['BIKERSHOP_PORT'] ?? 3306,
        'name'   => $env['BIKERSHOP_NAME'],
        'user'   => $env['BIKERSHOP_USER'] ?? '',
        'pass'   => $env['BIKERSHOP_PASS'] ?? '',
        'prefix' => $env['BIKERSHOP_PREFIX'] ?? 'ps_',
    ];
}

$dbCfg = null;
$dbSrc = '';
if ($opt['ps_root'] && ($dbCfg = db_config_from_prestashop($opt['ps_root']))) {
    $dbSrc = $opt['ps_root'] . '/app/config/parameters.php';
} else {
    foreach ([$baseDir . '/../../.env', $baseDir . '/.env'] as $envFile) {
        if ($dbCfg = db_config_from_env($envFile)) {
            $dbSrc = realpath($envFile);
            break;
        }
    }
}
if (!$dbCfg) {
    fwrite(STDERR, "Nu găsesc credențialele DB: dă --ps-root=<rădăcina PrestaShop> sau rulează din repo-ul portalului (.env cu BIKERSHOP_*).\n");
    exit(1);
}

$prefix = $dbCfg['prefix'];
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbCfg['host'], (int) $dbCfg['port'], $dbCfg['name']),
        $dbCfg['user'],
        $dbCfg['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 15,
        ]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "Conexiune DB eșuată ({$dbSrc}): " . $e->getMessage() . "\n");
    exit(1);
}
@ini_set('memory_limit', '512M');
set_time_limit(0);

$brandNames = array_map(fn ($id) => MANUFACTURERS[$id], $opt['brands']);
logln(sprintf('dezactivare-produse-vechi %s — DB %s@%s (%s), PHP %s',
    $opt['apply'] ? 'APPLY' : 'DRY-RUN', $dbCfg['name'], $dbCfg['host'], $dbSrc, PHP_VERSION));
logln(sprintf('Branduri: %s | furnizor = %s | %s', implode(', ', $brandNames),
    $opt['supplier_qty'] ? 'cantitate > 0 în B2B' : 'prezent în B2B',
    $opt['include_inactive'] ? 'inclusiv produsele inactive' : 'doar produsele active'));
logln(str_repeat('─', 100));

function norm(?string $s): string
{
    return strtoupper(trim((string) $s));
}

// ---------------------------------------------------------------------------
// 1. Seturi de referință: feed B2B + stoc fizic
// ---------------------------------------------------------------------------
$b2bCod = [];   // cod → true
$b2bGlobe = []; // cod_globe → true
$b2bEan = [];   // ean → true
$b2bNames = []; // cod → [brand, NUME] (pentru sugestii de echivalent)
$stmt = $pdo->query('SELECT cod, cod_globe, ean, cantitate, brand, nume FROM dainese2026_b2b');
$b2bRows = 0;
foreach ($stmt as $r) {
    $b2bRows++;
    if ($opt['supplier_qty'] && (int) $r['cantitate'] <= 0) {
        continue;
    }
    $c = norm($r['cod']);
    $g = norm($r['cod_globe']);
    $e = trim((string) $r['ean']);
    if ($c !== '') {
        $b2bCod[$c] = true;
        $b2bNames[$c] ??= [norm($r['brand']), norm($r['nume'])];
    }
    if ($g !== '') {
        $b2bGlobe[$g] = true;
    }
    if ($e !== '') {
        $b2bEan[$e] = true;
    }
}
if ($b2bRows === 0) {
    fwrite(STDERR, "Tabela dainese2026_b2b e goală → refuz să continui (aș dezactiva tot).\n");
    exit(1);
}

$stock = [];    // codprodus (stoc > 0) → stoc
$stockEan = []; // cod_bare (stoc > 0) → stoc
$stockRows = 0;
foreach ($pdo->query('SELECT codprodus, cod_bare, stoc FROM stocuri2024') as $r) {
    $stockRows++;
    if ((int) $r['stoc'] <= 0) {
        continue;
    }
    $c = norm($r['codprodus']);
    $e = trim((string) $r['cod_bare']);
    if ($c !== '') {
        $stock[$c] = (int) $r['stoc'];
    }
    if ($e !== '') {
        $stockEan[$e] = (int) $r['stoc'];
    }
}
if ($stockRows === 0) {
    fwrite(STDERR, "Tabela stocuri2024 e goală → refuz să continui.\n");
    exit(1);
}
$stockKeys = array_map('strval', array_keys($stock));
logln(sprintf('B2B: %d rânduri, %d coduri, %d combinații, %d EAN | Stoc fizic: %d rânduri, %d cu stoc > 0',
    $b2bRows, count($b2bCod), count($b2bGlobe), count($b2bEan), $stockRows, count($stock)));

// ---------------------------------------------------------------------------
// 2. Produsele candidate din BikerShop (+ combinații + furnizori)
// ---------------------------------------------------------------------------
$brandList = implode(',', array_map('intval', $opt['brands']));
$where = "p.id_manufacturer IN ({$brandList})";
if (!$opt['include_inactive']) {
    $where .= ' AND ps.active = 1';
}
$params = [];
if ($opt['only'] !== null) {
    $where .= ' AND (p.id_product = :only_id OR p.reference = :only_ref)';
    $params = [':only_id' => (int) $opt['only'], ':only_ref' => $opt['only']];
}
$stmt = $pdo->prepare("
    SELECT p.id_product, p.id_manufacturer, p.reference, p.ean13,
           ps.active, ps.visibility, pl.name, pl.link_rewrite
    FROM {$prefix}product p
    JOIN {$prefix}product_shop ps ON ps.id_product = p.id_product AND ps.id_shop = " . ID_SHOP_MAIN . "
    LEFT JOIN {$prefix}product_lang pl ON pl.id_product = p.id_product AND pl.id_lang = " . LANG_RO . "
         AND pl.id_shop = " . ID_SHOP_MAIN . "
    WHERE {$where}
    ORDER BY p.id_manufacturer, p.id_product
");
$stmt->execute($params);
$products = [];
foreach ($stmt as $r) {
    $r['combos'] = [];
    $r['suppliers'] = 0;
    $products[(int) $r['id_product']] = $r;
}
if (!$products) {
    logln('Niciun produs candidat.');
    exit(0);
}
$idList = implode(',', array_keys($products));
foreach ($pdo->query("SELECT id_product, id_product_attribute, reference, ean13 FROM {$prefix}product_attribute WHERE id_product IN ({$idList})") as $r) {
    $products[(int) $r['id_product']]['combos'][] = $r;
}
foreach ($pdo->query("SELECT id_product, COUNT(*) c FROM {$prefix}product_supplier WHERE id_product IN ({$idList}) GROUP BY id_product") as $r) {
    $products[(int) $r['id_product']]['suppliers'] = (int) $r['c'];
}
logln(sprintf('Produse candidate: %d (cu combinații: %d)', count($products),
    count(array_filter($products, fn ($p) => $p['combos']))));
logln();

// ---------------------------------------------------------------------------
// 3. Clasificare: păstrat (cu motiv) sau vechi
// ---------------------------------------------------------------------------
/** Motivele pentru care produsul e încă valid; [] = vechi. */
function keep_reasons(array $p): array
{
    global $b2bCod, $b2bGlobe, $b2bEan, $stock, $stockEan, $stockKeys;
    $reasons = [];
    $refs = [['produs', norm($p['reference']), trim((string) $p['ean13'])]];
    foreach ($p['combos'] as $c) {
        $refs[] = ['combinație', norm($c['reference']), trim((string) $c['ean13'])];
    }
    foreach ($refs as [$kind, $ref, $ean]) {
        if ($ref !== '') {
            if (isset($b2bCod[$ref]) || isset($b2bGlobe[$ref])) {
                $reasons[] = "B2B {$kind} {$ref}";
            }
            if (isset($stock[$ref])) {
                $reasons[] = "STOC {$kind} {$ref} ({$stock[$ref]} buc)";
            }
        }
        if ($ean !== '') {
            if (isset($b2bEan[$ean])) {
                $reasons[] = "B2B EAN {$ean}";
            }
            if (isset($stockEan[$ean])) {
                $reasons[] = "STOC EAN {$ean} ({$stockEan[$ean]} buc)";
            }
        }
    }
    if (!$reasons) {
        $ref = norm($p['reference']);
        if (strlen($ref) >= PREFIX_MIN) {
            foreach ($stockKeys as $k) {
                if (str_starts_with($k, $ref)) {
                    $reasons[] = "STOC prefix {$k} ({$stock[$k]} buc)";
                    break;
                }
            }
        }
    }
    return array_values(array_unique($reasons));
}

const NAME_STOPWORDS = ['GHETE', 'GETE', 'CIZME', 'CASCA', 'GEACA', 'JACHETA', 'MANUSI', 'PANTALONI', 'COMBINEZON',
    'PROTECTIE', 'PROTECTII', 'BLACK', 'WHITE', 'NEGRU', 'NEGRE', 'ALB', 'ROSU', 'GREY', 'GRAY', 'BROWN', 'MARO',
    'LADY', 'DAMA', 'WATERPROOF', 'GORE-TEX', 'GORETEX', 'DAINESE', 'MOMO', 'DESIGN', 'CULOARE', 'MOTO',
    'ANVELOPE', 'IMPERMEABIL', 'MICHELIN', 'YELLOW', 'GALBEN', 'FLUO', 'GREEN', 'VERDE', 'BLUE', 'ALBASTRU'];

/** Sugestii de coduri B2B cu nume asemănător (ajutor la verificarea manuală a raportului). */
function b2b_hint(array $p): string
{
    global $b2bNames;
    $brand = MANUFACTURERS[(int) $p['id_manufacturer']] ?? '';
    $brand = $brand === 'MOMO Design' ? 'MOMODESIGN' : strtoupper($brand);
    $tokens = [];
    foreach (preg_split('/[^A-Z0-9\-]+/', norm($p['name'])) as $t) {
        if (strlen($t) >= 4 && !in_array($t, NAME_STOPWORDS, true) && $t !== $brand && !preg_match('/^\d+$/', $t)) {
            $tokens[] = $t;
        }
        if (count($tokens) === 2) {
            break;
        }
    }
    if (!$tokens) {
        return '';
    }
    $hits = [];
    foreach ($b2bNames as $cod => [$b, $name]) {
        $cod = (string) $cod;
        if ($b !== $brand) {
            continue;
        }
        foreach ($tokens as $t) {
            if (!str_contains($name, $t)) {
                continue 2;
            }
        }
        $hits[] = $cod;
        if (count($hits) === 3) {
            break;
        }
    }
    return implode(' ', $hits);
}

$csv = fopen($csvFile, 'w');
fwrite($csv, "\xEF\xBB\xBF"); // BOM → Excel deschide corect diacriticele
fputcsv($csv, ['id_product', 'brand', 'referinta', 'nume', 'activ', 'vizibilitate', 'combinatii', 'randuri_furnizor',
    'decizie', 'motiv', 'sugestii_b2b', 'url'], ';');

$old = [];
$kept = 0;
foreach ($products as $id => $p) {
    $reasons = keep_reasons($p);
    $brand = MANUFACTURERS[(int) $p['id_manufacturer']];
    $url = "https://bikershop.ro/{$id}-{$p['link_rewrite']}.html";
    if ($reasons) {
        $kept++;
        fputcsv($csv, [$id, $brand, $p['reference'], $p['name'], $p['active'], $p['visibility'], count($p['combos']),
            $p['suppliers'], 'PASTRAT', implode(' | ', $reasons), '', $url], ';');
        if ($opt['show_kept']) {
            logln(sprintf('  păstrat  #%-7d %-12s %-14s %-55s %s', $id, $brand, $p['reference'],
                mb_strimwidth((string) $p['name'], 0, 55, '…'), $reasons[0]));
        }
        continue;
    }
    // deja inactiv + invizibil + fără furnizori → nimic de făcut
    $alreadyDone = (int) $p['active'] === 0 && $p['visibility'] === 'none' && $p['suppliers'] === 0;
    $hint = b2b_hint($p);
    fputcsv($csv, [$id, $brand, $p['reference'], $p['name'], $p['active'], $p['visibility'], count($p['combos']),
        $p['suppliers'], $alreadyDone ? 'DEJA_INACTIV' : 'DEZACTIVARE', 'lipsă B2B + lipsă stoc fizic', $hint, $url], ';');
    if (!$alreadyDone) {
        $old[$id] = $p + ['hint' => $hint];
    }
}
fclose($csv);

logln(sprintf('Păstrate: %d | De dezactivat: %d', $kept, count($old)));
logln();
$byBrand = [];
foreach ($old as $p) {
    $byBrand[MANUFACTURERS[(int) $p['id_manufacturer']]] = ($byBrand[MANUFACTURERS[(int) $p['id_manufacturer']]] ?? 0) + 1;
}
foreach ($byBrand as $b => $n) {
    logln(sprintf('  %-12s %d', $b, $n));
}
logln();

// ---------------------------------------------------------------------------
// 4. Dezactivare (sau doar listare la dry-run)
// ---------------------------------------------------------------------------
$redis = null;
if ($opt['apply'] && $opt['redis'] && $old && $opt['ps_root'] && is_file($opt['ps_root'] . '/config/config.inc.php')) {
    try {
        $root = $opt['ps_root'];
        if (!defined('_PS_ADMIN_DIR_')) {
            $adminDir = null;
            foreach (scandir($root) as $entry) {
                if ((str_starts_with($entry, 'admin') || str_starts_with($entry, '__admin')) && $entry !== 'admin-api'
                    && is_dir($root . '/' . $entry) && is_file($root . '/' . $entry . '/index.php')) {
                    $adminDir = $root . '/' . $entry;
                    break;
                }
            }
            define('_PS_ADMIN_DIR_', $adminDir ?? $root . '/admin');
        }
        require $root . '/config/config.inc.php';
        $redis = Module::isEnabled('teamwant_redis') ? Module::getInstanceByName('teamwant_redis') : null;
        logln('PrestaShop ' . _PS_VERSION_ . ' încărcat pentru invalidarea cache-ului Redis' . ($redis ? '' : ' (modulul teamwant_redis lipsește)'));
    } catch (Throwable $e) {
        logln('Redis: PrestaShop nu s-a încărcat (' . $e->getMessage() . ') — cache-ul rămâne până la TTL');
        $redis = null;
    }
}

$stSupplier = $pdo->prepare("SELECT * FROM {$prefix}product_supplier WHERE id_product = :id");
$stShops    = $pdo->prepare("SELECT id_shop, active, visibility FROM {$prefix}product_shop WHERE id_product = :id");
$stProd     = $pdo->prepare("SELECT active, visibility FROM {$prefix}product WHERE id_product = :id");
$upProd     = $pdo->prepare("UPDATE {$prefix}product SET active = 0, visibility = 'none', date_upd = NOW() WHERE id_product = :id");
$upShop     = $pdo->prepare("UPDATE {$prefix}product_shop SET active = 0, visibility = 'none', date_upd = NOW() WHERE id_product = :id");
$delSup     = $pdo->prepare("DELETE FROM {$prefix}product_supplier WHERE id_product = :id");

$done = 0;
$errors = 0;
foreach ($old as $id => $p) {
    if ($opt['limit'] > 0 && $done >= $opt['limit']) {
        logln("Limita de {$opt['limit']} produse atinsă; restul rămân pentru rularea următoare.");
        break;
    }
    $brand = MANUFACTURERS[(int) $p['id_manufacturer']];
    $line = sprintf('%s #%-7d %-12s %-14s %-55s combinații=%d furnizori=%d%s',
        $opt['apply'] ? 'DEZACTIVAT' : 'ar dezactiva', $id, $brand, $p['reference'],
        mb_strimwidth((string) $p['name'], 0, 55, '…'), count($p['combos']), $p['suppliers'],
        $p['hint'] !== '' ? "  ? B2B: {$p['hint']}" : '');

    if (!$opt['apply']) {
        logln($line);
        $done++;
        continue;
    }

    try {
        $pdo->beginTransaction();

        // rollback: starea de dinainte
        $stProd->execute([':id' => $id]);
        if ($cur = $stProd->fetch()) {
            $rollback[] = sprintf("UPDATE {$prefix}product SET active = %d, visibility = %s WHERE id_product = %d;",
                (int) $cur['active'], $pdo->quote($cur['visibility']), $id);
        }
        $stShops->execute([':id' => $id]);
        foreach ($stShops as $s) {
            $rollback[] = sprintf("UPDATE {$prefix}product_shop SET active = %d, visibility = %s WHERE id_product = %d AND id_shop = %d;",
                (int) $s['active'], $pdo->quote($s['visibility']), $id, (int) $s['id_shop']);
        }
        $stSupplier->execute([':id' => $id]);
        foreach ($stSupplier as $s) {
            $cols = array_map(fn ($c) => "`{$c}`", array_keys($s));
            $vals = array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($s));
            $rollback[] = sprintf("INSERT INTO {$prefix}product_supplier (%s) VALUES (%s);", implode(', ', $cols), implode(', ', $vals));
        }

        $upProd->execute([':id' => $id]);
        $upShop->execute([':id' => $id]);
        $delSup->execute([':id' => $id]);
        $deleted = $delSup->rowCount();

        $pdo->commit();
        rollback_flush();
        $done++;
        logln($line . " (furnizori șterși: {$deleted})");

        if ($redis && method_exists($redis, 'hookActionObjectProductUpdateAfter')) {
            try {
                $redis->hookActionObjectProductUpdateAfter(['object' => new Product($id)]);
            } catch (Throwable $e) {
                logln("    redis: " . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $rollback = [];
        $errors++;
        logln("EROARE #{$id}: " . $e->getMessage());
    }
}

logln();
logln(sprintf('%s: %d produse %s, %d erori. Raport: %s%s',
    $opt['apply'] ? 'GATA' : 'DRY-RUN', $done, $opt['apply'] ? 'dezactivate' : 'ar fi dezactivate', $errors,
    $csvFile, $opt['apply'] && $done ? " | rollback: {$rbFile}" : ''));
if (!$opt['apply'] && $done) {
    logln('Verifică raportul CSV, apoi rulează cu --apply.');
}
exit($errors ? 1 : 0);
