<?php
declare(strict_types=1);

/**
 * Echipament Kenny din stoc care lipsește de pe BikerShop → CSV de import (formatul NOS / Furygan / Dainese).
 *
 * Kenny NU e importat de Dual Tours: marfa e a importatorului partener, ținută în showroomul din Pipera.
 *
 * Sursa = kenny-racing.com (PrestaShop): căutarea lor e aproximativă (și după cod, și după EAN), iar
 * paginile nu expun EAN-ul → se parcurge tot catalogul din sitemap-ul EN și se citește referința
 * produsului din `data-product` (`251-0405021` = sezon 251 + cod). Codul din gestiune e aceeași
 * referință fără liniuță + mărimea: `2510405021L`. Kenny schimbă codul în fiecare sezon, deci un
 * articol dintr-un sezon care nu mai e pe site NU se găsește (e raportat).
 *
 * Pași (local, PHP 8.1 Laragon; BikerShop din .env):
 *   1. --crawl            → storage/kenny/catalog.json (paginile în storage/kenny/pages/, refolosite 7 zile)
 *   2. --extract --csv-stoc=<raport stoc-nelistat.php>  (sau --codes=…) → storage/kenny/extract.json
 *   3. traducerea: storage/kenny/ro.json = { "<model>": {"name": "...", "description": "<p>…</p>", "category"?: id} }
 *   4. --csv              → storage/kenny/kenny-<data>.csv (Active = 0)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

const SITE = 'https://kenny-racing.com';
const EUR_RON = 5.25;
const SIZE_RE = '/^(.+?)(XXS|XS|XXXL|XXL|XL|S|M|L|\d{2})$/';
const SIZE_MAP = ['XXL' => '2XL', 'XXXL' => '3XL'];
// primul cuvânt din denumirea din gestiune → categoria implicită pe BikerShop (off-road)
const CAT_MAP = ['CASCA' => 767, 'PANTALONI' => 773, 'TRICOU' => 772, 'JERSEU' => 772, 'MANUSI' => 755, 'CIZME' => 749,
    'GHETE' => 749, 'OCHELARI' => 746, 'GEACA' => 774, 'JACHETA' => 774, 'VESTA' => 774, 'CIORAPI' => 751, 'SOSETE' => 751,
    'GENUNCHIERE' => 779, 'PROTECTIE' => 777, 'HANORAC' => 741, 'SAPCA' => 739, 'RUCSAC' => 735, 'GEANTA' => 736];
const CAT_FALLBACK = 808;

$root = dirname(__DIR__, 2);
$dir = $root . '/storage/kenny';
@mkdir($dir . '/pages', 0775, true);

$opt = ['crawl' => false, 'extract' => false, 'csv' => false, 'stoc' => null, 'codes' => []];
foreach (array_slice($argv, 1) as $a) {
    if (in_array($a, ['--crawl', '--extract', '--csv'], true)) {
        $opt[substr($a, 2)] = true;
    } elseif (str_starts_with($a, '--csv-stoc=')) {
        $opt['stoc'] = substr($a, 11);
    } elseif (str_starts_with($a, '--codes=')) {
        $opt['codes'] = array_filter(array_map(fn ($c) => strtoupper(trim($c)), explode(',', substr($a, 8))));
    } else {
        fwrite(STDERR, "Opțiune necunoscută: {$a}\n");
        exit(2);
    }
}

function http_get(string $url): ?string
{
    for ($try = 1; $try <= 3; $try++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 40, CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', CURLOPT_HTTPHEADER => ['Accept-Language: en-US,en;q=0.9']]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code === 200 && is_string($body)) {
            return $body;
        }
        if ($code === 404) {
            return null;
        }
        sleep(2 * $try);
    }
    return null;
}

function clean(string $t): string
{
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
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

/** Datele unei pagini de produs: referința de model, nume, descriere, imagini. */
function parse_product(string $html, string $url): ?array
{
    if (!preg_match('/data-product="([^"]+)"/', $html, $m)) {
        return null;
    }
    $data = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
    if (!is_array($data) || empty($data['reference'])) {
        return null;
    }
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $x = new DOMXPath($dom);
    $desc = [];
    foreach (['description_short', 'description'] as $k) {
        $t = trim(strip_tags(str_replace(['</p>', '<br>', '<br />', '</li>'], "\n", (string) ($data[$k] ?? ''))));
        if ($t !== '') {
            $desc[] = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    $images = [];
    foreach ((array) ($data['images'] ?? []) as $img) {
        $src = $img['bySize']['extra_large']['url'] ?? ($img['large']['url'] ?? '');
        if ($src) {
            $images[] = $src;
        }
    }
    if (!$images) {
        foreach ($x->query('//meta[@property="og:image"]') as $n) {
            $images[] = $n->getAttribute('content');
        }
    }
    $model = strtoupper(preg_replace('/-\d{2,3}$/', '', (string) $data['reference']));   // `271-0303020-55` → model
    return [
        'ref' => $model, 'ref_plain' => str_replace('-', '', $model), 'name_en' => clean((string) ($data['name'] ?? '')),
        'desc_en' => trim(implode("\n", $desc)), 'images' => array_values(array_unique($images)), 'url' => $url,
        'price_eur' => (float) ($data['price_amount'] ?? 0),
    ];
}

/* ===================== 1. catalogul ===================== */
if ($opt['crawl']) {
    $sm = http_get(SITE . '/1_en_0_sitemap.xml');
    // <loc><![CDATA[https://kenny-racing.com/en/jackets/814-casaque-zippee-3661465013256.html]]></loc>
    preg_match_all('#<loc>(?:<!\[CDATA\[)?(' . preg_quote(SITE, '#') . '/en/[^<\]]*/\d+-[^<\]]*\.html)#', (string) $sm, $m);
    $urls = array_values(array_unique($m[1]));
    echo count($urls), " pagini de produs în sitemap\n";
    $catalog = [];
    foreach ($urls as $i => $url) {
        $file = $dir . '/pages/' . md5($url) . '.html';
        if (is_file($file) && filemtime($file) > time() - 7 * 86400) {
            $html = (string) file_get_contents($file);
        } else {
            $html = (string) http_get($url);
            file_put_contents($file, $html);
            usleep(500000);
        }
        $p = parse_product($html, $url);
        if ($p) {
            $catalog[$p['ref_plain']] ??= $p;   // un model poate avea mai multe URL-uri (categorii)
        }
        if (($i + 1) % 100 === 0) {
            echo '  ', $i + 1, ' / ', count($urls), ' · ', count($catalog), " modele\n";
        }
    }
    file_put_contents("{$dir}/catalog.json", json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo count($catalog), " modele → {$dir}/catalog.json\n";
}

/* ===================== 2. extragere ===================== */
if ($opt['extract']) {
    $catalog = json_decode((string) @file_get_contents("{$dir}/catalog.json"), true);
    if (!$catalog) {
        fwrite(STDERR, "Lipsește catalogul: rulează întâi --crawl\n");
        exit(2);
    }
    $pdo = bs_pdo($root);
    $codes = $opt['codes'];
    if ($opt['stoc']) {
        $h = fopen($opt['stoc'], 'r');
        $head = fgetcsv($h, 0, ';', '"', '');
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
        while (($r = fgetcsv($h, 0, ';', '"', '')) !== false) {
            $r = array_combine($head, $r);
            if ($r['Marca'] === 'KENNY' && $r['Stare pe site'] === 'lipsește complet') {
                $codes[] = strtoupper($r['Cod gestiune']);
            }
        }
        fclose($h);
    }
    $models = [];
    foreach (array_unique($codes) as $c) {
        $c = (string) $c;
        $models[preg_match(SIZE_RE, $c, $m) && strlen($m[1]) >= 8 ? $m[1] : $c][] = $c;
    }
    $out = [];
    $missing = [];
    $exists = $pdo->prepare("SELECT id_product FROM ps_product WHERE reference IN (?, ?)");
    $gest = $pdo->prepare("SELECT codprodus, denumire, stoc, pret, cod_bare FROM stocuri2024 WHERE codprodus LIKE ?");
    foreach ($models as $model => $mcodes) {
        $model = (string) $model;
        $dashed = strlen($model) === 10 ? substr($model, 0, 3) . '-' . substr($model, 3) : $model;
        $exists->execute([$model, $dashed]);
        if ($id = $exists->fetchColumn()) {
            echo "  {$model}: există deja produsul #{$id} (sărit)\n";
            continue;
        }
        $gest->execute([addcslashes($model, '%_') . '%']);
        $sizes = [];
        $gname = '';
        foreach ($gest as $g) {
            $gc = strtoupper(trim((string) $g['codprodus']));
            $rest = substr($gc, strlen($model));
            if ($rest !== '' && !preg_match('/^(XXS|XS|XXXL|XXL|XL|S|M|L|\d{2})$/', $rest)) {
                continue;
            }
            $gname = (string) $g['denumire'];
            $sizes[$gc] = ['code' => $gc, 'size' => $rest === '' ? 'One Size' : (SIZE_MAP[$rest] ?? $rest), 'stoc' => ($sizes[$gc]['stoc'] ?? 0) + (int) $g['stoc'],
                'pret' => (float) $g['pret'], 'ean' => preg_match('/^\d{8,13}$/', (string) $g['cod_bare']) ? (string) $g['cod_bare'] : ''];
        }
        if (!isset($catalog[$model])) {
            $missing[] = "{$dashed} {$gname} (" . implode(', ', $mcodes) . ')';
            continue;
        }
        $p = $catalog[$model];
        $out[$model] = $p + ['gestiune_name' => $gname, 'category' => CAT_MAP[strtoupper(strtok($gname, ' '))] ?? CAT_FALLBACK, 'sizes' => array_values($sizes)];
        echo "  {$model}: {$p['name_en']} · " . count($sizes) . ' mărimi · ' . count($p['images']) . " imagini\n";
    }
    file_put_contents("{$dir}/extract.json", json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "\nProduse de creat: ", count($out), " → {$dir}/extract.json\n";
    if ($missing) {
        echo "\nNu sunt pe kenny-racing.com (sezon retras sau cod schimbat):\n  ", implode("\n  ", $missing), "\n";
    }
}

/* ===================== 4. CSV ===================== */
if ($opt['csv']) {
    $ex = json_decode((string) @file_get_contents("{$dir}/extract.json"), true) ?: [];
    $ro = json_decode((string) @file_get_contents("{$dir}/ro.json"), true) ?: [];
    $file = "{$dir}/kenny-" . date('Y-m-d') . '.csv';
    $h = fopen($file, 'w');
    fputcsv($h, [
        'Product Name', 'Product Reference Code', 'Combinations Reference Code',
        'Attribute Group 1', 'Attribute Value 1', 'Attribute Group 2', 'Attribute Value 2',
        'Description', 'Retail Price With Tax', 'Final Price With Tax', 'Category Default ID',
        'Product Image Urls', 'Active', 'EAN13', 'Supplier Reference',
        'Stoc gestiune', 'Pret site EUR', 'Sursa pret',
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
            $price = $s['pret'] > 0 ? $s['pret'] : $p['price_eur'] * EUR_RON;
            fputcsv($h, [
                $ro[$ref]['name'], $ref, $s['code'], 'Color', 'DEFAULT', 'Size', $s['size'],
                $ro[$ref]['description'], number_format($price, 2, '.', ''), number_format($price, 2, '.', ''), $ro[$ref]['category'] ?? $p['category'],
                implode(',', $p['images']), 0, $s['ean'], $p['ref'],
                $s['stoc'], number_format($p['price_eur'], 2, '.', ''), $s['pret'] > 0 ? 'gestiune' : 'site',
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
