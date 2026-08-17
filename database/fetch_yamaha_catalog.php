<?php

declare(strict_types=1);

/**
 * Snapshot al catalogului public de accesorii Yamaha → tabela `yamaha_catalog`.
 *
 * Rulează cu binarul Laragon 8.1 (are curl + pdo_mysql):
 *   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/fetch_yamaha_catalog.php [--apply]
 *
 * Fără --apply doar raportează ce ar scrie (dry-run).
 *
 * De ce există: referințele din categoria 473 de pe BikerShop au fost salvate
 * TRUNCHIATE (10 caractere în loc de 12), iar sufixul lipsă NU e mereu „00"
 * (ex. BR8-HIPER-KT-10). Singura sursă de adevăr pentru codul complet + prețul
 * corect este catalogul Yamaha.
 */

use App\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/_dbutil.php';
Dotenv::createImmutable($root)->safeLoad();

$settings = require $root . '/config/settings.php';
$argvList = $argv ?? [];
$apply    = in_array('--apply', $argvList, true);

/** Subtree-ul „toate accesoriile" (fără filtru pe model). GUID constant. */
const CATALOG_GUID = 'bf96ad91-485c-4c2b-86a0-c1857d86097b';

const URL_TEMPLATE =
    'https://hyperdrive.yamaha-motor.eu/products/yme-prod-ro'
    . '?projectKey=yme-prod-ro&locale=ro-RO'
    . '&query=categories.id:subtree(%22' . CATALOG_GUID . '%22)'
    . '%7Cvariants.attributes.embargoExternalReleased:true'
    . '&allFacets=categories.id%7Cvariants.attributes.collection%7Cvariants.attributes.gender%7Cvariants.attributes.accessoryType'
    . '&selectedFacets='
    . '&sort=variants.attributes.popularityIndex.desc%7Cvariants.sku.desc'
    . '&text=&productType=Accessory&version=caas';

/**
 * GET JSON — același tipar ca App\Accessories\Importer::getJson().
 * @return array<string,mixed>|null
 */
function fetch_json(string $url): ?array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_HTTPHEADER     => [
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

/** Normalizare SKU → formatul `ps_product.reference` (fără cratime/spații, majuscule). */
function normalize_sku(string $sku): string
{
    return strtoupper((string) preg_replace('/[\s\-\.]+/', '', $sku));
}

echo "Yamaha catalog snapshot" . ($apply ? '' : ' (dry-run)') . "\n";
echo str_repeat('─', 72) . "\n";

// ---- 1. Preia tot catalogul, paginat ------------------------------------
$limit  = 200;
$offset = 0;
$rows   = [];   // sku => rând
$total  = 0;

do {
    $data = fetch_json(URL_TEMPLATE . "&limit={$limit}&offset={$offset}");
    if (!is_array($data)) {
        fwrite(STDERR, "Eșec la offset {$offset} — Yamaha indisponibil. Nu se scrie nimic.\n");
        exit(1);
    }
    $total = (int) ($data['total'] ?? 0);
    $batch = $data['results'] ?? [];
    if (!$batch) {
        break;
    }

    foreach ($batch as $p) {
        $yid  = (string) ($p['id'] ?? '');
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($p['name'] ?? '')));

        $type = '';
        foreach (($p['variants'][0]['attributes'] ?? []) as $a) {
            if (($a['name'] ?? '') === 'accessoryType') {
                $type = is_array($a['value'] ?? null) ? (string) reset($a['value']) : (string) ($a['value'] ?? '');
                break;
            }
        }

        // Fiecare variantă are propriul SKU (mărimi) → fiecare devine un rând.
        foreach (($p['variants'] ?? []) as $v) {
            $raw = (string) ($v['sku'] ?? '');
            if ($raw === '') {
                continue;
            }
            $sku = normalize_sku($raw);
            if ($sku === '') {
                continue;
            }
            $rows[$sku] = [
                'sku'       => $sku,
                'sku_raw'   => $raw,
                'sku_base'  => substr($sku, 0, 10),
                'yamaha_id' => $yid,
                'name'      => $name,
                'price_eur' => (float) ($v['prices'][0]['amount'] ?? 0),
                'type'      => $type,
                'image'     => (string) ($v['images'][0]['url'] ?? ''),
            ];
        }
    }

    $offset += $limit;
    if ($offset < $total) {
        usleep(400000); // politețe
    }
} while ($offset < $total);

$lengths = [];
$noPrice = 0;
foreach ($rows as $r) {
    $lengths[strlen($r['sku'])] = ($lengths[strlen($r['sku'])] ?? 0) + 1;
    if ($r['price_eur'] <= 0) {
        $noPrice++;
    }
}
ksort($lengths);

printf("  produse Yamaha : %d\n", $total);
printf("  SKU-uri unice  : %d\n", count($rows));
printf("  fără preț      : %d\n", $noPrice);
echo   "  lungimi SKU    : ";
foreach ($lengths as $len => $n) {
    echo "{$len}→{$n}  ";
}
echo "\n";

if (!$rows) {
    fwrite(STDERR, "Niciun SKU primit. Nu se scrie nimic.\n");
    exit(1);
}

// ---- 2. Scrie în DB -----------------------------------------------------
if (!$apply) {
    echo str_repeat('─', 72) . "\n";
    echo "Dry-run: rulează din nou cu --apply ca să scrie în `yamaha_catalog`.\n";
    exit(0);
}

$pdo = (new Database($settings['db']))->local();
run_sql_file($pdo, __DIR__ . '/schema_yamaha_catalog.sql');

$upsert = $pdo->prepare(
    "INSERT INTO yamaha_catalog (sku, sku_raw, sku_base, yamaha_id, name, price_eur, accessory_type, image_url)
     VALUES (:sku, :raw, :base, :yid, :name, :price, :type, :img)
     ON DUPLICATE KEY UPDATE sku_raw=VALUES(sku_raw), sku_base=VALUES(sku_base),
        yamaha_id=VALUES(yamaha_id), name=VALUES(name), price_eur=VALUES(price_eur),
        accessory_type=VALUES(accessory_type), image_url=VALUES(image_url)"
);

$pdo->beginTransaction();
foreach ($rows as $r) {
    $upsert->execute([
        ':sku'   => $r['sku'],
        ':raw'   => $r['sku_raw'],
        ':base'  => $r['sku_base'],
        ':yid'   => $r['yamaha_id'],
        ':name'  => mb_substr($r['name'], 0, 512),
        ':price' => $r['price_eur'],
        ':type'  => mb_substr($r['type'], 0, 128),
        ':img'   => mb_substr($r['image'], 0, 512),
    ]);
}
$pdo->commit();

$inDb = (int) $pdo->query("SELECT COUNT(*) FROM yamaha_catalog")->fetchColumn();
echo str_repeat('─', 72) . "\n";
printf("  ✓ scris. Total în `yamaha_catalog`: %d\n", $inDb);
