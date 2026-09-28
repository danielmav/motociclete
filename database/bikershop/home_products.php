<?php
/**
 * Produsele din zonele de pe prima pagină BikerShop (IqitElementor, landing activ).
 *
 * Rulează PE SERVERUL BIKERSHOP, din ~/public_html/tool/ (bootstrap PrestaShop), ca să treacă prin aceeași cale
 * ca editorul Elementor: IqitElementorLanding::update() + iqitelementor->clearHomeCache().
 *
 *   ea-php84 home_products.php home_products_lista.txt # dry-run: validează + arată diferențele
 *   ea-php84 home_products.php home_products_lista.txt --apply # scrie (backup JSON per limbă în logs/)
 *   ea-php84 home_products.php --restore=<ts>       # pune înapoi backup-ul unei rulări
 *
 * Fișierul de intrare: un rând-titlu cu numele zonei (Arai / AGV / TCX / Dainese / Mix),
 * urmat de produse, câte unul pe rând: link bikershop.ro, id_product sau cod produs (reference).
 * Zonele care nu apar în fișier rămân neatinse. Ordinea din fișier = ordinea din carusel.
 *
 * Opțiuni: --ps-root=<dir> (implicit ~/public_html).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Doar din linia de comandă.\n");
}

// Zonă → id-ul widgetului ProductsList în landing, per limbă (1 = RO, 2 = EN).
// Id-urile se văd în JSON-ul din ps_iqit_elementor_landing_lang. Dacă cineva recreează
// widgetul din editor, id-ul se schimbă → scriptul se oprește cu „widget negăsit".
const ZONES = [
    'arai'    => [1 => 'ncqty55', 2 => 'rvcxuvx'],
    'mix'     => [1 => 'e9sjnkq'],
    'dainese' => [1 => '6dw5n9m', 2 => 'cjyhx8u'],
    'agv'     => [1 => 'r9chv1y', 2 => 'rybtea7'],
    'tcx'     => [1 => 'fshkooz', 2 => 'jgo82sl'],
];
const ID_SHOP = 1;

$opt = ['apply' => false, 'restore' => null, 'input' => null, 'ps_root' => getenv('HOME') . '/public_html'];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--apply') {
        $opt['apply'] = true;
    } elseif (str_starts_with($a, '--restore=')) {
        $opt['restore'] = substr($a, 10);
    } elseif (str_starts_with($a, '--ps-root=')) {
        $opt['ps_root'] = rtrim(substr($a, 10), '/');
    } elseif (!str_starts_with($a, '--')) {
        $opt['input'] = $a;
    } else {
        fwrite(STDERR, "Opțiune necunoscută: {$a}\n");
        exit(1);
    }
}
if ($opt['input'] === null && $opt['restore'] === null) {
    fwrite(STDERR, "Folosire: home_products.php lista.txt [--apply] | --restore=<ts>\n");
    exit(1);
}

$logDir = __DIR__ . '/logs'; // comun cu celelalte scripturi din tool/ → fișierele au prefix home-products-
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// ---------------------------------------------------------------------------
// Bootstrap PrestaShop (același tipar ca enrich_yamaha_accessories.php)
// ---------------------------------------------------------------------------
$root = $opt['ps_root'];
if (!is_file($root . '/config/config.inc.php')) {
    fwrite(STDERR, "PrestaShop nu e la {$root}.\n");
    exit(1);
}
if (!defined('_PS_ADMIN_DIR_')) {
    $adminDir = $root . '/admin';
    foreach (scandir($root) as $entry) {
        if (str_starts_with($entry, '__admin') && is_dir($root . '/' . $entry)) {
            $adminDir = $root . '/' . $entry;
            break;
        }
    }
    define('_PS_ADMIN_DIR_', $adminDir);
}
require $root . '/config/config.inc.php';

$db     = Db::getInstance();
$prefix = _DB_PREFIX_;
$module = Module::getInstanceByName('iqitelementor');
if (!$module || !class_exists('IqitElementorLanding')) {
    fwrite(STDERR, "Modulul iqitelementor nu e disponibil.\n");
    exit(1);
}
$landingId = (int) Configuration::get('iqit_homepage_layout');
if ($landingId <= 0) {
    fwrite(STDERR, "iqit_homepage_layout nu e setat.\n");
    exit(1);
}

function landing_data(int $landingId, int $idLang): string
{
    return (string) (new IqitElementorLanding($landingId, $idLang))->data;
}

function save_landing(int $landingId, int $idLang, string $data): void
{
    $landing = new IqitElementorLanding($landingId, $idLang);
    $landing->data = $data;
    if (!$landing->update()) {
        throw new RuntimeException("update() a eșuat pentru landing {$landingId}, limba {$idLang}");
    }
}

// Păstrează stilul de encodare al editorului (slash-uri / unicode escapate sau nu).
// JSON-ul e decodat ca obiecte → `{}` rămâne `{}` (nu devine `[]`).
function encode_like($data, string $original): string
{
    $flags = JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
    if (!str_contains($original, '\\/')) {
        $flags |= JSON_UNESCAPED_SLASHES;
    }
    if (!preg_match('/\\\\u[0-9a-fA-F]{4}/', $original)) {
        $flags |= JSON_UNESCAPED_UNICODE;
    }
    return json_encode($data, $flags);
}

/** Widgetul (stdClass) cu id-ul dat, căutat recursiv; obiectele se modifică pe loc. */
function find_widget(array $elements, string $id): ?stdClass
{
    foreach ($elements as $el) {
        if (($el->id ?? null) === $id) {
            return $el;
        }
        if (!empty($el->elements) && ($found = find_widget($el->elements, $id))) {
            return $found;
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// --restore
// ---------------------------------------------------------------------------
if ($opt['restore'] !== null) {
    $files = glob($logDir . '/home-products-backup-' . preg_replace('/[^0-9_-]/', '', $opt['restore']) . '-landing' . $landingId . '-lang*.json');
    if (!$files) {
        fwrite(STDERR, "Nu găsesc backup-uri pentru {$opt['restore']} în {$logDir}.\n");
        exit(1);
    }
    foreach ($files as $f) {
        preg_match('/-lang(\d+)\.json$/', $f, $m);
        save_landing($landingId, (int) $m[1], (string) file_get_contents($f));
        echo "Restaurat limba {$m[1]} din " . basename($f) . "\n";
    }
    $module->clearHomeCache();
    echo "Cache home golit.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Citire listă
// ---------------------------------------------------------------------------
$lines = @file($opt['input'], FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "Nu pot citi {$opt['input']}.\n");
    exit(1);
}
$wanted = [];   // zona => [token, ...]
$zone = null;
$errors = [];
foreach ($lines as $n => $line) {
    $line = trim(preg_replace('/^\xEF\xBB\xBF/', '', $line));
    if ($line === '') {
        continue;
    }
    if (preg_match('~^https?://~i', $line)) {
        // bikershop.ro/<categorie>/<id_product>-<id_combinatie>-<slug>.html#/...
        $path = (string) parse_url($line, PHP_URL_PATH);
        if (!preg_match('~/(\d+)-[^/]*$~', $path, $m)) {
            $errors[] = 'rândul ' . ($n + 1) . ": nu găsesc id-ul produsului în {$line}";
            continue;
        }
        $token = ['id', (int) $m[1], $line];
    } elseif (isset(ZONES[strtolower($line)])) {
        $zone = strtolower($line);
        $wanted[$zone] = [];
        continue;
    } elseif (ctype_digit($line)) {
        $token = ['id', (int) $line, $line];
    } else {
        $token = ['ref', $line, $line];
    }
    if ($zone === null) {
        $errors[] = 'rândul ' . ($n + 1) . ": produs înaintea unui titlu de zonă ({$line}) sau zonă necunoscută";
        continue;
    }
    $wanted[$zone][] = $token;
}

// ---------------------------------------------------------------------------
// Validare produse (există, activ pe shop, are copertă)
// ---------------------------------------------------------------------------
$resolved = []; // zona => [id_product, ...]
foreach ($wanted as $z => $tokens) {
    $resolved[$z] = [];
    foreach ($tokens as [$kind, $val, $raw]) {
        $where = $kind === 'id'
            ? 'p.id_product = ' . (int) $val
            : "p.reference = '" . pSQL($val) . "'";
        $rows = $db->executeS("
            SELECT p.id_product, p.reference, ps.active, pl.name,
                   (SELECT COUNT(*) FROM {$prefix}image i WHERE i.id_product = p.id_product AND i.cover = 1) AS covers
            FROM {$prefix}product p
            LEFT JOIN {$prefix}product_shop ps ON ps.id_product = p.id_product AND ps.id_shop = " . ID_SHOP . "
            LEFT JOIN {$prefix}product_lang pl ON pl.id_product = p.id_product AND pl.id_lang = 1 AND pl.id_shop = " . ID_SHOP . "
            WHERE {$where}");
        if (!$rows) {
            $errors[] = "[{$z}] produs inexistent: {$raw}";
            continue;
        }
        if (count($rows) > 1) {
            $errors[] = "[{$z}] codul {$val} e pe " . count($rows) . ' produse (' . implode(', ', array_column($rows, 'id_product')) . ') — folosește linkul sau id-ul';
            continue;
        }
        $p = $rows[0];
        $flags = [];
        if ((int) $p['active'] !== 1) {
            $flags[] = 'INACTIV';
        }
        if ((int) $p['covers'] === 0) {
            $flags[] = 'FĂRĂ IMAGINE';
        }
        if (in_array((int) $p['id_product'], $resolved[$z], true)) {
            $flags[] = 'DUPLICAT';
        }
        printf("  [%-7s] %7d  %-16s %s%s\n", $z, $p['id_product'], $p['reference'], $p['name'],
            $flags ? '  <<< ' . implode(', ', $flags) : '');
        if ($flags) {
            $errors[] = "[{$z}] {$p['id_product']} {$p['name']}: " . implode(', ', $flags);
            continue;
        }
        $resolved[$z][] = (int) $p['id_product'];
    }
}

if ($errors) {
    echo "\nNu scriu nimic — de corectat:\n  - " . implode("\n  - ", $errors) . "\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Aplicare pe JSON-ul fiecărei limbi
// ---------------------------------------------------------------------------
$langs = [];
foreach (ZONES as $map) {
    foreach (array_keys($map) as $l) {
        $langs[$l] = true;
    }
}
$ts = date('Ymd-His');
$changed = 0;
foreach (array_keys($langs) as $idLang) {
    $original = landing_data($landingId, $idLang);
    $data = json_decode($original);
    if (!is_array($data)) {
        fwrite(STDERR, "Landing {$landingId} limba {$idLang}: JSON invalid.\n");
        exit(1);
    }
    echo "\n== landing {$landingId}, limba {$idLang}\n";
    $langChanged = false;
    foreach ($resolved as $z => $ids) {
        $widgetId = ZONES[$z][$idLang] ?? null;
        if ($widgetId === null) {
            continue;
        }
        $w = find_widget($data, $widgetId);
        if ($w === null || ($w->settings->product_source ?? '') !== 'ms') {
            fwrite(STDERR, "Widgetul {$widgetId} ({$z}, limba {$idLang}) negăsit sau nu e pe selecție manuală — actualizează ZONES.\n");
            exit(1);
        }
        $old = array_map('intval', (array) ($w->settings->products_ids ?? []));
        $new = $ids;
        $status = $old === $new ? 'neschimbat' : 'MODIFICAT';
        echo "  {$z} [{$widgetId}] {$status}\n    înainte: " . implode(', ', $old) . "\n    după:    " . implode(', ', $new) . "\n";
        if ($old !== $new) {
            $w->settings->products_ids = array_map('strval', $new);
            if ((int) ($w->settings->products_limit ?? 0) < count($new)) {
                $w->settings->products_limit = (string) count($new);
            }
            $langChanged = true;
        }
    }
    if ($langChanged && $opt['apply']) {
        file_put_contents("{$logDir}/home-products-backup-{$ts}-landing{$landingId}-lang{$idLang}.json", $original);
        save_landing($landingId, $idLang, encode_like($data, $original));
        $changed++;
    }
}

if (!$opt['apply']) {
    echo "\nDry-run. Rulează cu --apply ca să scrie.\n";
    exit(0);
}
if ($changed > 0) {
    $module->clearHomeCache();
    echo "\nSalvat ({$changed} limbi), cache home golit. Backup: --restore={$ts}\n";
} else {
    echo "\nNimic de schimbat.\n";
}
