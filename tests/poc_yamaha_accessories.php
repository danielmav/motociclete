<?php

declare(strict_types=1);

/**
 * POC — preluare accesorii originale Yamaha din endpointul hyperdrive, dat doar
 * ID-ul produsului Yamaha al modelului (yamaha_pid).
 *
 * Scop (Faza 0 din plan): dovedim că, dat un PID, putem aduce fiabil lista
 * completă de accesorii (referință, preț, imagini) fără browser/JS/auth.
 *
 * GUID-ul categoriei e CONSTANT în URL; doar PID-ul variază → URL-ul se
 * construiește dintr-un template.
 *
 * Rulare (binarul Laragon PHP 8.1 — are curl):
 *   php tests/poc_yamaha_accessories.php [PID ...]
 * Fără argumente, rulează pe setul implicit de PID-uri de test.
 *
 * MOD SIGUR pentru primul test pe server (o SINGURĂ cerere HTTP, fără paginare):
 *   - terminal:  php tests/poc_yamaha_accessories.php --probe
 *   - browser:   .../tests/poc_yamaha_accessories.php?probe=1
 * Confirmă doar că endpointul răspunde (HTTP 200 + câteva produse) de pe acel IP,
 * fără risc de flood/blocare. Abia apoi rulezi modul complet dacă vrei
 * (browser: ?pid=288454  sau  ?pid=288454,266986).
 */

// PID-uri de test implicite: R9 (288454), exemplul din comentariul scriptului (266986),
// + un al treilea ca să confirmăm că template-ul ține pentru orice model.
const DEFAULT_PIDS = [288454, 266986, 288455];

// Template URL hyperdrive: identic cu cel care funcționează în browser/Network,
// cu PID-ul ca placeholder și FĂRĂ limit/offset (le adăugăm la paginare).
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

/** @return array{0:?array<string,mixed>,1:int} [decoded JSON|null, http_status] */
function getJson(string $url): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: Mozilla/5.0',
            'Accept: application/json',
            'Referer: https://www.yamaha-motor.eu/',
            'Origin: https://www.yamaha-motor.eu',
        ],
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        fwrite(STDERR, "curl error: {$err}\n");
        return [null, $status];
    }
    return [json_decode((string) $body, true), $status];
}

/** PrestaShop reference: SKU fără cratime, fără ultimele 2 caractere (codul de mărime). */
function extractBaseReference(string $sku): string
{
    $clean = str_replace('-', '', $sku);
    return substr($clean, 0, -2);
}

/**
 * Aduce TOATE accesoriile pentru un PID, paginat.
 * @return array{products:array<int,array<string,mixed>>,total:int,status:int}
 */
function fetchAccessories(int $pid): array
{
    $base   = str_replace('%PID%', (string) $pid, URL_TEMPLATE);
    $limit  = 96;
    $offset = 0;
    $all    = [];
    $total  = 0;
    $status = 0;

    do {
        $url = $base . "&limit={$limit}&offset={$offset}";
        [$data, $status] = getJson($url);
        if (!is_array($data)) {
            break;
        }
        $total = (int) ($data['total'] ?? 0);
        $batch = $data['results'] ?? [];
        if (!$batch) {
            break;
        }
        foreach ($batch as $p) {
            $all[] = $p;
        }
        $offset += $limit;
        if ($offset < $total) {
            usleep(500000); // pauză 0.5s între pagini (politețe / anti-blocare)
        }
    } while ($offset < $total);

    return ['products' => $all, 'total' => $total, 'status' => $status];
}

/** Extrage referință, preț EUR și imagini din variantele unui produs. */
function shape(array $product): array
{
    $variants  = $product['variants'] ?? [];
    $priceEur  = 0.0;
    $reference = '';
    $images    = [];

    foreach ($variants as $v) {
        if ($priceEur === 0.0 && !empty($v['prices'][0]['amount']) && $v['prices'][0]['amount'] > 0) {
            $priceEur = (float) $v['prices'][0]['amount'];
        }
        if ($reference === '' && !empty($v['sku'])) {
            $reference = extractBaseReference((string) $v['sku']);
        }
        foreach (($v['images'] ?? []) as $img) {
            if (!empty($img['url'])) {
                $images[] = $img['url'];
            }
        }
    }

    return [
        'yamaha_id'  => $product['id'] ?? '',
        'reference'  => $reference,
        'name'       => $product['name'] ?? '',
        'price_eur'  => $priceEur,
        'image_count'=> count(array_unique($images)),
        'first_image'=> $images[0] ?? '',
    ];
}

// ── Intrare CLI sau browser ─────────────────────────────────────────────────
// Terminal:  php poc_yamaha_accessories.php --probe        (sau: PID PID ...)
// Browser:   poc_yamaha_accessories.php?probe=1            (sau: ?pid=288454,266986)
$cliArgs = $argv ?? [];
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8'); // ieșire lizibilă în browser
}
$probeRequested = in_array('--probe', $cliArgs, true) || isset($_GET['probe']);
$pidArgs = array_slice($cliArgs, 1);
if (!empty($_GET['pid'])) {
    $pidArgs = array_map('trim', explode(',', (string) $_GET['pid']));
}
$pidArgs = array_values(array_filter($pidArgs, static fn ($a) => $a !== '' && $a[0] !== '-'));

// ── Mod PROBE: o singură cerere HTTP, fără paginare (test sigur pe server) ────
if ($probeRequested) {
    $pid = 288454; // R9 — model real, ~91 accesorii
    $url = str_replace('%PID%', (string) $pid, URL_TEMPLATE) . '&limit=5&offset=0';
    echo "PROBE (o singură cerere) PID {$pid}\n";
    [$data, $status] = getJson($url);
    $results = is_array($data) ? ($data['results'] ?? []) : [];
    echo "HTTP {$status} | total raportat: " . (is_array($data) ? (int) ($data['total'] ?? 0) : 0)
        . " | produse în acest batch: " . count($results) . "\n";
    if ($results) {
        echo "Primele produse:\n";
        foreach ($results as $p) {
            $s = shape($p);
            echo "  - " . $s['reference'] . "  " . mb_substr((string) $s['name'], 0, 40) . "\n";
        }
        echo "\n=> ENDPOINTUL RĂSPUNDE de pe acest server. OK pentru Faza 1.\n";
    } else {
        echo "\n=> NICIUN rezultat (HTTP {$status}). Posibil blocare după IP sau eroare — NU rula modul complet.\n";
    }
    exit;
}

// ── Main ────────────────────────────────────────────────────────────────────
$pids = $pidArgs ? array_map('intval', $pidArgs) : DEFAULT_PIDS;

$grandTotal = 0;
foreach ($pids as $pid) {
    echo str_repeat('=', 80) . "\n";
    echo "PID {$pid}\n";
    echo str_repeat('=', 80) . "\n";

    $res = fetchAccessories($pid);
    $fetched = count($res['products']);
    $cover   = $fetched === $res['total'] ? 'OK' : 'INCOMPLET';

    echo "HTTP {$res['status']} | total raportat: {$res['total']} | preluate: {$fetched} | paginare: {$cover}\n\n";

    if ($fetched === 0) {
        echo "(niciun produs)\n\n";
        continue;
    }

    printf("%-10s %-16s %-9s %-4s %s\n", 'YamahaID', 'Referinta', 'PretEUR', 'Img', 'Nume');
    echo str_repeat('-', 80) . "\n";
    foreach ($res['products'] as $p) {
        $s = shape($p);
        printf(
            "%-10s %-16s %-9s %-4d %s\n",
            (string) $s['yamaha_id'],
            $s['reference'],
            number_format((float) $s['price_eur'], 2),
            (int) $s['image_count'],
            mb_substr((string) $s['name'], 0, 40)
        );
    }
    echo "\nExemplu imagine: " . (shape($res['products'][0])['first_image'] ?: '(fără)') . "\n\n";
    $grandTotal += $fetched;
}

echo str_repeat('=', 80) . "\n";
echo "TOTAL accesorii preluate (toate PID-urile): {$grandTotal}\n";
