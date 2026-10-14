<?php
declare(strict_types=1);

/**
 * Raport „în stoc, dar nu pe site" pentru BikerShop (DOAR CITIRE).
 *
 * Ia fiecare cod cu stoc fizic din gestiune (`stocuri2024`, stoc > 0) și îl caută pe site:
 *   1. potrivire exactă: referința produsului / a unei variante sau EAN-ul (produs ori variantă);
 *   2. altfel, codul de model = codul fără sufixul de mărime (`18100026604M` → `18100026604`):
 *      produs cu referința egală cu modelul sau cu o variantă a aceluiași model.
 *
 * Stări: listat · produs inactiv · lipsește mărimea (produs activ) · lipsește mărimea (produs inactiv)
 *        · lipsește complet.
 *
 * Grupe: „echipament" (marcă sau tip de produs recunoscut în denumire), „alte produse" (consumabile,
 * piese aftermarket) și „exclus" (servicii, avansuri, vehicule, piese OEM Yamaha/CFMOTO, codurile din
 * stoc-nelistat-excluderi.txt). Raportul listează doar echipamentul și alte produse nelistate.
 *
 * Utilizare:
 *   local:  C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/bikershop/stoc-nelistat.php
 *   server: /usr/local/bin/ea-php84 stoc-nelistat.php --ps-root=/home2/bikershop/public_html
 * Opțiuni:
 *   --out=FIȘIER.csv   unde scrie CSV-ul (implicit logs/stoc-nelistat-<data>.csv)
 *   --all              CSV-ul conține toate codurile cu stoc, inclusiv cele listate și excluse
 *   --group=echipament CSV-ul (și coloana „Nou") doar pentru o grupă: echipament | „alte produse"
 *   --mail=a@b,c@d     trimite rezumatul + CSV-ul atașat (mail() al serverului) și salvează lista
 *                      de coduri nelistate în logs/stoc-nelistat-ultima.json → „Nou" la rularea următoare
 * Cron (serverul bikershop, luni 07:30):
 *   30 7 * * 1 /usr/local/bin/ea-php84 ~/public_html/tool/stoc-nelistat.php --group=echipament --mail=…
 */

// Din browser (tool/ e sub Basic Auth): rezumat + tabel + descărcare CSV, fără email și fără a
// atinge starea săptămânală. ?grupa=echipament (implicit) | alte | toate · ?csv=1 = descarcă.
$web = PHP_SAPI !== 'cli';
if ($web && !isset($_SERVER['PHP_AUTH_USER']) && !isset($_SERVER['REMOTE_USER']) && !isset($_SERVER['REDIRECT_REMOTE_USER'])) {
    http_response_code(403);
    exit;
}

// cod_producator din gestiune = furnizorul. Furnizorii ale căror coduri nu sunt de vândut online.
const EXCLUDED_SUPPLIERS = [
    0  => 'fără furnizor (becuri, avansuri)',
    31 => 'piese OEM Yamaha',
    32 => 'servicii, avansuri, uleiuri service, vehicule',
    36 => 'servicii / Linhai',
    6  => 'piese OEM CFMOTO',
];
const VEHICLE_PRICE = 15000;   // vehiculele au preț > 15.000 și seria de șasiu în denumire

// Mărci de echipament (căutate ca cuvânt întreg în denumire) → numele afișat.
const BRANDS = [
    'AGV' => 'AGV', 'DAINESE' => 'DAINESE', 'ARAI' => 'ARAI', 'FURYGAN' => 'FURYGAN', 'FURIGAN' => 'FURYGAN', 'KENNY' => 'KENNY',
    'OAKLEY' => 'OAKLEY', 'NOS' => 'NOS', 'LAZER' => 'LAZER', 'CARDO' => 'CARDO', 'MOMO' => 'MOMO', 'MOMODESIGN' => 'MOMO',
    'IXON' => 'IXON', 'EVS' => 'EVS', 'VYROS' => 'VYROS',
    'QUAD LOCK' => 'QUAD LOCK', 'QUADLOCK' => 'QUAD LOCK', 'SPECIALIZED' => 'SPECIALIZED', 'SPEC' => 'SPECIALIZED',
    'TCX' => 'TCX', 'GMS' => 'GMS', 'OJ' => 'OJ', 'JOHN DOE' => 'JOHN DOE', 'PMJ' => 'PMJ', 'RAM' => 'RAM MOUNTS',
    'ALPINESTARS' => 'ALPINESTARS', 'SHOEI' => 'SHOEI', 'HJC' => 'HJC', 'SHARK' => 'SHARK', 'SCHUBERTH' => 'SCHUBERTH',
    'REV IT' => 'REV\'IT', 'REVIT' => 'REV\'IT', 'HELD' => 'HELD', 'SENA' => 'SENA', 'LEATT' => 'LEATT',
    'FOX' => 'FOX', 'ONEAL' => 'O\'NEAL', 'O NEAL' => 'O\'NEAL', 'YAMAHA' => 'YAMAHA', 'REBELHORN' => 'REBELHORN',
];
// Tipuri de echipament (începutul denumirii).
const TYPES = [
    'CASCA', 'CASCHETA', 'CAGULA', 'GEACA', 'JACHETA', 'VESTA', 'MANUSI', 'PANTALONI', 'PANTALON', 'BLUGI', 'CIZME', 'GHETE',
    'BOCANCI', 'PANTOFI', 'ADIDASI', 'TRICOU', 'HANORAC', 'BLUZA', 'CAMASA', 'SAPCA', 'FES', 'BANDANA', 'GULER',
    'COMBINEZON', 'COSTUM', 'OCHELARI', 'OCH', 'VIZIERA', 'VIZOR', 'PINLOCK', 'LENTILE', 'PROTECTIE', 'PROTECTII',
    'ARMURA', 'GENUNCHIERE', 'COTIERE', 'CENTURA', 'BRETELE', 'SOSETE', 'LENJERIE', 'INTERIOR', 'BURETI', 'SISTEM COMUNICATIE',
    'INTERCOM', 'SUPORT TEL', 'GEANTA', 'RUCSAC', 'HUSA', 'AIRBAG', 'SHORT', 'COLANTI', 'POLO',
];
// Sufixe de mărime (cel mai lung întâi). Cifrele (39-48 la încălțăminte, 36N la pantaloni) se tratează separat.
// XX, 3X, X2 = codurile Dainese pentru 2XL, 3XL, X/2X (feedul B2B și gestiunea)
const SIZES = ['XXXS', '3XS', '4XL', '3XL', '2XL', 'XXXL', 'XXL', 'XXS', 'XS', 'XL', 'XX', '3X', 'X2', 'S', 'M', 'L'];

$opt = ['out' => null, 'all' => false, 'mail' => [], 'ps_root' => null, 'group' => null];
if ($web) {
    $opt['group'] = ['alte' => 'alte produse', 'toate' => null][$_GET['grupa'] ?? ''] ?? 'echipament';
    $opt['out'] = sys_get_temp_dir() . '/stoc-nelistat-' . bin2hex(random_bytes(6)) . '.csv';
    $opt['ps_root'] = dirname(__DIR__);
    ob_start();   // rezumatul text se pune în pagină
}
foreach ($web ? [] : array_slice($argv, 1) as $a) {
    if ($a === '--all') {
        $opt['all'] = true;
    } elseif (str_starts_with($a, '--group=')) {
        $opt['group'] = substr($a, 8);   // echipament | alte produse
    } elseif (str_starts_with($a, '--out=')) {
        $opt['out'] = substr($a, 6);
    } elseif (str_starts_with($a, '--mail=')) {
        $opt['mail'] = array_filter(array_map('trim', explode(',', substr($a, 7))));
    } elseif (str_starts_with($a, '--ps-root=')) {
        $opt['ps_root'] = rtrim(substr($a, 10), '/');
    } else {
        fwrite(STDERR, "Opțiune necunoscută: {$a}\n");
        exit(2);
    }
}
if ($opt['ps_root'] === null && is_dir('/home2/bikershop/public_html')) {
    $opt['ps_root'] = '/home2/bikershop/public_html';
}

// --- conexiune: parameters.php (server) sau .env-ul portalului (local) ---
function db_cfg(?string $root): ?array
{
    if ($root && is_file($root . '/app/config/parameters.php')) {
        $c = (require $root . '/app/config/parameters.php')['parameters'] ?? [];
        [$host, $port] = array_pad(explode(':', (string) $c['database_host'], 2), 2, null);
        return ['host' => $host, 'port' => $c['database_port'] ?: ($port ?: 3306), 'name' => $c['database_name'],
                'user' => $c['database_user'], 'pass' => (string) $c['database_password'], 'prefix' => $c['database_prefix'] ?? 'ps_'];
    }
    foreach ([__DIR__ . '/../../.env', __DIR__ . '/.env'] as $f) {
        if (!is_file($f)) {
            continue;
        }
        $e = [];
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
            $l = trim($l);
            if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $l, 2);
            $v = trim($v);
            if ($v !== '' && ($v[0] === '"' || $v[0] === "'") && str_ends_with($v, $v[0])) {
                $v = substr($v, 1, -1);
            }
            $e[trim($k)] = $v;
        }
        if (!empty($e['BIKERSHOP_HOST'])) {
            return ['host' => $e['BIKERSHOP_HOST'], 'port' => $e['BIKERSHOP_PORT'] ?? 3306, 'name' => $e['BIKERSHOP_NAME'],
                    'user' => $e['BIKERSHOP_USER'] ?? '', 'pass' => $e['BIKERSHOP_PASS'] ?? '', 'prefix' => $e['BIKERSHOP_PREFIX'] ?? 'ps_'];
        }
    }
    return null;
}
$cfg = db_cfg($opt['ps_root']);
if (!$cfg) {
    fwrite(STDERR, "Nu găsesc credențialele DB (--ps-root sau .env cu BIKERSHOP_*).\n");
    exit(1);
}
$P = $cfg['prefix'];
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], (int) $cfg['port'], $cfg['name']),
    $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// --- excluderi manuale (un cod de gestiune pe rând, # = comentariu) ---
$manualExcluded = [];
$exFile = __DIR__ . '/stoc-nelistat-excluderi.txt';
if (is_file($exFile)) {
    foreach (file($exFile, FILE_IGNORE_NEW_LINES) as $l) {
        $l = strtoupper(trim(explode('#', $l, 2)[0]));
        if ($l !== '') {
            $manualExcluded[$l] = true;
        }
    }
}

/** Marca din denumire (cuvânt întreg), sau ''. */
function brand_of(string $name): string
{
    $n = ' ' . preg_replace('/[^A-Z0-9]+/', ' ', strtoupper($name)) . ' ';
    foreach (BRANDS as $k => $label) {
        if (str_contains($n, ' ' . $k . ' ')) {
            return $label;
        }
    }
    return '';
}

function is_equipment_type(string $name): bool
{
    $n = strtoupper(trim($name));
    foreach (TYPES as $t) {
        if (str_starts_with($n, $t . ' ') || str_starts_with($n, $t . '.')) {
            return true;
        }
    }
    return false;
}

/** Codurile de model posibile (codul fără sufixul de mărime), cel mai probabil întâi. */
function model_codes(string $code): array
{
    // toate tăieturile posibile, cel mai lung cod de model întâi: `184A8030004XL` = model `184A8030004`
    // + XL, nu `184A803000` + 4XL (alt model, altă culoare)
    $out = [];
    foreach (SIZES as $s) {
        if (strlen($code) > strlen($s) + 4 && str_ends_with($code, $s)) {
            $out[] = substr($code, 0, -strlen($s));
        }
    }
    usort($out, fn ($a, $b) => strlen($b) <=> strlen($a));
    // mărimi numerice: 39–50 (încălțăminte, mănuși), 36N/52 (pantaloni, combinezoane), C2/C3...
    if (preg_match('/^(.{5,}?)(\d{2}N?|C\d)$/', $code, $m)) {
        $out[] = $m[1];
    }
    return array_values(array_unique($out));
}

$t0 = microtime(true);

// --- stocul fizic ---
$stock = [];
foreach ($pdo->query("SELECT codprodus, denumire, stoc, pret, cod_bare, cod_producator, data_adaugarii
        FROM stocuri2024 WHERE stoc > 0") as $r) {
    $code = strtoupper(trim((string) $r['codprodus']));
    if ($code === '') {
        continue;
    }
    if (isset($stock[$code])) {          // același cod pe mai multe gestiuni
        $stock[$code]['stoc'] += (int) $r['stoc'];
        continue;
    }
    $ean = trim((string) $r['cod_bare']);
    $stock[$code] = [
        'code' => $code, 'name' => trim((string) $r['denumire']), 'stoc' => (int) $r['stoc'], 'pret' => (float) $r['pret'],
        'ean' => preg_match('/^\d{8,14}$/', $ean) ? $ean : '', 'supplier' => (int) $r['cod_producator'],
        'last' => substr((string) $r['data_adaugarii'], 0, 10),
    ];
}

// --- grupa fiecărui cod ---
foreach ($stock as $code => &$s) {
    $s['brand'] = brand_of($s['name']);
    if (isset(EXCLUDED_SUPPLIERS[$s['supplier']])) {
        $s['group'] = 'exclus';
        $s['why'] = EXCLUDED_SUPPLIERS[$s['supplier']];
    } elseif ($s['pret'] > VEHICLE_PRICE && preg_match('/\b[A-HJ-NPR-Z0-9]{17}\b/', strtoupper($s['name']))) {
        $s['group'] = 'exclus';
        $s['why'] = 'vehicul';
    } elseif (isset($manualExcluded[$code])) {
        $s['group'] = 'exclus';
        $s['why'] = 'stoc-nelistat-excluderi.txt';
    } elseif (preg_match('/^(BIKE|BICICLETA|CADRU)\b/', strtoupper($s['name']))) {
        $s['group'] = 'alte produse';   // biciclete Specialized
        $s['why'] = '';
    } elseif ($s['brand'] !== '' && $s['brand'] !== 'YAMAHA' || is_equipment_type($s['name'])) {
        $s['group'] = 'echipament';
        $s['why'] = '';
    } else {
        $s['group'] = 'alte produse';
        $s['why'] = '';
    }
}
unset($s);

// --- căutare pe site (pe bucăți, cu IN pe coloane indexate; fără funcții pe coloane) ---
$byRef = [];   // REF => [id_product, id_product_attribute]
$byEan = [];
$prodActive = [];
$prodName = [];
$chunks = array_chunk(array_map('strval', array_keys($stock)), 400);
foreach ($chunks as $chunk) {
    $in = implode(',', array_fill(0, count($chunk), '?'));
    $eans = array_values(array_filter(array_map(fn ($c) => $stock[$c]['ean'], $chunk)));
    $st = $pdo->prepare("SELECT id_product, reference FROM {$P}product WHERE reference IN ($in)");
    $st->execute($chunk);
    foreach ($st as $r) {
        $byRef[strtoupper($r['reference'])] ??= [(int) $r['id_product'], 0];
    }
    $st = $pdo->prepare("SELECT id_product, id_product_attribute, reference FROM {$P}product_attribute WHERE reference IN ($in)");
    $st->execute($chunk);
    foreach ($st as $r) {
        $byRef[strtoupper($r['reference'])] ??= [(int) $r['id_product'], (int) $r['id_product_attribute']];
    }
    if ($eans) {
        $ein = implode(',', array_fill(0, count($eans), '?'));
        $st = $pdo->prepare("SELECT id_product, ean13 FROM {$P}product WHERE ean13 IN ($ein)");
        $st->execute($eans);
        foreach ($st as $r) {
            $byEan[$r['ean13']] ??= [(int) $r['id_product'], 0];
        }
        $st = $pdo->prepare("SELECT id_product, id_product_attribute, ean13 FROM {$P}product_attribute WHERE ean13 IN ($ein)");
        $st->execute($eans);
        foreach ($st as $r) {
            $byEan[$r['ean13']] ??= [(int) $r['id_product'], (int) $r['id_product_attribute']];
        }
    }
}

// codurile de model pentru ce n-a avut potrivire exactă
$modelOf = [];
foreach ($stock as $code => $s) {
    if ($s['group'] === 'exclus' || isset($byRef[$code]) || ($s['ean'] !== '' && isset($byEan[$s['ean']]))) {
        continue;
    }
    $modelOf[$code] = model_codes($s['code']);
}
$models = array_map('strval', array_unique(array_merge(...array_values($modelOf ?: [[]]))));
// Produsul unui model = cel care are deja variante „model + altă mărime" (o simplă referință egală cu
// codul de model nu ajunge: `55011107` → `550111` era alt produs).
$byModel = [];   // MODEL => id_product
$st = $pdo->prepare("SELECT id_product, reference FROM {$P}product_attribute WHERE reference LIKE ? LIMIT 40");
foreach ($models as $m) {
    if (strlen($m) < 5) {
        continue;
    }
    $st->execute([addcslashes($m, '%_') . '%']);
    foreach ($st as $r) {
        $rest = strtoupper(substr($r['reference'], strlen($m)));
        if (in_array($rest, SIZES, true) || preg_match('/^(\d{2}N?|C\d)$/', $rest)) {
            $byModel[$m] ??= (int) $r['id_product'];
        }
    }
}

// starea produselor găsite
$ids = [];
foreach ([$byRef, $byEan] as $map) {
    foreach ($map as $v) {
        $ids[$v[0]] = true;
    }
}
foreach ($byModel as $id) {
    $ids[$id] = true;
}
foreach (array_chunk(array_keys($ids), 400) as $chunk) {
    $in = implode(',', array_map('intval', $chunk));
    foreach ($pdo->query("SELECT ps.id_product, ps.active, pl.name FROM {$P}product_shop ps
            LEFT JOIN {$P}product_lang pl ON pl.id_product = ps.id_product AND pl.id_lang = 1 AND pl.id_shop = 1
            WHERE ps.id_shop = 1 AND ps.id_product IN ($in)") as $r) {
        $prodActive[(int) $r['id_product']] = (int) $r['active'] === 1;
        $prodName[(int) $r['id_product']] = (string) $r['name'];
    }
}

// --- clasificare ---
foreach ($stock as $code => &$s) {
    $s['state'] = '';
    $s['id'] = 0;
    $s['model'] = '';
    if ($s['group'] === 'exclus') {
        continue;
    }
    $hit = $byRef[$code] ?? ($s['ean'] !== '' ? ($byEan[$s['ean']] ?? null) : null);
    if ($hit) {
        $s['id'] = $hit[0];
        $s['state'] = ($prodActive[$hit[0]] ?? false) ? 'listat' : 'produs inactiv';
        continue;
    }
    // tăietura „…X" + S/L e validă tehnic (`…XS` = `…X` + S), dar codul de model real e cel fără X
    $cands = $modelOf[$code] ?? [];
    usort($cands, fn ($a, $b) => (str_ends_with($a, 'X') <=> str_ends_with($b, 'X')) ?: strlen($b) <=> strlen($a));
    foreach ($cands as $m) {
        if (isset($byModel[$m])) {
            $s['id'] = $byModel[$m];
            $s['model'] = $m;
            $s['state'] = ($prodActive[$s['id']] ?? false) ? 'lipsește mărimea (produs activ)' : 'lipsește mărimea (produs inactiv)';
            continue 2;
        }
    }
    $s['model'] = $modelOf[$code][0] ?? '';
    $s['state'] = 'lipsește complet';
}
unset($s);

// --- rezumat ---
$fmt = fn (float $v) => number_format($v, 0, ',', '.');
$sum = [];
foreach ($stock as $s) {
    $k = $s['group'] === 'exclus' ? 'exclus' : $s['group'] . ' · ' . $s['state'];
    $sum[$k] ??= ['n' => 0, 'buc' => 0, 'val' => 0.0];
    $sum[$k]['n']++;
    $sum[$k]['buc'] += $s['stoc'];
    $sum[$k]['val'] += $s['stoc'] * $s['pret'];
}
ksort($sum);
$lines = [];
$lines[] = 'Stoc fizic BikerShop vs. site · ' . date('Y-m-d H:i') . ' · ' . count($stock) . ' coduri cu stoc';
$lines[] = '';
$lines[] = str_pad('grupa · stare', 52) . str_pad('coduri', 8, ' ', STR_PAD_LEFT) . str_pad('buc', 8, ' ', STR_PAD_LEFT) . str_pad('valoare lei', 14, ' ', STR_PAD_LEFT);
foreach ($sum as $k => $v) {
    $lines[] = str_pad($k, 52) . str_pad((string) $v['n'], 8, ' ', STR_PAD_LEFT) . str_pad((string) $v['buc'], 8, ' ', STR_PAD_LEFT) . str_pad($fmt($v['val']), 14, ' ', STR_PAD_LEFT);
}
$byBrand = [];
foreach ($stock as $s) {
    if ($s['group'] === 'echipament' && $s['state'] !== 'listat') {
        $b = $s['brand'] ?: 'alt brand';
        $byBrand[$b] ??= ['n' => 0, 'val' => 0.0];
        $byBrand[$b]['n']++;
        $byBrand[$b]['val'] += $s['stoc'] * $s['pret'];
    }
}
uasort($byBrand, fn ($a, $b) => $b['val'] <=> $a['val']);
$lines[] = '';
$lines[] = 'Echipament nelistat, pe mărci:';
foreach ($byBrand as $b => $v) {
    $lines[] = '  ' . str_pad($b, 16) . str_pad((string) $v['n'], 6, ' ', STR_PAD_LEFT) . str_pad($fmt($v['val']), 12, ' ', STR_PAD_LEFT) . ' lei';
}

// --- noi față de rularea săptămânală precedentă (starea se salvează doar la rularea cu --mail) ---
$stateFile = __DIR__ . '/logs/stoc-nelistat-ultima.json';
$prev = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;
$unlisted = fn ($s) => $s['group'] !== 'exclus' && $s['state'] !== 'listat';
$newCodes = [];
if (is_array($prev)) {
    $was = array_flip($prev['codes'] ?? []);
    foreach ($stock as $s) {
        if ($s['group'] === $opt['group'] || $opt['group'] === null) {
            if ($unlisted($s) && !isset($was[$s['code']])) {
                $newCodes[] = $s['code'];
            }
        }
    }
    $lines[] = '';
    $lines[] = sprintf('Noi față de raportul din %s: %d coduri%s', $prev['date'] ?? '?', count($newCodes),
        $newCodes ? ' (coloana „Nou" din CSV)' : '');
}
if ($opt['mail']) {
    $codes = array_values(array_map(fn ($s) => (string) $s['code'], array_filter($stock, $unlisted)));
    file_put_contents($stateFile, json_encode(['date' => date('Y-m-d'), 'codes' => $codes]));
}
$isNew = array_flip($newCodes);

// --- CSV ---
$out = $opt['out'] ?? (__DIR__ . '/logs/stoc-nelistat-' . date('Y-m-d') . '.csv');
@mkdir(dirname($out), 0775, true);
$h = fopen($out, 'w');
fwrite($h, "\xEF\xBB\xBF");
fputcsv($h, ['Grupa', 'Marca', 'Cod gestiune', 'Denumire', 'Stoc', 'Pret', 'Valoare stoc', 'Cod bare', 'Ultima operatiune',
    'Furnizor gestiune', 'Stare pe site', 'Cod model', 'ID produs BikerShop', 'Produs BikerShop', 'Nou', 'Motiv excludere'], ';');
$rows = array_values(array_filter($stock, fn ($s) => $opt['all'] || ($unlisted($s) && ($opt['group'] === null || $s['group'] === $opt['group']))));
usort($rows, fn ($a, $b) => [$a['group'], $a['brand'] ?: 'zz', $b['stoc'] * $b['pret']] <=> [$b['group'], $b['brand'] ?: 'zz', $a['stoc'] * $a['pret']]);
foreach ($rows as $s) {
    fputcsv($h, [$s['group'], $s['brand'], $s['code'], $s['name'], $s['stoc'], number_format($s['pret'], 2, '.', ''),
        number_format($s['stoc'] * $s['pret'], 2, '.', ''), $s['ean'], $s['last'], $s['supplier'], $s['state'], $s['model'],
        $s['id'] ?: '', $s['id'] ? ($prodName[$s['id']] ?? '') : '', isset($isNew[$s['code']]) ? 'da' : '', $s['why']], ';');
}
fclose($h);
$lines[] = '';
$lines[] = ($web ? 'CSV: ' : 'CSV: ' . $out . ' ') . '(' . count($rows) . ' rânduri) · ' . round(microtime(true) - $t0, 1) . ' s';

if ($web) {
    ob_end_clean();
    $label = $opt['group'] ?? 'toate';
    if (!empty($_GET['csv'])) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="stoc-nelistat-' . str_replace(' ', '-', $label) . '-' . date('Y-m-d') . '.csv"');
        readfile($out);
        unlink($out);
        exit;
    }
    unlink($out);
    $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $q = isset($_GET['grupa']) ? '&grupa=' . rawurlencode((string) $_GET['grupa']) : '';
    header('Content-Type: text/html; charset=UTF-8');
    echo "<!doctype html><html lang='ro'><head><meta charset='utf-8'><meta name='robots' content='noindex'><title>În stoc, dar nu pe site</title>
<style>body{font:14px/1.45 -apple-system,Segoe UI,Arial,sans-serif;margin:24px;color:#222}pre{background:#f7f7f7;border:1px solid #ddd;padding:12px;overflow:auto}
.btn{display:inline-block;padding:10px 18px;background:#E10600;color:#fff;font-weight:bold;text-decoration:none;border-radius:6px}
nav a{margin-right:14px}table{border-collapse:collapse;font-size:12px;margin-top:16px}td,th{border:1px solid #ddd;padding:4px 6px;text-align:left}
th{background:#f2f2f2;position:sticky;top:0}td.n{text-align:right}</style></head><body>";
    echo "<h1>În stoc, dar nu pe site</h1><nav>Grupa: <a href='?'>echipament</a><a href='?grupa=alte'>alte produse</a><a href='?grupa=toate'>toate</a></nav>";
    echo '<pre>' . $e(implode("\n", $lines)) . '</pre>';
    echo "<p><a class='btn' href='?csv=1{$q}'>Descarcă CSV (" . $e($label) . ')</a></p>';
    echo '<table><tr><th>Marca</th><th>Cod</th><th>Denumire</th><th>Stoc</th><th>Preț</th><th>Ultima operațiune</th><th>Stare pe site</th><th>Produs BikerShop</th></tr>';
    foreach ($rows as $s) {
        echo '<tr><td>' . $e($s['brand']) . '</td><td>' . $e($s['code']) . '</td><td>' . $e($s['name']) . "</td><td class='n'>" . $s['stoc']
            . "</td><td class='n'>" . number_format($s['pret'], 0, ',', '.') . '</td><td>' . $e($s['last']) . '</td><td>' . $e($s['state']) . '</td><td>'
            . ($s['id'] ? '#' . $s['id'] . ' ' . $e($prodName[$s['id']] ?? '') : '') . '</td></tr>';
    }
    echo '</table></body></html>';
    exit;
}
echo implode("\n", $lines), "\n";

// --- email (pe server) ---
if ($opt['mail']) {
    $boundary = 'b' . bin2hex(random_bytes(8));
    $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode(implode("\n", $lines) . "\n\nCodurile care nu trebuie vândute online se trec în tool/stoc-nelistat-excluderi.txt (unul pe rând).\n"))
        . "--{$boundary}\r\nContent-Type: text/csv; charset=UTF-8; name=\"" . basename($out) . "\"\r\nContent-Transfer-Encoding: base64\r\n"
        . "Content-Disposition: attachment; filename=\"" . basename($out) . "\"\r\n\r\n"
        . chunk_split(base64_encode((string) file_get_contents($out))) . "--{$boundary}--\r\n";
    $headers = "From: BikerShop <comenzi@bikershop.ro>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"{$boundary}\"";
    $subject = '=?UTF-8?B?' . base64_encode('În stoc, dar nu pe site · ' . date('d.m.Y')) . '?=';
    foreach ($opt['mail'] as $to) {
        $ok = mail($to, $subject, $body, $headers);
        echo ($ok ? 'Trimis la ' : 'EȘEC la trimiterea către ') . $to, "\n";
    }
}
