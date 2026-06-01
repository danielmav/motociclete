<?php

declare(strict_types=1);

/**
 * One-off catalog migration: legacy source DBs -> local `motociclete` DB.
 *
 * Copies ACTIVE categories + ACTIVE products + their images for both brands
 * (Yamaha, CFMOTO) into the unified schema (database/schema.sql), and downloads
 * the image files into /media/{brand}/{type}/ (gitignored).
 *
 * Run with the Laragon PHP 8.1 CLI (it has pdo_mysql + curl):
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" database/migrate_catalog.php
 *
 * Idempotent: schema is dropped + recreated; existing media files are skipped.
 */

use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';            // PSR-4 + helpers (slugify) + Dotenv
Dotenv::createImmutable($root)->safeLoad();
$settings = require $root . '/config/settings.php';

$dm  = $settings['db']['dm'];
$loc = $settings['db']['local'];

if (empty($dm['host'])) {
    fwrite(STDERR, "DM_* source DB credentials not configured in .env\n");
    exit(1);
}

// --- Connections -----------------------------------------------------------
$opt = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$srcDsn = fn(string $db) => sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dm['host'], $dm['port'], $db);

$local  = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $loc['host'], $loc['port'], $loc['name']), $loc['user'], $loc['pass'], $opt);
$moto   = new PDO($srcDsn($dm['db_moto']),   $dm['user'], $dm['pass'], $opt);
$cfmoto = new PDO($srcDsn($dm['db_cfmoto']), $dm['user'], $dm['pass'], $opt);

echo "Connected. Applying schema...\n";

// --- Schema (split simple statements, skip comments) -----------------------
$sql = file_get_contents($root . '/database/schema.sql');
$sql = preg_replace('/--[^\n]*/', '', $sql);        // strip all -- comments (some contain ';')
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    $local->exec($stmt);
}

// --- Brand definitions -----------------------------------------------------
$HOST = 'https://www.motociclete.com.ro';
$brands = [
    'yamaha' => [
        'pdo'   => $moto,
        'paths' => ['color' => '/images/culori', 'gallery' => '/images/motociclete', 'detail' => '/images/detalii'],
    ],
    'cfmoto' => [
        'pdo'   => $cfmoto,
        'paths' => ['color' => '/cfmoto/images/culori', 'gallery' => '/cfmoto/images/motociclete', 'detail' => '/cfmoto/images/detalii'],
    ],
];
$tableType = ['culori' => 'color', 'imagini' => 'gallery', 'detalii' => 'detail'];
// Local media subfolder names mirror the legacy server folders (so images can
// be copied 1:1 from the server). type -> folder.
$folderFor = ['color' => 'culori', 'gallery' => 'motociclete', 'detail' => 'detalii'];

/** Original basename, trimmed. Stored verbatim in DB; URLs rawurlencode it. */
$cleanName = function (?string $name): string {
    return basename(str_replace('\\', '/', trim((string) $name)));
};

$download = in_array('--download', $argv, true); // optional HTTP fallback

// --- Prepared inserts ------------------------------------------------------
$insCat = $local->prepare(
    "INSERT INTO categories (brand,parent_id,name,slug,description,position,is_active,legacy_id)
     VALUES (:brand,:parent_id,:name,:slug,:description,:position,1,:legacy_id)"
);
$insProd = $local->prepare(
    "INSERT INTO products
       (brand,category_id,name,subtitle,slug,year,price,discount_pct,licence,cover_image,
        excerpt,description,details_html,specs_engine,specs_chassis,specs_dimensions,specs_connectivity,
        video,keywords,is_active,position,legacy_id,legacy_url)
     VALUES
       (:brand,:category_id,:name,:subtitle,:slug,:year,:price,:discount_pct,:licence,:cover_image,
        :excerpt,:description,:details_html,:specs_engine,:specs_chassis,:specs_dimensions,:specs_connectivity,
        :video,:keywords,1,:position,:legacy_id,:legacy_url)"
);
$insImg = $local->prepare(
    "INSERT INTO product_images (product_id,type,filename,position) VALUES (:pid,:type,:file,:pos)"
);

$downloads = [];   // [url => destPath]
$stats = ['cats' => 0, 'products' => 0, 'images' => 0];
$seen = [];        // brand => [slugs] for de-duplication

foreach ($brands as $brand => $cfg) {
    $src = $cfg['pdo'];
    echo "\n=== $brand ===\n";

    // ---- Categories : active ones + their (possibly inactive) ancestors ---
    // Some top-level categories are flagged inactive in the legacy data (e.g.
    // Snowmobile) while their children + products are active. Pull the parents
    // in too so the 2-level hierarchy stays intact, and show them all.
    $all = $src->query("SELECT id_categories,category_name,category_parent,category_description,position_order,active
                        FROM categories")->fetchAll();
    $byId = [];
    foreach ($all as $c) { $byId[(int) $c['id_categories']] = $c; }

    $include = [];
    foreach ($all as $c) {
        if ((int) $c['active'] !== 1) { continue; }
        $id = (int) $c['id_categories'];
        $include[$id] = true;
        $pid = (int) $c['category_parent'];          // include ancestor(s)
        while ($pid && isset($byId[$pid]) && empty($include[$pid])) {
            $include[$pid] = true;
            $pid = (int) $byId[$pid]['category_parent'];
        }
    }
    $cats = array_values(array_filter($all, fn($c) => isset($include[(int) $c['id_categories']])));
    usort($cats, fn($a, $b) => [(int) $a['category_parent'] !== 0 ? 1 : 0, (int) $a['position_order']]
                            <=> [(int) $b['category_parent'] !== 0 ? 1 : 0, (int) $b['position_order']]);

    $catMap = [];                       // legacy_id => new id
    foreach ($cats as $c) {
        $legacyParent = (int) $c['category_parent'];
        $parentId = $legacyParent && isset($catMap[$legacyParent]) ? $catMap[$legacyParent] : null;
        $insCat->execute([
            ':brand'       => $brand,
            ':parent_id'   => $parentId,
            ':name'        => trim((string) $c['category_name']),
            ':slug'        => slugify((string) $c['category_name']),
            ':description' => $c['category_description'] ?: null,
            ':position'    => (int) $c['position_order'],
            ':legacy_id'   => (int) $c['id_categories'],
        ]);
        $catMap[(int) $c['id_categories']] = (int) $local->lastInsertId();
        $stats['cats']++;
    }

    // ---- Products (active) ------------------------------------------------
    $products = $src->query("SELECT * FROM products WHERE active=1 ORDER BY position_order, id_product")->fetchAll();
    foreach ($products as $p) {
        $legacyId = (int) $p['id_product'];
        $slug = trim((string) ($p['url_string_short'] ?? ''));
        if ($slug === '') {
            $slug = slugify(trim((string) $p['nume']) . '-' . (int) $p['an']);
        }
        // guard against duplicate slugs within a brand
        $slugBase = $slug; $n = 1;
        while (in_array($slug, $seen[$brand] ?? [], true)) { $slug = $slugBase . '-' . (++$n); }
        $seen[$brand][] = $slug;

        $insProd->execute([
            ':brand'              => $brand,
            ':category_id'        => $catMap[(int) $p['categorie']] ?? null,
            ':name'               => trim((string) $p['nume']),
            ':subtitle'           => $p['titlu'] ?: null,
            ':slug'               => $slug,
            ':year'               => (int) $p['an'] ?: null,
            ':price'              => (int) $p['pret'],
            ':discount_pct'       => (float) $p['reducere'],
            ':licence'            => $p['permis'] ?: null,
            ':cover_image'        => $p['imagine_principala'] ? $cleanName($p['imagine_principala']) : null,
            ':excerpt'            => $p['descriere_scurta'] ?: null,
            ':description'        => $p['descriere_lunga'] ?: null,
            ':details_html'       => $p['detalii'] ?: null,
            ':specs_engine'       => $p['motor'] ?: null,
            ':specs_chassis'      => $p['sasiu'] ?: null,
            ':specs_dimensions'   => $p['dimensiuni'] ?: null,
            ':specs_connectivity' => $p['conectivitate'] ?: null,
            ':video'              => $p['video'] ?: null,
            ':keywords'           => $p['cuvinte_cheie'] ?: null,
            ':position'           => (int) $p['position_order'],
            ':legacy_id'          => $legacyId,
            ':legacy_url'         => $p['url_string_full'] ?: null,
        ]);
        $newId = (int) $local->lastInsertId();
        $stats['products']++;

        // ---- Images (color/gallery/detail) --------------------------------
        foreach ($tableType as $table => $type) {
            $rows = $src->query("SELECT imagine FROM `$table` WHERE id_produs=$legacyId ORDER BY id_imagine")->fetchAll(PDO::FETCH_COLUMN);
            $pos = 0;
            foreach ($rows as $orig) {
                $file = $cleanName($orig);
                if ($file === '') { continue; }
                $insImg->execute([':pid' => $newId, ':type' => $type, ':file' => $file, ':pos' => $pos++]);
                $stats['images']++;

                $dest = "$root/media/$brand/{$folderFor[$type]}/$file";
                if (!is_file($dest)) {
                    $url = $HOST . $cfg['paths'][$type] . '/' . rawurlencode($file);
                    $downloads[$url] = $dest;
                }
            }
        }
    }
    echo "  categories={$stats['cats']} products={$stats['products']} images={$stats['images']} (cumulative)\n";
}

// --- Download image files (optional HTTP fallback, curl_multi) -------------
// By default images are copied off the server manually (see database/README.md).
// Pass --download to fetch missing files over HTTP instead.
if (!$download) {
    echo "\nDONE (data only).\n";
    echo "  categories : {$stats['cats']}\n  products   : {$stats['products']}\n  image rows : {$stats['images']}\n";
    echo "  Images: copy server folders into /media (see database/README.md), or re-run with --download.\n";
    return;
}

echo "\nDownloading " . count($downloads) . " image files into /media ...\n";
$logFail = fopen($root . '/storage/logs/migrate-404.log', 'w');
$jobs = array_map(fn($u, $d) => [$u, $d], array_keys($downloads), array_values($downloads));
$ok = 0; $fail = 0; $window = 8; $done = 0;
$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

for ($i = 0; $i < count($jobs); $i += $window) {
    $batch = array_slice($jobs, $i, $window);
    $mh = curl_multi_init();
    $handles = [];
    foreach ($batch as $idx => [$url, $dest]) {
        @mkdir(dirname($dest), 0775, true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => $UA,
            CURLOPT_REFERER        => $HOST . '/',
            CURLOPT_HTTPHEADER     => ['Accept: image/avif,image/webp,image/*,*/*;q=0.8'],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$idx] = [$ch, $dest, $url];
    }
    do { $status = curl_multi_exec($mh, $running); if ($running) { curl_multi_select($mh, 1.0); } }
    while ($running && $status === CURLM_OK);

    foreach ($handles as [$ch, $dest, $url]) {
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body = curl_multi_getcontent($ch);
        if ($code === 200 && $body !== '' && strncmp($body, '<', 1) !== 0) {
            file_put_contents($dest, $body);
            $ok++;
        } else {
            fwrite($logFail, "$code  $url\n");
            $fail++;
        }
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);
    $done += count($batch);
    echo "  $done/" . count($jobs) . "  (ok=$ok fail=$fail)\r";
    usleep(150000); // be gentle: avoid tripping Cloudflare rate limits
}
fclose($logFail);

echo "\n\nDONE.\n";
echo "  categories : {$stats['cats']}\n";
echo "  products   : {$stats['products']}\n";
echo "  image rows : {$stats['images']}\n";
echo "  downloaded : $ok   failed/404 : $fail (see storage/logs/migrate-404.log)\n";
