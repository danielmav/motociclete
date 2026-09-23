<?php
/**
 * Generare CSV produse noi 2026 (Dainese / AGV / TCX) — o categorie pe rând.
 *
 * Copie pe server: ~/public_html/tool/generare-csv-produse-noi-2026.php
 *
 * Flux:
 *   ?step=N      → scrapează categoria N din $categories (default 0), scrie CSV-ul,
 *                  afișează progresul live și, la final, butonul „Descarcă CSV”.
 *   ?download=N  → trimite CSV-ul categoriei N către browser și ÎL ȘTERGE după trimitere.
 *   ?status=N    → JSON {"exists":bool} — pagina îl interoghează după click pe download;
 *                  când fișierul a dispărut, trece automat la ?step=N+1.
 *
 * Optimizări față de dainese_json.php:
 *   - referința (Product Reference Code) se derivă din codul din URL-ul produsului ÎNAINTE de a
 *     descărca pagina; dacă există deja în ps_product.reference, produsul e SĂRIT (fără fetch);
 *   - coloane noi la finalul CSV-ului: EAN13 (din dainese2026_b2b pe cod_globe = referință+mărime,
 *     feedul B2B) și Supplier Reference (codul original, nemodificat, din URL/pagină).
 * DB: require __connect.php ($conn, mysqli) — citire doar (ps_product, dainese2026_b2b).
 */

set_time_limit(0);

/* ================= CATEGORIES (în ordinea de rulare) ================= */

$DW  = "https://www.dainese.com/on/demandware.store/Sites-dainese-row-Site/en_RO/Search-UpdateGrid?cgid=";
$AGV = "https://www.agv.com/on/demandware.store/Sites-agv-row-Site/en_RO/Search-UpdateGrid?cgid=";
$TCX = "https://www.tcxboots.com/on/demandware.store/Sites-tcx-row-Site/en_RO/Search-UpdateGrid?cgid=";

$categories = [

    /* BARBATI (subcategoriile = exact reuniunea lui motorbike-men, verificat sept. 2026) */
    "men_jackets"       => $DW . "motorbike-men-jackets&start=0&sz=200",
    "men_pants"         => $DW . "motorbike-men-pants&start=0&sz=200",
    "men_leather_suits" => $DW . "motorbike-men-leather_suits&start=0&sz=200",
    "men_gloves"        => $DW . "motorbike-men-gloves&start=0&sz=200",
    "men_boots"         => $DW . "motorbike-men-boots&start=0&sz=200",
    "men_shoes"         => $DW . "motorbike-men-shoes&start=0&sz=200",
    "men_layers"        => $DW . "motorbike-men-technical_layers&start=0&sz=200",
    "men_casual"        => $DW . "motorbike-men-casual_wear&start=0&sz=200",

    /* FEMEI */
    "women_jackets"       => $DW . "motorbike-women-jackets&start=0&sz=200",
    "women_pants"         => $DW . "motorbike-women-pants&start=0&sz=200",
    "women_leather_suits" => $DW . "motorbike-women-leather_suits&start=0&sz=200",
    "women_gloves"        => $DW . "motorbike-women-gloves&start=0&sz=200",
    "women_boots"         => $DW . "motorbike-women-boots&start=0&sz=200",
    "women_shoes"         => $DW . "motorbike-women-shoes&start=0&sz=200",
    "women_layers"        => $DW . "motorbike-women-technical_layers&start=0&sz=200",
    "women_casual"        => $DW . "motorbike-women-casual_wear&start=0&sz=200",

    /* PROTECTII + ACCESORII — pe categoria parinte: subcategoriile NU acopera tot
       (protections 72 vs 68 in subcategorii, accessories 37 vs 19) */
    "protections" => $DW . "motorbike-protections&start=0&sz=200",
    "accessories" => $DW . "motorbike-accessories&start=0&sz=200",

    /* AGV — site propriu (Sites-agv-row-Site); cgid-urile vechi agv_helmets-* de pe
       dainese.com intorc un subset invechit (ex. open face 22 din 51).
       MOMO Design (FGTR Classic/EVO) e inclus in open_face. */
    "agv_full_face" => $AGV . "full_face&start=0&sz=200",
    "agv_flip_up"   => $AGV . "flip_up&start=0&sz=200",
    "agv_open_face" => $AGV . "open_face&start=0&sz=200",

    /* TCX — site propriu (Sites-tcx-row-Site) */
    "tcx_touring"     => $TCX . "tcx-touring&start=0&sz=200",
    "tcx_urban"       => $TCX . "tcx-urban&start=0&sz=200",
    "tcx_woman"       => $TCX . "tcx-woman&start=0&sz=200",
    "tcx_accessories" => $TCX . "tcx-accessories&start=0&sz=200",
];

$catNames = array_keys($categories);
$total    = count($catNames);

function csvFilename($catName){
    return __DIR__ . "/dainese_" . $catName . ".csv";
}

function stepIndex($key, $total){
    $n = isset($_GET[$key]) ? (int)$_GET[$key] : 0;
    return ($n >= 0 && $n < $total) ? $n : -1;
}

/* ================= MODE: download (trimite + șterge) ================= */

if (isset($_GET['download'])) {
    $n = stepIndex('download', $total);
    $file = $n >= 0 ? csvFilename($catNames[$n]) : '';

    if ($n < 0 || !is_file($file)) {
        http_response_code(404);
        header("Content-Type: text/plain; charset=utf-8");
        echo "Fisierul CSV nu exista (a fost deja descarcat si sters?).";
        exit;
    }

    while (ob_get_level()) ob_end_clean();

    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"" . basename($file) . "\"");
    header("Content-Length: " . filesize($file));
    header("Cache-Control: no-store");
    header("Pragma: no-cache");

    readfile($file);
    flush();

    // fișierul a plecat spre browser → îl ștergem
    @unlink($file);
    exit;
}

/* ================= MODE: status (JSON) ================= */

if (isset($_GET['status'])) {
    $n = stepIndex('status', $total);
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    echo json_encode([
        "exists" => $n >= 0 && is_file(csvFilename($catNames[$n])),
    ]);
    exit;
}

/* ================= HELPERS (scraping) ================= */

function getHTML($url){

    $ch = curl_init();

    curl_setopt_array($ch,[
        CURLOPT_URL=>$url,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_USERAGENT=>"Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
        CURLOPT_HTTPHEADER => [
            "Accept-Language: en-US,en;q=0.9"
        ],
        CURLOPT_ENCODING => ""
    ]);

    $html = curl_exec($ch);
    curl_close($ch);

    return $html;
}

function cleanText($t){
    return trim(preg_replace('/\s+/',' ',$t));
}

function normalizeTitle($text){

    $text = strtolower($text);
    $text = ucwords($text);

    $replacements = [
        "D-air" => "D-Air",
        "Gtx" => "GTX",
        "Evo" => "EVO",
        "Pro" => "Pro",
        "Air" => "Air",
        "Drystar" => "D-Dry",
        "D-dry" => "D-Dry"
    ];

    foreach($replacements as $bad => $good){
        $text = str_replace($bad, $good, $text);
    }

    return $text;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Codul original din URL-ul produsului (…-2118469001003.html → 2118469001003) sau ''. */
function urlCode($link){
    return preg_match('/-([A-Za-z0-9]{6,})\.html(?:\?.*)?$/', $link, $m) ? strtoupper($m[1]) : '';
}

/** Referința PrestaShop din codul original: primele 2 caractere tăiate (ca în dainese_json.php). */
function refFromCode($code){
    return strlen($code) > 5 ? substr($code, 2) : $code;
}

/* ================= DB (read-only) ================= */

function productExists(mysqli $conn, $reference){
    static $st = null;
    if ($st === null) $st = $conn->prepare("SELECT id_product FROM ps_product WHERE reference = ? LIMIT 1");
    $st->bind_param("s", $reference);
    $st->execute();
    $st->bind_result($id);
    $found = $st->fetch() ? (int)$id : 0;
    $st->free_result();
    return $found;
}

/** Mărimea de pe site → codul de mărime din feedul B2B (dainese2026_b2b.size). */
function b2bSize($size){
    $s = strtoupper(trim($size));
    $map = [
        'ONE SIZE' => 'N', 'ONESIZE' => 'N', 'OS' => 'N', 'TU' => 'N', 'UNI' => 'N', 'U' => 'N',
        'XXL' => 'XX', '2XL' => 'XX', 'XXXL' => '3X', '3XL' => '3X',
        'M/S' => 'MS', 'M-S' => 'MS', 'M/L' => 'ML', 'M-L' => 'ML',
    ];
    return $map[$s] ?? $s;
}

/** EAN din feedul B2B: exact pe cod_globe (referință+mărime B2B); fallback pe cod dacă are un singur EAN. */
function eanFor(mysqli $conn, $baseSKU, $size){
    static $stG = null, $stC = null;
    if ($stG === null) {
        $stG = $conn->prepare("SELECT ean FROM dainese2026_b2b WHERE cod_globe = ? AND ean <> '' LIMIT 1");
        $stC = $conn->prepare("SELECT DISTINCT ean FROM dainese2026_b2b WHERE cod = ? AND ean <> '' LIMIT 2");
    }
    $codGlobe = $baseSKU . b2bSize($size);
    $stG->bind_param("s", $codGlobe);
    $stG->execute();
    $stG->bind_result($ean);
    $hit = $stG->fetch() ? trim((string)$ean) : '';
    $stG->free_result();
    if ($hit !== '') return $hit;

    $stC->bind_param("s", $baseSKU);
    $stC->execute();
    $stC->bind_result($ean);
    $list = [];
    while ($stC->fetch()) $list[] = trim((string)$ean);
    $stC->free_result();
    return count($list) === 1 ? $list[0] : '';
}

/** Prețul retail EUR din feedul B2B (pret_euro) pentru un cod, sau 0 dacă lipsește. */
function b2bPriceEur(mysqli $conn, $baseSKU){
    static $st = null;
    if ($st === null) $st = $conn->prepare("SELECT MAX(pret_euro) FROM dainese2026_b2b WHERE cod = ? AND pret_euro > 0");
    $st->bind_param("s", $baseSKU);
    $st->execute();
    $st->bind_result($eur);
    $val = $st->fetch() ? (float)$eur : 0.0;
    $st->free_result();
    return $val;
}

/* ================= MODE: step (pagină + generare) ================= */

require_once __DIR__ . '/__connect.php';   // $conn (mysqli, bikershop_ps9)
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Conexiunea DB (\$conn din __connect.php) lipseste.");
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 0;
$self = basename(__FILE__);

header("Content-Type: text/html; charset=utf-8");
header("X-Accel-Buffering: no");

echo "<!DOCTYPE html><html lang='ro'><head><meta charset='utf-8'><title>Generare CSV produse noi 2026</title>";
echo "<style>
body{font:14px/1.5 -apple-system,Segoe UI,Arial,sans-serif;margin:24px;color:#222}
h1{font-size:20px;margin:0 0 6px}
.muted{color:#777}
ol.cats{columns:3;font-size:13px;margin:12px 0 20px}
ol.cats li.done{color:#2a8a2a}
ol.cats li.cur{font-weight:bold;color:#c00}
.log{font-family:Consolas,monospace;font-size:12px;white-space:pre-wrap;background:#f7f7f7;border:1px solid #ddd;padding:10px;max-height:50vh;overflow:auto}
.btn{display:inline-block;padding:12px 22px;background:#E10600;color:#fff;font-weight:bold;text-decoration:none;border-radius:6px;font-size:16px;border:0;cursor:pointer}
.btn[disabled]{background:#999;cursor:default}
.panel{margin-top:18px;padding:16px;border:2px solid #E10600;border-radius:8px;background:#fff8f8}
</style></head><body>";

echo "<h1>Generare CSV produse noi 2026</h1>";

if ($step < 0 || $step >= $total) {
    echo "<p class='muted'>Categorii: $total</p>";
    echo "<h2>✔ DONE — toate categoriile au fost generate și descărcate.</h2>";
    echo "<p><a class='btn' href='$self?step=0'>Reia de la prima categorie</a></p>";
    echo "</body></html>";
    exit;
}

$catName     = $catNames[$step];
$categoryURL = $categories[$catName];
$filename    = csvFilename($catName);

echo "<p class='muted'>Categoria <b>" . ($step + 1) . " / $total</b>: <b>" . h($catName) . "</b></p>";
echo "<ol class='cats'>";
foreach ($catNames as $i => $c) {
    $cls = $i < $step ? 'done' : ($i == $step ? 'cur' : '');
    echo "<li class='$cls'><a href='$self?step=$i' style='color:inherit'>" . h($c) . "</a></li>";
}
echo "</ol>";

echo "<div class='log' id='log'>";

// streaming
echo str_repeat(" ", 4096);
ob_implicit_flush(true);
while (ob_get_level()) ob_end_flush();
flush();

libxml_use_internal_errors(true);

// hostul linkurilor de produs = hostul site-ului din URL-ul categoriei (dainese.com / agv.com)
$siteBase = "https://" . parse_url($categoryURL, PHP_URL_HOST);

$products = [];

// paginare defensivă: start=0,200,400… până când o pagină nu mai aduce produse noi
$pageSize = 200;
for ($start = 0; $start < 5000; $start += $pageSize) {

    $url = preg_replace('/([?&])start=\d+/', '${1}start=' . $start, $categoryURL);
    $url = preg_replace('/([?&])sz=\d+/',    '${1}sz=' . $pageSize, $url);

    $html = getHTML($url);
    if (!$html) break;

    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $nodes = $xpath->query("//a[contains(concat(' ', normalize-space(@class), ' '), ' product-name ')]");

    $before = count($products);

    foreach($nodes as $node){

        $name = cleanText($node->textContent);
        $href = $node->getAttribute("href");
        if ($href === '') continue;

        $link = preg_match('#^https?://#', $href) ? $href : $siteBase . $href;

        $products[$link] = [
            "name"=>$name,
            "link"=>$link
        ];
    }

    $got = $nodes->length;
    echo "Pagina start=$start: $got produse (" . (count($products) - $before) . " noi)\n";
    flush();

    if ($got < $pageSize || count($products) == $before) break;
}

$products = array_values($products);

echo "Total produse: ".count($products)."\n";
flush();

/* ================= CSV ================= */

$csv = fopen($filename,"w");

fputcsv($csv,[
    "Product Name",
    "Product Reference Code",
    "Combinations Reference Code",
    "Attribute Group 1",
    "Attribute Value 1",
    "Attribute Group 2",
    "Attribute Value 2",
    "Description",
    "Retail Price With Tax",
    "Final Price With Tax",
    "Category Default ID",
    "Product Image Urls",
    "Active",
    "EAN13",
    "Supplier Reference"
],";");

/* ================= PRODUCTS LOOP ================= */

$i = 1;
$added = 0;
$skipped = 0;

foreach($products as $product){

    /* ===== referința din URL → skip dacă există deja pe bikershop (fără fetch) ===== */
    $origCode = urlCode($product['link']);
    $baseSKU  = $origCode !== '' ? refFromCode($origCode) : '';

    if ($baseSKU !== '' && ($pid = productExists($conn, $baseSKU))) {
        echo h("$catName → $i : SKIP (există #$pid, ref $baseSKU) — " . $product['name']) . "
";
        flush();
        $i++; $skipped++;
        continue;
    }

    $html = getHTML($product['link']);
    if(!$html) continue;

    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    /* NAME */
    $nameNode = $xpath->query("//h1")->item(0);
    $productNameRaw = $nameNode ? cleanText($nameNode->textContent) : $product['name'];
    $productName = normalizeTitle($productNameRaw);

    /* SKU */
    $skuNode=$xpath->query("//span[contains(@class,'product-sku')]")->item(0);
    $sku=$skuNode?cleanText($skuNode->textContent):"";

    echo h("$catName → $i : $productName") . "\n";
    flush();

    /* ================= SIZES (ALL, inclusiv fără stoc) ================= */

    $sizes = [];

    $sizeNodes = $xpath->query("//button[contains(@class,'size-attribute')]");

    foreach($sizeNodes as $btn){

        $sizeNode = $xpath->query(".//span[contains(@class,'size-value')]", $btn)->item(0);
        if(!$sizeNode) continue;

        $size = cleanText($sizeNode->textContent);

        if($size != ""){
            $sizes[] = $size;
        }
    }

    $sizes = array_unique($sizes);

    if(empty($sizes)){
        $sizes = ["One Size"];
    }

    /* ================= BASE SKU ================= */

    // fără cod în URL: din span.product-sku (SKU + 3 caractere cod mărime), ca în dainese_json.php
    if ($baseSKU === '' && $sku !== '') {
        $origCode = strlen($sku) > 5 ? substr($sku, 0, -3) : $sku;
        $baseSKU  = refFromCode($origCode);

        if ($baseSKU !== '' && ($pid = productExists($conn, $baseSKU))) {
            echo h("   ↳ SKIP (există #$pid, ref $baseSKU)") . "
";
            flush();
            $i++; $skipped++;
            continue;
        }
    }

    // fallback: hash din link
    if($baseSKU === ''){
        $baseSKU  = substr(md5($product['link']),0,10);
        $origCode = $sku;
    }

    $supplierRef = $origCode !== '' ? $origCode : $sku;

    /* PRICE */
    $priceNode = $xpath->query("//span[contains(@class,'sales')]")->item(0);
    $price = 0;

    if($priceNode){

        $rawPrice = trim($priceNode->textContent);
        $rawPrice = preg_replace('/[^\d\.,]/u', '', $rawPrice);

        if (preg_match('/^\d{1,3}\.\d{3}$/', $rawPrice)) {
            $rawPrice = str_replace('.', '', $rawPrice);                 // 1.480 => 1480
        } elseif (preg_match('/^\d{1,3}(?:\.\d{3})+,\d+$/', $rawPrice)) {
            $rawPrice = str_replace('.', '', $rawPrice);                 // 1.480,50 => 1480.50
            $rawPrice = str_replace(',', '.', $rawPrice);
        } elseif (preg_match('/^\d{1,3}(?:,\d{3})+\.\d+$/', $rawPrice)) {
            $rawPrice = str_replace(',', '', $rawPrice);                 // 1,480.50 => 1480.50
        } elseif (strpos($rawPrice, ',') !== false) {
            $rawPrice = str_replace(',', '.', $rawPrice);                // 1480,50 => 1480.50
        }

        $price = (float)$rawPrice;
        $price *= 5.25; // EUR -> RON
        $price = number_format($price, 2, '.', '');
    }

    // fără preț pe pagină → prețul retail din feedul B2B (aceeași conversie EUR → RON)
    $priceNote = '';
    if ((float)$price <= 0 && ($eur = b2bPriceEur($conn, $baseSKU)) > 0) {
        $price = number_format($eur * 5.25, 2, '.', '');
        $priceNote = " [preț din B2B: $eur EUR]";
    }

    /* DESCRIPTION */
    $description="";
    $descNode=$xpath->query("//div[@id='details']//div[contains(@class,'offcanvas-body')]")->item(0);

    if($descNode){
        $text=cleanText($descNode->textContent);
        $text=preg_replace('/Item:\s*[A-Z0-9]+/i','',$text);
        $description="<p>".$text."</p>";
    }

    /* IMAGES */
    $images = [];

    $imgNodes = $xpath->query("//div[contains(@class,'primary-images')]//img");

    foreach ($imgNodes as $imgNode) {

        $src = $imgNode->getAttribute("data-src") ?: $imgNode->getAttribute("src");

        if(!$src) continue;
        if(strpos($src,'dainese-cdn') === false) continue;

        $src = preg_replace('/\/\d+x\d+\//','/1920xundefined/',$src);
        $src = preg_replace('/\?.*/','',$src);

        $images[] = $src;
    }

    $images = array_unique($images);
    $imageList = implode(",", $images);

    /* ================= WRITE CSV ================= */

    $eanHits = 0;

    foreach($sizes as $size){

        $combSKU = $baseSKU . $size;
        $ean     = eanFor($conn, $baseSKU, $size);
        if ($ean !== '') $eanHits++;

        fputcsv($csv, [
            $productName,
            $baseSKU,
            $combSKU,
            "Color",
            "DEFAULT",
            "Size",
            $size,
            $description,
            $price,
            $price,
            808,
            $imageList,
            0,
            $ean,
            $supplierRef
        ], ";");
    }

    echo h("   ↳ ref $baseSKU | EAN " . ($eanHits ? "$eanHits/" . count($sizes) : "lipsă în B2B") . $priceNote) . "\n";
    flush();

    $i++; $added++;
    usleep(150000);
}

fclose($csv);

echo "Produse noi în CSV: $added | sărite (există deja pe bikershop): $skipped
";

$next     = $step + 1;
$nextName = $next < $total ? $catNames[$next] : null;

/* ================= NIMIC NOU → fără CSV, trecem automat mai departe ================= */

if ($added === 0) {
    @unlink($filename);
    echo "— Niciun produs nou în această categorie: nu se generează CSV.
";
    echo "</div>";

    $nextUrl = $self . "?step=" . $next;
    echo "<div class='panel' id='panel'>";
    echo "<p>Categoria <b>" . h($catName) . "</b> nu are produse noi.</p>";
    echo "<p class='muted'>Trecem automat la " . ($nextName ? "categoria următoare: <b>" . h($nextName) . "</b>" : "<b>final</b>")
       . " în 3 secunde… <a href='" . h($nextUrl) . "'>sau click aici</a>.</p>";
    echo "</div>";
    echo "<script>document.getElementById('log').scrollTop=1e9;setTimeout(function(){window.location.href=" . json_encode($nextUrl) . ";},3000);</script>";
    echo "</body></html>";
    exit;
}

echo "✔ CSV generat: " . h(basename($filename)) . " (" . number_format(filesize($filename)) . " bytes)
";
echo "</div>";

/* ================= PANOU DOWNLOAD + AUTO-NEXT ================= */

echo "<div class='panel' id='panel'>";
echo "<p>Categoria <b>" . h($catName) . "</b> este gata.</p>";
echo "<p><button class='btn' id='dl' type='button'>⬇ Descarcă " . h(basename($filename)) . "</button></p>";
echo "<p class='muted' id='msg'>La click, fișierul se descarcă, apoi se șterge de pe server și trecem automat la "
   . ($nextName ? "categoria următoare: <b>" . h($nextName) . "</b>" : "<b>final</b>") . ".</p>";
echo "</div>";

echo "<iframe id='dlframe' style='display:none'></iframe>";

echo "<script>
(function(){
  var self = " . json_encode($self) . ";
  var step = $step;
  var nextUrl = self + '?step=' + (step + 1);
  var btn = document.getElementById('dl');
  var msg = document.getElementById('msg');

  document.getElementById('log').scrollTop = 1e9;

  btn.addEventListener('click', function(){
    btn.disabled = true;
    msg.innerHTML = 'Se descarcă… nu închide pagina.';
    document.getElementById('dlframe').src = self + '?download=' + step + '&t=' + Date.now();

    var tries = 0;
    var timer = setInterval(function(){
      tries++;
      fetch(self + '?status=' + step + '&t=' + Date.now(), {cache:'no-store'})
        .then(function(r){ return r.json(); })
        .then(function(j){
          if (!j.exists) {
            clearInterval(timer);
            msg.innerHTML = 'Descărcat și șters. Trecem la categoria următoare…';
            setTimeout(function(){ window.location.href = nextUrl; }, 800);
          } else if (tries > 600) {
            clearInterval(timer);
            btn.disabled = false;
            msg.innerHTML = 'Descărcarea nu s-a finalizat în 10 minute. Reîncearcă sau <a href=\"' + nextUrl + '\">treci manual mai departe</a>.';
          }
        })
        .catch(function(){});
    }, 1000);
  });
})();
</script>";

echo "</body></html>";
