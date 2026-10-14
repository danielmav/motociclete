<?php
declare(strict_types=1);

/**
 * Arai din stoc care lipsește de pe BikerShop → CSV de import (formatul NOS / Furygan / Dainese).
 * Regula Dual Tours: pe site intră DOAR ce e pe stoc (nu tot catalogul Arai).
 *
 * Surse:
 *   - gestiunea (`stocuri2024`): coduri, stoc, preț RON cu TVA, EAN. Căștile: cod = model+culoare (7 cifre)
 *     + mărime (01 XS … 06 XXL): `137014903` = 1370149 / M. Piesele: codul fără mărime (`55011058`).
 *   - căști: API-ul public araihelmet.eu (`POST /api/collections/<slug>` {"locale":"en"}) → colecția
 *     (descriere, caracteristici) și designul (nume + imagine mare); potrivire după denumirea din gestiune.
 *   - piese: `documente/Arai 2025/products_import(1).csv` (denumiri) + pozele din
 *     `documente/Arai 2025/Piese si accesorii Arai/<cod>.jpg`, copiate în storage/arai/img/ și urcate
 *     pe portal în /media/arai/ (bikershop.ro e în spatele Cloudflare → importul n-ar putea citi de acolo).
 *
 * Pași (local, PHP 8.1 Laragon; BikerShop din .env):
 *   1. --extract --csv-stoc=<raport stoc-nelistat.php>  → storage/arai/extract.json (+ storage/arai/img/)
 *   2. scp storage/arai/img/* dualmotors:public_html/motociclete.com.ro/media/arai/
 *   3. traducerea: storage/arai/ro.json = { "<ref>": {"name": "...", "description": "<p>…</p>", "category"?: id} }
 *   4. --csv → storage/arai/arai-<data>.csv (Active = 0). Piesele fără poză NU intră (sunt raportate).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

const API = 'https://www.araihelmet.eu/api/collections/';
const IMG_BASE = 'https://www.motociclete.com.ro/media/arai/';
const HELMET_SIZES = ['01' => 'XS', '02' => 'S', '03' => 'M', '04' => 'L', '05' => 'XL', '06' => '2XL', '07' => '3XL'];
// colecțiile Arai (slug API) după cuvintele din denumirea din gestiune, cel mai specific întâi
const COLLECTIONS = [
    'MX-V FIM' => ['mx-v-fim-racing-2', 'mx-v-fim-racing-#2', 'mx-v-fim-racing'], 'MX-V EVO' => ['mx-v-evo'], 'MX-V' => ['mx-v'],
    'RX 7V EVO' => ['rx-7v-evo'], 'RX-7V EVO' => ['rx-7v-evo'], 'RX 7V' => ['rx-7v'], 'TOUR-X5' => ['tour-x5'], 'TOUR X5' => ['tour-x5'],
    'CONCEPT-XE' => ['concept-xe'], 'QUANTIC' => ['quantic'], 'RENEGADE' => ['renegade-v'], 'CHASER' => ['chaser-x'],
];
const CAT_HELMET = ['MX-V' => 767, 'default' => 765];   // off-road / integrale
const CAT_PART = ['VIZOR' => 770, 'VIZIERA' => 770, 'PINLOCK' => 770, 'COZOROC' => 770, 'default' => 769];

$root = dirname(__DIR__, 2);
$dir = $root . '/storage/arai';
$src = $root . '/documente/Arai 2025';
@mkdir($dir . '/img', 0775, true);

$opt = ['extract' => false, 'csv' => false, 'stoc' => null];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--extract' || $a === '--csv') {
        $opt[substr($a, 2)] = true;
    } elseif (str_starts_with($a, '--csv-stoc=')) {
        $opt['stoc'] = substr($a, 11);
    } else {
        fwrite(STDERR, "Opțiune necunoscută: {$a}\n");
        exit(2);
    }
}

function bs_pdo(string $root): PDO
{
    $e = [];
    foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        if (str_contains($l, '=') && $l[0] !== '#') {
            [$k, $v] = explode('=', $l, 2);
            $e[trim($k)] = trim(trim($v), "\"'");
        }
    }
    return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $e['BIKERSHOP_HOST'], (int) ($e['BIKERSHOP_PORT'] ?? 3306), $e['BIKERSHOP_NAME']),
        $e['BIKERSHOP_USER'], $e['BIKERSHOP_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

function api_collection(string $slug): ?array
{
    static $cache = [];
    if (array_key_exists($slug, $cache)) {
        return $cache[$slug];
    }
    $ch = curl_init(API . rawurlencode($slug));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{"locale":"en"}', CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_USERAGENT => 'Mozilla/5.0']);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $j = $code === 200 ? json_decode((string) $body, true) : null;
    return $cache[$slug] = (is_array($j) && !empty($j['designs'])) ? $j : null;
}

function words(string $s): array
{
    return array_values(array_filter(preg_split('/[^A-Z0-9]+/', strtoupper(str_replace('&', ' ', $s)))));
}

function plain(string $html): string
{
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br />'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/* ===================== 1. extragere ===================== */
if ($opt['extract']) {
    if (!$opt['stoc'] || !is_file($opt['stoc'])) {
        fwrite(STDERR, "Lipsește --csv-stoc=<raport stoc-nelistat.php>\n");
        exit(2);
    }
    $pdo = bs_pdo($root);

    // codurile Arai lipsă CU STOC
    $h = fopen($opt['stoc'], 'r');
    $head = fgetcsv($h, 0, ';', '"', '');
    $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
    $rows = [];
    while (($r = fgetcsv($h, 0, ';', '"', '')) !== false) {
        $r = array_combine($head, $r);
        if ($r['Marca'] === 'ARAI' && $r['Stare pe site'] === 'lipsește complet' && (int) $r['Stoc'] > 0) {
            $rows[strtoupper($r['Cod gestiune'])] = $r;
        }
    }
    fclose($h);

    // denumirile pieselor din importul pregătit de importator
    $partNames = [];
    if (($ph = fopen($src . '/products_import(1).csv', 'r')) !== false) {
        $ph_head = fgetcsv($ph, 0, ';', '"', '');
        while (($r = fgetcsv($ph, 0, ';', '"', '')) !== false) {
            if (isset($r[12]) && $r[12] !== '') {
                $partNames[strtoupper(trim($r[12]))] = trim(mb_convert_encoding($r[2], 'UTF-8', 'UTF-8'));
            }
        }
        fclose($ph);
    }

    $out = [];
    $skipped = [];
    $gest = $pdo->prepare("SELECT codprodus, stoc, pret, cod_bare FROM stocuri2024 WHERE codprodus LIKE ?");
    $exists = $pdo->prepare("SELECT id_product FROM ps_product WHERE reference = ?");
    foreach ($rows as $code => $r) {
        $code = (string) $code;
        $name = strtoupper($r['Denumire']);
        if (str_starts_with($name, 'CASCA') && preg_match('/^(\d{7})(\d{2})$/', $code, $m)) {
            // ---- cască: model+culoare = primele 7 cifre
            $ref = $m[1];
            if (isset($out[$ref])) {
                continue;
            }
            $exists->execute([$ref]);
            if ($id = $exists->fetchColumn()) {
                $skipped[] = "{$code} {$r['Denumire']}: există produsul #{$id}";
                continue;
            }
            $coll = null;
            foreach (COLLECTIONS as $kw => $slugs) {
                if (str_contains(str_replace('-', ' ', $name), str_replace('-', ' ', $kw))) {
                    foreach ($slugs as $s) {
                        if ($coll = api_collection($s)) {
                            break 2;
                        }
                    }
                }
            }
            if (!$coll) {
                $skipped[] = "{$code} {$r['Denumire']}: colecția nu e pe araihelmet.eu";
                continue;
            }
            // designul: toate cuvintele din numele designului apar în denumirea din gestiune
            $nameWords = words(str_replace('RAICING', 'RACING', $name));
            $design = null;
            foreach ($coll['designs'] as $d) {
                $dw = array_diff(words((string) $d['name']), ['MATT']);
                if ($dw && !array_diff($dw, $nameWords) && (!$design || count($dw) > count(words($design['name'])))) {
                    $design = $d;
                }
            }
            if (!$design || empty($design['big_image']['original'])) {
                $skipped[] = "{$code} {$r['Denumire']}: designul nu e în colecția {$coll['name']}";
                continue;
            }
            $gest->execute([$ref . '%']);
            $sizes = [];
            foreach ($gest as $g) {
                $sz = substr((string) $g['codprodus'], 7);
                // doar mărimile cu stoc (regula Arai; și ferește de prețuri greșite pe coduri moarte:
                // Tour-X5 L fără stoc avea 22.551 lei în gestiune)
                if (isset(HELMET_SIZES[$sz]) && (int) $g['stoc'] > 0) {
                    $sizes[] = ['code' => (string) $g['codprodus'], 'size' => HELMET_SIZES[$sz], 'stoc' => (int) $g['stoc'], 'pret' => (float) $g['pret'],
                        'ean' => preg_match('/^\d{8,13}$/', (string) $g['cod_bare']) ? (string) $g['cod_bare'] : ''];
                }
            }
            $features = [];
            foreach ($coll['featuresPerCategory'] ?? [] as $cat) {
                foreach ($cat['items'] ?? [] as $it) {
                    $features[] = $it['title'] . ': ' . plain((string) ($it['description'] ?? ''));
                }
            }
            $out[$ref] = [
                'ref' => $ref, 'type' => 'casca', 'gestiune_name' => $r['Denumire'], 'collection' => $coll['name'], 'design' => $design['name'],
                'images' => [preg_replace('/\?.*/', '', $design['big_image']['original'])],
                'category' => str_contains($name, 'MX-V') ? CAT_HELMET['MX-V'] : CAT_HELMET['default'],
                'about_en' => plain((string) ($coll['translation']['about_description'] ?? $coll['translation']['description'] ?? '')),
                'features_en' => $features, 'sizes' => $sizes,
            ];
            echo "  cască {$ref}: {$coll['name']} / {$design['name']} · " . count($sizes) . " mărimi\n";
        } else {
            // ---- piesă: un singur cod, fără mărime
            $exists->execute([$code]);
            if ($id = $exists->fetchColumn()) {
                $skipped[] = "{$code} {$r['Denumire']}: există produsul #{$id}";
                continue;
            }
            $img = $src . '/Piese si accesorii Arai/' . $code . '.jpg';
            if (!is_file($img)) {
                $skipped[] = "{$code} {$r['Denumire']}: piesă fără poză";
                continue;
            }
            copy($img, "{$dir}/img/{$code}.jpg");
            $first = strtoupper(strtok($r['Denumire'], ' '));
            $out[$code] = [
                'ref' => $code, 'type' => 'piesa', 'gestiune_name' => $r['Denumire'], 'importer_name' => $partNames[$code] ?? '',
                'images' => [IMG_BASE . $code . '.jpg'], 'category' => CAT_PART[$first] ?? CAT_PART['default'],
                'sizes' => [['code' => $code, 'size' => 'One Size', 'stoc' => (int) $r['Stoc'], 'pret' => (float) $r['Pret'], 'ean' => (string) $r['Cod bare']]],
            ];
            echo "  piesă {$code}: {$r['Denumire']}\n";
        }
    }
    file_put_contents("{$dir}/extract.json", json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "\nProduse de creat: ", count($out), " → {$dir}/extract.json; poze de urcat: {$dir}/img/\n";
    if ($skipped) {
        echo "\nNu intră în CSV:\n  ", implode("\n  ", $skipped), "\n";
    }
}

/* ===================== 4. CSV ===================== */
if ($opt['csv']) {
    $ex = json_decode((string) @file_get_contents("{$dir}/extract.json"), true) ?: [];
    $ro = json_decode((string) @file_get_contents("{$dir}/ro.json"), true) ?: [];
    $file = "{$dir}/arai-" . date('Y-m-d') . '.csv';
    $h = fopen($file, 'w');
    fputcsv($h, [
        'Product Name', 'Product Reference Code', 'Combinations Reference Code',
        'Attribute Group 1', 'Attribute Value 1', 'Attribute Group 2', 'Attribute Value 2',
        'Description', 'Retail Price With Tax', 'Final Price With Tax', 'Category Default ID',
        'Product Image Urls', 'Active', 'EAN13', 'Supplier Reference', 'Stoc gestiune',
    ], ';', '"', '');
    $rows = 0;
    $missingRo = [];
    foreach ($ex as $ref => $p) {
        $ref = (string) $ref;
        if (empty($ro[$ref]['name']) || empty($ro[$ref]['description'])) {
            $missingRo[] = $ref;
            continue;
        }
        foreach ($p['sizes'] as $s) {
            fputcsv($h, [
                $ro[$ref]['name'], $ref, $s['code'], 'Color', 'DEFAULT', 'Size', $s['size'],
                $ro[$ref]['description'], number_format($s['pret'], 2, '.', ''), number_format($s['pret'], 2, '.', ''), $ro[$ref]['category'] ?? $p['category'],
                implode(',', $p['images']), 0, $s['ean'], $s['code'], $s['stoc'],
            ], ';', '"', '');
            $rows++;
        }
    }
    fclose($h);
    echo 'CSV: ', $file, ' · ', count($ex) - count($missingRo), " produse, {$rows} rânduri\n";
    if ($missingRo) {
        echo 'Fără traducere în ro.json (sărite): ', implode(', ', $missingRo), "\n";
    }
}
