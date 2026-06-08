<?php

declare(strict_types=1);

/**
 * Fitment sync: populates lp_make_id / lp_model_id / lp_year_id on local
 * `products` by fuzzy-matching each product against BikerShop LeoPartsFilter.
 *
 * Run with:
 *   & "C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" database/migrate_fitment.php
 *   & "C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" database/migrate_fitment.php --dry-run
 *
 * Reports: ✓ potrivit / ⚠ ambiguu / ✗ nepotrivit / - fără an
 * Idempotent: rulat din nou suprascrie cu noile valori.
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

$stats = ['ok' => 0, 'amb' => 0, 'miss' => 0, 'no_year' => 0];

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
        continue;
    }

    $yearNote = $result['year_id'] ? "year_id={$result['year_id']}" : 'fără year_id';
    $icon = $result['ambiguous'] ? '⚠' : '✓';

    if ($result['ambiguous']) {
        echo "  ⚠  {$label}  AMBIGUU: " . implode(' | ', $result['candidates']) . "\n";
        echo "      → ales primul (model_id={$result['model_id']}, {$yearNote})\n";
        $stats['amb']++;
    } else {
        echo "  ✓  {$label}  model_id={$result['model_id']}, {$yearNote}\n";
        $result['year_id'] ? $stats['ok']++ : $stats['no_year']++;
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
echo sprintf("  ✗  Nepotrivite       : %d\n", $stats['miss']);
echo "─────────────────────────────────────────\n";

if ($stats['miss'] > 0) {
    echo "\nPentru produsele nepotrivite, setează manual make/model/year din /admin/fitment.\n";
}
if ($dryRun) {
    echo "\n[DRY-RUN] Nicio scriere nu a fost efectuată.\n";
}
