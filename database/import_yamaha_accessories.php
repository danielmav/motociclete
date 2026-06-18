<?php

declare(strict_types=1);

/**
 * Import accesorii originale Yamaha pentru TOATE modelele cu `products.yamaha_pid`.
 * Logica reală e în App\Accessories\Importer (refolosită și de admin). Acest script
 * e wrapper-ul CLI / pentru cron.
 *
 * Rulează cu Laragon PHP 8.1 (are curl + pdo_mysql):
 *   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/import_yamaha_accessories.php [--apply]
 * Fără --apply = dry-run. Pe server (cron) folosește binarul PHP al hostingului.
 */

use App\Accessories\Importer;
use App\BikerShop\Client;
use App\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
$settings = require $root . '/config/settings.php';

$apply = in_array('--apply', $argv, true);

$db = new Database($settings['db']);
$bs = new Client($db, $settings['db']['bikershop']);
$importer = new Importer($db, $bs);

if (!$bs->isAvailable()) {
    fwrite(STDERR, "AVERTISMENT: BikerShop indisponibil — accesoriile vor fi stocate FĂRĂ bs_product_id (fără link de cumpărare). Verifică whitelist-ul IP.\n");
}

$models = $importer->models();
if (!$models) {
    echo "Niciun model Yamaha cu yamaha_pid setat. Setează-l în admin și reia.\n";
    exit;
}

echo ($apply ? "MOD: APPLY (scriu în DB)\n" : "MOD: DRY-RUN (nu scriu nimic; rulează cu --apply ca să salvezi)\n");
echo "Modele de procesat: " . count($models) . "\n\n";

$agg = ['fetched' => 0, 'new' => 0, 'price_changed' => 0, 'unmatched' => 0, 'links' => 0];
foreach ($models as $m) {
    $r = $importer->importForModel((int) $m['id'], $apply);
    if ($r['ok']) {
        echo sprintf("── %-32s → %d accesorii (%d noi, %d fără bikershop)\n", mb_substr($r['model'], 0, 32), $r['fetched'], $r['new'], $r['unmatched']);
        foreach (['fetched', 'new', 'price_changed', 'unmatched', 'links'] as $k) {
            $agg[$k] += $r[$k];
        }
    } else {
        echo sprintf("── %-32s → EROARE: %s\n", mb_substr((string) $m['name'], 0, 32), $r['error']);
    }
}

echo "\n" . str_repeat('─', 60) . "\n";
echo "Total: {$agg['links']} legături | {$agg['fetched']} accesorii | {$agg['new']} noi | {$agg['price_changed']} preț schimbat | {$agg['unmatched']} fără bikershop\n";
echo $apply ? "Salvat.\n" : "(dry-run — adaugă --apply)\n";
