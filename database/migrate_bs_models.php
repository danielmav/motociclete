<?php

declare(strict_types=1);

/**
 * Maps each local motorcycle to its BikerShop motorcycle PRODUCT, so the product
 * page can show exactly the same OEM/aftermarket related products BikerShop shows
 * (curated via the advrider_related module: manual + partseurope caches).
 *
 * BikerShop bike products are named "Motocicletă/Scuter <brand> <model> - <an>" with
 * a slug-like `reference` (e.g. `mt-09-2026`) that almost equals our `products.slug`.
 * Primary match = reference == slug; fallback = fuzzy on model name (family + number
 * guard, diacritics folded). Stores `products.bs_product_id`.
 *
 * Run with the Laragon 8.1 binary:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" database/migrate_bs_models.php
 *   …  database/migrate_bs_models.php --dry-run
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
$bs    = $db->bikershop();
if (!$bs instanceof PDO) {
    fwrite(STDERR, "BikerShop indisponibil.\n");
    exit(1);
}

const MATCH_THRESHOLD = 0.5;

$fold = static function (string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['ă'=>'a','â'=>'a','î'=>'i','ș'=>'s','ş'=>'s','ț'=>'t','ţ'=>'t','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','á'=>'a','à'=>'a','ä'=>'a','ó'=>'o','ö'=>'o','ú'=>'u','ü'=>'u','í'=>'i','ñ'=>'n','ç'=>'c']);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';
    $s = preg_replace('/(?<=[a-z])(?=\d)|(?<=\d)(?=[a-z])/', ' ', $s) ?? '';
    return trim(preg_replace('/\s+/', ' ', $s) ?? '');
};
$familyOf = static function (string $s): ?string { foreach (explode(' ', $s) as $t) if ($t !== '' && !ctype_digit($t)) return $t; return null; };
$numOf = static function (string $s): ?string { foreach (explode(' ', $s) as $t) if (ctype_digit($t)) return $t; return null; };

// --- Load BikerShop motorcycle products (the relation anchors) ---------------
$shop = 1; $lang = 1; $p = 'ps_';
$bikes = $bs->query(
    "SELECT pr.id_product, pr.reference, pl.name
     FROM {$p}product pr
     JOIN {$p}product_lang pl ON pl.id_product=pr.id_product AND pl.id_lang={$lang} AND pl.id_shop={$shop}
     WHERE pl.name LIKE 'Motociclet%' OR pl.name LIKE 'Scuter%'"
)->fetchAll(PDO::FETCH_ASSOC);

$byRef = [];   // reference (lower) => id_product
$cands = [];   // [id_product, model_prep, family, num]
foreach ($bikes as $b) {
    $ref = strtolower(trim((string) $b['reference']));
    if ($ref !== '') {
        $byRef[$ref] = (int) $b['id_product'];
    }
    // Strip the "Motocicletă/Scuter <brand> " prefix and trailing " - <an>".
    $name = preg_replace('/^(Motociclet[ăa]|Scuter)\s+(Yamaha|CFMoto|CF\s*Moto)\s+/iu', '', (string) $b['name']) ?? $b['name'];
    $name = preg_replace('/\s*-\s*\d{4}\s*$/', '', $name) ?? $name;
    $prep = $fold($name);
    $cands[] = ['id' => (int) $b['id_product'], 'prep' => $prep, 'family' => $familyOf($prep), 'num' => $numOf($prep), 'name' => $b['name']];
}
echo sprintf("BikerShop: %d produse-motocicletă (anchor).\n", count($bikes));

// --- Match local products ----------------------------------------------------
$products = $local->query("SELECT id, brand, name, slug FROM products WHERE is_active=1")->fetchAll(PDO::FETCH_ASSOC);
$upd = $local->prepare("UPDATE products SET bs_product_id = :bid WHERE id = :id");

$stats = ['exact' => 0, 'fuzzy' => 0, 'none' => 0];
foreach ($products as $pr) {
    $slug = strtolower(trim((string) $pr['slug']));
    $bid = null; $how = '';

    if (isset($byRef[$slug])) {
        $bid = $byRef[$slug]; $how = 'exact';
        $stats['exact']++;
    } else {
        $q = $fold($pr['name']);
        $qf = $familyOf($q); $qn = $numOf($q);
        $best = 0.0;
        foreach ($cands as $c) {
            if ($qf !== null && $c['family'] !== null && $qf !== $c['family']) continue;
            if ($qn !== null && $c['num'] !== null && $qn !== $c['num']) continue;
            $score = FitmentMatcher::score($q, $c['prep']);
            if ($score > $best) { $best = $score; $bid = $c['id']; }
        }
        if ($bid !== null && $best >= MATCH_THRESHOLD) { $how = 'fuzzy(' . number_format($best, 2) . ')'; $stats['fuzzy']++; }
        else { $bid = null; $stats['none']++; }
    }

    if ($how !== '') {
        echo sprintf("  ✓ %-34s [%s] → bs %d  %s\n", $pr['name'], $pr['brand'], $bid, $how);
    }
    if (!$dryRun) {
        $upd->execute([':bid' => $bid, ':id' => $pr['id']]);
    }
}

echo "\n─────────────────────────────────────────\n";
echo sprintf("  ✓ exact (reference=slug): %d\n  ✓ fuzzy (nume):           %d\n  ✗ fără produs BikerShop:  %d\n", $stats['exact'], $stats['fuzzy'], $stats['none']);
if ($dryRun) echo "[DRY-RUN] fără scriere.\n";
