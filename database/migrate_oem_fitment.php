<?php

declare(strict_types=1);

/**
 * OEM parts map: populates local `oem_product_map` (product_id -> BikerShop
 * id_product) by linking each local model to its genuine OEM parts in
 * BikerShop's `ps_advrider_related_diagram_cache`.
 *
 * Why precompute: `path_value` (which encodes `{category}/{year}/{model-slug}/…`)
 * is NOT indexed in BikerShop, so a per-model LIKE is a ~12s full scan. Instead
 * we stream the cache ONCE per catalog (unbuffered), bucket distinct product ids
 * per (year, model-slug) capped at CAP, then fuzzy-match each local product name
 * to the best model-slug (reusing FitmentMatcher). Runtime then reads ids from
 * the local map and fetches details on the PK (fast).
 *
 * Run with the Laragon 8.1 binary (PATH `php` 8.2 has no pdo_mysql):
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" database/migrate_oem_fitment.php
 *   …  database/migrate_oem_fitment.php --catalog=cfmoto      (doar un brand, mai rapid)
 *   …  database/migrate_oem_fitment.php --dry-run             (fără scriere)
 *
 * Idempotent: rescrie maparea pentru produsele procesate. Rulează schema_garage.sql întâi.
 */

use App\BikerShop\FitmentMatcher;
use App\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$settings = require $root . '/config/settings.php';
$dryRun   = in_array('--dry-run', $argv ?? [], true);
$onlyCat  = null;
foreach ($argv ?? [] as $a) {
    if (str_starts_with($a, '--catalog=')) {
        $onlyCat = substr($a, 10);
    }
}

const CAP        = 60;    // câte id-uri OEM stocăm per produs (teaser + viitor)
const THRESHOLD  = 0.45;  // scor minim de potrivire model (ca FitmentMatcher)

$db    = new Database($settings['db']);
$local = $db->local();
$bs    = $db->bikershop();

if (!$bs instanceof PDO) {
    fwrite(STDERR, "BikerShop DB indisponibil — verifică .env și whitelist IP.\n");
    exit(1);
}

// brand local -> catalog în diagram_cache
$catalogs = ['yamaha' => 'yamaha', 'cfmoto' => 'cfmoto'];
if ($onlyCat) {
    $catalogs = array_intersect($catalogs, [$onlyCat]);
}

// --- Load active products grouped by brand ----------------------------------
$products = $local
    ->query("SELECT id, brand, name, year FROM products WHERE is_active = 1 AND year IS NOT NULL ORDER BY brand, name")
    ->fetchAll(PDO::FETCH_ASSOC);

$byBrand = [];
foreach ($products as $p) {
    $byBrand[$p['brand']][] = $p;
}

if (!$dryRun) {
    $local->exec("CREATE TABLE IF NOT EXISTS oem_product_map (
        product_id INT UNSIGNED NOT NULL,
        bs_id_product INT UNSIGNED NOT NULL,
        position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (product_id, bs_id_product),
        KEY idx_oemmap_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

$ins = $local->prepare(
    "INSERT INTO oem_product_map (product_id, bs_id_product, position)
     VALUES (:pid, :bid, :pos)
     ON DUPLICATE KEY UPDATE position = VALUES(position)"
);

/** "tenere-700-xtz690" -> "tenere 700 xtz690" pentru scoring. */
$slugToName = static fn (string $slug): string => str_replace('-', ' ', $slug);

/** ASCII-fold + lowercase (diacriticele BikerShop sunt fără semne: "Ténéré" -> "tenere"). */
$fold = static function (string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, [
        'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'á' => 'a', 'à' => 'a', 'ä' => 'a',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'í' => 'i', 'ñ' => 'n', 'ç' => 'c',
    ]);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';
    return trim(preg_replace('/\s+/', ' ', $s) ?? '');
};

$grand = ['matched' => 0, 'no_match' => 0, 'ids' => 0];

foreach ($catalogs as $brand => $catalog) {
    $prods = $byBrand[$brand] ?? [];
    if (!$prods) {
        echo "[$brand] niciun produs activ — skip\n";
        continue;
    }
    echo "[$brand] stream ps_advrider_related_diagram_cache (catalog=$catalog)…\n";

    // --- Stream the cache once (unbuffered), bucket ids per (year, model-slug) ---
    $bs->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    $stmt = $bs->prepare(
        "SELECT id_product, path_value FROM ps_advrider_related_diagram_cache WHERE catalog = :cat"
    );
    $stmt->execute([':cat' => $catalog]);

    /** @var array<string,array<string,array<int,int>>> $buckets [year][modelSlug][id]=pos */
    $buckets = [];
    $rows = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ((++$rows % 500000) === 0) {
            echo "  …$rows rânduri\n";
        }
        $seg = explode('/', $row['path_value']);
        if (count($seg) < 3) {
            continue;
        }
        $year = $seg[1];
        $slug = $seg[2];
        if ($year === '' || $slug === '') {
            continue;
        }
        $id = (int) $row['id_product'];
        $b =& $buckets[$year][$slug];
        if ($b === null) {
            $b = [];
        }
        if (!isset($b[$id]) && count($b) < CAP) {
            $b[$id] = count($b);
        }
        unset($b);
    }
    $stmt->closeCursor();
    $bs->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

    $modelCount = array_sum(array_map('count', $buckets));
    echo "  → $rows rânduri, " . count($buckets) . " ani, $modelCount modele.\n";

    // --- Match each product to the best model-slug of its year ----------------
    foreach ($prods as $p) {
        $year = (string) (int) $p['year'];
        $candidates = $buckets[$year] ?? [];
        $productName = $fold($p['name']);
        $productTokens = preg_split('/\s+/', $productName, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $bestSlug = null;
        $bestScore = 0.0;
        foreach ($candidates as $slug => $ids) {
            // Family guard: the model family (first alphabetic token of the slug,
            // e.g. "tenere", "grizzly", "xmax") must appear in the product name.
            // Kills cross-family mismatches like Ténéré→grizzly or NMAX→XMAX that
            // pure token/displacement scoring lets through.
            $family = null;
            foreach (explode('-', $slug) as $tok) {
                if ($tok !== '' && !ctype_digit($tok)) { $family = $tok; break; }
            }
            if ($family !== null && !in_array($family, $productTokens, true)) {
                continue;
            }
            $score = FitmentMatcher::score($productName, $slugToName($slug));
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestSlug = $slug;
            }
        }

        $label = sprintf('%-34s %4s', $p['name'], $year);
        if ($bestSlug === null || $bestScore < THRESHOLD) {
            echo "  ✗ $label  fără potrivire OEM (best=" . number_format($bestScore, 2) . ")\n";
            $grand['no_match']++;
            continue;
        }

        $ids = $candidates[$bestSlug];
        echo "  ✓ $label  → $bestSlug (" . count($ids) . " piese, scor " . number_format($bestScore, 2) . ")\n";
        $grand['matched']++;
        $grand['ids'] += count($ids);

        if (!$dryRun) {
            $local->prepare("DELETE FROM oem_product_map WHERE product_id = ?")->execute([$p['id']]);
            foreach ($ids as $id => $pos) {
                $ins->execute([':pid' => $p['id'], ':bid' => $id, ':pos' => $pos]);
            }
        }
    }
    unset($buckets);
}

echo "\n─────────────────────────────────────────\n";
echo sprintf("  ✓ Produse cu piese OEM : %d\n", $grand['matched']);
echo sprintf("  ✗ Fără potrivire       : %d\n", $grand['no_match']);
echo sprintf("  Σ legături produse-OEM : %d\n", $grand['ids']);
echo "─────────────────────────────────────────\n";
if ($dryRun) {
    echo "[DRY-RUN] Nicio scriere în DB.\n";
}
