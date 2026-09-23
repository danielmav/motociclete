<?php
/**
 * Generare CSV produse noi 2026 (Dainese / AGV / MOMO) — o categorie pe rând.
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
 * Derivat din dainese_json.php (aceeași logică de scraping / format CSV).
 */

set_time_limit(0);

/* ================= CATEGORIES (în ordinea de rulare) ================= */

$DW = "https://www.dainese.com/on/demandware.store/Sites-dainese-row-Site/en_RO/Search-UpdateGrid?cgid=";

$categories = [

    /* BARBATI */
    "men_jackets"   => $DW . "motorbike-men-jackets&start=0&sz=200",
    "men_pants"     => $DW . "motorbike-men-pants&start=0&sz=200",
    "men_gloves"    => $DW . "motorbike-men-gloves&start=0&sz=200",
    "men_boots"     => $DW . "motorbike-men-boots&start=0&sz=200",
    "men_shoes"     => $DW . "motorbike-men-shoes&start=0&sz=200",
    "men_layers"    => $DW . "motorbike-men-technical_layers&start=0&sz=200",
    "men_casual"    => $DW . "motorbike-men-casual_wear&start=0&sz=200",

    /* FEMEI */
    "women_jackets" => $DW . "motorbike-women-jackets&start=0&sz=200",
    "women_pants"   => $DW . "motorbike-women-pants&start=0&sz=200",
    "women_gloves"  => $DW . "motorbike-women-gloves&start=0&sz=200",
    "women_boots"   => $DW . "motorbike-women-boots&start=0&sz=200",
    "women_shoes"   => $DW . "motorbike-women-shoes&start=0&sz=200",
    "women_layers"  => $DW . "motorbike-women-technical_layers&start=0&sz=200",
    "women_casual"  => $DW . "motorbike-women-casual_wear&start=0&sz=200",

    /* AGV */
    "agv_full_face" => $DW . "agv_helmets-full_face&start=0&sz=200",
    "agv_flip_up"   => $DW . "agv_helmets-flip_up&start=0&sz=200",
    "agv_open_face" => $DW . "agv_helmets-open_face&start=0&sz=200",

    /* MOMO */
    "momodesign"    => $DW . "motorbike-momodesign-momodesign_helmets&start=0&sz=200",
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

/* ================= MODE: step (pagină + generare) ================= */

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

$html = getHTML($categoryURL);

libxml_use_internal_errors(true);

$dom = new DOMDocument();
$dom->loadHTML($html ?: '<html></html>');
$xpath = new DOMXPath($dom);

$products = [];

$nodes = $xpath->query("//h2/a[contains(@class,'product-name')]");

foreach($nodes as $node){

    $name = cleanText($node->textContent);
    $link = "https://www.dainese.com".$node->getAttribute("href");

    $products[$link] = [
        "name"=>$name,
        "link"=>$link
    ];
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
    "Active"
],";");

/* ================= PRODUCTS LOOP ================= */

$i = 1;

foreach($products as $product){

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

    $baseSKU = $sku;

    if(strlen($baseSKU) > 5){
        $baseSKU = substr($baseSKU, 2);
        $baseSKU = substr($baseSKU, 0, -3);
    }

    if(empty($baseSKU)){
        $baseSKU = substr(md5($product['link']),0,10);
    }

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

    foreach($sizes as $size){

        $combSKU = $baseSKU . $size;

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
            0
        ], ";");
    }

    $i++;
    usleep(150000);
}

fclose($csv);

echo "✔ CSV generat: " . h(basename($filename)) . " (" . number_format(filesize($filename)) . " bytes)\n";
echo "</div>";

/* ================= PANOU DOWNLOAD + AUTO-NEXT ================= */

$next     = $step + 1;
$nextName = $next < $total ? $catNames[$next] : null;

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
