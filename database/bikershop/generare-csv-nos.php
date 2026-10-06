<?php
/**
 * Generare CSV produse NOS (căști + piese de schimb) din shop.nos-helmets.com — o categorie pe rând.
 *
 * Copie pe server: ~/public_html/tool/generare-csv-nos.php (lângă generare-csv-produse-noi-2026.php).
 * Același flux ca scriptul Dainese:
 *   ?step=N      → scrapează categoria N (0 = căști, 1 = piese), scrie CSV-ul, afișează progresul live
 *                  și, la final, butonul „Descarcă CSV”.
 *   ?download=N  → trimite CSV-ul către browser și ÎL ȘTERGE după trimitere.
 *   ?status=N    → JSON {"exists":bool} — după download, pagina trece automat la ?step=N+1.
 *   ?instock=1   → exportă doar modelele cu cel puțin o mărime cu stoc > 0 în gestiune (stocuri2024).
 *
 * CLI (local, PHP 8.1 Laragon, sau pe server cu ea-php84):
 *   php generare-csv-nos.php --step=0 [--out=fisier.csv] [--limit=N] [--only=slug-produs] [--instock]
 *
 * Surse de date:
 *   - site NOS (WooCommerce): listare /en/product-category/{helmets|spare-parts}/page/N/, pagina de produs
 *     cu JSON-ul `data-product_variations` (SKU, preț EUR, stoc, imagine per mărime).
 *   - gestiune (`stocuri2024`, cheie `codprodus` = SKU-ul de variantă NOS, ex. NS1000LABM):
 *     PREȚ DE VÂNZARE = `pret` (RON, cu TVA) când codul există, altfel preț listă EUR × 5,25;
 *     EAN13 = `cod_bare`; `Stoc gestiune` = `stoc`.
 *   - BikerShop (`ps_product.reference` / `ps_product_attribute.reference`): produs SĂRIT dacă referința
 *     de bază sau oricare SKU de variantă există deja.
 *
 * Convenție referințe (verificată pe modelele NOS existente pe BikerShop):
 *   SKU variantă NOS = 6 caractere model + 2 caractere mărime + sufix culoare  (NS1000 LA BM)
 *   Product Reference Code = SKU fără mărime (NS1000BM); Combinations Reference Code = SKU-ul variantei.
 *
 * DB (mysqli, citire doar): pe server credențialele PrestaShop din ../app/config/parameters.php,
 * local din .env (BIKERSHOP_*). Conexiunea se reface automat dacă expiră în timpul așteptărilor la site.
 * Site-ul NOS răspunde 503 la rafale → o cerere la ~1 s, backoff progresiv la 503/timeout.
 *
 * Nume: site-ul taie numele la 40 de caractere → se reconstruiesc din atributele „Modello” + „Colore”;
 * cuvintele italiene/engleze frecvente se traduc (CASCO → Casca, P. ARIA → Priza aer, GUANCIALI → Bureti laterali…).
 * Produsele fără nicio variantă pe site (toate mărimile șterse, preț 0) sunt raportate „FĂRĂ VARIANTE” și sărite.
 */

set_time_limit(0);
libxml_use_internal_errors(true);

/* ================= CONFIG ================= */

const NOS_BASE   = 'https://shop.nos-helmets.com';
const EUR_RON    = 5.25;          // fallback când codul nu e în gestiune (ca la Dainese)
const CAT_FALLBACK = 808;         // „Inactive” — când nu recunoaștem subcategoria

$categories = [
    'nos_casti' => NOS_BASE . '/en/product-category/helmets/',
    'nos_piese' => NOS_BASE . '/en/product-category/spare-parts/',
];

/** Subcategoria NOS (clasa product_cat-* / slug din „Categories:”) → Category Default ID pe BikerShop. */
$CAT_MAP = [
    'full-face-en' => 765, 'full-face' => 765,   // Integrale
    'modular-en'   => 766, 'modular'   => 766,   // Modulare
    'open-face-en' => 768, 'open-face' => 768,   // Open Face
    'jet-en'       => 768, 'jet'       => 768,
    'crossover-en' => 764, 'crossover' => 764,   // Dual Sport
    'dual-sport'   => 764,                       // (NS-9 „crossover” are slug-ul dual-sport pe /en/)
    'spare-parts'  => 769,                       // Componente si piese de schimb
];
const CAT_VIZIERE = 770;                         // Viziere si cozoroace (piese cu VISIERA/FRONTINO/VISOR în nume)

/** Mărimi NOS → convenția BikerShop (atributul „Optiune”). */
$SIZE_MAP = [
    'XS' => 'XS', 'SM' => 'S', 'ME' => 'M', 'LA' => 'L', 'XL' => 'XL', 'XX' => '2XL', '3X' => '3XL',
    '00' => 'One Size', '' => 'One Size', 'XLXX' => 'XL/2XL', 'XS-L' => 'XS-L',
];

/** Cuvinte italiene din numele NOS → română (aplicat pe cuvinte întregi, înainte de title-case). */
$IT_RO = [
    'CASCO' => 'Casca', 'VISIERA' => 'Viziera', 'VISIERE' => 'Viziere', 'VISIERINA' => 'Viziera interioara',
    'MECCANISMO' => 'Mecanism', 'INTERNO' => 'Interior', 'INTERNI' => 'Interior', 'GUANCIALI' => 'Bureti laterali',
    'GUANCIALE' => 'Burete lateral', 'CALOTTA' => 'Calota', 'DEFLETTORE' => 'Deflector', 'MENTONIERA' => 'Mentoniera',
    'PARANASO' => 'Deflector nas', 'PARAVENTO' => 'Deflector vant', 'FRONTINO' => 'Cozoroc', 'SPOILER' => 'Spoiler',
    'PLACCHETTA' => 'Placuta', 'PLACCHETTE' => 'Placute', 'VITI' => 'Suruburi', 'VITE' => 'Surub', 'KIT' => 'Kit',
    'RICAMBIO' => 'Piesa de schimb', 'RICAMBI' => 'Piese de schimb', 'BORSA' => 'Geanta', 'SACCA' => 'Husa',
    'CINTURINO' => 'Curea', 'CINGHIA' => 'Curea', 'FODERA' => 'Captuseala', 'FIBBIA' => 'Catarama',
    'PRESA' => 'Priza', 'ARIA' => 'aer', 'GOMMA' => 'Garnitura', 'GUARNIZIONE' => 'Garnitura', 'PINLOCK' => 'Pinlock',
    'TRASPARENTE' => 'Transparenta', 'FUME' => 'Fumurie', 'SPECCHIO' => 'Oglinda', 'SPECCHIATA' => 'Oglinda',
    'ORO' => 'Auriu', 'ARGENTO' => 'Argintiu', 'NERO' => 'Negru', 'NERA' => 'Neagra', 'BIANCO' => 'Alb',
    'BIANCA' => 'Alba', 'ROSSO' => 'Rosu', 'ROSSA' => 'Rosie', 'BLU' => 'Albastru', 'GIALLO' => 'Galben',
    'GIALLA' => 'Galbena', 'OPACO' => 'Mat', 'OPACA' => 'Mata', 'LUCIDO' => 'Lucios', 'LUCIDA' => 'Lucioasa',
    'GRIGIO' => 'Gri', 'GRIGIA' => 'Gri', 'VERDE' => 'Verde', 'ROSA' => 'Roz', 'ARANCIO' => 'Portocaliu',
    'ARANCIONE' => 'Portocaliu', 'TITANIO' => 'Titan', 'CARBONIO' => 'Carbon', 'SCURO' => 'Inchis',
    'CHIARO' => 'Deschis', 'COPPIA' => 'Pereche', 'PER' => 'pentru', 'CON' => 'cu', 'SENZA' => 'fara',
    'E' => 'si', 'DI' => 'de', 'DA' => 'de', 'SUPERIORE' => 'Superior', 'INFERIORE' => 'Inferior',
    'POSTERIORE' => 'Posterior', 'ANTERIORE' => 'Anterior', 'LATERALE' => 'Lateral', 'LATERALI' => 'Laterale',
    'CENTRALE' => 'Central', 'SINISTRO' => 'Stanga', 'DESTRO' => 'Dreapta', 'SINISTRA' => 'Stanga', 'DESTRA' => 'Dreapta',
    'COPRIMENTONIERA' => 'Protectie barbie', 'SOTTOGOLA' => 'Deflector barbie',   // ca în gestiune (NS1006…)
    'TENDINA' => 'Parasolar', 'OCCHIALINO' => 'Parasolar', 'PARASOLE' => 'Parasolar',
];

/* ================= HELPERS ================= */

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function cleanText($t){ return trim(preg_replace('/\s+/u', ' ', html_entity_decode((string)$t, ENT_QUOTES | ENT_HTML5, 'UTF-8'))); }

function getHTML($url, &$status = 0){
    // 503/429/timeout → backoff progresiv (site-ul limitează rafalele)
    $waits = [5, 15, 30, 60, 90];
    for ($try = 0; $try <= count($waits); $try++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128 Safari/537.36',
            CURLOPT_HTTPHEADER     => ['Accept-Language: en-US,en;q=0.9'],
            CURLOPT_ENCODING       => '',
        ]);
        $html   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status === 404) return false;                 // pagină inexistentă (sfârșit de paginare)
        if ($html !== false && $status === 200) return $html;
        if ($try < count($waits)) {
            logLine("   … HTTP $status la $url → reîncerc în {$waits[$try]} s");
            sleep($waits[$try]);
        }
    }
    return false;
}

/** Expresii (mai multe cuvinte / abrevieri) din numele NOS → română; aplicate ÎNAINTEA dicționarului pe cuvinte. */
$IT_RO_PHRASES = [
    '/\bP\.\s*ARIA\b/u'              => 'PRIZA AER',
    '/\bPRESA\s+ARIA\b/u'            => 'PRIZA AER',
    // atributul „Modello” e uneori în engleză (FRONTAL AIR P. / REAR AIR P. / SIDE AIR P. / CHIN AIR P.)
    '/\bFRONTAL\s+AIR\s+P\.?/u'      => 'PRIZA AER ANTERIOARA',
    '/\bREAR\s+AIR\s+P\.?/u'         => 'PRIZA AER POSTERIOARA',
    '/\bSIDE\s+AIR\s+P\.?/u'         => 'PRIZA AER LATERALA',
    '/\bCHIN\s+AIR\s+P\.?/u'         => 'PRIZA AER MENTONIERA',
    '/\bAIR\s+P\.(?=\s|$)/u'         => 'PRIZA AER',
    '/\bAIR\s+INTAKE\b/u'            => 'PRIZA AER',
    '/\bCHEEK\s+PADS?\b/u'           => 'BURETI LATERALI',
    '/\bINNER\s+LINING\b/u'          => 'CAPTUSEALA INTERIOARA',
    '/\bCHIN\s+STRAP\b/u'            => 'CUREA BARBIE',
    '/\bNOSE\s+GUARD\b/u'            => 'DEFLECTOR NAS',
    '/\bVISOR\s+MECHANISM\b/u'       => 'MECANISM VIZIERA',
    '/\bCHIN\s+GUARD\b/u'            => 'DEFLECTOR BARBIE',
    '/\bIMBOTT\.?\s+INTERNA\b/u'     => 'CAPTUSEALA INTERIOARA',
    '/\bFUM[EÉ][\x{2019}\x{2018}\'´`]?(?=\s|$)/u' => 'FUMURIE',   // „Fume’” (viziere fumurii)
    '/\bVISIERA\b(.*)\bCHIARO\b/u'   => 'VISIERA$1TRANSPARENTA',   // viziera „chiaro” = transparentă
    '/\bIMBOTTITURA\s+INTERNA\b/u'   => 'CAPTUSEALA INTERIOARA',
    '/\bSUN\s+VISOR\b/u'             => 'PARASOLAR',
    '/\bREFLECTIVE\s+STICKERS\s+KIT\b/u' => 'KIT STICKERE REFLECTORIZANTE',
    '/\bREFLECTIVE\s+STICKERS\b/u'   => 'STICKERE REFLECTORIZANTE',
    // după „PRIZA AER” adjectivele sunt la feminin
    '/\bANTERIORE\b/u'  => 'ANTERIOARA', '/\bPOSTERIORE\b/u' => 'POSTERIOARA', '/\bLATERALE\b/u' => 'LATERALA',
];

/** Numele NOS (italiană, majuscule) → nume de produs BikerShop. */
function normalizeName($raw, array $IT_RO){
    global $IT_RO_PHRASES;
    $raw = cleanText($raw);
    $raw = preg_replace('/\s*[—–-]\s*$/u', '', $raw);     // „… —” la finalul numelor fără culoare
    $up  = mb_strtoupper($raw, 'UTF-8');
    foreach ($IT_RO_PHRASES as $re => $ro) $up = preg_replace($re, $ro, $up);
    $words = preg_split('/\s+/u', trim($up));
    $out = [];
    foreach ($words as $i => $w) {
        if (isset($IT_RO[$w])) { $out[] = $IT_RO[$w]; continue; }
        if ($w === 'NOS') { $out[] = 'NOS'; continue; }
        // coduri de model / tehnice: conțin cifre sau încep cu NS- (NS-1, NS-15F, NS-J, ECE22-05, NS-11C/NS-11F, 10MM)
        if (preg_match('/\d/u', $w) || preg_match('/^NS-?[A-Z]/u', $w)) { $out[] = preg_replace('/(\d)\s*MM$/u', '$1mm', $w); continue; }
        // intervale de mărimi în nume: (XS-LA), (XL/XXL) → rămân cu majuscule
        if (preg_match('#^\(?(XS|SM|ME|LA|XL|XXL|XX|S|M|L)([/-](XS|SM|ME|LA|XL|XXL|XX|S|M|L))*\)?$#u', $w)) { $out[] = $w; continue; }
        // RED/BLUE, BLUE-RED → fiecare parte cu majusculă
        if (preg_match('#[/-]#u', $w)) {
            $out[] = preg_replace_callback('/[^\/-]+/u', fn($m) => mb_convert_case(mb_strtolower($m[0], 'UTF-8'), MB_CASE_TITLE, 'UTF-8'), $w);
            continue;
        }
        $out[] = mb_strlen($w, 'UTF-8') <= 2 && $i > 0 ? mb_strtolower($w, 'UTF-8')
               : mb_convert_case(mb_strtolower($w, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }
    $name = implode(' ', $out);
    // unele nume de piese n-au brandul („IMBOTTITURA INTERNA NS-1 10mm”) → „NOS” înaintea codului de model
    if (!preg_match('/\bNOS\b/u', $name)) $name = preg_replace('/\b(NS-?\d)/u', 'NOS $1', $name, 1);
    // primul cuvânt mereu cu majusculă
    return mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($name, 1, null, 'UTF-8');
}

/**
 * Site-ul NOS taie numele produselor la 40 de caractere („…FREEDOM BLACK MA”). Când numele pare tăiat,
 * îl reconstruim din atributele „Modello” + „Colore” (complete în tabelul de atribute).
 */
function untruncatedName($raw, array $attrs){
    if (mb_strlen($raw, 'UTF-8') < 40) return $raw;
    $model = trim($attrs['MODELLO'] ?? '');
    $color = trim($attrs['COLORE'] ?? '');
    if ($model === '') return $raw;
    if ($color === '' || preg_match('/^[-—–]+$/u', $color)) return $model;
    return $model . ' ' . $color;
}

/** Codul de mărime (2 caractere) din SKU-ul variantei; '' dacă nu se potrivește convenției. */
function sizeCodeFromSku($sku, $sizeLabel){
    $sku = strtoupper($sku);
    $size = strtoupper(trim($sizeLabel));
    if (strlen($sku) >= 8 && substr($sku, 6, 2) === $size) return $size;
    return '';
}

function baseRefFromSku($sku){
    $sku = strtoupper($sku);
    return strlen($sku) >= 8 ? substr($sku, 0, 6) . substr($sku, 8) : $sku;
}

/** Categoria BikerShop după slug-urile de categorie NOS + nume. */
function categoryIdFor(array $catSlugs, $name, array $CAT_MAP){
    if (preg_match('/\b(VISIER|VISOR|FRONTINO|VISIERINA|PINLOCK)/iu', $name)) return CAT_VIZIERE;
    foreach ($catSlugs as $s) {
        if ($s === 'helmets') continue;
        if (isset($CAT_MAP[$s])) return $CAT_MAP[$s];
    }
    return CAT_FALLBACK;
}

/* ---------- parsare pagină listare ---------- */

function parseListing($html, array &$products){
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    $xp = new DOMXPath($dom);
    $nodes = $xp->query("//li[contains(concat(' ', normalize-space(@class), ' '), ' product ')]");
    $got = 0;
    foreach ($nodes as $li) {
        $a = $xp->query(".//a[contains(concat(' ', normalize-space(@class), ' '), ' woocommerce-LoopProduct-link ')]", $li)->item(0);
        if (!$a) continue;
        $href = $a->getAttribute('href');
        if ($href === '') continue;
        $t = $xp->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' woocommerce-loop-product__title ')]", $li)->item(0);
        $cats = [];
        if (preg_match_all('/\bproduct_cat-([a-z0-9-]+)/', $li->getAttribute('class'), $m)) $cats = $m[1];
        $got++;
        $products[$href] = ['name' => $t ? cleanText($t->textContent) : '', 'link' => $href, 'cats' => $cats];
    }
    return $got;
}

/* ---------- parsare pagină produs ---------- */

function parseProduct($html){
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    $xp = new DOMXPath($dom);
    $p = ['name' => '', 'sku' => '', 'cats' => [], 'short' => '', 'desc' => '', 'images' => [], 'variations' => [], 'sizes_table' => [], 'labels' => []];

    $n = $xp->query("//h1")->item(0);
    if ($n) $p['name'] = cleanText($n->textContent);

    $n = $xp->query("//span[contains(concat(' ', normalize-space(@class), ' '), ' sku ')]")->item(0);
    if ($n) $p['sku'] = cleanText($n->textContent);

    foreach ($xp->query("//span[contains(@class,'posted_in')]//a") as $a) {
        if (preg_match('#/product-category/([a-z0-9-]+)/?$#', $a->getAttribute('href'), $m)) $p['cats'][] = $m[1];
    }

    $n = $xp->query("//div[contains(@class,'woocommerce-product-details__short-description')]")->item(0);
    if ($n) $p['short'] = cleanText($n->textContent);
    $n = $xp->query("//div[@id='tab-description']")->item(0);
    if ($n) {
        $txt = cleanText($n->textContent);
        $txt = preg_replace('/^Description\s*/iu', '', $txt);
        if (!preg_match('/^Non disponibile$/iu', $txt)) $p['desc'] = $txt;
    }

    foreach ($xp->query("//*[@data-large_image]") as $el) {
        $src = $el->getAttribute('data-large_image');
        if ($src) $p['images'][] = $src;
    }

    // etichete opțiuni (slug atribut → text afișat), ex. la-en-2 → LA
    foreach ($xp->query("//select[starts-with(@name,'attribute_')]/option[@value!='']") as $o) {
        $p['labels'][$o->getAttribute('value')] = cleanText($o->textContent);
    }

    // tabelul de atribute (Colore / Taglia / Modello): Taglia → lista de mărimi, restul → $p['attrs'] (chei UPPER)
    $p['attrs'] = [];
    foreach ($xp->query("//tr[contains(@class,'woocommerce-product-attributes-item')]") as $tr) {
        $th = $xp->query(".//th", $tr)->item(0);
        $td = $xp->query(".//td", $tr)->item(0);
        if (!$th || !$td) continue;
        $label = mb_strtoupper(cleanText($th->textContent), 'UTF-8');
        $value = cleanText($td->textContent);
        $p['attrs'][$label] = $value;
        if (preg_match('/^(TAGLIA|SIZE)$/u', $label)) {
            $p['sizes_table'] = array_values(array_filter(array_map('trim', explode(',', $value))));
        }
    }

    $form = $xp->query("//form[@data-product_variations]")->item(0);
    if ($form) {
        $json = $form->getAttribute('data-product_variations');
        $vars = json_decode($json, true);
        if (is_array($vars)) {
            foreach ($vars as $v) {
                if (isset($v['variation_is_visible']) && !$v['variation_is_visible']) continue;
                $sizeSlug  = $v['attributes']['attribute_pa_taglia'] ?? '';
                $colorSlug = $v['attributes']['attribute_pa_colori'] ?? '';
                $p['variations'][] = [
                    'id'       => (int)($v['variation_id'] ?? 0),
                    'sku'      => strtoupper(trim((string)($v['sku'] ?? ''))),
                    'size'     => $p['labels'][$sizeSlug] ?? strtoupper(preg_replace('/-en(-\d+)?$/', '', $sizeSlug)),
                    'color'    => $p['labels'][$colorSlug] ?? $colorSlug,
                    'price'    => (float)($v['display_price'] ?? 0),
                    'regular'  => (float)($v['display_regular_price'] ?? 0),
                    'in_stock' => !empty($v['is_in_stock']),
                    'image'    => $v['image']['url'] ?? ($v['image']['src'] ?? ''),
                ];
            }
        }
    }
    return $p;
}

/** URL-ul imaginii fără sufixul de dimensiune WordPress (-300x300). */
function fullImage($url){
    $url = preg_replace('/-\d+x\d+(\.[a-z]{3,4})$/i', '$1', $url);
    return preg_replace('/\?.*/', '', $url);
}

/* ================= DB (read-only) ================= */

mysqli_report(MYSQLI_REPORT_OFF);

/**
 * Credențialele DB: pe serverul bikershop din PrestaShop (`../app/config/parameters.php`, scriptul stă în
 * ~/public_html/tool/), local din `.env`-ul portalului (BIKERSHOP_*). NU folosim __connect.php: definește
 * funcții, deci nu poate fi re-inclus la reconectare.
 */
function dbConfig(){
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $ps = dirname(__DIR__) . '/app/config/parameters.php';
    if (is_file($ps)) {
        $p = (require $ps)['parameters'] ?? [];
        if (!empty($p['database_host'])) {
            [$host, $port] = array_pad(explode(':', (string)$p['database_host'], 2), 2, null);
            return $cfg = ['host' => $host, 'port' => (int)($p['database_port'] ?: ($port ?: 3306)),
                           'user' => $p['database_user'], 'pass' => $p['database_password'], 'name' => $p['database_name']];
        }
    }
    $root = dirname(__DIR__, 2);
    $env = [];
    if (is_file($root . '/.env')) {
        foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line[0] === '#' || strpos($line, '=') === false) continue;
            [$k, $v] = explode('=', $line, 2);
            $env[trim($k)] = trim(trim($v), "'\"");
        }
    }
    if (empty($env['BIKERSHOP_HOST'])) die("Lipsesc app/config/parameters.php (PrestaShop) și BIKERSHOP_* din .env\n");
    return $cfg = ['host' => $env['BIKERSHOP_HOST'], 'port' => (int)($env['BIKERSHOP_PORT'] ?? 3306),
                   'user' => $env['BIKERSHOP_USER'], 'pass' => $env['BIKERSHOP_PASS'], 'name' => $env['BIKERSHOP_NAME']];
}

function connectDb(){
    $c = dbConfig();
    $conn = @new mysqli($c['host'], $c['user'], $c['pass'], $c['name'], $c['port']);
    if ($conn->connect_error) die("DB: " . $conn->connect_error . "\n");
    $conn->set_charset('utf8mb4');
    return $conn;
}

/**
 * Conexiunea curentă, reconectată dacă a căzut („MySQL server has gone away” după așteptările lungi
 * la site-ul NOS). Statement-urile pregătite se refac după reconectare.
 */
$DB = ['conn' => null, 'stmts' => []];

function db(){
    global $DB;
    if ($DB['conn'] instanceof mysqli && @$DB['conn']->query('SELECT 1')) return $DB['conn'];
    if ($DB['conn'] instanceof mysqli) logLine('   … conexiunea DB a căzut → reconectez');
    $DB['conn']  = connectDb();
    $DB['stmts'] = [];
    return $DB['conn'];
}

/** Rulează un SELECT cu un singur parametru string; întoarce toate rândurile (assoc). */
function dbRows($key, $sql, $param){
    global $DB;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $conn = db();
        if (!isset($DB['stmts'][$key])) {
            $st = $conn->prepare($sql);
            if (!$st) { logLine("   … prepare eșuat ($key): " . $conn->error); $DB['conn'] = null; continue; }
            $DB['stmts'][$key] = $st;
        }
        $st = $DB['stmts'][$key];
        $st->bind_param('s', $param);
        if (!$st->execute()) { $DB['conn'] = null; continue; }   // conexiune pierdută între ping și execute
        $res = $st->get_result();
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
    die("DB: interogarea $key a eșuat de două ori\n");
}

/** id_product dacă referința de bază SAU oricare referință de combinație există deja pe BikerShop. */
function existingProduct($baseRef, array $variantSkus){
    $r = dbRows('prod', "SELECT id_product FROM ps_product WHERE reference = ? LIMIT 1", $baseRef);
    if ($r) return (int)$r[0]['id_product'];
    foreach ($variantSkus as $sku) {
        if ($sku === '') continue;
        $r = dbRows('attr', "SELECT id_product FROM ps_product_attribute WHERE reference = ? LIMIT 1", $sku);
        if ($r) return (int)$r[0]['id_product'];
    }
    return 0;
}

/** Rândul din gestiune (stocuri2024) pentru un SKU de variantă, sau null. */
function gestiune($sku){
    $r = dbRows('gest', "SELECT stoc, pret, cod_bare FROM stocuri2024 WHERE codprodus = ? ORDER BY stoc DESC LIMIT 1", $sku);
    if (!$r) return null;
    return ['stoc' => (int)$r[0]['stoc'], 'pret' => (float)$r[0]['pret'], 'ean' => trim((string)$r[0]['cod_bare'])];
}

/* ================= MODE DETECTION ================= */

$isCli    = PHP_SAPI === 'cli';
$catNames = array_keys($categories);
$total    = count($catNames);

function csvFilename($catName){ return __DIR__ . '/' . $catName . '.csv'; }

$opt = ['step' => 0, 'out' => '', 'limit' => 0, 'only' => '', 'instock' => false];
if ($isCli) {
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--(\w+)(?:=(.*))?$/', $a, $m)) $opt[$m[1]] = $m[2] ?? true;
    }
    $opt['step'] = (int)$opt['step'];
    $opt['limit'] = (int)$opt['limit'];
    $opt['instock'] = (bool)$opt['instock'];
} else {
    $opt['step']    = isset($_GET['step']) ? (int)$_GET['step'] : 0;
    $opt['instock'] = !empty($_GET['instock']);
    $opt['limit']   = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;
    $opt['only']    = isset($_GET['only']) ? trim($_GET['only']) : '';
}

/* ================= WEB: download / status ================= */

if (!$isCli && isset($_GET['download'])) {
    $n = (int)$_GET['download'];
    $file = ($n >= 0 && $n < $total) ? csvFilename($catNames[$n]) : '';
    if ($file === '' || !is_file($file)) {
        http_response_code(404); header('Content-Type: text/plain; charset=utf-8');
        echo "Fisierul CSV nu exista (a fost deja descarcat si sters?)."; exit;
    }
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: no-store'); header('Pragma: no-cache');
    readfile($file); flush();
    @unlink($file);
    exit;
}
if (!$isCli && isset($_GET['status'])) {
    $n = (int)$_GET['status'];
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode(['exists' => $n >= 0 && $n < $total && is_file(csvFilename($catNames[$n]))]);
    exit;
}

/* ================= OUTPUT (log) ================= */

$self = basename(__FILE__);
$step = $opt['step'];
$qs   = $opt['instock'] ? '&instock=1' : '';

function logLine($s){
    global $isCli;
    echo $isCli ? $s . "\n" : h($s) . "\n";
    flush();
}

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Accel-Buffering: no');
    echo "<!DOCTYPE html><html lang='ro'><head><meta charset='utf-8'><title>Generare CSV NOS</title>";
    echo "<style>
body{font:14px/1.5 -apple-system,Segoe UI,Arial,sans-serif;margin:24px;color:#222}
h1{font-size:20px;margin:0 0 6px}
.muted{color:#777}
ol.cats{font-size:13px;margin:12px 0 20px}
ol.cats li.done{color:#2a8a2a}
ol.cats li.cur{font-weight:bold;color:#c00}
.log{font-family:Consolas,monospace;font-size:12px;white-space:pre-wrap;background:#f7f7f7;border:1px solid #ddd;padding:10px;max-height:50vh;overflow:auto}
.btn{display:inline-block;padding:12px 22px;background:#E10600;color:#fff;font-weight:bold;text-decoration:none;border-radius:6px;font-size:16px;border:0;cursor:pointer}
.btn[disabled]{background:#999;cursor:default}
.panel{margin-top:18px;padding:16px;border:2px solid #E10600;border-radius:8px;background:#fff8f8}
</style></head><body>";
    echo "<h1>Generare CSV NOS" . ($opt['instock'] ? " <small class='muted'>(doar pe stoc)</small>" : "") . "</h1>";
    if ($step < 0 || $step >= $total) {
        echo "<h2>✔ DONE — toate categoriile au fost generate și descărcate.</h2>";
        echo "<p><a class='btn' href='$self?step=0$qs'>Reia de la prima categorie</a></p></body></html>";
        exit;
    }
    echo "<p class='muted'>Categoria <b>" . ($step + 1) . " / $total</b>: <b>" . h($catNames[$step]) . "</b></p><ol class='cats'>";
    foreach ($catNames as $i => $c) {
        $cls = $i < $step ? 'done' : ($i == $step ? 'cur' : '');
        echo "<li class='$cls'><a href='$self?step=$i$qs' style='color:inherit'>" . h($c) . "</a></li>";
    }
    echo "</ol><div class='log' id='log'>";
    echo str_repeat(' ', 4096);
    ob_implicit_flush(true);
    while (ob_get_level()) ob_end_flush();
    flush();
} elseif ($step < 0 || $step >= $total) {
    fwrite(STDERR, "Categorii: " . implode(', ', array_map(fn($i, $c) => "$i=$c", array_keys($catNames), $catNames)) . "\n");
    exit(1);
}

db();   // conectare (eșuează devreme dacă DB-ul nu merge)
$catName     = $catNames[$step];
$categoryURL = $categories[$catName];
$filename    = $isCli && $opt['out'] !== '' ? $opt['out'] : csvFilename($catName);

/* ================= LISTARE ================= */

$products = [];
for ($page = 1; $page <= 100; $page++) {
    $url = $page === 1 ? $categoryURL : rtrim($categoryURL, '/') . "/page/$page/";
    $status = 0;
    $html = getHTML($url, $status);
    if ($html === false) { logLine("Pagina $page: HTTP $status → stop"); break; }
    $before = count($products);
    $got = parseListing($html, $products);
    logLine("Pagina $page: $got produse (" . (count($products) - $before) . " noi)");
    if ($got === 0 || count($products) === $before) break;
}
$products = array_values($products);
if ($opt['only'] !== '') {
    $products = array_values(array_filter($products, fn($p) => strpos($p['link'], $opt['only']) !== false));
}
if ($opt['limit'] > 0) $products = array_slice($products, 0, $opt['limit']);
logLine("Total produse: " . count($products));

/* ================= CSV ================= */

$csv = fopen($filename, 'w');
fputcsv($csv, [
    'Product Name', 'Product Reference Code', 'Combinations Reference Code',
    'Attribute Group 1', 'Attribute Value 1', 'Attribute Group 2', 'Attribute Value 2',
    'Description', 'Retail Price With Tax', 'Final Price With Tax', 'Category Default ID',
    'Product Image Urls', 'Active', 'EAN13', 'Supplier Reference',
    'Stoc gestiune', 'Pret site EUR', 'Sursa pret',
], ';');

$i = 0; $added = 0; $skipped = 0; $noStock = 0; $warn = 0; $rows = 0;

foreach ($products as $product) {
    $i++;
    $status = 0;
    $html = getHTML($product['link'], $status);
    if ($html === false) { logLine("$catName → $i : EROARE HTTP $status — " . $product['link']); $warn++; continue; }

    $p = parseProduct($html);
    $rawName = untruncatedName($p['name'] !== '' ? $p['name'] : $product['name'], $p['attrs']);
    $name    = normalizeName($rawName, $IT_RO);
    $cats    = $p['cats'] ?: $product['cats'];

    if (empty($p['variations'])) {
        logLine("$catName → $i : FĂRĂ VARIANTE (sărit) — $rawName");
        $warn++;
        continue;
    }

    /* ---- referință de bază + SKU-uri ---- */
    $variantSkus = array_column($p['variations'], 'sku');
    $baseRef = '';
    foreach ($p['variations'] as $v) {
        if ($v['sku'] === '') continue;
        if (sizeCodeFromSku($v['sku'], $v['size']) !== '') { $baseRef = baseRefFromSku($v['sku']); break; }
    }
    if ($baseRef === '') {
        // codul de mărime din SKU ≠ eticheta (ex. deflector bărbie NS10061000 cu mărimea „XL/XXL”):
        // referința produsului = SKU-ul de produs de pe site, fără separatori (NS1006/100 → NS1006100), unic per produs
        $baseRef = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $p['sku']));
        logLine("   ↳ notă: mărimea nu e în SKU (" . implode(',', $variantSkus) . ") → ref din SKU-ul produsului: $baseRef");
    }

    if ($pid = existingProduct($baseRef, $variantSkus)) {
        logLine("$catName → $i : SKIP (există #$pid, ref $baseRef) — $name");
        $skipped++;
        continue;
    }

    /* ---- mărimi lipsă din JSON dar prezente în tabelul de atribute (variantă dezactivată) ---- */
    $haveSizes = array_map(fn($v) => strtoupper($v['size']), $p['variations']);
    $extra = [];
    foreach ($p['sizes_table'] as $s) {
        $S = strtoupper($s);
        if (!in_array($S, $haveSizes, true) && strlen($S) === 2 && strlen($baseRef) >= 6 && !strpos($baseRef, '-')) {
            $ref = substr($baseRef, 0, 6) . $S . substr($baseRef, 6);
            $p['variations'][] = ['id' => 0, 'sku' => $ref, 'size' => $S, 'color' => $p['variations'][0]['color'], 'price' => $p['variations'][0]['price'],
                                  'regular' => $p['variations'][0]['regular'], 'in_stock' => false, 'image' => '', 'derived' => true];
            $extra[] = $S;
        }
    }

    /* ---- gestiune per variantă ---- */
    $gest = [];
    foreach ($p['variations'] as $k => $v) $gest[$k] = $v['sku'] !== '' ? gestiune($v['sku']) : null;
    $stockTotal = array_sum(array_map(fn($g) => $g ? $g['stoc'] : 0, $gest));
    if ($opt['instock'] && $stockTotal <= 0) {
        logLine("$catName → $i : fără stoc în gestiune (sărit) — $name");
        $noStock++;
        continue;
    }

    /* ---- descriere / imagini / categorie ---- */
    $descParts = [];
    if ($p['short'] !== '') $descParts[] = '<p>' . h($p['short']) . '</p>';
    if ($p['desc'] !== '' && $p['desc'] !== $p['short']) $descParts[] = '<p>' . h($p['desc']) . '</p>';
    $description = implode('', $descParts);

    $images = [];
    foreach ($p['images'] as $src) $images[] = fullImage($src);
    foreach ($p['variations'] as $v) if ($v['image'] !== '') $images[] = fullImage($v['image']);
    $images = array_values(array_unique(array_filter($images)));
    $imageList = implode(',', $images);

    $catId = categoryIdFor($cats, $rawName, $CAT_MAP);

    /* ---- rânduri ---- */
    $eanHits = 0; $gestHits = 0; $fromSite = 0;
    $siteEur = 0.0;
    foreach ($p['variations'] as $k => $v) {
        $g = $gest[$k];
        $size = $SIZE_MAP[strtoupper($v['size'])] ?? strtoupper($v['size']);
        $siteEur = $v['regular'] > 0 ? $v['regular'] : $v['price'];
        if ($g && $g['pret'] > 0) {
            $price = number_format($g['pret'], 2, '.', ''); $src = 'gestiune'; $gestHits++;
        } else {
            $price = number_format($siteEur * EUR_RON, 2, '.', ''); $src = 'site'; $fromSite++;
        }
        $ean = $g ? $g['ean'] : '';
        if ($ean !== '') $eanHits++;

        fputcsv($csv, [
            $name, $baseRef, $v['sku'], 'Color', 'DEFAULT', 'Size', $size,
            $description, $price, $price, $catId, $imageList, 0, $ean, $p['sku'],
            $g ? $g['stoc'] : 0, number_format($siteEur, 2, '.', ''), $src . (!empty($v['derived']) ? ' (marime derivata)' : ''),
        ], ';');
        $rows++;
    }

    $n = count($p['variations']);
    $sale = '';
    foreach ($p['variations'] as $v) if ($v['regular'] > 0 && $v['price'] < $v['regular']) { $sale = " [redus pe site: {$v['price']} din {$v['regular']} EUR]"; break; }
    logLine("$catName → $i : $name");
    logLine("   ↳ ref $baseRef | cat $catId | $n mărimi | preț gestiune $gestHits/$n, site $fromSite/$n | EAN $eanHits/$n | stoc total $stockTotal"
          . ($extra ? " | mărimi derivate: " . implode(',', $extra) : '') . $sale . (count($images) === 0 ? ' | FĂRĂ IMAGINI' : ''));
    $added++;
    usleep(700000);   // ritm blând: site-ul dă 503 la rafale
}
fclose($csv);

logLine("Produse noi în CSV: $added ($rows rânduri) | sărite (există pe bikershop): $skipped" . ($opt['instock'] ? " | fără stoc: $noStock" : '') . " | avertismente: $warn");

/* ================= FINAL ================= */

if ($isCli) {
    if ($added === 0) { @unlink($filename); logLine("— Niciun produs nou: nu se generează CSV."); exit(0); }
    logLine("✔ CSV generat: $filename (" . number_format(filesize($filename)) . " bytes)");
    exit(0);
}

$next     = $step + 1;
$nextName = $next < $total ? $catNames[$next] : null;
$nextUrl  = $self . "?step=" . $next . $qs;

if ($added === 0) {
    @unlink($filename);
    echo h("— Niciun produs nou în această categorie: nu se generează CSV.") . "\n</div>";
    echo "<div class='panel' id='panel'><p>Categoria <b>" . h($catName) . "</b> nu are produse noi.</p>";
    echo "<p class='muted'>Trecem automat la " . ($nextName ? "categoria următoare: <b>" . h($nextName) . "</b>" : "<b>final</b>")
       . " în 3 secunde… <a href='" . h($nextUrl) . "'>sau click aici</a>.</p></div>";
    echo "<script>document.getElementById('log').scrollTop=1e9;setTimeout(function(){window.location.href=" . json_encode($nextUrl) . ";},3000);</script></body></html>";
    exit;
}

echo h("✔ CSV generat: " . basename($filename) . " (" . number_format(filesize($filename)) . " bytes)") . "\n</div>";
echo "<div class='panel' id='panel'><p>Categoria <b>" . h($catName) . "</b> este gata.</p>";
echo "<p><button class='btn' id='dl' type='button'>⬇ Descarcă " . h(basename($filename)) . "</button></p>";
echo "<p class='muted' id='msg'>La click, fișierul se descarcă, apoi se șterge de pe server și trecem automat la "
   . ($nextName ? "categoria următoare: <b>" . h($nextName) . "</b>" : "<b>final</b>") . ".</p></div>";
echo "<iframe id='dlframe' style='display:none'></iframe>";
echo "<script>
(function(){
  var self = " . json_encode($self) . ", step = $step, nextUrl = " . json_encode($nextUrl) . ";
  var btn = document.getElementById('dl'), msg = document.getElementById('msg');
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
          if (!j.exists) { clearInterval(timer); msg.innerHTML = 'Descărcat și șters. Trecem mai departe…'; setTimeout(function(){ window.location.href = nextUrl; }, 800); }
          else if (tries > 600) { clearInterval(timer); btn.disabled = false; msg.innerHTML = 'Descărcarea nu s-a finalizat în 10 minute. Reîncearcă sau <a href=\"' + nextUrl + '\">treci manual mai departe</a>.'; }
        }).catch(function(){});
    }, 1000);
  });
})();
</script></body></html>";
