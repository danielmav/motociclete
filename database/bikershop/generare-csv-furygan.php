<?php
declare(strict_types=1);

/**
 * Echipament Furygan din stoc care lipsește de pe BikerShop → CSV de import (formatul NOS / Dainese).
 *
 * Sursa = API-ul public WooCommerce Store al furygan.com (doar colecția curentă, ~210 modele):
 *   /wp-json/wc/store/v1/products?per_page=100&page=N  → modelul (sku 4 cifre), textele FR, categoria;
 *   /wp-json/wc/store/v1/products/<id variantă>        → culoarea (sku „model-culoare"), imaginile ei.
 * Codul din gestiune = model (4 cifre) + culoare + mărime: `4555143L` = 4555 / 143 / L → varianta `4555-143`.
 * Un produs BikerShop = model + culoare (referința `4555143`), variante = TOATE codurile acelui model+culoare
 * din gestiune (`stocuri2024`, inclusiv cele fără stoc), cu EAN-ul și prețul (RON cu TVA) din gestiune.
 *
 * Pași (local, PHP 8.1 Laragon; BikerShop din .env):
 *   1. --extract --csv-stoc=<raport stoc-nelistat.php>  → storage/furygan/extract.json (textele FR + date)
 *   2. traducerea: storage/furygan/ro.json = { "<ref produs>": {"name": "...", "description": "<p>…</p>"} }
 *   3. --csv                                           → storage/furygan/furygan-<data>.csv (Active = 0)
 * Produsele care nu mai sunt pe furygan.com sunt doar listate (de tratat separat).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

const API = 'https://www.furygan.com/wp-json/wc/store/v1/products';
const EUR_RON = 5.25;
// „Ligne produit" de pe furygan.com → categoria implicită de pe BikerShop
const CAT_MAP = [
    'gants' => 756, 'blousons-et-vestes' => 787, 'pantalons' => 788, 'bottes-et-baskets' => 752, 'chaussures' => 752,
    'bottes' => 752, 'baskets' => 752, 'sacs' => 736, 'protections' => 777,
];
const CAT_FALLBACK = 808;   // „Inactive"
const SIZE_MAP = ['XXL' => '2XL', 'XXXL' => '3XL', 'XX' => '2XL'];

$root = dirname(__DIR__, 2);
$dir = $root . '/storage/furygan';
@mkdir($dir, 0775, true);

$opt = ['extract' => false, 'csv' => false, 'stoc' => null];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--extract') {
        $opt['extract'] = true;
    } elseif ($a === '--csv') {
        $opt['csv'] = true;
    } elseif (str_starts_with($a, '--csv-stoc=')) {
        $opt['stoc'] = substr($a, 11);
    } else {
        fwrite(STDERR, "Opțiune necunoscută: {$a}\n");
        exit(2);
    }
}

function http_json(string $url): ?array
{
    for ($try = 1; $try <= 3; $try++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 40,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (BikerShop catalog)']);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200 && is_string($body) && ($j = json_decode($body, true)) !== null) {
            return $j;
        }
        sleep(2 * $try);
    }
    return null;
}

/** Textul unei descrieri Elementor: paragrafe și puncte, fără markup. */
function plain(string $html): string
{
    $html = preg_replace('#<(script|style)[^>]*>.*?</\1>#s', '', $html);
    $t = preg_replace('#<(br|/p|/li|/h\d|/div)[^>]*>#i', "\n", $html);
    $t = preg_replace('#<li[^>]*>#i', '- ', $t);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace("/[ \t]+/", ' ', $t);
    return trim(preg_replace("/\n\s*\n+/", "\n", $t));
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

/** Mărimea de la finalul codului (după model+culoare), sau null. */
function size_of(string $rest): ?string
{
    $rest = strtoupper($rest);
    if ($rest === '') {
        return 'One Size';             // protecții, genți: codul = model + culoare
    }
    if (preg_match('/^\d$/', $rest)) {
        return $rest;                  // protecția de spate Fury D3O Evo: mărimile 6 / 7 / 8
    }
    if (in_array($rest, ['XS', 'S', 'M', 'L', 'XL', 'XXL', '2XL', 'XXXL', '3XL', '4XL', 'XXS'], true) || preg_match('/^\d{2}$/', $rest)) {
        return SIZE_MAP[$rest] ?? $rest;
    }
    return null;
}

/* ===================== 1. extragere ===================== */
if ($opt['extract']) {
    if (!$opt['stoc'] || !is_file($opt['stoc'])) {
        fwrite(STDERR, "Lipsește --csv-stoc=<raport stoc-nelistat.php>\n");
        exit(2);
    }
    $pdo = bs_pdo($root);

    // codurile Furygan lipsă din raport
    $h = fopen($opt['stoc'], 'r');
    $head = fgetcsv($h, 0, ';', '"', '');
    $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
    $missing = [];
    while (($r = fgetcsv($h, 0, ';', '"', '')) !== false) {
        $r = array_combine($head, $r);
        if ($r['Marca'] === 'FURYGAN' && $r['Stare pe site'] === 'lipsește complet') {
            $missing[strtoupper($r['Cod gestiune'])] = $r;
        }
    }
    fclose($h);

    // catalogul curent
    $catalog = [];
    for ($page = 1; $page <= 10; $page++) {
        $list = http_json(API . "?per_page=100&page={$page}");
        if (!$list) {
            break;
        }
        foreach ($list as $p) {
            $catalog[(string) $p['sku']] = $p;
        }
        if (count($list) < 100) {
            break;
        }
    }
    echo 'Catalog furygan.com: ', count($catalog), " modele\n";

    // model + culoare: modelul = primele 4 cifre; culoarea = restul codului fără mărime
    $groups = [];
    $notOnSite = [];
    foreach ($missing as $code => $r) {
        $code = (string) $code;
        $model = substr($code, 0, 4);
        if (!isset($catalog[$model])) {
            $notOnSite[$model][] = $code . ' ' . $r['Denumire'];
            continue;
        }
        $groups[$model][] = $code;
    }

    $out = [];
    foreach ($groups as $model => $codes) {
        $p = $catalog[$model];
        // variantele de culoare ale modelului (sku „model-culoare")
        $colors = [];
        foreach ($p['variations'] as $v) {
            $vd = http_json(API . '/' . (int) $v['id']);
            usleep(300000);
            if (!$vd || !preg_match('/^' . $model . '-(\w+)$/', (string) $vd['sku'], $m)) {
                continue;
            }
            $colors[$m[1]] = [
                'slug' => $v['attributes'][0]['value'] ?? '',
                'images' => array_values(array_map(fn ($i) => $i['src'], $vd['images'] ?? [])),
                'price_eur' => ((int) ($vd['prices']['regular_price'] ?? $p['prices']['regular_price'])) / 100,
            ];
        }
        $colorNames = [];
        foreach ($p['attributes'] as $a) {
            if (($a['taxonomy'] ?? '') === 'pa_coloris') {
                foreach ($a['terms'] as $t) {
                    $colorNames[$t['slug']] = $t['name'];
                }
            }
        }
        $line = '';
        foreach ($p['attributes'] as $a) {
            if (($a['taxonomy'] ?? '') === 'pa_ligne-produit') {
                $line = $a['terms'][0]['slug'] ?? '';
            }
        }

        // culoarea codului din gestiune = cel mai lung cod de culoare de pe site care se potrivește
        $byColor = [];
        foreach ($codes as $code) {
            $code = (string) $code;
            $rest = substr($code, 4);
            $hit = null;
            foreach (array_keys($colors) as $c) {
                if (str_starts_with($rest, (string) $c) && size_of(substr($rest, strlen((string) $c))) !== null
                    && ($hit === null || strlen((string) $c) > strlen((string) $hit))) {
                    $hit = (string) $c;
                }
            }
            if ($hit === null) {
                $notOnSite[$model][] = $code . ' ' . $missing[$code]['Denumire'] . ' (culoare indisponibilă pe site)';
                continue;
            }
            $byColor[$hit] = true;
        }

        foreach (array_keys($byColor) as $c) {
            $c = (string) $c;
            $ref = $model . $c;
            // toate mărimile acestui model+culoare din gestiune (cu sau fără stoc)
            $st = $pdo->prepare("SELECT codprodus, denumire, stoc, pret, cod_bare FROM stocuri2024 WHERE codprodus LIKE ?");
            $st->execute([$ref . '%']);
            $sizes = [];
            foreach ($st as $g) {
                $size = size_of(substr(strtoupper(trim((string) $g['codprodus'])), strlen($ref)));
                if ($size === null) {
                    continue;
                }
                $k = strtoupper(trim((string) $g['codprodus']));
                $sizes[$k] = ['code' => $k, 'size' => $size, 'stoc' => ($sizes[$k]['stoc'] ?? 0) + (int) $g['stoc'], 'pret' => (float) $g['pret'],
                    'ean' => preg_match('/^\d{8,13}$/', (string) $g['cod_bare']) ? (string) $g['cod_bare'] : '', 'denumire' => (string) $g['denumire']];
            }
            // două coduri pe aceeași mărime (`45888XXL` cu stoc, `458882XL` fără) → unul singur:
            // cel cu stoc, apoi cel cu EAN
            $bySize = [];
            foreach ($sizes as $k => $sz) {
                $cur = $bySize[$sz['size']] ?? null;
                if ($cur === null || [$sz['stoc'] > 0, $sz['ean'] !== ''] > [$sizes[$cur]['stoc'] > 0, $sizes[$cur]['ean'] !== '']) {
                    $bySize[$sz['size']] = $k;
                }
            }
            $sizes = array_intersect_key($sizes, array_flip($bySize));
            $exists = $pdo->prepare("SELECT id_product FROM ps_product WHERE reference = ?");
            $exists->execute([$ref]);
            if ($id = $exists->fetchColumn()) {
                echo "  {$ref}: există deja produsul #{$id} (sărit)\n";
                continue;
            }
            $out[$ref] = [
                'ref' => $ref, 'model' => $model, 'color_code' => $c, 'name_fr' => $p['name'],
                'color_fr' => $colorNames[$colors[$c]['slug']] ?? $colors[$c]['slug'], 'line' => $line,
                'category' => CAT_MAP[$line] ?? CAT_FALLBACK, 'url' => $p['permalink'],
                'images' => $colors[$c]['images'], 'price_eur' => $colors[$c]['price_eur'],
                'short_fr' => plain((string) $p['short_description']), 'desc_fr' => plain((string) $p['description']),
                'gestiune_name' => reset($sizes)['denumire'] ?? '', 'sizes' => array_values($sizes),
            ];
            echo "  {$ref}: {$p['name']} · " . ($colorNames[$colors[$c]['slug']] ?? $c) . ' · ' . count($sizes) . ' mărimi · '
                . count($colors[$c]['images']) . " imagini · {$line}\n";
        }
    }
    file_put_contents("{$dir}/extract.json", json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "\nProduse de creat: ", count($out), " → {$dir}/extract.json\n";
    echo "\nNu sunt pe furygan.com (sau culoarea nu mai e):\n";
    foreach ($notOnSite as $m => $list) {
        foreach ($list as $l) {
            echo "  {$l}\n";
        }
    }
}

/* ===================== 3. CSV ===================== */
if ($opt['csv']) {
    $ex = json_decode((string) @file_get_contents("{$dir}/extract.json"), true) ?: [];
    $ro = json_decode((string) @file_get_contents("{$dir}/ro.json"), true) ?: [];
    $file = "{$dir}/furygan-" . date('Y-m-d') . '.csv';
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
        if (empty($ro[$ref]['name']) || empty($ro[$ref]['description'])) {
            $missingRo[] = $ref;
            continue;
        }
        foreach ($p['sizes'] as $s) {
            $price = $s['pret'] > 0 ? $s['pret'] : $p['price_eur'] * EUR_RON;
            fputcsv($h, [
                $ro[$ref]['name'], $ref, $s['code'], 'Color', 'DEFAULT', 'Size', $s['size'],
                $ro[$ref]['description'], number_format($price, 2, '.', ''), number_format($price, 2, '.', ''), $ro[$ref]['category'] ?? $p['category'],
                implode(',', $p['images']), 0, $s['ean'], $p['model'] . '-' . $p['color_code'],
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
