#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Îmbogățește accesoriile Yamaha de pe BikerShop (PrestaShop 9) cu imagini + descriere
 * din catalogul public Yamaha (endpointul hyperdrive) și raportează prețurile divergente.
 *
 * RULEAZĂ PE SERVERUL BIKERSHOP (are nevoie de PrestaShop pe disc: clase + img/p/):
 *   /usr/local/bin/ea-php84 /home2/bikershop/tools/yamaha-enrich/enrich_yamaha_accessories.php [opțiuni]
 *
 * Opțiuni:
 *   --apply            scrie efectiv (implicit = dry-run, doar raportează)
 *   --only=ID|SKU      un singur produs (id_product BikerShop sau SKU Yamaha, ex. BMKF4730A100)
 *   --limit=N          maxim N produse modificate (pentru rulări în tranșe)
 *   --no-images        nu adaugă imagini
 *   --no-desc          nu completează descrieri
 *   --no-categorize    nu mută produsele în categoria 473
 *   --rate=5.30        cursul EUR→RON folosit în raportul de prețuri (și la --fix-prices)
 *   --fix-prices       corectează prețul produselor ACTIVE cu EXACT UN furnizor a căror valoare
 *                      rrp diferă de EUR×curs: rescrie câmpurile special|rrp din
 *                      `ps_product_supplier.product_supplier_reference` (stocul rămâne), scrie
 *                      și ps_product(.shop).price = brut/1.21 pentru efect imediat și pune produsul
 *                      în `ps_supplierpricing_queue` (force_update=1). Restul (mai mulți furnizori,
 *                      fără furnizor, fără preț Yamaha) rămân doar în CSV, de verificat manual.
 *   --ps-root=PATH     rădăcina PrestaShop (implicit /home2/bikershop/public_html)
 *
 * Ce face pentru fiecare produs ACTIV din BikerShop al cărui `reference` e un SKU Yamaha:
 *   1. imagini  — dacă produsul n-are NICIO imagine: descarcă pozele variantei de pe CDN-ul
 *                 Yamaha și le adaugă prin clasa PrestaShop `Image` + redimensionare GD locală
 *                 (toate tipurile de imagine + formatele configurate; vezi local_resize); prima = cover.
 *                 Produsele care au deja imagini NU sunt atinse.
 *   2. descriere — dacă `description` e goală: RO (id_lang=1) din `description`, EN (id_lang=2)
 *                 din `internalDescription` (fallback RO). Descrierile existente NU sunt suprascrise.
 *   3. categorie — adaugă produsul în 473 „Accesorii OEM Yamaha" și îl face categoria implicită
 *                 dacă era 2545 „Piese Yamaha - diagrame" (bucket-ul de 350k piese din diagrame).
 *   4. prețuri  — DOAR RAPORT (CSV în logs/): brut Yamaha (EUR × curs) vs. câmpul rrp din
 *                 `ps_product_supplier.product_supplier_reference` (sursa modulului supplierpricing).
 *                 Nu scrie prețuri: modulul supplierpricing le guvernează (vezi CLAUDE.md).
 *
 * NU scrie niciodată în ps_product.price / active. Fiecare rulare cu --apply produce
 * logs/enrich-<ts>.log + logs/rollback-<ts>.sql (revine la starea dinainte; fișierele
 * imagine rămân pe disc, dar orfane).
 *
 * Copia canonică a scriptului e în repo-ul portalului (database/bikershop/); pe server
 * se copiază cu scp. Cron pe serverul bikershop: de 2 ori pe lună (1 și 15), 04:00.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

// ---------------------------------------------------------------------------
// Argumente
// ---------------------------------------------------------------------------
$opt = [
    'apply'      => false,
    'only'       => null,
    'limit'      => 0,
    'images'     => true,
    'desc'       => true,
    'categorize' => true,
    'rate'       => 5.30,
    'fix_prices' => false,
    'ps_root'    => '/home2/bikershop/public_html',
];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--apply') {
        $opt['apply'] = true;
    } elseif ($a === '--no-images') {
        $opt['images'] = false;
    } elseif ($a === '--no-desc') {
        $opt['desc'] = false;
    } elseif ($a === '--no-categorize') {
        $opt['categorize'] = false;
    } elseif ($a === '--fix-prices') {
        $opt['fix_prices'] = true;
    } elseif (str_starts_with($a, '--only=')) {
        $opt['only'] = strtoupper(trim(substr($a, 7)));
    } elseif (str_starts_with($a, '--limit=')) {
        $opt['limit'] = max(0, (int) substr($a, 8));
    } elseif (str_starts_with($a, '--rate=')) {
        $opt['rate'] = (float) substr($a, 7);
    } elseif (str_starts_with($a, '--ps-root=')) {
        $opt['ps_root'] = rtrim(substr($a, 10), '/');
    } else {
        fwrite(STDERR, "Opțiune necunoscută: {$a}\n");
        exit(2);
    }
}
if ($opt['rate'] <= 0) {
    $opt['rate'] = 5.30;
}

// ---------------------------------------------------------------------------
// Constante de domeniu
// ---------------------------------------------------------------------------
const CAT_OEM        = 473;   // Accesorii OEM Yamaha (curatate)
const CAT_DIAGRAMS   = 2545;  // Piese Yamaha - diagrame (bucket fără imagini)
const ID_SHOP        = 1;
const LANG_RO        = 1;
const LANG_EN        = 2;
const CATALOG_GUID   = 'bf96ad91-485c-4c2b-86a0-c1857d86097b'; // subtree „toate accesoriile"
const PRICE_TOLERANCE = 1.00; // lei
const VAT_RATE       = 1.21;  // = SUPPLIERPRICING_VAT_RATE (cronul face price = rrp / 1.21)

/**
 * Rescrie `product_supplier_reference` (`stoc|special|rrp` sau 5 câmpuri cu perioadă promo)
 * păstrând stocul; special = rrp (special > rrp invalidează produsul în supplierpricing).
 * Copie a App\Accessories\RefAudit::rewriteSupplierReference (scriptul n-are Composer).
 */
function rewrite_supplier_reference(string $current, float $gross): ?string
{
    $parts = explode('|', $current);
    if (count($parts) !== 3 && count($parts) !== 5) {
        return null;
    }
    $price = rtrim(rtrim(number_format($gross, 2, '.', ''), '0'), '.');
    if ($price === '' || $price === '-0' || $gross <= 0) {
        return null;
    }
    $parts[1] = $price;
    $parts[2] = $price;
    return implode('|', $parts);
}

const URL_TEMPLATE =
    'https://hyperdrive.yamaha-motor.eu/products/yme-prod-ro'
    . '?projectKey=yme-prod-ro&locale=ro-RO'
    . '&query=categories.id:subtree(%22' . CATALOG_GUID . '%22)'
    . '%7Cvariants.attributes.embargoExternalReleased:true'
    . '&allFacets=&selectedFacets='
    . '&sort=variants.attributes.popularityIndex.desc%7Cvariants.sku.desc'
    . '&text=&productType=Accessory&version=caas';

// ---------------------------------------------------------------------------
// Log + lock
// ---------------------------------------------------------------------------
$baseDir = __DIR__;
$logDir  = $baseDir . '/logs';
$tmpDir  = $baseDir . '/tmp';
@mkdir($logDir, 0775, true);
@mkdir($tmpDir, 0775, true);

$ts       = date('Ymd-His');
$logFile  = $logDir . "/enrich-{$ts}.log";
$rbFile   = $logDir . "/rollback-{$ts}.sql";
$csvFile  = $logDir . "/prices-{$ts}.csv";
$lockFile = $tmpDir . '/enrich.lock';

$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "O altă rulare e în curs (lock {$lockFile}).\n");
    exit(1);
}

$logHandle = $opt['apply'] ? fopen($logFile, 'a') : null;
function logln(string $line): void
{
    global $logHandle;
    $out = $line . "\n";
    echo $out;
    if ($logHandle) {
        fwrite($logHandle, date('H:i:s') . ' ' . $out);
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
        file_put_contents($rbFile, "-- Rollback pentru rularea din " . date('c') . "
-- (fișierele imagine rămân pe disc)
");
    }
    file_put_contents($rbFile, implode("
", $rollback) . "
", FILE_APPEND);
    $rollback = [];
}

// ---------------------------------------------------------------------------
// Bootstrap PrestaShop (același tipar ca modules/advrider_related/cli/sync.php)
// ---------------------------------------------------------------------------
$root = $opt['ps_root'];
if (!is_file($root . '/config/config.inc.php')) {
    fwrite(STDERR, "PrestaShop nu e la {$root} (lipsește config/config.inc.php).\n");
    exit(1);
}
if (!defined('_PS_ADMIN_DIR_')) {
    $adminDir = null;
    foreach (scandir($root) as $entry) {
        if (str_starts_with($entry, 'admin') && $entry !== 'admin-api' && is_dir($root . '/' . $entry)
            && is_file($root . '/' . $entry . '/index.php')) {
            $adminDir = $root . '/' . $entry;
            break;
        }
    }
    if ($adminDir === null) {
        foreach (scandir($root) as $entry) {
            if (str_starts_with($entry, '__admin') && is_dir($root . '/' . $entry)) {
                $adminDir = $root . '/' . $entry;
                break;
            }
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

$db     = Db::getInstance();
$prefix = _DB_PREFIX_;

logln(sprintf('enrich_yamaha_accessories %s — PrestaShop %s, PHP %s, curs raport %.2f',
    $opt['apply'] ? 'APPLY' : 'DRY-RUN', _PS_VERSION_, PHP_VERSION, $opt['rate']));
logln(str_repeat('─', 96));

// ---------------------------------------------------------------------------
// Helpers HTTP
// ---------------------------------------------------------------------------
function http_get(string $url, int $timeout = 40, int $attempts = 3): ?string
{
    for ($try = 1; $try <= $attempts; $try++) {
        $body = http_get_once($url, $timeout);
        if ($body !== null) {
            return $body;
        }
        if ($try < $attempts) {
            sleep(2 * $try); // backoff: hyperdrive răspunde uneori 5xx/gol la rafale
        }
    }
    return null;
}

function http_get_once(string $url, int $timeout): ?string
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: Mozilla/5.0 (compatible; BikerShop enrich)',
            'Accept: application/json, image/*;q=0.9, */*;q=0.8',
            'Referer: https://www.yamaha-motor.eu/',
            'Origin: https://www.yamaha-motor.eu',
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        return null;
    }
    return (string) $body;
}

function normalize_sku(string $sku): string
{
    return strtoupper((string) preg_replace('/[\s\-\.]+/', '', $sku));
}

/** Text simplu → HTML cu paragrafe (escapat). */
function text_to_html(string $text): string
{
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    if ($text === '') {
        return '';
    }
    $paras = preg_split('/\n{2,}/', $text) ?: [];
    $out = [];
    foreach ($paras as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        $lines = array_map('trim', explode("\n", $p));
        $out[] = '<p>' . implode('<br>', array_map(
            static fn (string $l) => htmlspecialchars($l, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $lines
        )) . '</p>';
    }
    return implode("\n", $out);
}

function attr(array $variant, string $name): mixed
{
    foreach (($variant['attributes'] ?? []) as $a) {
        if (($a['name'] ?? '') === $name) {
            return $a['value'] ?? null;
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// 1. Catalogul Yamaha complet (paginat) → sku => date
// ---------------------------------------------------------------------------
$catalog = [];
$limit   = 200;
$offset  = 0;
$total   = 0;
do {
    $body = http_get(URL_TEMPLATE . "&limit={$limit}&offset={$offset}");
    $data = $body !== null ? json_decode($body, true) : null;
    if (!is_array($data)) {
        logln("EROARE: Yamaha hyperdrive indisponibil la offset {$offset}. Nu se scrie nimic.");
        exit(1);
    }
    $total = (int) ($data['total'] ?? 0);
    $batch = $data['results'] ?? [];
    if (!$batch) {
        break;
    }
    foreach ($batch as $p) {
        $name   = trim((string) preg_replace('/\s+/u', ' ', (string) ($p['name'] ?? '')));
        $descRo = trim((string) ($p['description'] ?? ''));
        $master = null;
        foreach (($p['variants'] ?? []) as $v) {
            if (!empty($v['master'])) {
                $master = $v;
                break;
            }
        }
        $master = $master ?? ($p['variants'][0] ?? []);
        $masterImgs = array_values(array_filter(array_unique(array_map(
            static fn ($im) => (string) ($im['url'] ?? ''),
            $master['images'] ?? []
        )), static fn (string $u) => (bool) preg_match('/\.(jpe?g|png|webp)(\?|$)/i', $u)));

        foreach (($p['variants'] ?? []) as $v) {
            $raw = (string) ($v['sku'] ?? '');
            $sku = normalize_sku($raw);
            if ($sku === '') {
                continue;
            }
            // Doar imagini: în `images` apar și PDF-uri (instrucțiuni de montaj).
            $imgs = array_values(array_filter(array_unique(array_map(
                static fn ($im) => (string) ($im['url'] ?? ''),
                $v['images'] ?? []
            )), static fn (string $u) => (bool) preg_match('/\.(jpe?g|png|webp)(\?|$)/i', $u)));
            if (!$imgs) {
                $imgs = array_values(array_filter($masterImgs));
            }
            $descEn = trim((string) (attr($v, 'internalDescription') ?? ''));
            $typeRaw = attr($v, 'accessoryType');
            $type    = is_array($typeRaw) ? (string) (reset($typeRaw) ?: '') : (string) ($typeRaw ?? '');
            $catalog[$sku] = [
                'sku'       => $sku,
                'sku_raw'   => $raw,
                'yamaha_id' => (string) ($p['id'] ?? ''),
                'name'      => $name,
                'name_en'   => trim((string) ($p['productNameEn'] ?? '')),
                'desc_ro'   => $descRo,
                'desc_en'   => $descEn,
                'images'    => $imgs,
                'price_eur' => (float) ($v['prices'][0]['amount'] ?? 0),
                'type'      => $type,
            ];
        }
    }
    $offset += $limit;
    if ($offset < $total) {
        usleep(300000);
    }
} while ($offset < $total);

logln(sprintf('Catalog Yamaha: %d produse, %d SKU-uri', $total, count($catalog)));
if (count($catalog) < 500) {
    logln('EROARE: catalog suspect de mic — oprire de siguranță.');
    exit(1);
}

// ---------------------------------------------------------------------------
// 2. Produsele BikerShop cu referință = SKU Yamaha
// ---------------------------------------------------------------------------
$skus = array_keys($catalog);
if ($opt['only'] !== null && !ctype_digit($opt['only'])) {
    $skus = isset($catalog[$opt['only']]) ? [$opt['only']] : [];
    if (!$skus) {
        logln("SKU {$opt['only']} nu există în catalogul Yamaha.");
        exit(1);
    }
}

$products = [];
foreach (array_chunk($skus, 500) as $chunk) {
    $in = implode(',', array_map(static fn ($s) => "'" . pSQL($s) . "'", $chunk));
    $onlyId = ($opt['only'] !== null && ctype_digit($opt['only'])) ? ' AND p.id_product = ' . (int) $opt['only'] : '';
    $rows = $db->executeS(
        "SELECT p.id_product, p.reference, p.id_category_default, p.price AS ps_price,
                ps.active, ps.price AS shop_price,
                pl.name, pl.link_rewrite, LENGTH(pl.description) AS dlen_ro,
                (SELECT LENGTH(pl2.description) FROM {$prefix}product_lang pl2
                   WHERE pl2.id_product = p.id_product AND pl2.id_shop = " . ID_SHOP . " AND pl2.id_lang = " . LANG_EN . ") AS dlen_en,
                (SELECT COUNT(*) FROM {$prefix}image i WHERE i.id_product = p.id_product) AS imgs,
                (SELECT GROUP_CONCAT(cp.id_category) FROM {$prefix}category_product cp WHERE cp.id_product = p.id_product) AS cats,
                (SELECT GROUP_CONCAT(sp.product_supplier_reference SEPARATOR '||') FROM {$prefix}product_supplier sp WHERE sp.id_product = p.id_product) AS sup_refs
         FROM {$prefix}product p
         JOIN {$prefix}product_shop ps ON ps.id_product = p.id_product AND ps.id_shop = " . ID_SHOP . "
         LEFT JOIN {$prefix}product_lang pl ON pl.id_product = p.id_product AND pl.id_shop = " . ID_SHOP . " AND pl.id_lang = " . LANG_RO . "
         WHERE p.reference IN ({$in}){$onlyId}
         ORDER BY p.id_product"
    ) ?: [];
    foreach ($rows as $r) {
        $products[(int) $r['id_product']] = $r;
    }
}
if ($opt['only'] !== null && ctype_digit($opt['only']) && !$products) {
    logln("Produsul {$opt['only']} nu există sau referința lui nu e un SKU Yamaha.");
    exit(1);
}

$counts = [
    'matched' => count($products), 'inactive' => 0, 'complete' => 0,
    'need_images' => 0, 'need_desc' => 0, 'need_cat' => 0,
    'images_added' => 0, 'images_failed' => 0, 'desc_set' => 0, 'cat_set' => 0,
    'products_changed' => 0, 'skipped_limit' => 0, 'prices_fixed' => 0,
];

// ---------------------------------------------------------------------------
// 3. Pregătire scriere imagini
// ---------------------------------------------------------------------------
$imageTypes = ImageType::getImagesTypes('products');
$formats    = ['jpg'];
$fmtCfg     = (string) Configuration::get('PS_IMAGE_FORMAT');
if ($fmtCfg !== '') {
    $decoded = json_decode($fmtCfg, true);
    $list = is_array($decoded) ? $decoded : explode(',', $fmtCfg);
    foreach ($list as $f) {
        $f = strtolower(trim((string) $f));
        if (in_array($f, ['jpg', 'png', 'webp', 'avif'], true) && !in_array($f, $formats, true)) {
            $formats[] = $f;
        }
    }
}
logln(sprintf('Tipuri imagine: %s | formate: %s',
    implode(',', array_map(static fn ($t) => $t['name'], $imageTypes)), implode(',', $formats)));

/**
 * Redimensionare locală cu GD, același algoritm ca ImageManager::resize / writeImageOnDisk
 * (fundal alb, centrat, PS_IMAGE_GENERATION_METHOD). NU folosim ImageManager::resize pentru că
 * declanșează hook-ul `actionOnImageResizeAfter`, pe care teamwant_redis îl tratează fără
 * id_product → curățare oarbă a cache-ului Redis, ~4 s per apel (27 s per imagine).
 * Cache-ul Redis al produsului e oricum invalidat de hook-ul din Image::add().
 */
function local_resize(string $src, string $dst, ?int $dstW, ?int $dstH, string $fmt): bool
{
    static $cache = [];
    $key = $src;
    if (!isset($cache[$key])) {
        $cache = []; // o singură sursă în memorie
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
        $cache[$key] = ['im' => $im, 'w' => (int) $info[0], 'h' => (int) $info[1]];
    }
    $srcIm = $cache[$key]['im'];
    $srcW  = $cache[$key]['w'];
    $srcH  = $cache[$key]['h'];

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
    $white = imagecolorallocate($out, 255, 255, 255);
    imagefill($out, 0, 0, $white);
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

/**
 * Adaugă o imagine unui produs prin API-ul PrestaShop. Întoarce id_image sau null.
 */
function add_product_image(int $idProduct, string $url, bool $cover, array $legends, array $shops, array $imageTypes, array $formats, string $tmpDir, ?string &$err): ?int
{
    global $imgTiming;
    $err = null;
    $tm = microtime(true);
    $bin = http_get($url, 60);
    $imgTiming['dl'] = ($imgTiming['dl'] ?? 0) + microtime(true) - $tm;
    $tm = microtime(true);
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
    $image->legend     = $legends;
    try {
        if (!$image->add()) {
            @unlink($tmp);
            $err = 'Image::add a eșuat';
            return null;
        }
        $image->associateTo($shops, $idProduct);
        $imgTiming['add'] = ($imgTiming['add'] ?? 0) + microtime(true) - $tm;
        $tm = microtime(true);
        $path = $image->getPathForCreation();
        if (!$path) {
            throw new RuntimeException('getPathForCreation');
        }
        if (!local_resize($tmp, $path . '.jpg', null, null, 'jpg')) {
            throw new RuntimeException('resize original');
        }
        foreach ($imageTypes as $t) {
            foreach ($formats as $fmt) {
                if (!local_resize($tmp, sprintf('%s-%s.%s', $path, stripslashes($t['name']), $fmt), (int) $t['width'], (int) $t['height'], $fmt)) {
                    throw new RuntimeException('resize ' . $t['name'] . '.' . $fmt);
                }
            }
        }
        $imgTiming['resize'] = ($imgTiming['resize'] ?? 0) + microtime(true) - $tm;
        $tm = microtime(true);
        Hook::exec('actionWatermark', ['id_image' => (int) $image->id, 'id_product' => $idProduct]);
        $imgTiming['hook'] = ($imgTiming['hook'] ?? 0) + microtime(true) - $tm;
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

// ---------------------------------------------------------------------------
// 4. Raport prețuri (CSV) — DOAR CITIRE
// ---------------------------------------------------------------------------
$csv = fopen($csvFile, 'w');
fputcsv($csv, ['id_product', 'reference', 'name', 'active', 'price_eur', 'expected_gross_ron', 'supplier_rrp_ron', 'diff_ron', 'ps_price_net', 'status', 'supplier_refs', 'url']);
$priceStats = ['ok' => 0, 'diff' => 0, 'multi_identical' => 0, 'multi' => 0, 'no_supplier' => 0, 'no_yamaha_price' => 0];
try {
    $linkBase = rtrim((string) Context::getContext()->link->getBaseLink(ID_SHOP), '/');
} catch (Throwable) {
    $linkBase = 'https://bikershop.ro';
}

// ---------------------------------------------------------------------------
// 5. Procesare produs cu produs
// ---------------------------------------------------------------------------
$redis = null;
try {
    $redis = Module::isEnabled('teamwant_redis') ? Module::getInstanceByName('teamwant_redis') : null;
} catch (Throwable) {
    $redis = null;
}

$changed = 0;
$imgTiming = [];
foreach ($products as $id => $p) {
    $sku = strtoupper(trim((string) $p['reference']));
    $y   = $catalog[$sku] ?? null;
    if ($y === null) {
        continue;
    }

    // --- raport preț (toate produsele, inclusiv inactive) ---
    $expected = $y['price_eur'] > 0 ? round($y['price_eur'] * $opt['rate'], 2) : 0.0;
    $supRefs  = $p['sup_refs'] !== null && $p['sup_refs'] !== '' ? explode('||', (string) $p['sup_refs']) : [];
    $rrp = null;
    $status = 'ok';
    if ($y['price_eur'] <= 0) {
        $status = 'no_yamaha_price';
    } elseif (!$supRefs) {
        $status = 'no_supplier';
    } else {
        foreach ($supRefs as $sr) {
            $f = explode('|', $sr);
            $v = (float) ($f[2] ?? 0);
            if ($rrp === null) {
                $rrp = $v;
            } elseif (abs($rrp - $v) > 0.01) {
                $status = 'multi';
                break;
            }
        }
        if ($status === 'ok' && $rrp !== null && abs($rrp - $expected) > PRICE_TOLERANCE) {
            $status = count($supRefs) === 1 ? 'diff' : 'multi_identical';
        }
    }
    $priceStats[$status] = ($priceStats[$status] ?? 0) + 1;

    // --- corectare preț: doar activ + exact un furnizor + format cunoscut ---
    if ($opt['fix_prices'] && $status === 'diff' && (int) $p['active'] === 1) {
        $newRef = rewrite_supplier_reference($supRefs[0], $expected);
        if ($newRef === null) {
            $status = 'diff_format_necunoscut';
        } else {
            $newNet  = round($expected / VAT_RATE, 2);
            $fixed   = false;
            $fixLine = sprintf('%s %-8d %-13s %-44s [preț %.2f → %.2f lei brut]',
                $opt['apply'] ? '€' : '·', $id, $sku, mb_substr((string) $p['name'], 0, 44), $rrp, $expected);
            if ($opt['apply']) {
                try {
                    $sup = $db->getRow("SELECT id_product_supplier, product_supplier_reference FROM {$prefix}product_supplier WHERE id_product = " . (int) $id);
                    if (!$sup || (string) $sup['product_supplier_reference'] !== $supRefs[0]) {
                        throw new RuntimeException('furnizorul s-a schimbat între timp');
                    }
                    $db->execute("UPDATE {$prefix}product_supplier SET product_supplier_reference = '" . pSQL($newRef) . "' WHERE id_product_supplier = " . (int) $sup['id_product_supplier']);
                    $db->execute("UPDATE {$prefix}product SET price = " . $newNet . ", date_upd = NOW() WHERE id_product = " . (int) $id);
                    $db->execute("UPDATE {$prefix}product_shop SET price = " . $newNet . ", date_upd = NOW() WHERE id_product = " . (int) $id . " AND id_shop = " . ID_SHOP);
                    $db->execute("INSERT INTO {$prefix}supplierpricing_queue (id_product, last_update, in_progress, force_update) VALUES (" . (int) $id . ", NOW(), 0, 1)
                                  ON DUPLICATE KEY UPDATE force_update = 1, last_update = NOW()");
                    $rollback[] = sprintf("UPDATE {$prefix}product_supplier SET product_supplier_reference = '%s' WHERE id_product_supplier = %d;",
                        str_replace("'", "''", $supRefs[0]), (int) $sup['id_product_supplier']);
                    $rollback[] = sprintf("UPDATE {$prefix}product SET price = %s WHERE id_product = %d;", (string) $p['ps_price'], $id);
                    $rollback[] = sprintf("UPDATE {$prefix}product_shop SET price = %s WHERE id_product = %d AND id_shop = %d;", (string) $p['shop_price'], $id, ID_SHOP);
                    rollback_flush();
                    $fixed = true;
                } catch (Throwable $e) {
                    $fixLine .= '  → EROARE: ' . $e->getMessage();
                }
            } else {
                $fixed = true;
            }
            if ($fixed) {
                $counts['prices_fixed']++;
                $status = 'fixed';
            }
            logln($fixLine);
        }
    }
    if ($status !== 'ok' && $status !== 'fixed') {
        fputcsv($csv, [
            $id, $sku, (string) $p['name'], (int) $p['active'], $y['price_eur'], $expected,
            $rrp ?? '', $rrp !== null ? round($rrp - $expected, 2) : '', (float) $p['shop_price'],
            $status, implode(' ; ', $supRefs), $linkBase . '/index.php?id_product=' . $id . '&controller=product',
        ]);
    }

    // --- îmbogățire doar pentru produse active ---
    if ((int) $p['active'] !== 1) {
        $counts['inactive']++;
        continue;
    }
    $needImg = $opt['images'] && (int) $p['imgs'] === 0 && $y['images'];
    $needRo  = $opt['desc'] && (int) ($p['dlen_ro'] ?? 0) === 0 && $y['desc_ro'] !== '';
    $needEn  = $opt['desc'] && (int) ($p['dlen_en'] ?? 0) === 0 && ($y['desc_en'] !== '' || $y['desc_ro'] !== '');
    $cats    = array_map('intval', array_filter(explode(',', (string) $p['cats'])));
    $needCat = $opt['categorize'] && (!in_array(CAT_OEM, $cats, true) || (int) $p['id_category_default'] === CAT_DIAGRAMS);

    if ($needImg) {
        $counts['need_images']++;
    }
    if ($needRo || $needEn) {
        $counts['need_desc']++;
    }
    if ($needCat) {
        $counts['need_cat']++;
    }
    if (!$needImg && !$needRo && !$needEn && !$needCat) {
        $counts['complete']++;
        continue;
    }
    if ($opt['limit'] > 0 && $changed >= $opt['limit']) {
        $counts['skipped_limit']++;
        continue;
    }
    $changed++;

    $line = sprintf('%s %-8d %-13s %-44s', $opt['apply'] ? '✓' : '·', $id, $sku, mb_substr((string) $p['name'], 0, 44));
    $todo = [];
    if ($needImg) {
        $todo[] = 'img×' . count($y['images']);
    }
    if ($needRo) {
        $todo[] = 'desc-ro';
    }
    if ($needEn) {
        $todo[] = 'desc-en';
    }
    if ($needCat) {
        $todo[] = 'cat473';
    }
    $line .= ' [' . implode(' ', $todo) . ']';

    if (!$opt['apply']) {
        logln($line);
        continue;
    }

    $notes = [];
    $timing = [];
    $t0 = microtime(true);
    try {
        // ---- imagini ----
        if ($needImg) {
            $shops = array_map(static fn ($r) => (int) $r['id_shop'],
                $db->executeS("SELECT id_shop FROM {$prefix}product_shop WHERE id_product = " . (int) $id) ?: []);
            if (!$shops) {
                $shops = [ID_SHOP];
            }
            $legend = [];
            foreach (Language::getLanguages(false) as $lang) {
                $legend[(int) $lang['id_lang']] = mb_substr((string) $p['name'], 0, 128);
            }
            $first = true;
            $added = [];
            foreach ($y['images'] as $url) {
                $err = null;
                $idImage = add_product_image((int) $id, $url, $first, $legend, $shops, $imageTypes, $formats, $tmpDir, $err);
                if ($idImage === null) {
                    $counts['images_failed']++;
                    $notes[] = 'img eșuată (' . $err . '): ' . basename($url);
                    continue;
                }
                $added[] = $idImage;
                $counts['images_added']++;
                $first = false;
            }
            if ($added) {
                $ids = implode(',', $added);
                $rollback[] = "DELETE FROM {$prefix}image_lang WHERE id_image IN ({$ids});";
                $rollback[] = "DELETE FROM {$prefix}image_shop WHERE id_image IN ({$ids});";
                $rollback[] = "DELETE FROM {$prefix}image WHERE id_image IN ({$ids});";
                $notes[] = 'imagini ' . $ids;
            }
            $timing[] = sprintf('img %.1fs [%s]', microtime(true) - $t0,
                implode(' ', array_map(static fn ($k, $v) => sprintf('%s %.1f', $k, $v), array_keys($imgTiming), $imgTiming)));
            $imgTiming = [];
            $t0 = microtime(true);
        }

        // ---- descrieri ----
        if ($needRo || $needEn) {
            $htmlRo = text_to_html($y['desc_ro']);
            $htmlEn = text_to_html($y['desc_en'] !== '' ? $y['desc_en'] : $y['desc_ro']);
            if ($needRo && $htmlRo !== '') {
                $db->execute("UPDATE {$prefix}product_lang SET description = '" . pSQL($htmlRo, true) . "'
                              WHERE id_product = " . (int) $id . " AND id_lang = " . LANG_RO . " AND (description IS NULL OR description = '')");
                $rollback[] = "UPDATE {$prefix}product_lang SET description = '' WHERE id_product = {$id} AND id_lang = " . LANG_RO . ";";
                $counts['desc_set']++;
            }
            if ($needEn && $htmlEn !== '') {
                $db->execute("UPDATE {$prefix}product_lang SET description = '" . pSQL($htmlEn, true) . "'
                              WHERE id_product = " . (int) $id . " AND id_lang = " . LANG_EN . " AND (description IS NULL OR description = '')");
                $rollback[] = "UPDATE {$prefix}product_lang SET description = '' WHERE id_product = {$id} AND id_lang = " . LANG_EN . ";";
            }
        }

        // ---- categorie ----
        if ($needCat) {
            if (!in_array(CAT_OEM, $cats, true)) {
                $pos = (int) $db->getValue("SELECT COALESCE(MAX(position),0)+1 FROM {$prefix}category_product WHERE id_category = " . CAT_OEM);
                $db->execute("INSERT IGNORE INTO {$prefix}category_product (id_category, id_product, position) VALUES (" . CAT_OEM . ", " . (int) $id . ", {$pos})");
                $rollback[] = "DELETE FROM {$prefix}category_product WHERE id_category = " . CAT_OEM . " AND id_product = {$id};";
            }
            if ((int) $p['id_category_default'] === CAT_DIAGRAMS) {
                $db->execute("UPDATE {$prefix}product SET id_category_default = " . CAT_OEM . " WHERE id_product = " . (int) $id);
                $db->execute("UPDATE {$prefix}product_shop SET id_category_default = " . CAT_OEM . " WHERE id_product = " . (int) $id);
                $rollback[] = "UPDATE {$prefix}product SET id_category_default = " . CAT_DIAGRAMS . " WHERE id_product = {$id};";
                $rollback[] = "UPDATE {$prefix}product_shop SET id_category_default = " . CAT_DIAGRAMS . " WHERE id_product = {$id};";
            }
            $counts['cat_set']++;
        }

        $timing[] = sprintf('sql %.1fs', microtime(true) - $t0);
        $t0 = microtime(true);
        $db->execute("UPDATE {$prefix}product SET date_upd = NOW() WHERE id_product = " . (int) $id);
        $db->execute("UPDATE {$prefix}product_shop SET date_upd = NOW() WHERE id_product = " . (int) $id);

        // Invalidare cache Redis (modulul teamwant_redis), fără să declanșăm hook-urile
        // altor module (supplierpricing, leopartsfilter…).
        if ($redis && method_exists($redis, 'hookActionObjectProductUpdateAfter')) {
            try {
                $redis->hookActionObjectProductUpdateAfter(['object' => new Product((int) $id)]);
            } catch (Throwable $e) {
                $notes[] = 'redis: ' . $e->getMessage();
            }
            $timing[] = sprintf('redis %.1fs', microtime(true) - $t0);
        }
        $counts['products_changed']++;
    } catch (Throwable $e) {
        $notes[] = 'EROARE: ' . $e->getMessage();
    }

    rollback_flush();
    logln($line . ($notes ? '  → ' . implode('; ', $notes) : '') . ($timing ? '  (' . implode(', ', $timing) . ')' : ''));
}
fclose($csv);

// ---------------------------------------------------------------------------
// 6. Sumar
// ---------------------------------------------------------------------------
logln(str_repeat('─', 96));
logln(sprintf('Produse BikerShop cu SKU Yamaha: %d  (inactive: %d, deja complete: %d)',
    $counts['matched'], $counts['inactive'], $counts['complete']));
logln(sprintf('De îmbogățit: imagini %d, descrieri %d, categorie %d%s',
    $counts['need_images'], $counts['need_desc'], $counts['need_cat'],
    $counts['skipped_limit'] ? sprintf('  (sărite de --limit: %d)', $counts['skipped_limit']) : ''));
if ($opt['apply']) {
    logln(sprintf('Scris: %d produse, %d imagini (%d eșuate), %d descrieri, %d categorii',
        $counts['products_changed'], $counts['images_added'], $counts['images_failed'], $counts['desc_set'], $counts['cat_set']));
}
logln(sprintf('Prețuri (curs %.2f): ok %d, DIFERITE (1 furnizor) %d%s, mai mulți furnizori identici %d, furnizori divergenți %d, fără furnizor %d, fără preț Yamaha %d → %s',
    $opt['rate'], $priceStats['ok'], $priceStats['diff'],
    $opt['fix_prices'] ? sprintf(' (%s %d)', $opt['apply'] ? 'corectate' : 'de corectat', $counts['prices_fixed']) : '',
    $priceStats['multi_identical'], $priceStats['multi'], $priceStats['no_supplier'], $priceStats['no_yamaha_price'],
    basename($csvFile)));

if ($opt['apply']) {
    rollback_flush();
    if (is_file($rbFile)) {
        logln('Rollback: ' . basename($rbFile));
    }
    logln('Log: ' . basename($logFile));
    logln('Notă: paginile publice pot rămâne în cache-ul LiteSpeed până la expirarea TTL-ului (max. 24h).');
} else {
    logln('Dry-run. Rulează cu --apply ca să scrie.');
}

flock($lock, LOCK_UN);
if ($logHandle) {
    fclose($logHandle);
}
exit(0);
