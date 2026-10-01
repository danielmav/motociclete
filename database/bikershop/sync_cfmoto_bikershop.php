#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Sincronizează motocicletele CFMOTO de pe BikerShop (PrestaShop 9) cu portalul
 * motociclete.com.ro. Portalul = sursa de adevăr (lanț: feed ATVROM → portal, unde Daniel
 * verifică și activează → BikerShop). Legătura = `ps_product.reference` = `products.sku`.
 *
 * RULEAZĂ PE SERVERUL BIKERSHOP (are nevoie de PrestaShop pe disc: clase + img/p/):
 *   /usr/local/bin/ea-php84 /home2/bikershop/public_html/tool/sync_cfmoto_bikershop.php [opțiuni]
 *
 * Opțiuni:
 *   --apply              scrie efectiv (implicit = dry-run, doar raportează)
 *   --only=SKU           un singur produs (ex. cfmoto-450mt-2026)
 *   --no-create          nu creează produse noi
 *   --no-deactivate      nu dezactivează nimic
 *   --force              trece peste plasa de siguranță (dezactivare > jumătate din produse)
 *   --new-inactive       creează produsele noi INACTIVE (test/verificare în BO; rularea normală
 *                        următoare le activează, fiind active pe portal)
 *   --url=URL            exportul portalului (implicit https://www.motociclete.com.ro/api/export/cfmoto)
 *   --token-file=PATH    fișierul cu EXPORT_TOKEN (implicit ~/.cfmoto_export_token, în afara docroot)
 *   --ps-root=PATH       rădăcina PrestaShop (implicit /home2/bikershop/public_html)
 *
 * Ce face, pe produsele BikerShop cu producătorul CFMoto și referința `cfmoto-*`:
 *   1. PREȚ  — produs ACTIV pe portal + existent pe BikerShop: rescrie câmpurile special|rrp din
 *              `ps_product_supplier.product_supplier_reference` (furnizorul 11, „stoc|special|rrp",
 *              stocul rămâne) = lei cu TVA, exact ca pe portal (EUR × curs BRD); ps_product(.shop)
 *              .price = rrp/1,21 pentru efect imediat + coadă supplierpricing (force_update=1).
 *              Lipsă rând de furnizor → îl creează (stoc DEFAULT_STOCK). Reactivează dacă era inactiv.
 *   2. NOU   — produs ACTIV pe portal fără corespondent pe BikerShop: îl creează ACTIV (nume „… 2026",
 *              descriere, categoriile 810+818+subcategoria, furnizor 11, caracteristici din specificații,
 *              imagini, câte o combinație per culoare: referință `<sku>-<culoare>`, imaginea culorii).
 *   3. VECHI — produs ACTIV pe BikerShop al cărui cod e INACTIV pe portal sau lipsește de acolo:
 *              active=0, visibility='none', redirect 301-category (ps_product ȘI ps_product_shop) +
 *              DELETE din ps_product_supplier (altfel supplierpricing îl reactivează).
 * Fiecare rulare --apply scrie log + rollback SQL în tool/logs/ (cfmoto-sync-*). Produsele create
 * nu au rollback SQL — se șterg din BO dacă e cazul.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// ---------------------------------------------------------------------------
// Argumente
// ---------------------------------------------------------------------------
$opt = [
    'apply'      => false,
    'only'       => null,
    'create'     => true,
    'deactivate' => true,
    'force'      => false,
    'new_inactive' => false,
    'url'        => 'https://www.motociclete.com.ro/api/export/cfmoto',
    'token_file' => (getenv('HOME') ?: '/home2/bikershop') . '/.cfmoto_export_token',
    'ps_root'    => '/home2/bikershop/public_html',
];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--apply') {
        $opt['apply'] = true;
    } elseif ($a === '--no-create') {
        $opt['create'] = false;
    } elseif ($a === '--no-deactivate') {
        $opt['deactivate'] = false;
    } elseif ($a === '--force') {
        $opt['force'] = true;
    } elseif ($a === '--new-inactive') {
        $opt['new_inactive'] = true;
    } elseif (str_starts_with($a, '--only=')) {
        $opt['only'] = strtolower(trim(substr($a, 7)));
    } elseif (str_starts_with($a, '--url=')) {
        $opt['url'] = substr($a, 6);
    } elseif (str_starts_with($a, '--token-file=')) {
        $opt['token_file'] = substr($a, 13);
    } elseif (str_starts_with($a, '--ps-root=')) {
        $opt['ps_root'] = rtrim(substr($a, 10), '/');
    } else {
        fwrite(STDERR, "Opțiune necunoscută: {$a}\n");
        exit(2);
    }
}

// ---------------------------------------------------------------------------
// Constante de domeniu (vezi produsele existente, ex. #356282 CFMOTO 450MT 2026)
// ---------------------------------------------------------------------------
const ID_SHOP         = 1;
const LANGS           = [1, 2];
const MANUFACTURER    = 498;   // CFMoto
const SUPPLIER        = 11;    // „Import manual Motociclete"
const TAX_RULES_GROUP = 1;
const ATTR_GROUP_COLOR = 19;   // „Culoare"
const CAT_ROOT        = 810;   // Motociclete, Scutere, ATV-uri
const CAT_MOTO        = 818;   // Motociclete (implicită)
const CAT_BY_PORTAL   = ['sport' => 811, 'naked' => 812, 'heritage' => 813, 'touring-travel' => 816];
const DEFAULT_STOCK   = 30;
const VAT_RATE        = 1.21;  // = SUPPLIERPRICING_VAT_RATE (cronul face price = rrp / 1.21)
const REDIRECT_TYPE   = '301-category';
/** Etichetă specificație portal → id caracteristică BikerShop (ps_feature). */
const FEATURE_MAP = [
    'Motor' => 68, 'Capacitate' => 69, 'Putere' => 72, 'Cutie de viteze' => 79, 'Transmisie' => 79,
    'Frână față' => 90, 'Frână spate' => 91, 'Anvelopă față' => 92, 'Anvelopă spate' => 93,
    'Lungime' => 94, 'Lățime' => 95, 'Înălțime' => 96, 'Înălțime șa' => 97, 'Ampatament' => 98,
    'Gardă la sol' => 99, 'Greutate' => 100, 'Rezervor' => 101,
];

// ---------------------------------------------------------------------------
// Log + lock + rollback
// ---------------------------------------------------------------------------
$baseDir = __DIR__;
$logDir  = $baseDir . '/logs';
$tmpDir  = $baseDir . '/tmp';
@mkdir($logDir, 0775, true);
@mkdir($tmpDir, 0775, true);

$ts       = date('Ymd-His');
$logFile  = $logDir . "/cfmoto-sync-{$ts}.log";
$rbFile   = $logDir . "/rollback-cfmoto-sync-{$ts}.sql";
$lockFile = $tmpDir . '/cfmoto-sync.lock';

$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "O altă rulare e în curs (lock {$lockFile}).\n");
    exit(1);
}

$logHandle = $opt['apply'] ? fopen($logFile, 'a') : null;
function logln(string $line = ''): void
{
    global $logHandle;
    echo $line . "\n";
    if ($logHandle) {
        fwrite($logHandle, date('H:i:s') . ' ' . $line . "\n");
    }
}

$rollback = [];
function rollback_flush(): void
{
    global $rollback, $rbFile, $opt;
    if (!$opt['apply'] || !$rollback) {
        return;
    }
    if (!is_file($rbFile)) {
        file_put_contents($rbFile, "-- Rollback sync_cfmoto_bikershop din " . date('c') . "\n-- (produsele CREATE nu sunt incluse — se șterg din BO)\n");
    }
    file_put_contents($rbFile, implode("\n", $rollback) . "\n", FILE_APPEND);
    $rollback = [];
}

// ---------------------------------------------------------------------------
// Exportul portalului
// ---------------------------------------------------------------------------
function http_get(string $url, int $timeout = 60, array $headers = []): ?string
{
    for ($try = 1; $try <= 3; $try++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => array_merge(['User-Agent: Mozilla/5.0 (compatible; BikerShop cfmoto sync)'], $headers),
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body !== false && $code === 200) {
            return (string) $body;
        }
        if ($try < 3) {
            sleep(2 * $try);
        }
    }
    return null;
}

$token = is_file($opt['token_file']) ? trim((string) file_get_contents($opt['token_file'])) : '';
if ($token === '') {
    fwrite(STDERR, "Lipsește tokenul de export ({$opt['token_file']}).\n");
    exit(1);
}
$json = http_get($opt['url'], 60, ['X-Export-Token: ' . $token]);
$export = $json !== null ? json_decode($json, true) : null;
if (!is_array($export) || !isset($export['products']) || !is_array($export['products'])) {
    fwrite(STDERR, "Exportul portalului nu a putut fi citit ({$opt['url']}).\n");
    exit(1);
}
$portal = [];
foreach ($export['products'] as $p) {
    $sku = strtolower(trim((string) ($p['sku'] ?? '')));
    if ($sku !== '' && !isset($portal[$sku])) {
        $portal[$sku] = $p;
    }
}
$portalActive = array_filter($portal, static fn ($p) => !empty($p['active']));
// Plasă: un export trunchiat/gol nu are voie să dezactiveze tot catalogul.
if (count($portalActive) < 5) {
    fwrite(STDERR, 'Exportul are doar ' . count($portalActive) . " produse active — opresc.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Bootstrap PrestaShop
// ---------------------------------------------------------------------------
$root = $opt['ps_root'];
if (!is_file($root . '/config/config.inc.php')) {
    fwrite(STDERR, "PrestaShop nu e la {$root} (lipsește config/config.inc.php).\n");
    exit(1);
}
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
if (!defined('_PS_VERSION_')) {
    fwrite(STDERR, "PrestaShop nu s-a încărcat.\n");
    exit(1);
}
@ini_set('memory_limit', '1024M');
set_time_limit(0);
Shop::setContext(Shop::CONTEXT_SHOP, ID_SHOP);
Context::getContext()->shop = new Shop(ID_SHOP);

$db     = Db::getInstance();
$prefix = _DB_PREFIX_;

// Mișcările de stoc (StockAvailable::setQuantity → StockMvt) cer un angajat în context; din CLI
// nu există → primul SuperAdmin activ.
$idEmployee = (int) $db->getValue("SELECT id_employee FROM {$prefix}employee WHERE active = 1 AND id_profile = 1 ORDER BY id_employee");
if ($idEmployee) {
    Context::getContext()->employee = new Employee($idEmployee);
}

logln(sprintf('sync_cfmoto_bikershop %s — PrestaShop %s, PHP %s, export %s (%d produse, %d active, curs %s)',
    $opt['apply'] ? 'APPLY' : 'DRY-RUN', _PS_VERSION_, PHP_VERSION, (string) ($export['generated_at'] ?? '?'),
    count($portal), count($portalActive), (string) ($export['rate'] ?? '?')));
logln(str_repeat('─', 100));

// ---------------------------------------------------------------------------
// Produsele CFMOTO de pe BikerShop
// ---------------------------------------------------------------------------
$rows = $db->executeS("
    SELECT p.id_product, p.reference, p.price, p.active, p.visibility, p.redirect_type, p.id_type_redirected,
           ps.price AS shop_price, pl.name
    FROM {$prefix}product p
    LEFT JOIN {$prefix}product_shop ps ON ps.id_product = p.id_product AND ps.id_shop = " . ID_SHOP . "
    LEFT JOIN {$prefix}product_lang pl ON pl.id_product = p.id_product AND pl.id_lang = 1 AND pl.id_shop = " . ID_SHOP . "
    WHERE p.id_manufacturer = " . MANUFACTURER . " AND (p.reference LIKE 'cfmoto-%' OR p.reference LIKE 'cflite-%')
    ORDER BY p.id_product") ?: [];
$bs = [];
foreach ($rows as $r) {
    $bs[strtolower(trim((string) $r['reference']))] = $r;
}
logln(sprintf('BikerShop: %d motociclete CFMOTO (%d active)', count($bs), count(array_filter($bs, static fn ($r) => (int) $r['active'] === 1))));

$redis = null;
try {
    $redis = Module::isEnabled('teamwant_redis');
} catch (Throwable) {
    $redis = null;
}

/** Rescrie în Redis obiectul Product (toate shop × limbă) — vezi dezactivare-produse-vechi.php. */
function refresh_redis_product(int $id): void
{
    global $redis;
    if (!$redis) {
        return;
    }
    foreach ([1, 2] as $shop) {
        foreach ([null, 1, 2] as $lang) {
            try {
                $p = new Product($id, false, $lang, $shop);
                if (method_exists($p, 'overrideObjectCache')) {
                    $p->overrideObjectCache($lang);
                }
            } catch (Throwable $e) {
                logln("    redis #{$id} ({$shop}/{$lang}): " . $e->getMessage());
            }
        }
    }
}

function q(string $s): string
{
    return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $s) . "'";
}

function queue_pricing(int $id): void
{
    global $db, $prefix;
    $db->execute("INSERT INTO {$prefix}supplierpricing_queue (id_product, last_update, in_progress, force_update) VALUES ({$id}, NOW(), 0, 1)
                  ON DUPLICATE KEY UPDATE force_update = 1, last_update = NOW()");
}

/** „stoc|special|rrp" (sau 5 câmpuri) cu noile prețuri; stocul rămâne. Null dacă formatul e necunoscut. */
function rewrite_ref(string $current, int $special, int $rrp): ?string
{
    $parts = explode('|', $current);
    if (count($parts) !== 3 && count($parts) !== 5) {
        return null;
    }
    $parts[1] = (string) $special;
    $parts[2] = (string) $rrp;
    return implode('|', $parts);
}

/** Numele pe BikerShop: „CFMOTO 450MT - 2026" → „CFMOTO 450MT 2026" (anul adăugat dacă lipsește). */
function bs_name(array $p): string
{
    $name = trim((string) preg_replace('/\s+-\s+(20\d{2})\s*$/', ' $1', (string) $p['name']));
    $year = !empty($p['year']) ? (int) $p['year']
        : (preg_match('/-(20\d{2})$/', (string) $p['sku'], $m) ? (int) $m[1] : 0);  // anul din cod
    if ($year && !preg_match('/\b20\d{2}\s*$/', $name)) {
        $name .= ' ' . $year;
    }
    return $name;
}

$counts = ['price' => 0, 'same' => 0, 'reactivated' => 0, 'created' => 0, 'deactivated' => 0, 'errors' => 0];

// ---------------------------------------------------------------------------
// 1. Prețuri (+ reactivare) pentru produsele active pe portal care există pe BikerShop
// ---------------------------------------------------------------------------
foreach ($portalActive as $sku => $p) {
    if ($opt['only'] && $opt['only'] !== $sku) {
        continue;
    }
    $b = $bs[$sku] ?? null;
    if ($b === null) {
        continue;
    }
    $id = (int) $b['id_product'];
    $special = (int) $p['special_ron'];
    $rrp = max((int) $p['rrp_ron'], $special); // special > rrp invalidează produsul în supplierpricing
    $notes = [];
    try {
        $sup = $db->executeS("SELECT id_product_supplier, id_product_attribute, product_supplier_reference
                              FROM {$prefix}product_supplier WHERE id_product = {$id} AND id_supplier = " . SUPPLIER) ?: [];
        $changes = [];
        foreach ($sup as $s) {
            $cur = (string) $s['product_supplier_reference'];
            if ($cur === '') {
                continue; // rândul gol de la nivel de produs al produselor cu combinații
            }
            $new = rewrite_ref($cur, $special, $rrp);
            if ($new === null) {
                $notes[] = "format necunoscut „{$cur}”";
            } elseif ($new !== $cur) {
                $changes[] = [$s, $new];
            }
        }
        $missing = !array_filter($sup, static fn ($s) => (string) $s['product_supplier_reference'] !== '');
        $inactive = (int) $b['active'] !== 1;

        if (!$changes && !$missing && !$inactive) {
            $counts['same']++;
            continue;
        }
        $line = sprintf('%s #%-7d %-28s %-36s', $opt['apply'] ? '€' : '·', $id, $sku, mb_substr((string) $b['name'], 0, 36));
        if ($changes) {
            $line .= sprintf(' preț: %s → %d|%d lei', implode(', ', array_unique(array_map(static fn ($c) => implode('|', array_slice(explode('|', (string) $c[0]['product_supplier_reference']), 1, 2)), $changes))), $special, $rrp);
        }
        if ($missing) {
            $line .= ' + rând furnizor (stoc ' . DEFAULT_STOCK . ')';
        }
        if ($inactive) {
            $line .= ' + REACTIVARE';
        }

        if ($opt['apply']) {
            foreach ($changes as [$s, $new]) {
                $db->execute("UPDATE {$prefix}product_supplier SET product_supplier_reference = " . q($new) . " WHERE id_product_supplier = " . (int) $s['id_product_supplier']);
                $rollback[] = "UPDATE {$prefix}product_supplier SET product_supplier_reference = " . q((string) $s['product_supplier_reference']) . " WHERE id_product_supplier = " . (int) $s['id_product_supplier'] . ";";
            }
            if ($missing) {
                $combos = array_map('intval', array_column($db->executeS("SELECT id_product_attribute FROM {$prefix}product_attribute WHERE id_product = {$id}") ?: [], 'id_product_attribute'));
                foreach ($combos ?: [0] as $ipa) {
                    $db->execute("DELETE FROM {$prefix}product_supplier WHERE id_product = {$id} AND id_product_attribute = {$ipa} AND id_supplier = " . SUPPLIER);
                    $db->execute("INSERT INTO {$prefix}product_supplier (id_product, id_product_attribute, id_supplier, product_supplier_reference, product_supplier_price_te, id_currency)
                                  VALUES ({$id}, {$ipa}, " . SUPPLIER . ", " . q(DEFAULT_STOCK . "|{$special}|{$rrp}") . ", 0, 1)");
                }
                $rollback[] = "DELETE FROM {$prefix}product_supplier WHERE id_product = {$id} AND id_supplier = " . SUPPLIER . ";";
            }
            $net = round($rrp / VAT_RATE, 6);
            $db->execute("UPDATE {$prefix}product SET price = {$net}, date_upd = NOW() WHERE id_product = {$id}");
            $db->execute("UPDATE {$prefix}product_shop SET price = {$net}, date_upd = NOW() WHERE id_product = {$id}");
            $rollback[] = "UPDATE {$prefix}product SET price = " . (float) $b['price'] . " WHERE id_product = {$id};";
            $rollback[] = "UPDATE {$prefix}product_shop SET price = " . (float) $b['shop_price'] . " WHERE id_product = {$id} AND id_shop = " . ID_SHOP . ";";
            if ($inactive) {
                $shops = $db->executeS("SELECT id_shop, active, visibility, redirect_type, id_type_redirected FROM {$prefix}product_shop WHERE id_product = {$id}") ?: [];
                $db->execute("UPDATE {$prefix}product SET active = 1, visibility = 'both', redirect_type = 'default', id_type_redirected = 0 WHERE id_product = {$id}");
                $db->execute("UPDATE {$prefix}product_shop SET active = 1, visibility = 'both', redirect_type = 'default', id_type_redirected = 0 WHERE id_product = {$id}");
                $rollback[] = sprintf("UPDATE {$prefix}product SET active = %d, visibility = %s, redirect_type = %s, id_type_redirected = %d WHERE id_product = %d;",
                    (int) $b['active'], q((string) $b['visibility']), q((string) $b['redirect_type']), (int) $b['id_type_redirected'], $id);
                foreach ($shops as $s) {
                    $rollback[] = sprintf("UPDATE {$prefix}product_shop SET active = %d, visibility = %s, redirect_type = %s, id_type_redirected = %d WHERE id_product = %d AND id_shop = %d;",
                        (int) $s['active'], q((string) $s['visibility']), q((string) $s['redirect_type']), (int) $s['id_type_redirected'], $id, (int) $s['id_shop']);
                }
            }
            queue_pricing($id);
            rollback_flush();
            refresh_redis_product($id);
        }
        $counts['price'] += $changes || $missing ? 1 : 0;
        $counts['reactivated'] += $inactive ? 1 : 0;
        logln($line . ($notes ? '  ⚠ ' . implode('; ', $notes) : ''));
    } catch (Throwable $e) {
        $counts['errors']++;
        logln("! #{$id} {$sku}: EROARE " . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 2. Produse noi (active pe portal, lipsă pe BikerShop)
// ---------------------------------------------------------------------------
$imageTypes = ImageType::getImagesTypes('products');
$formats    = ['jpg'];
$fmtCfg     = (string) Configuration::get('PS_IMAGE_FORMAT');
if ($fmtCfg !== '') {
    $decoded = json_decode($fmtCfg, true);
    foreach ((is_array($decoded) ? $decoded : explode(',', $fmtCfg)) as $f) {
        $f = strtolower(trim((string) $f));
        if (in_array($f, ['jpg', 'png', 'webp', 'avif'], true) && !in_array($f, $formats, true)) {
            $formats[] = $f;
        }
    }
}

/**
 * Redimensionare locală cu GD (copie din enrich_yamaha_accessories.php): ImageManager::resize
 * declanșează `actionOnImageResizeAfter`, pe care teamwant_redis îl tratează cu o curățare oarbă (~4 s/apel).
 */
function local_resize(string $src, string $dst, ?int $dstW, ?int $dstH, string $fmt): bool
{
    static $cache = [];
    if (!isset($cache[$src])) {
        $cache = [];
        $info = @getimagesize($src);
        if (!$info) {
            return false;
        }
        $im = match ($info[2]) {
            IMAGETYPE_PNG  => @imagecreatefrompng($src),
            IMAGETYPE_WEBP => @imagecreatefromwebp($src),
            default        => @imagecreatefromjpeg($src),
        };
        if (!$im) {
            return false;
        }
        $cache[$src] = ['im' => $im, 'w' => (int) $info[0], 'h' => (int) $info[1]];
    }
    ['im' => $srcIm, 'w' => $srcW, 'h' => $srcH] = $cache[$src];
    $dstW = $dstW ?: $srcW;
    $dstH = $dstH ?: $srcH;
    $method = (int) Configuration::get('PS_IMAGE_GENERATION_METHOD');
    $wDiff = $dstW / $srcW;
    $hDiff = $dstH / $srcH;
    if ($wDiff > 1 && $hDiff > 1) {
        $nextW = $srcW;
        $nextH = $srcH;
    } elseif ($method === 2 || ($method === 0 && $wDiff > $hDiff)) {
        $nextH = $dstH;
        $nextW = (int) (($srcW * $nextH) / $srcH);
        $dstW  = $method === 0 ? $dstW : $nextW;
    } else {
        $nextW = $dstW;
        $nextH = (int) ($srcH * $dstW / $srcW);
        $dstH  = $method === 0 ? $dstH : $nextH;
    }
    $out = imagecreatetruecolor($dstW, $dstH);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $srcIm, (int) (($dstW - $nextW) / 2), (int) (($dstH - $nextH) / 2), 0, 0, $nextW, $nextH, $srcW, $srcH);
    $ok = match ($fmt) {
        'png'  => imagepng($out, $dst, (int) (Configuration::get('PS_PNG_QUALITY') ?: 7)),
        'webp' => imagewebp($out, $dst, (int) (Configuration::get('PS_WEBP_QUALITY') ?: 80)),
        'avif' => function_exists('imageavif') ? imageavif($out, $dst, (int) (Configuration::get('PS_AVIF_QUALITY') ?: 90)) : false,
        default => imagejpeg($out, $dst, (int) (Configuration::get('PS_JPEG_QUALITY') ?: 90)),
    };
    imagedestroy($out);
    if ($ok) {
        @chmod($dst, 0664);
    }
    return (bool) $ok;
}

/** Adaugă o imagine produsului (API PrestaShop + resize local). Întoarce id_image sau null. */
function add_product_image(int $idProduct, string $url, bool $cover, string $legend, ?string &$err): ?int
{
    global $imageTypes, $formats, $tmpDir;
    $err = null;
    $bin = http_get($url, 60);
    if ($bin === null || strlen($bin) < 1000) {
        $err = 'download eșuat';
        return null;
    }
    $tmp = $tmpDir . '/' . md5($url) . '.img';
    file_put_contents($tmp, $bin);
    $info = @getimagesize($tmp);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] < 100) {
        @unlink($tmp);
        $err = 'fișier invalid';
        return null;
    }
    $image = new Image();
    $image->id_product = $idProduct;
    $image->position   = (int) Image::getHighestPosition($idProduct) + 1;
    $image->cover      = $cover;
    $image->legend     = array_fill_keys(LANGS, mb_substr($legend, 0, 128));
    try {
        if (!$image->add()) {
            throw new RuntimeException('Image::add');
        }
        $image->associateTo([ID_SHOP], $idProduct);
        $path = $image->getPathForCreation();
        if (!$path || !local_resize($tmp, $path . '.jpg', null, null, 'jpg')) {
            throw new RuntimeException('resize original');
        }
        foreach ($imageTypes as $t) {
            foreach ($formats as $fmt) {
                if (!local_resize($tmp, sprintf('%s-%s.%s', $path, stripslashes($t['name']), $fmt), (int) $t['width'], (int) $t['height'], $fmt)) {
                    throw new RuntimeException('resize ' . $t['name'] . '.' . $fmt);
                }
            }
        }
        Hook::exec('actionWatermark', ['id_image' => (int) $image->id, 'id_product' => $idProduct]);
    } catch (Throwable $e) {
        $err = $e->getMessage();
        try {
            $image->delete();
        } catch (Throwable) {
        }
        @unlink($tmp);
        return null;
    }
    @unlink($tmp);
    return (int) $image->id;
}

/** Hex aproximativ din numele culorii (ultimul cuvânt recunoscut câștigă: „Nebula Black" → negru). */
function color_hex(string $name): string
{
    $map = [
        'black' => '#000000', 'negru' => '#000000', 'white' => '#ffffff', 'alb' => '#ffffff', 'ivory' => '#fffff0',
        'grey' => '#808080', 'gray' => '#808080', 'gri' => '#808080', 'silver' => '#c0c0c0', 'titanium' => '#8a8d8f',
        'blue' => '#1f5fbf', 'albastru' => '#1f5fbf', 'teal' => '#008080', 'cyan' => '#00bcd4',
        'green' => '#2e8b57', 'verde' => '#2e8b57', 'lime' => '#9acd32', 'red' => '#e10600', 'rosu' => '#e10600',
        'bordeaux' => '#800020', 'orange' => '#ff7f00', 'portocaliu' => '#ff7f00', 'yellow' => '#ffd700', 'galben' => '#ffd700',
    ];
    $hex = '#cccccc';
    foreach (preg_split('/[^a-z]+/', strtolower(Tools::replaceAccentedChars($name))) ?: [] as $w) {
        if (isset($map[$w])) {
            $hex = $map[$w];
        }
    }
    return $hex;
}

/** Id-ul atributului de culoare (grupul „Culoare"), creat dacă nu există. */
function color_attribute(string $name, bool $apply): ?int
{
    global $db, $prefix;
    $id = (int) $db->getValue("SELECT a.id_attribute FROM {$prefix}attribute a
        JOIN {$prefix}attribute_lang al ON al.id_attribute = a.id_attribute AND al.id_lang = 1
        WHERE a.id_attribute_group = " . ATTR_GROUP_COLOR . " AND al.name = " . q($name));
    if ($id || !$apply) {
        return $id ?: null;
    }
    $cls = class_exists('ProductAttribute') ? 'ProductAttribute' : 'Attribute';
    $a = new $cls();
    $a->id_attribute_group = ATTR_GROUP_COLOR;
    $a->name = array_fill_keys(LANGS, mb_substr($name, 0, 128));
    $a->color = color_hex($name); // grupul e de tip „color" → fără hex, frontul arată un pătrat gol
    $a->add();
    return (int) $a->id ?: null;
}

/** Culorile modelului: cele cu imagine de pe portal, altfel specificația „Culori" (fără imagine). */
function colors_of(array $p): array
{
    $out = [];
    foreach ((array) ($p['colors'] ?? []) as $c) {
        $name = trim((string) ($c['caption'] ?? ''));
        if ($name !== '' && !isset($out[mb_strtolower($name)])) {
            $out[mb_strtolower($name)] = ['name' => $name, 'url' => (string) $c['url']];
        }
    }
    if (!$out) {
        foreach ((array) ($p['specs'] ?? []) as $s) {
            if (($s['label'] ?? '') === 'Culori') {
                foreach (preg_split('/\s*[,\/]\s*/', (string) $s['value']) ?: [] as $name) {
                    $name = trim($name);
                    if ($name !== '' && !isset($out[mb_strtolower($name)])) {
                        $out[mb_strtolower($name)] = ['name' => $name, 'url' => null];
                    }
                }
            }
        }
    }
    return array_values($out);
}

function slug(string $s): string
{
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(Tools::replaceAccentedChars($s))), '-');
}

foreach ($portalActive as $sku => $p) {
    if (($opt['only'] && $opt['only'] !== $sku) || isset($bs[$sku])) {
        continue;
    }
    $name    = bs_name($p);
    $special = (int) $p['special_ron'];
    $rrp     = max((int) $p['rrp_ron'], $special);
    $colors  = colors_of($p);
    $cat     = CAT_BY_PORTAL[$p['category']] ?? null;
    $imgUrls = array_values(array_unique(array_filter(array_merge(
        [$p['cover'] ?? null], array_column($colors, 'url'), (array) ($p['gallery'] ?? [])
    ))));
    $line = sprintf('%s NOU %-28s %-36s %d|%d lei, %d culori (%s), %d imagini, cat %s',
        $opt['apply'] ? '+' : '·', $sku, mb_substr($name, 0, 36), $special, $rrp, count($colors),
        implode(', ', array_column($colors, 'name')), count($imgUrls), $cat ?? '-');
    if (!$opt['create']) {
        logln($line . '  (sărit: --no-create)');
        continue;
    }
    if (!$opt['apply']) {
        logln($line);
        continue;
    }

    $id = 0;
    try {
        $prod = new Product();
        $prod->name              = array_fill_keys(LANGS, $name);
        $prod->link_rewrite      = array_fill_keys(LANGS, Tools::str2url($name));
        $prod->description       = array_fill_keys(LANGS, (string) $p['description']);
        $short = trim(strip_tags((string) $p['excerpt']));
        if (mb_strlen($short) > 700) {
            $short = mb_substr($short, 0, 700) . '…';
        }
        $prod->description_short = array_fill_keys(LANGS, $short !== '' ? '<p>' . htmlspecialchars($short, ENT_QUOTES, 'UTF-8') . '</p>' : '');
        $prod->reference         = $sku;
        $prod->id_manufacturer   = MANUFACTURER;
        $prod->id_supplier       = SUPPLIER;
        $prod->supplier_reference = $colors ? '' : DEFAULT_STOCK . "|{$special}|{$rrp}";
        $prod->id_category_default = CAT_MOTO;
        $prod->id_shop_default   = ID_SHOP;
        $prod->id_tax_rules_group = TAX_RULES_GROUP;
        $prod->price             = round($rrp / VAT_RATE, 6);
        $prod->active            = $opt['new_inactive'] ? 0 : 1;
        $prod->visibility        = 'both';
        $prod->condition         = 'new';
        $prod->show_price        = 1;
        $prod->available_for_order = 1;
        $prod->out_of_stock      = 2;
        $prod->minimal_quantity  = 1;
        $prod->low_stock_threshold = 0; // NULL strică editorul de combinații din BO (PS 9)
        $prod->product_type      = $colors ? 'combinations' : 'standard';
        if (!$prod->add()) {
            throw new RuntimeException('Product::add');
        }
        $id = (int) $prod->id;
        $prod->updateCategories(array_values(array_filter([CAT_ROOT, CAT_MOTO, $cat])));

        // imagini: cover, culori, galerie
        $imgIds = [];
        $errs = [];
        foreach ($imgUrls as $i => $url) {
            $iid = add_product_image($id, $url, $i === 0, $name, $err);
            if ($iid) {
                $imgIds[$url] = $iid;
            } else {
                $errs[] = $err;
            }
        }

        // combinații pe culori (+ furnizor/stoc per combinație)
        $first = true;
        foreach ($colors as $c) {
            $attr = color_attribute($c['name'], true);
            if (!$attr) {
                $errs[] = 'atribut ' . $c['name'];
                continue;
            }
            $comb = new Combination();
            $comb->id_product = $id;
            $comb->reference = $sku . '-' . slug($c['name']);
            $comb->price = 0;
            $comb->minimal_quantity = 1;
            $comb->low_stock_threshold = 0;
            $comb->default_on = $first ? 1 : null;
            $comb->add();
            $comb->setAttributes([$attr]);
            if ($c['url'] && isset($imgIds[$c['url']])) {
                $comb->setImages([$imgIds[$c['url']]]);
            }
            StockAvailable::setQuantity($id, (int) $comb->id, DEFAULT_STOCK, ID_SHOP);
            $db->execute("INSERT INTO {$prefix}product_supplier (id_product, id_product_attribute, id_supplier, product_supplier_reference, product_supplier_price_te, id_currency)
                          VALUES ({$id}, " . (int) $comb->id . ", " . SUPPLIER . ", " . q(DEFAULT_STOCK . "|{$special}|{$rrp}") . ", 0, 1)");
            $first = false;
        }
        if ($colors) {
            Product::updateDefaultAttribute($id);
        } else {
            StockAvailable::setQuantity($id, 0, DEFAULT_STOCK, ID_SHOP);
            $db->execute("INSERT INTO {$prefix}product_supplier (id_product, id_product_attribute, id_supplier, product_supplier_reference, product_supplier_price_te, id_currency)
                          VALUES ({$id}, 0, " . SUPPLIER . ", " . q(DEFAULT_STOCK . "|{$special}|{$rrp}") . ", 0, 1)");
        }

        // caracteristici din specificațiile portalului
        foreach ((array) ($p['specs'] ?? []) as $s) {
            $fid = FEATURE_MAP[$s['label'] ?? ''] ?? null;
            if (!$fid || trim((string) $s['value']) === '') {
                continue;
            }
            try {
                $fv = (int) FeatureValue::addFeatureValueImport($fid, mb_substr((string) $s['value'], 0, 255), $id, null, true);
                if ($fv) {
                    Product::addFeatureProductImport($id, $fid, $fv);
                }
            } catch (Throwable $e) {
                $errs[] = 'feature ' . $s['label'] . ': ' . $e->getMessage();
            }
        }

        queue_pricing($id);
        $counts['created']++;
        logln($line . " → #{$id}" . ($errs ? '  ⚠ ' . implode('; ', array_filter($errs)) : ''));
    } catch (Throwable $e) {
        $counts['errors']++;
        // Fără produse pe jumătate create (fără furnizor/combinații): șterge-l, rularea următoare reia.
        $cleanup = '';
        if ($id) {
            try {
                (new Product($id))->delete();
                $cleanup = " (produsul parțial #{$id} a fost șters)";
            } catch (Throwable $e2) {
                $cleanup = " (ATENȚIE: produsul parțial #{$id} NU s-a putut șterge: " . $e2->getMessage() . ')';
            }
        }
        logln($line . '  → EROARE ' . $e->getMessage() . $cleanup);
    }
}

// ---------------------------------------------------------------------------
// 3. Dezactivare: active pe BikerShop, dar inactive pe portal sau absente de acolo
// ---------------------------------------------------------------------------
$toDeactivate = [];
foreach ($bs as $sku => $b) {
    if (($opt['only'] && $opt['only'] !== $sku) || (int) $b['active'] !== 1 || isset($portalActive[$sku])) {
        continue;
    }
    $toDeactivate[$sku] = $b;
}
$activeBs = count(array_filter($bs, static fn ($r) => (int) $r['active'] === 1));
if ($toDeactivate && !$opt['force'] && $activeBs > 0 && count($toDeactivate) > $activeBs / 2) {
    logln(sprintf('! Plasă de siguranță: %d din %d produse active ar fi dezactivate — sar dezactivarea (folosește --force).', count($toDeactivate), $activeBs));
    $toDeactivate = [];
}
foreach ($toDeactivate as $sku => $b) {
    $id = (int) $b['id_product'];
    $why = isset($portal[$sku]) ? 'inactiv pe portal' : 'lipsă pe portal';
    $line = sprintf('%s #%-7d %-28s %-36s DEZACTIVARE (%s)', $opt['apply'] ? '×' : '·', $id, $sku, mb_substr((string) $b['name'], 0, 36), $why);
    if (!$opt['deactivate']) {
        logln($line . '  (sărit: --no-deactivate)');
        continue;
    }
    if (!$opt['apply']) {
        logln($line);
        $counts['deactivated']++;
        continue;
    }
    try {
        $shops = $db->executeS("SELECT id_shop, active, visibility, redirect_type, id_type_redirected FROM {$prefix}product_shop WHERE id_product = {$id}") ?: [];
        $rollback[] = sprintf("UPDATE {$prefix}product SET active = %d, visibility = %s, redirect_type = %s, id_type_redirected = %d WHERE id_product = %d;",
            (int) $b['active'], q((string) $b['visibility']), q((string) $b['redirect_type']), (int) $b['id_type_redirected'], $id);
        foreach ($shops as $s) {
            $rollback[] = sprintf("UPDATE {$prefix}product_shop SET active = %d, visibility = %s, redirect_type = %s, id_type_redirected = %d WHERE id_product = %d AND id_shop = %d;",
                (int) $s['active'], q((string) $s['visibility']), q((string) $s['redirect_type']), (int) $s['id_type_redirected'], $id, (int) $s['id_shop']);
        }
        foreach ($db->executeS("SELECT * FROM {$prefix}product_supplier WHERE id_product = {$id}") ?: [] as $s) {
            $rollback[] = sprintf("INSERT INTO {$prefix}product_supplier (id_product_supplier, id_product, id_product_attribute, id_supplier, product_supplier_reference, product_supplier_price_te, id_currency) VALUES (%d, %d, %d, %d, %s, %s, %d);",
                (int) $s['id_product_supplier'], $id, (int) $s['id_product_attribute'], (int) $s['id_supplier'], q((string) $s['product_supplier_reference']), (string) (float) $s['product_supplier_price_te'], (int) $s['id_currency']);
        }
        rollback_flush();
        $db->execute("UPDATE {$prefix}product SET active = 0, visibility = 'none', redirect_type = '" . REDIRECT_TYPE . "', id_type_redirected = 0, date_upd = NOW() WHERE id_product = {$id}");
        $db->execute("UPDATE {$prefix}product_shop SET active = 0, visibility = 'none', redirect_type = '" . REDIRECT_TYPE . "', id_type_redirected = 0, date_upd = NOW() WHERE id_product = {$id}");
        $db->execute("DELETE FROM {$prefix}product_supplier WHERE id_product = {$id}");
        refresh_redis_product($id);
        $counts['deactivated']++;
        logln($line);
    } catch (Throwable $e) {
        $counts['errors']++;
        logln($line . '  → EROARE ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Sumar
// ---------------------------------------------------------------------------
logln(str_repeat('─', 100));
$inBoth = count(array_intersect_key($portalActive, $bs));
logln(sprintf('Active pe portal: %d (pe BikerShop: %d). Preț actualizat: %d, neschimbat: %d, reactivate: %d, %s: %d, %s: %d, erori: %d',
    count($portalActive), $inBoth, $counts['price'], $counts['same'], $counts['reactivated'],
    $opt['apply'] ? 'create' : 'de creat', $opt['apply'] ? $counts['created'] : count(array_diff_key($portalActive, $bs)),
    $opt['apply'] ? 'dezactivate' : 'de dezactivat', $counts['deactivated'], $counts['errors']));
if ($opt['apply']) {
    rollback_flush();
    if (is_file($rbFile)) {
        logln('Rollback: ' . basename($rbFile));
    }
    logln('Log: ' . basename($logFile));
    logln('Notă: paginile publice pot rămâne în cache-ul LiteSpeed până la expirarea TTL-ului (max. 24h) — „clear cache" din BO pentru efect imediat.');
} else {
    logln('Dry-run. Rulează cu --apply ca să scrie.');
}

flock($lock, LOCK_UN);
if ($logHandle) {
    fclose($logHandle);
}
exit($counts['errors'] ? 1 : 0);
