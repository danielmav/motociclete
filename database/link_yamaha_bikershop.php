<?php

declare(strict_types=1);

/**
 * Leagă modelele Yamaha ale portalului (motociclete / scutere / ATV) de produsele lor de pe
 * BikerShop: scrie `products.sku` (= `ps_product.reference`), corectează `products.bs_product_id`
 * și pune `bs_ref` pe variantele Y-AMT care au produs separat acolo. Rulat o dată (2026-10-01),
 * după confirmarea tabelului de către Daniel; idempotent (nu suprascrie un `sku` deja completat).
 *
 *   - pereche = nume normalizat identic (fără an/diacritice/punctuație); la mai mulți ani câștigă
 *     anul modelului, apoi cel mai nou; modelele active aleg primele;
 *   - fără pereche + activ → `sku` = slug (va fi CREAT pe BikerShop de sync_moto_bikershop.php);
 *   - mașinile de golf → `sku` = '-' (excluse din sincronizare — decizie Daniel);
 *   - fără pereche + inactiv → rămâne fără cod.
 *
 * Rulează cu PHP 8.1 (Laragon / ea-php81): php database/link_yamaha_bikershop.php [--apply]
 */

use App\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
$settings = require $root . '/config/settings.php';

$apply = in_array('--apply', $argv, true);
$db = new Database($settings['db']);
$pdo = $db->local();
$bp = $db->bikershop();
if (!$bp) {
    fwrite(STDERR, "BikerShop indisponibil.\n");
    exit(1);
}

/** Perechi stabilite manual (slug portal → referință BikerShop). */
const MANUAL = ['tricity-300-airbag-2026' => 'tricity-300-2026'];

$norm = static function (string $x): string {
    $x = strtr(mb_strtolower($x), ['é' => 'e', 'è' => 'e', 'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ț' => 't', '+' => 'plus']);
    $x = (string) preg_replace('/-?20\d{2}$/', '', $x);
    return (string) preg_replace('/[^a-z0-9]/', '', $x);
};

$B = [];
foreach ($bp->query(
    "SELECT p.id_product AS id, p.reference AS ref
     FROM ps_category_product cp JOIN ps_product p ON p.id_product = cp.id_product
     WHERE cp.id_category = 810 AND p.id_manufacturer = 41 AND p.reference <> ''",
    PDO::FETCH_ASSOC
) as $r) {
    $B[(int) $r['id']] = ['id' => (int) $r['id'], 'ref' => (string) $r['ref'], 'n' => $norm((string) $r['ref']),
        'y' => preg_match('/(20\d{2})$/', (string) $r['ref'], $m) ? (int) $m[1] : 0];
}

$P = $pdo->query(
    "SELECT p.id, p.name, p.slug, p.year, p.is_active, p.sku, p.bs_product_id, p.variants_json,
            COALESCE(t.slug, c.slug) AS top, c.slug AS cat
     FROM products p JOIN categories c ON c.id = p.category_id LEFT JOIN categories t ON t.id = c.parent_id
     WHERE p.brand = 'yamaha' AND COALESCE(t.slug, c.slug) IN ('motociclete', 'scutere', 'atvuri')
     ORDER BY p.is_active DESC, p.id"
)->fetchAll(PDO::FETCH_ASSOC);

$used = [];
foreach ($P as $p) {            // codurile deja puse își rezervă perechea
    foreach ($B as $b) {
        if ((string) $p['sku'] !== '' && mb_strtolower($b['ref']) === mb_strtolower((string) $p['sku'])) {
            $used[$b['id']] = true;
        }
    }
}
$pick = static function (string $key, ?int $year) use ($B, &$used): ?array {
    $c = array_values(array_filter($B, static fn ($b) => $b['n'] === $key && !isset($used[$b['id']])));
    if (!$c) {
        return null;
    }
    foreach ($c as $b) {
        if ($year && $b['y'] === $year) {
            return $b;
        }
    }
    usort($c, static fn ($a, $b) => $b['y'] <=> $a['y']);
    return $c[0];
};

$upd = $pdo->prepare("UPDATE products SET sku = :s, bs_product_id = COALESCE(:b, bs_product_id), variants_json = :v WHERE id = :id");
$n = ['legate' => 0, 'de_creat' => 0, 'excluse' => 0, 'variante' => 0, 'neschimbate' => 0];
echo $apply ? "APPLY\n" : "DRY-RUN (fără --apply nu se scrie nimic)\n";

foreach ($P as $p) {
    if ((string) $p['sku'] !== '') {
        $n['neschimbate']++;
        continue;
    }
    $b = null;
    if (isset(MANUAL[$p['slug']])) {
        foreach ($B as $x) {
            if ($x['ref'] === MANUAL[$p['slug']] && !isset($used[$x['id']])) {
                $b = $x;
            }
        }
    }
    $b ??= $pick($norm((string) $p['slug']), $p['year'] ? (int) $p['year'] : null);

    $sku = null;
    $what = '';
    if ($b) {
        $used[$b['id']] = true;
        $sku = $b['ref'];
        $what = "↔ {$b['ref']} (#{$b['id']})";
        $n['legate']++;
    } elseif ($p['cat'] === 'masini-de-golf') {
        $sku = '-';
        $what = 'exclus (mașină de golf)';
        $n['excluse']++;
    } elseif ((int) $p['is_active'] === 1) {
        $sku = (string) $p['slug'];
        $what = 'DE CREAT pe BikerShop (cod = slug)';
        $n['de_creat']++;
    } else {
        continue; // inactiv, fără pereche
    }

    // Variantele Y-AMT (nu cele de 35 kW) → produsul separat „<model>-y-amt-<an>".
    $variants = json_decode((string) $p['variants_json'], true) ?: [];
    $base = $norm((string) $p['slug']);
    foreach ($variants as &$v) {
        if (!empty($v['bs_ref']) || stripos((string) ($v['transmission'] ?? ''), 'y-amt') === false
            || stripos((string) ($v['version'] ?? ''), '35') !== false || str_contains($base, 'yamt')) {
            continue;
        }
        $vy = preg_match('/(20\d{2})/', (string) $v['transmission'], $m) ? (int) $m[1] : ($p['year'] ? (int) $p['year'] : null);
        if ($vb = $pick($base . 'yamt', $vy)) {
            $used[$vb['id']] = true;
            $v['bs_ref'] = $vb['ref'];
            $what .= " | variantă {$v['transmission']} ↔ {$vb['ref']}";
            $n['variante']++;
        }
    }
    unset($v);

    printf("#%-4d %-7s %-44s %s\n", $p['id'], $p['is_active'] ? 'activ' : 'inactiv', mb_substr($p['name'] . ' ' . $p['year'], 0, 44), $what);
    if ($apply) {
        $upd->execute([
            ':s'  => $sku,
            ':b'  => $b ? $b['id'] : null,
            ':v'  => $variants ? json_encode($variants, JSON_UNESCAPED_UNICODE) : ($p['variants_json'] ?: null),
            ':id' => $p['id'],
        ]);
    }
}

echo "\nBikerShop Yamaha fără pereche pe portal (vor fi dezactivate de sync):\n";
foreach ($B as $b) {
    if (!isset($used[$b['id']])) {
        echo "  #{$b['id']} {$b['ref']}\n";
    }
}
echo "\n" . json_encode($n, JSON_UNESCAPED_UNICODE) . "\n";
