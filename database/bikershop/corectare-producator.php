#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Corectează producătorul (ps_product.id_manufacturer) produselor de pe BikerShop după brandul
 * din feedul B2B Dainese (`dainese2026_b2b.brand`): produsele importate din feed au ajuns toate
 * sub DAINESE, deși feedul conține și AGV / TCX / Momodesign.
 *
 * Potrivire: `ps_product.reference` = `dainese2026_b2b.cod` sau referința unei combinații
 * (`ps_product_attribute.reference`) = `cod_globe`. Brandul din feed → id_manufacturer:
 *   Dainese → 24, AGV → 120, TCX → 140, Momodesign → 141.
 * Un produs ale cărui referințe indică branduri diferite în feed e sărit și raportat.
 *
 * Rulare (dry-run implicit — doar listează):
 *   php corectare-producator.php [--apply] [--only=ID|REF] [--limit=N] [--no-redis] [--ps-root=PATH]
 * Cu --apply scrie `logs/corectare-producator-<ts>.log` + `logs/rollback-producator-<ts>.sql`.
 * Pe serverul bikershop stă în ~/public_html/tool/ și se rulează cu /usr/local/bin/ea-php84;
 * acolo citește credențialele din app/config/parameters.php și invalidează cache-ul Redis.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

const BRAND_TO_MANUFACTURER = [
    'DAINESE'    => 24,
    'AGV'        => 120,
    'TCX'        => 140,
    'MOMODESIGN' => 141,
];
const ID_SHOP_MAIN = 1;
const LANG_RO      = 1;

$opt = [
    'apply'   => false,
    'only'    => null,
    'limit'   => 0,
    'redis'   => true,
    'ps_root' => is_dir('/home2/bikershop/public_html') ? '/home2/bikershop/public_html' : null,
];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--apply') {
        $opt['apply'] = true;
    } elseif ($a === '--no-redis') {
        $opt['redis'] = false;
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

$baseDir = __DIR__;
$logDir  = $baseDir . '/logs';
@mkdir($logDir, 0775, true);
$ts      = date('Ymd-His');
$logFile = $logDir . "/corectare-producator-{$ts}.log";
$rbFile  = $logDir . "/rollback-producator-{$ts}.sql";

$logHandle = $opt['apply'] ? fopen($logFile, 'a') : null;
function logln(string $line = ''): void
{
    global $logHandle;
    echo $line, "\n";
    if ($logHandle) {
        fwrite($logHandle, date('H:i:s') . ' ' . $line . "\n");
    }
}

// ---------------------------------------------------------------------------
// Conexiune DB (aceeași logică ca dezactivare-produse-vechi.php)
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
set_time_limit(0);

logln(sprintf('corectare-producator %s — DB %s@%s (%s), PHP %s',
    $opt['apply'] ? 'APPLY' : 'DRY-RUN', $dbCfg['name'], $dbCfg['host'], $dbSrc, PHP_VERSION));
logln(str_repeat('─', 100));

function norm(?string $s): string
{
    return strtoupper(trim((string) $s));
}

// ---------------------------------------------------------------------------
// 1. Brandul fiecărui cod / cod_globe din feed
// ---------------------------------------------------------------------------
$brandByCod = [];
$brandByGlobe = [];
$unknownBrands = [];
foreach ($pdo->query('SELECT cod, cod_globe, brand FROM dainese2026_b2b') as $r) {
    $b = str_replace([' ', '-'], '', norm($r['brand']));
    if (!isset(BRAND_TO_MANUFACTURER[$b])) {
        $unknownBrands[$b] = true;
        continue;
    }
    $c = norm($r['cod']);
    $g = norm($r['cod_globe']);
    if ($c !== '') {
        $brandByCod[$c] = $b;
    }
    if ($g !== '') {
        $brandByGlobe[$g] = $b;
    }
}
if (!$brandByCod) {
    fwrite(STDERR, "Feedul dainese2026_b2b e gol.\n");
    exit(1);
}
if ($unknownBrands) {
    logln('Branduri din feed fără mapare (ignorate): ' . implode(', ', array_keys($unknownBrands)));
}
$manufacturerNames = [];
foreach ($pdo->query("SELECT id_manufacturer, name FROM {$prefix}manufacturer") as $r) {
    $manufacturerNames[(int) $r['id_manufacturer']] = $r['name'];
}
foreach (BRAND_TO_MANUFACTURER as $b => $id) {
    if (!isset($manufacturerNames[$id])) {
        fwrite(STDERR, "Producătorul #{$id} ({$b}) nu există în {$prefix}manufacturer.\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// 2. Produsele ale căror referințe (produs sau combinație) sunt în feed
// ---------------------------------------------------------------------------
$codes = array_keys($brandByCod);
$globes = array_keys($brandByGlobe);
$candidates = []; // id_product → ['brands' => [brand => sursă], ...]

$fetch = function (string $sql, array $keys, string $kind, array $brandMap) use ($pdo, &$candidates): void {
    foreach (array_chunk($keys, 500) as $chunk) {
        $in = implode(',', array_map(fn ($k) => $pdo->quote((string) $k), $chunk));
        foreach ($pdo->query(str_replace(':in', $in, $sql)) as $r) {
            $ref = norm($r['ref']);
            if (!isset($brandMap[$ref])) {
                continue;
            }
            $id = (int) $r['id_product'];
            $candidates[$id]['brands'][$brandMap[$ref]] ??= "{$kind} {$ref}";
        }
    }
};
$fetch("SELECT id_product, reference AS ref FROM {$prefix}product WHERE reference IN (:in)", $codes, 'produs', $brandByCod);
$fetch("SELECT id_product, reference AS ref FROM {$prefix}product_attribute WHERE reference IN (:in)", $globes, 'combinație', $brandByGlobe);

if (!$candidates) {
    logln('Niciun produs cu referință din feed.');
    exit(0);
}
$idList = implode(',', array_keys($candidates));
$stmt = $pdo->query("
    SELECT p.id_product, p.reference, p.id_manufacturer, ps.active, pl.name, pl.link_rewrite
    FROM {$prefix}product p
    JOIN {$prefix}product_shop ps ON ps.id_product = p.id_product AND ps.id_shop = " . ID_SHOP_MAIN . "
    LEFT JOIN {$prefix}product_lang pl ON pl.id_product = p.id_product AND pl.id_lang = " . LANG_RO . "
         AND pl.id_shop = " . ID_SHOP_MAIN . "
    WHERE p.id_product IN ({$idList})
    ORDER BY p.id_product
");
$total = 0;
$ok = 0;
$conflicts = 0;
$todo = [];
foreach ($stmt as $p) {
    $id = (int) $p['id_product'];
    if ($opt['only'] !== null && (string) $id !== $opt['only'] && norm($p['reference']) !== $opt['only']) {
        continue;
    }
    $total++;
    $brands = $candidates[$id]['brands'];
    if (count($brands) > 1) {
        $conflicts++;
        $desc = [];
        foreach ($brands as $b => $src) {
            $desc[] = "{$b} ({$src})";
        }
        logln(sprintf('  CONFLICT #%-7d %-14s %-50s → branduri diferite în feed: %s', $id, $p['reference'],
            mb_strimwidth((string) $p['name'], 0, 50, '…'), implode(', ', $desc)));
        continue;
    }
    $brand = array_key_first($brands);
    $want = BRAND_TO_MANUFACTURER[$brand];
    if ((int) $p['id_manufacturer'] === $want) {
        $ok++;
        continue;
    }
    $todo[$id] = $p + ['want' => $want, 'brand' => $brand, 'src' => $brands[$brand]];
}
logln(sprintf('Produse cu referință în feed: %d | corecte: %d | de corectat: %d | conflicte: %d',
    $total, $ok, count($todo), $conflicts));
$pairs = [];
foreach ($todo as $p) {
    $k = ($manufacturerNames[(int) $p['id_manufacturer']] ?? "#{$p['id_manufacturer']}") . ' → ' . $manufacturerNames[$p['want']];
    $pairs[$k] = ($pairs[$k] ?? 0) + 1;
}
foreach ($pairs as $k => $n) {
    logln(sprintf('  %-28s %d', $k, $n));
}
logln();

// ---------------------------------------------------------------------------
// 3. Corectare
// ---------------------------------------------------------------------------
$redis = null;
if ($opt['apply'] && $opt['redis'] && $todo && $opt['ps_root'] && is_file($opt['ps_root'] . '/config/config.inc.php')) {
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

$upd = $pdo->prepare("UPDATE {$prefix}product SET id_manufacturer = :m, date_upd = NOW() WHERE id_product = :id");
$done = 0;
$errors = 0;
$rollback = [];
foreach ($todo as $id => $p) {
    if ($opt['limit'] > 0 && $done >= $opt['limit']) {
        logln("Limita de {$opt['limit']} produse atinsă.");
        break;
    }
    $from = $manufacturerNames[(int) $p['id_manufacturer']] ?? "#{$p['id_manufacturer']}";
    $line = sprintf('%s #%-7d %-14s %-55s %s → %s (%s)%s', $opt['apply'] ? 'CORECTAT' : 'ar corecta', $id,
        $p['reference'], mb_strimwidth((string) $p['name'], 0, 55, '…'), $from, $manufacturerNames[$p['want']], $p['src'],
        (int) $p['active'] ? '' : ' [inactiv]');
    if (!$opt['apply']) {
        logln($line);
        $done++;
        continue;
    }
    try {
        $upd->execute([':m' => $p['want'], ':id' => $id]);
        $rollback[] = sprintf("UPDATE {$prefix}product SET id_manufacturer = %d WHERE id_product = %d;", (int) $p['id_manufacturer'], $id);
        if (!is_file($rbFile)) {
            file_put_contents($rbFile, "-- Rollback pentru rularea corectare-producator din " . date('c') . "\n");
        }
        file_put_contents($rbFile, implode("\n", $rollback) . "\n", FILE_APPEND);
        $rollback = [];
        $done++;
        logln($line);
        if ($redis && method_exists($redis, 'hookActionObjectProductUpdateAfter')) {
            try {
                $redis->hookActionObjectProductUpdateAfter(['object' => new Product($id)]);
            } catch (Throwable $e) {
                logln('    redis: ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        $errors++;
        logln("EROARE #{$id}: " . $e->getMessage());
    }
}

logln();
logln(sprintf('%s: %d produse %s, %d erori.%s', $opt['apply'] ? 'GATA' : 'DRY-RUN', $done,
    $opt['apply'] ? 'corectate' : 'ar fi corectate', $errors, $opt['apply'] && $done ? " Rollback: {$rbFile}" : ''));
if ($opt['apply'] && $done) {
    logln('Notă: filtrele de brand din navigarea cu fațete pot cere reindexare din BO (Module → Faceted search → Indexare).');
}
exit($errors ? 1 : 0);
