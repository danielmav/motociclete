<?php

declare(strict_types=1);

/**
 * POC — preluare MODEL Yamaha (motocicletă) din endpointurile hyperdrive, dat URL-ul
 * paginii de pe yamaha-motor.eu. Validează App\Yamaha\ModelImporter::fetch() fără să
 * scrie în DB.
 *
 * Două endpointuri (confirmate din pagină):
 *   - produs: /products/yme-prod-ro/slug=<slug>?locale=ro-RO   (nume, key=PID, specs, imagini)
 *   - text:   /custom-objects/yme-prod-ro/keys=<features>      (descrieri / caracteristici)
 *
 * Rulare (binarul Laragon PHP 8.1 — are curl + pdo_mysql):
 *   php tests/poc_yamaha_model.php                       # URL implicit, doar fetch (fără download)
 *   php tests/poc_yamaha_model.php <url-sau-slug>        # alt model
 *   php tests/poc_yamaha_model.php <url> --download      # descarcă și imaginile în /media/yamaha
 *
 * Browser:  tests/poc_yamaha_model.php?url=<url>  (NU descarcă; doar inspecție).
 */

use App\BikerShop\Client;
use App\Database;
use App\Yamaha\ModelImporter;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
$settings = require $root . '/config/settings.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

$cli = $argv ?? [];
$DEFAULT = 'https://www.yamaha-motor.eu/ro/ro/motorcycles/competition/pdp/yz450f-monster-energy-yamaha-racing-edition/';
$url = $DEFAULT;
foreach (array_slice($cli, 1) as $a) {
    if ($a !== '' && $a[0] !== '-') {
        $url = $a;
    }
}
if (!empty($_GET['url'])) {
    $url = (string) $_GET['url'];
}
$download = in_array('--download', $cli, true);

$db = new Database($settings['db']);
$bs = new Client($db, $settings['db']['bikershop']);
$importer = new ModelImporter($root . '/media', $bs);

echo "URL : {$url}\n";
echo "Slug: " . ModelImporter::slugFromUrl($url) . "\n";
echo str_repeat('=', 78) . "\n";

$res = $importer->fetch($url, 2027);
if (!$res['ok']) {
    echo "EȘEC: " . ($res['error'] ?? 'necunoscut') . "\n";
    exit(1);
}
$d = $res['draft'];

echo "Nume        : {$d['name']}\n";
echo "PID accesorii: {$d['yamaha_pid']}\n";
echo "bs_product_id: " . ($d['bs_product_id'] ?? '(nelegat)') . "\n";
echo "details_html: " . strlen((string) $d['details_html']) . " caractere\n";
echo "\nSpecificații (rânduri / secțiune):\n";
foreach ($d['specs'] as $sec => $rows) {
    echo sprintf("  %-12s %d\n", $sec, count($rows));
    foreach (array_slice($rows, 0, 2) as $r) {
        echo "      · {$r['label']}: " . mb_substr((string) $r['value'], 0, 50) . "\n";
    }
}
echo "\nImagini (URL-uri):\n";
echo "  cover : " . ($d['images']['cover'] !== '' ? '1' : '0') . "\n";
foreach (['color', 'gallery', 'detail'] as $t) {
    echo sprintf("  %-8s %d\n", $t, count($d['images'][$t]));
}

if ($download && PHP_SAPI === 'cli') {
    echo "\nDescarc imaginile în /media/yamaha/...\n";
    $d = $importer->downloadImages($d);
    echo "  cover_image: " . ($d['cover_image'] ?: '(eșec)') . "\n";
    foreach (['color', 'gallery', 'detail'] as $t) {
        echo sprintf("  %-8s %d fișiere\n", $t, count($d['images'][$t]));
    }
    echo "  exemplu: " . ($d['images']['gallery'][0] ?? $d['cover_image'] ?? '—') . "\n";
} else {
    echo "\n(fără download — adaugă --download pentru a descărca imaginile)\n";
}

echo "\n=> OK.\n";
