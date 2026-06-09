<?php

declare(strict_types=1);

/**
 * Fitment sync: populates lp_make_id / lp_model_id / lp_year_id on local
 * `products` by fuzzy-matching each product against BikerShop LeoPartsFilter.
 *
 * Run with (binarul Laragon 8.1.10 — `php` din PATH n-are pdo_mysql/curl):
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" database/migrate_fitment.php
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" database/migrate_fitment.php --dry-run
 *
 * Reports: ✓ potrivit / ⚠ ambiguu (model ales prin scoring) / ? de verificat
 *          (scor mic → lăsat NULL) / ✗ nepotrivit / - fără an
 * Idempotent: rulat din nou suprascrie cu noile valori (inclusiv NULL pe cele nesigure).
 */

use App\BikerShop\Client;
use App\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$settings = require $root . '/config/settings.php';
$dryRun   = in_array('--dry-run', $argv ?? [], true);

if ($dryRun) {
    echo "[DRY-RUN] Nicio scriere în DB.\n\n";
}

// --- Connections --------------------------------------------------------------
$db     = new Database($settings['db']);
$client = new Client($db, $settings['db']['bikershop']);
$local  = $db->local();

if (!$client->isAvailable()) {
    fwrite(STDERR, "BikerShop DB indisponibil — verifică .env și whitelist IP.\n");
    exit(1);
}

// --- Load all active products ------------------------------------------------
$products = $local
    ->query("SELECT id, brand, name, year FROM products WHERE is_active = 1 ORDER BY brand, name")
    ->fetchAll(PDO::FETCH_ASSOC);

echo sprintf("Procesez %d produse...\n\n", count($products));

// --- Update statement --------------------------------------------------------
$upd = $local->prepare(
    "UPDATE products SET lp_make_id = :make, lp_model_id = :model, lp_year_id = :year WHERE id = :id"
);

$stats = ['ok' => 0, 'amb' => 0, 'review' => 0, 'miss' => 0, 'no_year' => 0];

foreach ($products as $p) {
    $label = sprintf('%-30s %4s  [%s]', $p['name'], $p['year'] ?? '—', $p['brand']);

    if (!$p['year']) {
        echo "  -  {$label}  (fără an — skipped)\n";
        $stats['miss']++;
        continue;
    }

    $result = $client->lookupFitment($p['brand'], $p['name'], (int) $p['year']);

    if ($result === null) {
        echo "  ✗  {$label}  NEPOTRIVIT\n";
        $stats['miss']++;
        $result = ['make_id' => null, 'model_id' => null, 'year_id' => null];
    } elseif (!$result['confident']) {
        // Scor sub prag → nu scriem o ghicire; lăsăm NULL pentru /admin/fitment.
        echo "  ?  {$label}  DE VERIFICAT — " . implode(' | ', $result['candidates']) . "\n";
        $stats['review']++;
        $result = ['make_id' => null, 'model_id' => null, 'year_id' => null];
    } else {
        $yearNote = $result['year_id'] ? "year_id={$result['year_id']}" : 'fără year_id';
        if ($result['ambiguous']) {
            echo "  ⚠  {$label}  model_id={$result['model_id']}, {$yearNote}  (ambiguu, ales cel mai bun)\n";
            $stats['amb']++;
        } else {
            echo "  ✓  {$label}  model_id={$result['model_id']}, {$yearNote}\n";
            $result['year_id'] ? $stats['ok']++ : $stats['no_year']++;
        }
    }

    if (!$dryRun) {
        $upd->execute([
            ':make'  => $result['make_id'],
            ':model' => $result['model_id'],
            ':year'  => $result['year_id'],
            ':id'    => $p['id'],
        ]);
    }
}

echo "\n";
echo "─────────────────────────────────────────\n";
echo sprintf("  ✓  Potrivite complet : %d\n", $stats['ok']);
echo sprintf("  ✓  Fără year_id      : %d\n", $stats['no_year']);
echo sprintf("  ⚠  Ambigue (ales 1st): %d\n", $stats['amb']);
echo sprintf("  ?  De verificat      : %d\n", $stats['review']);
echo sprintf("  ✗  Nepotrivite       : %d\n", $stats['miss']);
echo "─────────────────────────────────────────\n";

if ($stats['review'] + $stats['miss'] > 0) {
    echo "\nPentru produsele de verificat / nepotrivite, setează manual make/model/year din /admin/fitment.\n";
}
if ($dryRun) {
    echo "\n[DRY-RUN] Nicio scriere nu a fost efectuată.\n";
}
