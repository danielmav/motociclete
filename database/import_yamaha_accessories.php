<?php

declare(strict_types=1);

/**
 * Import accesorii originale Yamaha în baza portalului (sursă de adevăr a relației
 * accesoriu↔model). Pentru fiecare model Yamaha cu `products.yamaha_pid` setat:
 *   1. construiește URL-ul hyperdrive (GUID categorie CONSTANT, doar PID-ul variază),
 *   2. paginează tot feedul JSON (limit/offset),
 *   3. extrage referința/nume/preț EUR per accesoriu,
 *   4. face match referință → id_product pe BikerShop (read-only) pentru link-ul de cumpărare,
 *   5. UPSERT în `yamaha_accessories` + reconstruiește `yamaha_accessory_models`,
 *   6. raport diff (adăugate / preț schimbat / fără produs pe bikershop).
 *
 * Cumpărarea rămâne pe BikerShop → prețul/imaginea/URL-ul vin live la afișare prin
 * `bs_product_id`; aici stocăm doar relația + referința rezolvată.
 *
 * Rulează cu Laragon PHP 8.1 (are curl + pdo_mysql):
 *   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/import_yamaha_accessories.php [--apply]
 * Fără --apply = dry-run (nu scrie nimic, doar raportează).
 */

use App\BikerShop\Client;
use App\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
$settings = require $root . '/config/settings.php';

$apply = in_array('--apply', $argv, true);

// Template URL hyperdrive (GUID categorie constant; PID = placeholder; limit/offset la paginare).
const URL_TEMPLATE =
    'https://hyperdrive.yamaha-motor.eu/products/yme-prod-ro'
    . '?projectKey=yme-prod-ro&locale=ro-RO'
    . '&query=categories.id:subtree(%221a517708-545a-4094-89e3-ca507def0af3%22)'
    . '%7Cvariants.attributes.embargoExternalReleased:true'
    . '%7Cvariants.attributes.products:%PID%'
    . '&allFacets=categories.id%7Cvariants.attributes.collection%7Cvariants.attributes.gender%7Cvariants.attributes.accessoryType'
    . '&selectedFacets='
    . '&sort=variants.attributes.popularityIndex.desc%7Cvariants.sku.desc'
    . '&text=&productType=Accessory&version=caas';

function getJson(string $url): ?array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0',
            'Accept: application/json',
            'Referer: https://www.yamaha-motor.eu/',
            'Origin: https://www.yamaha-motor.eu',
        ],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body === false ? null : json_decode((string) $body, true);
}

/** PrestaShop reference: SKU fără cratime, fără ultimele 2 caractere (mărime). */
function extractBaseReference(string $sku): string
{
    return substr(str_replace('-', '', $sku), 0, -2);
}

/** Extrage {yamaha_id, reference, name, price_eur, type} din variantele unui produs. */
function shapeAccessory(array $product): array
{
    $priceEur = 0.0;
    $reference = '';
    foreach (($product['variants'] ?? []) as $v) {
        if ($priceEur === 0.0 && !empty($v['prices'][0]['amount']) && $v['prices'][0]['amount'] > 0) {
            $priceEur = (float) $v['prices'][0]['amount'];
        }
        if ($reference === '' && !empty($v['sku'])) {
            $reference = extractBaseReference((string) $v['sku']);
        }
    }
    $type = '';
    foreach (($product['variants'][0]['attributes'] ?? []) as $a) {
        if (($a['name'] ?? '') === 'accessoryType') {
            $type = is_array($a['value'] ?? null) ? (string) reset($a['value']) : (string) ($a['value'] ?? '');
            break;
        }
    }
    return [
        'yamaha_id' => (string) ($product['id'] ?? ''),
        'reference' => $reference,
        'name'      => trim(preg_replace('/\s+/u', ' ', (string) ($product['name'] ?? ''))),
        'price_eur' => $priceEur,
        'type'      => $type,
    ];
}

/** Aduce toate accesoriile (paginat) pentru un PID. @return array<int,array<string,mixed>> */
function fetchAll(string $pid): array
{
    $base = str_replace('%PID%', $pid, URL_TEMPLATE);
    $limit = 96;
    $offset = 0;
    $out = [];
    do {
        $data = getJson($base . "&limit={$limit}&offset={$offset}");
        if (!is_array($data)) {
            break;
        }
        $total = (int) ($data['total'] ?? 0);
        $batch = $data['results'] ?? [];
        if (!$batch) {
            break;
        }
        foreach ($batch as $p) {
            $out[] = $p;
        }
        $offset += $limit;
        if ($offset < $total) {
            usleep(500000); // politețe / anti-blocare
        }
    } while ($offset < $total);
    return $out;
}

// ── Setup ─────────────────────────────────────────────────────────────────
$db   = new Database($settings['db']);
$pdo  = $db->local();
$bs   = new Client($db, $settings['db']['bikershop']);

if (!$bs->isAvailable()) {
    fwrite(STDERR, "AVERTISMENT: BikerShop indisponibil — accesoriile vor fi stocate FĂRĂ bs_product_id (fără link de cumpărare). Verifică whitelist-ul IP.\n");
}

$models = $pdo->query(
    "SELECT id, name, yamaha_pid FROM products
     WHERE brand = 'yamaha' AND yamaha_pid IS NOT NULL AND yamaha_pid <> '' AND is_active = 1
     ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);

if (!$models) {
    echo "Niciun model Yamaha cu yamaha_pid setat. Setează-l în admin (editor produs) și reia.\n";
    exit;
}

echo ($apply ? "MOD: APPLY (scriu în DB)\n" : "MOD: DRY-RUN (nu scriu nimic; rulează cu --apply ca să salvezi)\n");
echo "Modele de procesat: " . count($models) . "\n\n";

// Starea curentă: yamaha_id => {id, price_eur} (pentru diff).
$existing = [];
foreach ($pdo->query("SELECT id, yamaha_id, price_eur FROM yamaha_accessories")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $existing[$r['yamaha_id']] = ['id' => (int) $r['id'], 'price_eur' => (float) $r['price_eur']];
}

// Prepared statements (folosite doar la --apply).
$upsert = $pdo->prepare(
    "INSERT INTO yamaha_accessories (yamaha_id, reference, name, price_eur, accessory_type, bs_product_id)
     VALUES (:yid, :ref, :name, :price, :type, :bs)
     ON DUPLICATE KEY UPDATE reference=VALUES(reference), name=VALUES(name),
        price_eur=VALUES(price_eur), accessory_type=VALUES(accessory_type), bs_product_id=VALUES(bs_product_id)"
);
$selId    = $pdo->prepare("SELECT id FROM yamaha_accessories WHERE yamaha_id = :yid");
$delLinks = $pdo->prepare("DELETE FROM yamaha_accessory_models WHERE product_id = :pid");
$insLink  = $pdo->prepare(
    "INSERT INTO yamaha_accessory_models (accessory_id, product_id, position)
     VALUES (:aid, :pid, :pos) ON DUPLICATE KEY UPDATE position = VALUES(position)"
);

$stat = ['fetched' => 0, 'added' => 0, 'price_changed' => 0, 'unmatched' => 0, 'links' => 0];

foreach ($models as $m) {
    $pid = (string) $m['yamaha_pid'];
    $products = fetchAll($pid);
    echo sprintf("── %-32s PID %-8s → %d accesorii\n", mb_substr((string) $m['name'], 0, 32), $pid, count($products));
    if (!$products) {
        continue;
    }

    // Shape + dedup pe yamaha_id în cadrul modelului, păstrând ordinea (popularitate).
    $shaped = [];
    foreach ($products as $p) {
        $s = shapeAccessory($p);
        if ($s['yamaha_id'] === '' || isset($shaped[$s['yamaha_id']])) {
            continue;
        }
        $shaped[$s['yamaha_id']] = $s;
    }

    // Match referințe → bs_product_id (un singur query per model).
    $refs = array_values(array_filter(array_map(static fn ($s) => $s['reference'], $shaped)));
    $bsMap = $bs->productIdsByReferences($refs);

    if ($apply) {
        $delLinks->execute([':pid' => (int) $m['id']]);
    }

    $pos = 0;
    foreach ($shaped as $yid => $s) {
        $stat['fetched']++;
        $bsId = $s['reference'] !== '' ? ($bsMap[$s['reference']] ?? null) : null;
        if ($bsId === null) {
            $stat['unmatched']++;
        }
        if (!isset($existing[$yid])) {
            $stat['added']++;
        } elseif (abs($existing[$yid]['price_eur'] - $s['price_eur']) > 0.001) {
            $stat['price_changed']++;
        }

        if ($apply) {
            $upsert->execute([
                ':yid' => $yid, ':ref' => $s['reference'], ':name' => $s['name'],
                ':price' => $s['price_eur'], ':type' => $s['type'], ':bs' => $bsId,
            ]);
            $selId->execute([':yid' => $yid]);
            $accId = (int) $selId->fetchColumn();
            if ($accId > 0) {
                $insLink->execute([':aid' => $accId, ':pid' => (int) $m['id'], ':pos' => $pos++]);
                $stat['links']++;
            }
        } else {
            $stat['links']++;
        }
    }
}

echo "\n" . str_repeat('─', 60) . "\n";
echo "Accesorii procesate (rânduri model×accesoriu): {$stat['links']}\n";
echo "Accesorii distincte preluate (total întâlniri): {$stat['fetched']}\n";
echo "  · noi (yamaha_id necunoscut): {$stat['added']}\n";
echo "  · cu preț schimbat: {$stat['price_changed']}\n";
echo "  · FĂRĂ produs pe bikershop (fără link cumpărare): {$stat['unmatched']}\n";
echo ($apply ? "\nSalvat în yamaha_accessories + yamaha_accessory_models.\n" : "\n(dry-run — nimic salvat; adaugă --apply)\n");
