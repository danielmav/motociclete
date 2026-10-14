<?php
declare(strict_types=1);

/**
 * NOS pe BikerShop = doar ce e pe stoc fizic (decizia Dual Tours, 2026-10-14).
 *
 * Produsele NOS (producătorul 143) au fost importate toate, dar pe site se vând doar cele cu stoc
 * în gestiune (`stocuri2024`, stoc > 0; potrivire pe referința produsului, a variantelor sau pe EAN).
 *
 *   - ACTIV fără stoc fizic  → dezactivat: active = 0, visibility = 'none', redirect 301 spre categorie
 *     (în `ps_product` ȘI `ps_product_shop`, toate shop-urile) + DELETE din `ps_product_supplier`,
 *     ca modulul supplierpricing să nu-l reactiveze.
 *   - INACTIV cu stoc fizic → activat; dacă nu are rândul furnizorului 1 („Stoc Bikershop"), îl
 *     creează cu „stoc|pret|pret" din gestiune (formatul sincronizării `globe/stoc-globe.php`) și
 *     pune produsul în `ps_supplierpricing_queue` (force_update) ca prețul și stocul să fie recalculate.
 *
 * Utilizare (pe serverul bikershop, din ~/public_html/tool/):
 *   /usr/local/bin/ea-php84 nos-doar-stoc.php --ps-root=/home2/bikershop/public_html            # dry-run
 *   /usr/local/bin/ea-php84 nos-doar-stoc.php --ps-root=/home2/bikershop/public_html --apply    # scrie
 * Opțiuni: --only-deactivate, --only-activate, --no-redis.
 * Log + rollback per rulare: logs/nos-doar-stoc-<ts>.log, logs/rollback-nos-doar-stoc-<ts>.sql.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

const MANUFACTURER_NOS = 143;
const SUPPLIER_STOC    = 1;   // „Stoc Bikershop", sincronizat orar din gestiune
const REDIRECT_TYPE    = '301-category';

$opt = ['apply' => false, 'ps_root' => null, 'redis' => true, 'deactivate' => true, 'activate' => true];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--apply') {
        $opt['apply'] = true;
    } elseif ($a === '--no-redis') {
        $opt['redis'] = false;
    } elseif ($a === '--only-deactivate') {
        $opt['activate'] = false;
    } elseif ($a === '--only-activate') {
        $opt['deactivate'] = false;
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

$logDir = __DIR__ . '/logs';
@mkdir($logDir, 0775, true);
$ts      = date('Ymd-His');
$logFile = $logDir . "/nos-doar-stoc-{$ts}.log";
$rbFile  = $logDir . "/rollback-nos-doar-stoc-{$ts}.sql";
$logH    = $opt['apply'] ? fopen($logFile, 'a') : null;

function logln(string $s = ''): void
{
    global $logH;
    echo $s, "\n";
    if ($logH) {
        fwrite($logH, $s . "\n");
    }
}

$rollback = [];
function rollback_flush(): void
{
    global $rollback, $rbFile, $opt;
    if ($opt['apply'] && $rollback) {
        file_put_contents($rbFile, implode("\n", $rollback) . "\n", FILE_APPEND);
    }
    $rollback = [];
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

logln(($opt['apply'] ? 'APPLY' : 'DRY-RUN') . ' · NOS doar cu stoc fizic · ' . date('Y-m-d H:i:s'));

// --- stoc fizic din gestiune ---
$stockByRef = [];
$stockByEan = [];
$priceByRef = [];
foreach ($pdo->query("SELECT codprodus, stoc, pret, cod_bare FROM stocuri2024 WHERE stoc > 0") as $r) {
    $ref = strtoupper(trim((string) $r['codprodus']));
    $stockByRef[$ref] = ($stockByRef[$ref] ?? 0) + (int) $r['stoc'];
    $priceByRef[$ref] = (float) $r['pret'];
    $ean = trim((string) $r['cod_bare']);
    if (preg_match('/^\d{8,14}$/', $ean)) {
        $stockByEan[$ean] = ['stoc' => (int) $r['stoc'], 'ref' => $ref];
    }
}

// --- produsele NOS + variantele lor ---
$products = [];
foreach ($pdo->query("SELECT p.id_product, p.reference, p.ean13, ps.active, pl.name
        FROM {$P}product p
        JOIN {$P}product_shop ps ON ps.id_product = p.id_product AND ps.id_shop = 1
        JOIN {$P}product_lang pl ON pl.id_product = p.id_product AND pl.id_lang = 1 AND pl.id_shop = 1
        WHERE p.id_manufacturer = " . MANUFACTURER_NOS) as $r) {
    $products[(int) $r['id_product']] = $r + ['units' => [['ipa' => 0, 'ref' => $r['reference'], 'ean' => $r['ean13']]]];
}
foreach ($pdo->query("SELECT pa.id_product, pa.id_product_attribute, pa.reference, pa.ean13
        FROM {$P}product_attribute pa JOIN {$P}product p ON p.id_product = pa.id_product
        WHERE p.id_manufacturer = " . MANUFACTURER_NOS) as $r) {
    $products[(int) $r['id_product']]['units'][] = ['ipa' => (int) $r['id_product_attribute'], 'ref' => $r['reference'], 'ean' => $r['ean13']];
}

/** Stocul fizic al unei unități (produs simplu sau variantă): referință exactă, apoi EAN. */
function physical(array $u): array
{
    global $stockByRef, $stockByEan, $priceByRef;
    $ref = strtoupper(trim((string) $u['ref']));
    if ($ref !== '' && isset($stockByRef[$ref])) {
        return [$stockByRef[$ref], $priceByRef[$ref] ?? 0.0];
    }
    $ean = trim((string) $u['ean']);
    if ($ean !== '' && isset($stockByEan[$ean])) {
        $g = $stockByEan[$ean];
        return [$g['stoc'], $priceByRef[$g['ref']] ?? 0.0];
    }
    return [0, 0.0];
}

$toDeactivate = [];
$toActivate = [];
foreach ($products as $id => $p) {
    $units = $p['units'];
    if (count($units) > 1) {
        array_shift($units); // produs cu variante: stocul e pe variante, nu pe nivelul de produs
    }
    $total = 0;
    $withStock = [];
    foreach ($units as $u) {
        [$q, $price] = physical($u);
        if ($q > 0) {
            $total += $q;
            $withStock[] = $u + ['q' => $q, 'price' => $price];
        }
    }
    if ((int) $p['active'] === 1 && $total === 0) {
        $toDeactivate[$id] = $p;
    } elseif ((int) $p['active'] === 0 && $total > 0) {
        $toActivate[$id] = $p + ['stock_units' => $withStock];
    }
}
logln(sprintf('Produse NOS: %d · de dezactivat (active fără stoc): %d · de activat (inactive cu stoc): %d',
    count($products), count($toDeactivate), count($toActivate)));

// --- Redis (doar pe server, cu PrestaShop pe disc) ---
$redis = false;
if ($opt['apply'] && $opt['redis'] && $opt['ps_root'] && is_file($opt['ps_root'] . '/config/config.inc.php')) {
    try {
        if (!defined('_PS_ADMIN_DIR_')) {
            define('_PS_ADMIN_DIR_', $opt['ps_root'] . '/__admin322y');
        }
        require $opt['ps_root'] . '/config/config.inc.php';
        $redis = Module::isEnabled('teamwant_redis');
        logln('PrestaShop ' . _PS_VERSION_ . ' încărcat; Redis ' . ($redis ? 'activ' : 'inactiv'));
    } catch (Throwable $e) {
        logln('Redis: PrestaShop nu s-a încărcat (' . $e->getMessage() . ')');
    }
}
function refresh_redis(int $id): void
{
    foreach ([1, 2] as $shop) {
        foreach ([null, 1, 2] as $lang) {
            try {
                $p = new Product($id, false, $lang, $shop);
                if (method_exists($p, 'overrideObjectCache')) {
                    $p->overrideObjectCache($lang);
                }
            } catch (Throwable $e) {
                logln("    redis #{$id}: " . $e->getMessage());
            }
        }
    }
}

$stProd   = $pdo->prepare("SELECT active, visibility, redirect_type, id_type_redirected FROM {$P}product WHERE id_product = :id");
$stShops  = $pdo->prepare("SELECT id_shop, active, visibility, redirect_type, id_type_redirected FROM {$P}product_shop WHERE id_product = :id");
$stSupp   = $pdo->prepare("SELECT * FROM {$P}product_supplier WHERE id_product = :id");
$snapshot = function (int $id) use ($pdo, $P, $stProd, $stShops, $stSupp, &$rollback): void {
    $stProd->execute([':id' => $id]);
    if ($c = $stProd->fetch()) {
        $rollback[] = sprintf("UPDATE {$P}product SET active = %d, visibility = %s, redirect_type = %s, id_type_redirected = %d WHERE id_product = %d;",
            (int) $c['active'], $pdo->quote($c['visibility']), $pdo->quote((string) $c['redirect_type']), (int) $c['id_type_redirected'], $id);
    }
    $stShops->execute([':id' => $id]);
    foreach ($stShops->fetchAll() as $s) {
        $rollback[] = sprintf("UPDATE {$P}product_shop SET active = %d, visibility = %s, redirect_type = %s, id_type_redirected = %d WHERE id_product = %d AND id_shop = %d;",
            (int) $s['active'], $pdo->quote($s['visibility']), $pdo->quote((string) $s['redirect_type']), (int) $s['id_type_redirected'], $id, (int) $s['id_shop']);
    }
    $rollback[] = "DELETE FROM {$P}product_supplier WHERE id_product = {$id};";
    $stSupp->execute([':id' => $id]);
    foreach ($stSupp->fetchAll() as $s) {
        $cols = array_map(fn ($c) => "`{$c}`", array_keys($s));
        $vals = array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($s));
        $rollback[] = sprintf("INSERT INTO {$P}product_supplier (%s) VALUES (%s);", implode(', ', $cols), implode(', ', $vals));
    }
};

$errors = 0;

// --- 1. dezactivare ---
if ($opt['deactivate']) {
    logln();
    logln('== Dezactivare: NOS active fără stoc fizic ==');
    foreach ($toDeactivate as $id => $p) {
        $line = sprintf('#%-7d %-12s %s', $id, $p['reference'], mb_strimwidth((string) $p['name'], 0, 60, '…'));
        if (!$opt['apply']) {
            logln('  ar dezactiva ' . $line);
            continue;
        }
        try {
            $pdo->beginTransaction();
            $snapshot($id);
            $pdo->exec("UPDATE {$P}product SET active = 0, visibility = 'none', redirect_type = '" . REDIRECT_TYPE . "', id_type_redirected = 0, date_upd = NOW() WHERE id_product = {$id}");
            $pdo->exec("UPDATE {$P}product_shop SET active = 0, visibility = 'none', redirect_type = '" . REDIRECT_TYPE . "', id_type_redirected = 0, date_upd = NOW() WHERE id_product = {$id}");
            $del = $pdo->exec("DELETE FROM {$P}product_supplier WHERE id_product = {$id}");
            $pdo->commit();
            rollback_flush();
            logln("  DEZACTIVAT {$line} (furnizori șterși: {$del})");
            if ($redis) {
                refresh_redis($id);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $rollback = [];
            $errors++;
            logln("  EROARE #{$id}: " . $e->getMessage());
        }
    }
}

// --- 2. activare ---
if ($opt['activate']) {
    logln();
    logln('== Activare: NOS inactive cu stoc fizic ==');
    $hasSupp = $pdo->prepare("SELECT product_supplier_reference FROM {$P}product_supplier WHERE id_product = :id AND id_product_attribute = :ipa AND id_supplier = " . SUPPLIER_STOC);
    $insSupp = $pdo->prepare("INSERT INTO {$P}product_supplier (id_product, id_product_attribute, id_supplier, product_supplier_reference, product_supplier_price_te, id_currency)
        VALUES (:id, :ipa, " . SUPPLIER_STOC . ", :ref, 0, 1)");
    foreach ($toActivate as $id => $p) {
        $plan = [];
        foreach ($p['stock_units'] as $u) {
            $hasSupp->execute([':id' => $id, ':ipa' => $u['ipa']]);
            $cur = $hasSupp->fetchColumn();
            $price = rtrim(rtrim(number_format((float) $u['price'], 2, '.', ''), '0'), '.');
            $plan[] = ['ipa' => $u['ipa'], 'ref' => "{$u['q']}|{$price}|{$price}", 'exists' => $cur !== false, 'cur' => $cur];
        }
        $desc = implode(', ', array_map(fn ($x) => ($x['ipa'] ? "varianta {$x['ipa']}" : 'produs') . ': ' . ($x['exists'] ? "furnizor 1 există ({$x['cur']})" : "furnizor 1 nou „{$x['ref']}”"), $plan));
        $line = sprintf('#%-7d %-12s %s — %s', $id, $p['reference'], mb_strimwidth((string) $p['name'], 0, 50, '…'), $desc);
        if (!$opt['apply']) {
            logln('  ar activa ' . $line);
            continue;
        }
        try {
            $pdo->beginTransaction();
            $snapshot($id);
            foreach ($plan as $x) {
                if (!$x['exists']) {
                    $insSupp->execute([':id' => $id, ':ipa' => $x['ipa'], ':ref' => $x['ref']]);
                }
            }
            $pdo->exec("UPDATE {$P}product SET active = 1, visibility = 'both', date_upd = NOW() WHERE id_product = {$id}");
            $pdo->exec("UPDATE {$P}product_shop SET active = 1, visibility = 'both', date_upd = NOW() WHERE id_product = {$id}");
            $pdo->exec("INSERT INTO {$P}supplierpricing_queue (id_product, last_update, in_progress, force_update) VALUES ({$id}, NOW(), 0, 1)
                ON DUPLICATE KEY UPDATE force_update = 1, in_progress = 0, last_update = NOW()");
            $pdo->commit();
            rollback_flush();
            logln('  ACTIVAT ' . $line . ' · pus în coada supplierpricing');
            if ($redis) {
                refresh_redis($id);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $rollback = [];
            $errors++;
            logln("  EROARE #{$id}: " . $e->getMessage());
        }
    }
}

logln();
logln(($opt['apply'] ? 'GATA' : 'DRY-RUN') . ": {$errors} erori." . ($opt['apply'] ? " Log: {$logFile} · rollback: {$rbFile}" : ' Rulează cu --apply ca să scrie.'));
exit($errors ? 1 : 0);
