<?php

declare(strict_types=1);

/**
 * Seeds `client_bikes` from `clienti`: one bike per clienti row, with the catalog
 * `product_id` resolved by fuzzy-matching `unitate` (free text like "XMAX 300",
 * "MT07", "Tenere") against active `products`. The rich fields (km, color, plate,
 * service history…) are filled later by the dealer in the admin back-office.
 *
 * Idempotent (UPSERT on clienti_id). Re-run after catalog re-migration to refresh
 * the product link. Run with the Laragon 8.1 binary:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" database/seed_garage.php
 *   …  database/seed_garage.php --dry-run
 */

use App\BikerShop\FitmentMatcher;
use App\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$settings = require $root . '/config/settings.php';
$dryRun   = in_array('--dry-run', $argv ?? [], true);
$db    = new Database($settings['db']);
$local = $db->local();

const MATCH_THRESHOLD = 0.5;

/** ASCII-fold + split letter/digit boundaries: "MT07"->"mt 07", "Ténéré"->"tenere". */
$prep = static function (string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, [
        'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'á' => 'a', 'à' => 'a', 'ä' => 'a',
        'ó' => 'o', 'ö' => 'o', 'ú' => 'u', 'ü' => 'u', 'í' => 'i', 'ñ' => 'n', 'ç' => 'c',
    ]);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';
    $s = preg_replace('/(?<=[a-z])(?=\d)|(?<=\d)(?=[a-z])/', ' ', $s) ?? '';
    return trim(preg_replace('/\s+/', ' ', $s) ?? '');
};

/** First alphabetic token ("family") and first numeric token ("model number"). */
$familyOf = static function (string $prepped): ?string {
    foreach (explode(' ', $prepped) as $t) {
        if ($t !== '' && !ctype_digit($t)) { return $t; }
    }
    return null;
};
$numOf = static function (string $prepped): ?string {
    foreach (explode(' ', $prepped) as $t) {
        if (ctype_digit($t)) { return $t; }
    }
    return null;
};

// Active products to match against.
$products = $local->query("SELECT id, brand, name FROM products WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
foreach ($products as &$p) {
    $p['prep']   = $prep($p['name']);
    $p['family'] = $familyOf($p['prep']);
    $p['num']    = $numOf($p['prep']);
}
unset($p);

$clients = $local->query("SELECT id, an, unitate, vin FROM clienti")->fetchAll(PDO::FETCH_ASSOC);

$ins = $local->prepare(
    "INSERT INTO client_bikes (clienti_id, product_id, model_label, year, vin)
     VALUES (:cid, :pid, :label, :year, :vin)
     ON DUPLICATE KEY UPDATE product_id = VALUES(product_id), model_label = VALUES(model_label),
        year = VALUES(year), vin = VALUES(vin)"
);

$stats = ['matched' => 0, 'unmatched' => 0];
foreach ($clients as $c) {
    $unit = trim((string) $c['unitate']);
    $pid = null;
    $bestScore = 0.0;
    $bestName = '';
    if ($unit !== '') {
        $q = $prep($unit);
        $qFamily = $familyOf($q);
        $qNum = $numOf($q);
        foreach ($products as $p) {
            // Family must match (mt vs r vs tenere); if both name a model number,
            // it must be the same (MT-07 != MT-09, R1 != R9, Tracer 900 != Tracer 9).
            if ($qFamily !== null && $p['family'] !== null && $qFamily !== $p['family']) {
                continue;
            }
            if ($qNum !== null && $p['num'] !== null && $qNum !== $p['num']) {
                continue;
            }
            $score = FitmentMatcher::score($q, $p['prep']);
            if ($score > $bestScore) {
                $bestScore = $score;
                $pid = (int) $p['id'];
                $bestName = $p['name'];
            }
        }
        if ($bestScore < MATCH_THRESHOLD) {
            $pid = null;
        }
    }
    $pid ? $stats['matched']++ : $stats['unmatched']++;

    if (!$dryRun) {
        $ins->execute([
            ':cid'   => $c['id'],
            ':pid'   => $pid,
            ':label' => $unit !== '' ? $unit : null,
            ':year'  => $c['an'] ?: null,
            ':vin'   => $c['vin'] ?: null,
        ]);
    }
}

echo sprintf(
    "Seed client_bikes: %d clienți.\n  ✓ legate la catalog: %d\n  ✗ doar text (fără match): %d\n%s",
    count($clients), $stats['matched'], $stats['unmatched'],
    $dryRun ? "[DRY-RUN] fără scriere\n" : ''
);
