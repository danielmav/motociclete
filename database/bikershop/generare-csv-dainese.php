<?php
declare(strict_types=1);

/**
 * Echipament Dainese / AGV / Momodesign din stoc care lipsește de pe BikerShop → CSV de import
 * (formatul NOS / Furygan). De folosit ori de câte ori raportul stoc-nelistat.php arată coduri noi.
 *
 * Surse:
 *   - gestiunea (`stocuri2024`): codurile reale ale variantelor, stocul, prețul RON cu TVA, EAN-ul;
 *   - feedul B2B Dainese (`dainese2026_b2b`): codul de model (`cod`), mărimea, EAN-ul, categoria,
 *     prin `cod_globe` = cod de model + mărime (`18395015012M`); conține și modele ieșite din ofertă;
 *   - pagina oficială: `Product-Show?pid=21<model>` pe agv.com (AGV, Momodesign), `20<model>` pe
 *     dainese.com → numele, descrierea (EN) și imaginile. Fără pagină = produsul nu intră în CSV,
 *     e doar raportat (poze de cerut de la importator / făcute în showroom).
 *
 * Pași (local, PHP 8.1 Laragon; BikerShop din .env):
 *   1. --extract --csv-stoc=<raport stoc-nelistat.php>   (sau --codes=COD,COD — coduri din gestiune)
 *        → storage/dainese/extract.json (texte EN + date)
 *   2. traducerea: storage/dainese/ro.json = { "<model>": {"name": "...", "description": "<p>…</p>", "category"?: id} }
 *   3. --csv → storage/dainese/dainese-<data>.csv (Active = 0)
 * Un produs BikerShop = un model (cod B2B, ex. `18381001018`), variante = codurile lui din gestiune
 * (cu sau fără stoc); referința variantei = codul din gestiune, deci sincronizarea orară
 * (globe/stoc-globe.php) le leagă singură de stoc și preț după import.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

const EUR_RON = 5.25;
const SITES = [
    ['host' => 'https://www.agv.com', 'site' => 'Sites-agv-row-Site', 'prefix' => '21'],
    ['host' => 'https://www.dainese.com', 'site' => 'Sites-dainese-row-Site', 'prefix' => '20'],
];
// categoria din feedul B2B (prefix, fără diacritice) → categoria implicită de pe BikerShop
const CAT_MAP = [
    'Helmets full face' => 765, 'Helmets modular' => 766, 'Helmets flip up' => 766, 'Helmets jet' => 768, 'Helmets open face' => 768,
    'Helmets crossover' => 764, 'Helmets off road' => 767,
    'Jackets' => 787, 'Leather jackets' => 787, 'Vests' => 791, 'Suits' => 790, 'Leather suits' => 790, 'Pants' => 788, 'Leather pants' => 788,
    'Gloves' => 756, 'Boots' => 752, 'Shoes' => 752, 'Back protectors' => 777, 'Chest protectors' => 777, 'Protectors' => 781,
    'Airbag' => 777, 'Base layers' => 759, 'Technical layers' => 759, 'Rain' => 789, 'Bags' => 736, 'Backpacks' => 735,
    'T-shirts' => 743, 'Sweatshirts' => 741, 'Caps' => 739, 'Neck' => 760,
];
const CAT_FALLBACK = 808;   // „Inactive" — de pus manual
// producătorii de pe BikerShop (coloana ID_Manufacturer din CSV)
const MANUFACTURERS = ['DAINESE' => 24, 'AGV' => 120, 'TCX' => 140, 'MOMODESIGN' => 141, 'MOMO' => 141];

/** ID-ul producătorului: marca din feedul B2B, altfel din nume (Momodesign / TCX) și site (agv.com → AGV). */
function manufacturer_id(string $b2bBrand, string $name, string $url): int
{
    $b = strtoupper(trim($b2bBrand));
    if (isset(MANUFACTURERS[$b])) {
        return MANUFACTURERS[$b];
    }
    $n = strtoupper($name);
    if (str_contains($n, 'MOMODESIGN') || str_contains($n, 'MOMO DESIGN')) {
        return MANUFACTURERS['MOMODESIGN'];
    }
    if (preg_match('/\bTCX\b/', $n)) {
        return MANUFACTURERS['TCX'];
    }
    return str_contains($url, 'agv.com') ? MANUFACTURERS['AGV'] : MANUFACTURERS['DAINESE'];
}
// sufixul de mărime din codul de gestiune (litere, codurile Dainese XX/3X/X2/N, numere 2 cifre)
const SIZE_RE = '/^(.+?)(XXS|XS|XXXL|XXL|XL|XX|3XL|3X|X2|S|M|L|N|\d{2})$/';
const SIZE_MAP = ['XX' => '2XL', '3X' => '3XL', 'X2' => 'X/2X', 'N' => 'One Size', 'XXL' => '2XL', 'XXXL' => '3XL'];

$root = dirname(__DIR__, 2);
$dir = $root . '/storage/dainese';
@mkdir($dir, 0775, true);

$opt = ['extract' => false, 'csv' => false, 'stoc' => null, 'codes' => []];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--extract') {
        $opt['extract'] = true;
    } elseif ($a === '--csv') {
        $opt['csv'] = true;
    } elseif (str_starts_with($a, '--csv-stoc=')) {
        $opt['stoc'] = substr($a, 11);
    } elseif (str_starts_with($a, '--codes=')) {
        $opt['codes'] = array_filter(array_map(fn ($c) => strtoupper(trim($c)), explode(',', substr($a, 8))));
    } else {
        fwrite(STDERR, "Opțiune necunoscută: {$a}\n");
        exit(2);
    }
}

function http_get(string $url, ?string &$finalUrl = null): ?string
{
    for ($try = 1; $try <= 3; $try++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 40, CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', CURLOPT_HTTPHEADER => ['Accept-Language: en-US,en;q=0.9']]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
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

/** Pagina oficială a modelului: [url, nume, descriere EN, imagini] sau null (modelul nu mai e în ofertă). */
function official_page(string $model): ?array
{
    foreach (SITES as $s) {
        $final = '';
        $html = http_get("{$s['host']}/on/demandware.store/{$s['site']}/en_RO/Product-Show?pid={$s['prefix']}{$model}", $final);
        usleep(400000);
        // modelele scoase din ofertă redirecționează spre categorie (fără .html)
        if ($html === null || !str_ends_with(parse_url($final, PHP_URL_PATH) ?? '', '.html')) {
            continue;
        }
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);   // altfel DOMDocument citește ISO-8859-1
        $x = new DOMXPath($dom);
        $h1 = $x->query('//h1')->item(0);
        $desc = '';
        $node = $x->query("//div[@id='details']//div[contains(@class,'offcanvas-body')]")->item(0);
        if ($node) {
            $parts = [];
            foreach ($x->query('.//p|.//li', $node) as $p) {
                $t = clean($p->textContent);
                if ($t !== '' && !preg_match('/^Item:\s*\w+$/i', $t)) {
                    $parts[] = ($p->nodeName === 'li' ? '- ' : '') . $t;
                }
            }
            $desc = $parts ? implode("\n", array_unique($parts)) : preg_replace('/Item:\s*[A-Z0-9]+/i', '', clean($node->textContent));
        }
        $images = [];
        foreach ($x->query("//div[contains(@class,'primary-images')]//img") as $img) {
            $src = $img->getAttribute('data-src') ?: $img->getAttribute('src');
            if ($src && str_contains($src, 'dainese-cdn')) {
                $images[] = preg_replace(['#/\d+x\d+/#', '/\?.*/'], ['/1920xundefined/', ''], $src);
            }
        }
        return ['url' => $final, 'name_en' => $h1 ? clean($h1->textContent) : '', 'desc_en' => $desc, 'images' => array_values(array_unique($images))];
    }
    return null;
}

/* ===================== 1. extragere ===================== */
if ($opt['extract']) {
    $pdo = bs_pdo($root);

    // codurile de tratat: din raport (AGV / DAINESE / MOMO, „lipsește complet") sau din --codes
    $codes = $opt['codes'];
    if ($opt['stoc']) {
        $h = fopen($opt['stoc'], 'r');
        $head = fgetcsv($h, 0, ';', '"', '');
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
        while (($r = fgetcsv($h, 0, ';', '"', '')) !== false) {
            $r = array_combine($head, $r);
            if (in_array($r['Marca'], ['AGV', 'DAINESE', 'MOMO'], true) && $r['Stare pe site'] === 'lipsește complet') {
                $codes[] = strtoupper($r['Cod gestiune']);
            }
        }
        fclose($h);
    }
    $codes = array_values(array_unique($codes));
    if (!$codes) {
        fwrite(STDERR, "Niciun cod: --csv-stoc=<raport> sau --codes=…\n");
        exit(2);
    }

    // codul de model din feedul B2B
    $b2bByGlobe = [];
    foreach (array_chunk($codes, 400) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("SELECT * FROM dainese2026_b2b WHERE cod_globe IN ($in)");
        $st->execute($chunk);
        foreach ($st as $r) {
            $b2bByGlobe[strtoupper($r['cod_globe'])] = $r;
        }
    }
    // rezervă fără B2B: codul de model = codul din gestiune fără sufixul de mărime
    // (`18381001018M` → `18381001018`, `1770001121G46` → `1770001121G`, `1D20041001L` → `1D20041001`)
    $models = [];
    $noModel = [];
    foreach ($codes as $c) {
        $c = (string) $c;
        if (isset($b2bByGlobe[$c])) {
            $models[strtoupper($b2bByGlobe[$c]['cod'])][] = $c;
        } elseif (preg_match(SIZE_RE, $c, $m) && strlen($m[1]) >= 8) {
            $models[$m[1]][] = $c;
        } else {
            $models[$c][] = $c;   // cod fără mărime (kituri, accesorii): modelul e chiar codul
        }
    }
    echo count($codes), ' coduri → ', count($models), " modele\n\n";

    $out = [];
    $noPage = [];
    $exists = $pdo->prepare("SELECT id_product FROM ps_product WHERE reference = ?");
    $b2bModel = $pdo->prepare("SELECT * FROM dainese2026_b2b WHERE cod = ?");
    $gest = $pdo->prepare("SELECT codprodus, stoc, pret, cod_bare FROM stocuri2024 WHERE codprodus = ?");
    foreach ($models as $model => $mcodes) {
        $model = (string) $model;
        $exists->execute([$model]);
        if ($id = $exists->fetchColumn()) {
            echo "  {$model}: există deja produsul #{$id} (sărit — mărimile lipsă se rezolvă cu marimi-lipsa.php)\n";
            continue;
        }
        $b2bModel->execute([$model]);
        $rows = $b2bModel->fetchAll();
        $gestName = '';
        if (!$rows) {
            // fără B2B: variantele = codurile din gestiune „model + mărime" (sau codul simplu)
            $st = $pdo->prepare("SELECT codprodus, denumire FROM stocuri2024 WHERE codprodus LIKE ?");
            $st->execute([addcslashes($model, '%_') . '%']);
            foreach ($st as $g) {
                $gc = strtoupper(trim((string) $g['codprodus']));
                $rest = substr($gc, strlen($model));
                if ($rest === '' || preg_match('/^(XXS|XS|XXXL|XXL|XL|XX|3XL|3X|X2|S|M|L|N|\d{2})$/', $rest)) {
                    $rows[$gc] = ['cod_globe' => $gc, 'size' => $rest === '' ? 'N' : $rest, 'ean' => '', 'pret' => 0, 'nume' => $g['denumire'],
                        'brand' => '', 'color_name' => '', 'gender' => '', 'category' => '', 'pret_euro' => 0];
                    $gestName = (string) $g['denumire'];
                }
            }
            $rows = array_values($rows);
            if (!$rows) {
                $noPage[] = "{$model} (codurile din gestiune nu au forma model + mărime)";
                continue;
            }
        }
        $first = $rows[0];
        $page = official_page($model);
        if (!$page || !$page['images']) {
            $noPage[] = "{$model} {$first['nume']}" . ($page ? ' (pagină fără imagini)' : '');
            continue;
        }
        if ($first['category'] === '') {
            // categoria după primul cuvânt din denumirea din gestiune
            $w = strtoupper(strtok($gestName, ' '));
            $first['category'] = ['CASCA' => 'Helmets full face', 'GHETE' => 'Shoes', 'CIZME' => 'Boots', 'MANUSI' => 'Gloves', 'JACHETA' => 'Jackets',
                'GEACA' => 'Jackets', 'PANTALONI' => 'Pants', 'VESTA' => 'Vests', 'COTIERE' => 'Protectors'][$w] ?? '';
        }
        // variantele = codurile modelului din gestiune (cu sau fără stoc), mărimea și EAN-ul din B2B
        $sizes = [];
        foreach ($rows as $b) {
            $gest->execute([$b['cod_globe']]);
            $g = $gest->fetchAll();
            if (!$g) {
                continue;
            }
            $stoc = array_sum(array_map(fn ($r) => (int) $r['stoc'], $g));
            $ean = preg_match('/^\d{8,13}$/', (string) $g[0]['cod_bare']) ? (string) $g[0]['cod_bare'] : (string) $b['ean'];
            $sizes[] = ['code' => strtoupper($b['cod_globe']), 'size' => SIZE_MAP[strtoupper($b['size'])] ?? strtoupper($b['size']), 'stoc' => $stoc,
                'pret' => (float) $g[0]['pret'] ?: (float) $b['pret'], 'ean' => $ean];
        }
        $cat = CAT_FALLBACK;
        foreach (CAT_MAP as $k => $v) {
            if (stripos((string) $first['category'], $k) === 0) {
                $cat = $v;
                break;
            }
        }
        $out[$model] = [
            'ref' => $model, 'brand' => $first['brand'], 'id_manufacturer' => manufacturer_id((string) $first['brand'], $page['name_en'] . ' ' . $first['nume'], $page['url']),
            'b2b_name' => $first['nume'], 'color' => $first['color_name'], 'gender' => $first['gender'],
            'b2b_category' => $first['category'], 'category' => $cat, 'price_eur' => (float) $first['pret_euro'],
            'url' => $page['url'], 'name_en' => $page['name_en'], 'desc_en' => $page['desc_en'], 'images' => $page['images'], 'sizes' => $sizes,
        ];
        echo "  {$model}: {$page['name_en']} · {$first['color_name']} · " . count($sizes) . ' mărimi · ' . count($page['images']) . " imagini · {$first['category']} → {$cat}\n";
    }
    file_put_contents("{$dir}/extract.json", json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "\nProduse de creat: ", count($out), " → {$dir}/extract.json\n";
    if ($noPage) {
        echo "\nFără pagină oficială (modele ieșite din ofertă — nu intră în CSV):\n  ", implode("\n  ", $noPage), "\n";
    }
    if ($noModel) {
        echo "\nCoduri fără model în feedul B2B (piese, kituri, modele vechi):\n  ", implode(', ', $noModel), "\n";
    }
}

/* ===================== 3. CSV ===================== */
if ($opt['csv']) {
    $ex = json_decode((string) @file_get_contents("{$dir}/extract.json"), true) ?: [];
    $ro = json_decode((string) @file_get_contents("{$dir}/ro.json"), true) ?: [];
    $file = "{$dir}/dainese-" . date('Y-m-d') . '.csv';
    $h = fopen($file, 'w');
    fputcsv($h, [
        'Product Name', 'Product Reference Code', 'Combinations Reference Code',
        'Attribute Group 1', 'Attribute Value 1', 'Attribute Group 2', 'Attribute Value 2',
        'Description', 'Retail Price With Tax', 'Final Price With Tax', 'Category Default ID',
        'Product Image Urls', 'Active', 'EAN13', 'Supplier Reference',
        'Stoc gestiune', 'Pret B2B EUR', 'Sursa pret', 'ID_Manufacturer',
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
                implode(',', $p['images']), 0, $s['ean'], $s['code'],
                $s['stoc'], number_format($p['price_eur'], 2, '.', ''), $s['pret'] > 0 ? 'gestiune' : 'B2B',
                $p['id_manufacturer'] ?? manufacturer_id((string) ($p['brand'] ?? ''), (string) ($p['name_en'] ?? ''), (string) ($p['url'] ?? '')),
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
