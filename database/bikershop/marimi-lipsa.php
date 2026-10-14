<?php
declare(strict_types=1);

/**
 * Mărimi din stoc care lipsesc de la un produs activ de pe BikerShop → variantă cu codul de gestiune.
 *
 * Intrare = CSV-ul raportului stoc-nelistat.php (rândurile „lipsește mărimea (produs activ)").
 * Pentru fiecare cod:
 *   - dacă produsul are deja o variantă pe aceeași mărime, dar cu alt cod (ex. `60251XXL` vs `602512XL`
 *     în gestiune) și codul vechi nu e în stoc → îi pune referința din gestiune;
 *   - altfel creează varianta (atributul de mărime din grupa „Optiune", preț 0, stoc 0, low_stock_threshold 0).
 * Stocul, prețul, EAN-ul și rândul furnizorului 1 le completează sincronizarea orară a gestiunii
 * (`globe/stoc-globe.php`, potrivire pe referință), care pune și produsul în coada supplierpricing.
 *
 * Utilizare (pe serverul bikershop, din ~/public_html/tool/):
 *   /usr/local/bin/ea-php84 stoc-nelistat.php --out=logs/stoc-nelistat.csv
 *   /usr/local/bin/ea-php84 marimi-lipsa.php --csv=logs/stoc-nelistat.csv            # dry-run
 *   /usr/local/bin/ea-php84 marimi-lipsa.php --csv=logs/stoc-nelistat.csv --apply    # scrie
 * Opțiuni: --only=COD[,COD], --ps-root=…
 * Log + rollback: logs/marimi-lipsa-<ts>.log, logs/rollback-marimi-lipsa-<ts>.sql.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

const SIZE_GROUP = 18;   // „Optiune" (radio) — grupa de mărimi
const STATE = 'lipsește mărimea (produs activ)';

$opt = ['apply' => false, 'csv' => null, 'only' => [], 'ps_root' => '/home2/bikershop/public_html'];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--apply') {
        $opt['apply'] = true;
    } elseif (str_starts_with($a, '--csv=')) {
        $opt['csv'] = substr($a, 6);
    } elseif (str_starts_with($a, '--only=')) {
        $opt['only'] = array_map('strtoupper', array_filter(explode(',', substr($a, 7))));
    } elseif (str_starts_with($a, '--ps-root=')) {
        $opt['ps_root'] = rtrim(substr($a, 10), '/');
    } else {
        fwrite(STDERR, "Opțiune necunoscută: {$a}\n");
        exit(2);
    }
}
if (!$opt['csv'] || !is_file($opt['csv'])) {
    fwrite(STDERR, "Lipsește --csv=<raport stoc-nelistat.php>.\n");
    exit(2);
}

$logDir = __DIR__ . '/logs';
@mkdir($logDir, 0775, true);
$ts = date('Ymd-His');
$logH = $opt['apply'] ? fopen("{$logDir}/marimi-lipsa-{$ts}.log", 'a') : null;
$rbFile = "{$logDir}/rollback-marimi-lipsa-{$ts}.sql";

function logln(string $s = ''): void
{
    global $logH;
    echo $s, "\n";
    if ($logH) {
        fwrite($logH, $s . "\n");
    }
}
function rollback(string $sql): void
{
    global $rbFile, $opt;
    if ($opt['apply']) {
        file_put_contents($rbFile, $sql . "\n", FILE_APPEND);
    }
}

// --- rândurile din raport ---
$h = fopen($opt['csv'], 'r');
$head = fgetcsv($h, 0, ';', '"', '');
$head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
$rows = [];
while (($r = fgetcsv($h, 0, ';', '"', '')) !== false) {
    $r = array_combine($head, $r);
    if ($r['Stare pe site'] !== STATE) {
        continue;
    }
    if ($opt['only'] && !in_array(strtoupper($r['Cod gestiune']), $opt['only'], true)) {
        continue;
    }
    $rows[] = $r;
}
fclose($h);

// --- PrestaShop ---
if (!defined('_PS_ADMIN_DIR_')) {
    define('_PS_ADMIN_DIR_', $opt['ps_root'] . '/__admin322y');
}
require $opt['ps_root'] . '/config/config.inc.php';
Shop::setContext(Shop::CONTEXT_SHOP, 1);
Context::getContext()->shop = new Shop(1);
$db = Db::getInstance();
$P = _DB_PREFIX_;
$idEmployee = (int) $db->getValue("SELECT id_employee FROM {$P}employee WHERE active = 1 AND id_profile = 1 ORDER BY id_employee");
if ($idEmployee) {
    Context::getContext()->employee = new Employee($idEmployee);
}
$redis = false;
try {
    $redis = Module::isEnabled('teamwant_redis');
} catch (Throwable) {
}

logln(($opt['apply'] ? 'APPLY' : 'DRY-RUN') . ' · mărimi lipsă · ' . date('Y-m-d H:i:s') . ' · ' . count($rows) . ' coduri din ' . basename($opt['csv']));

// codurile cu stoc (o variantă existentă cu alt cod se redenumește doar dacă vechiul ei cod nu e în stoc)
$inStock = [];
foreach ($db->executeS("SELECT codprodus FROM stocuri2024 WHERE stoc > 0") ?: [] as $r) {
    $inStock[strtoupper(trim((string) $r['codprodus']))] = true;
}

function norm_size(string $s): string
{
    $s = strtoupper(trim($s));
    return ['XXL' => '2XL', 'XXXL' => '3XL', 'XX' => '2XL', '3X' => '3XL', 'XXS' => '2XS', 'XXXS' => '3XS'][$s] ?? $s;
}

/** Atributul de mărime cu numele dat, din grupa de mărimi (cel mai folosit, dacă sunt mai multe cu același nume). */
function size_attribute(string $name): ?int
{
    global $db, $P;
    $id = $db->getValue("SELECT a.id_attribute FROM {$P}attribute a
        JOIN {$P}attribute_lang al ON al.id_attribute = a.id_attribute AND al.id_lang = 1
        LEFT JOIN {$P}product_attribute_combination c ON c.id_attribute = a.id_attribute
        WHERE a.id_attribute_group = " . SIZE_GROUP . " AND al.name = '" . pSQL($name) . "'
        GROUP BY a.id_attribute ORDER BY COUNT(c.id_product_attribute) DESC");   // getValue() adaugă singur LIMIT 1
    return $id ? (int) $id : null;
}

$done = 0;
foreach ($rows as $r) {
    $code = strtoupper(trim($r['Cod gestiune']));
    $id = (int) $r['ID produs BikerShop'];
    $model = strtoupper(trim($r['Cod model']));
    $ean = preg_match('/^\d{8,13}$/', $r['Cod bare']) ? $r['Cod bare'] : '';
    $label = "{$code} → #{$id} " . mb_substr((string) $r['Produs BikerShop'], 0, 45);

    if ($db->getValue("SELECT COUNT(*) FROM {$P}product_attribute WHERE reference = '" . pSQL($code) . "'")) {
        logln("  sărit  {$label}: codul există deja pe o variantă");
        continue;
    }
    // variantele produsului: o singură grupă de atribute (mărimea), altfel nu ghicim
    $siblings = $db->executeS("SELECT pa.id_product_attribute, pa.reference, pa.ean13, a.id_attribute, a.id_attribute_group, al.name
        FROM {$P}product_attribute pa
        JOIN {$P}product_attribute_combination c ON c.id_product_attribute = pa.id_product_attribute
        JOIN {$P}attribute a ON a.id_attribute = c.id_attribute
        JOIN {$P}attribute_lang al ON al.id_attribute = a.id_attribute AND al.id_lang = 1
        WHERE pa.id_product = {$id}") ?: [];
    $groups = array_unique(array_map(fn ($s) => (int) $s['id_attribute_group'], $siblings));
    if ($groups !== [SIZE_GROUP]) {
        logln("  sărit  {$label}: variantele nu sunt doar pe mărime (grupe " . implode(',', $groups) . ')');
        continue;
    }

    // mărimea: sufixul codului; dacă sufixul e numeric, iar mărimile produsului sunt litere
    // (Specialized `644217801` = XS), ultimul cuvânt din denumirea din gestiune
    $suffix = norm_size(substr($code, strlen($model)));
    $siblingNumeric = (bool) array_filter($siblings, fn ($s) => ctype_digit((string) $s['name']));
    if (ctype_digit($suffix) && !$siblingNumeric) {
        $words = preg_split('/\s+/', trim($r['Denumire']));
        $suffix = norm_size((string) end($words));
    }
    $attr = size_attribute($suffix);
    if (!$attr) {
        logln("  sărit  {$label}: nu găsesc mărimea „{$suffix}” în grupa " . SIZE_GROUP);
        continue;
    }

    $same = array_values(array_filter($siblings, fn ($s) => norm_size((string) $s['name']) === $suffix));
    if ($same) {
        $s = $same[0];
        $old = strtoupper((string) $s['reference']);
        if ($old !== '' && isset($inStock[$old])) {
            logln("  sărit  {$label}: varianta {$suffix} există cu codul {$old}, care e și el în stoc — de verificat manual");
            continue;
        }
        logln("  redenumire {$label} · mărimea {$suffix}: varianta #{$s['id_product_attribute']} {$old} → {$code}" . ($ean ? " · EAN {$ean}" : ''));
        if ($opt['apply']) {
            rollback("UPDATE {$P}product_attribute SET reference = '" . pSQL((string) $s['reference']) . "', ean13 = '" . pSQL((string) $s['ean13'])
                . "' WHERE id_product_attribute = " . (int) $s['id_product_attribute'] . ';');
            $db->execute("UPDATE {$P}product_attribute SET reference = '" . pSQL($code) . "'" . ($ean ? ", ean13 = '" . pSQL($ean) . "'" : '')
                . " WHERE id_product_attribute = " . (int) $s['id_product_attribute']);
        }
    } else {
        logln("  variantă nouă {$label} · mărimea {$suffix} (atribut #{$attr})" . ($ean ? " · EAN {$ean}" : ''));
        if ($opt['apply']) {
            try {
                // pe aceleași magazine ca variantele existente
                $shops = array_map('intval', array_column($db->executeS("SELECT DISTINCT pas.id_shop FROM {$P}product_attribute_shop pas
                    WHERE pas.id_product = {$id}") ?: [], 'id_shop')) ?: [1];
                $comb = new Combination();
                $comb->id_product = $id;
                $comb->reference = $code;
                $comb->ean13 = $ean;
                $comb->price = 0;
                $comb->minimal_quantity = 1;
                $comb->low_stock_threshold = 0; // NULL strică editorul de combinații din BO (PS 9)
                $comb->default_on = null;
                $comb->id_shop_list = $shops;
                $comb->add();
                $comb->setAttributes([$attr]);
                foreach ($shops as $shop) {
                    StockAvailable::setQuantity($id, (int) $comb->id, 0, $shop);
                }
                $ipa = (int) $comb->id;
                rollback("DELETE FROM {$P}product_attribute_combination WHERE id_product_attribute = {$ipa};");
                rollback("DELETE FROM {$P}product_attribute_shop WHERE id_product_attribute = {$ipa};");
                rollback("DELETE FROM {$P}stock_available WHERE id_product_attribute = {$ipa};");
                rollback("DELETE FROM {$P}product_supplier WHERE id_product_attribute = {$ipa};");
                rollback("DELETE FROM {$P}product_attribute WHERE id_product_attribute = {$ipa};");
                logln("    creată #{$ipa} pe magazinele " . implode(',', $shops));
            } catch (Throwable $e) {
                logln('    EROARE: ' . $e->getMessage());
                continue;
            }
        }
    }
    $done++;
    if ($opt['apply'] && $redis) {
        foreach ([1, 2] as $shop) {
            foreach ([null, 1, 2] as $lang) {
                try {
                    (new Product($id, false, $lang, $shop))->overrideObjectCache($lang);
                } catch (Throwable) {
                }
            }
        }
    }
}
logln(sprintf('%s: %d din %d. Stocul, prețul și rândul furnizorului vin la următoarea sincronizare a gestiunii (globe/stoc-globe.php, L–V 8–20 la :32).',
    $opt['apply'] ? 'Aplicat' : 'De aplicat', $done, count($rows)));
if ($opt['apply'] && is_file($rbFile)) {
    logln('Rollback: ' . $rbFile);
}
