<?php

declare(strict_types=1);

/**
 * Delete media files that are NOT referenced in the local catalog DB.
 * Keeps only the images actually used by migrated products:
 *   cover/      <- products.cover_image
 *   culori/     <- product_images.type = color
 *   motociclete/<- product_images.type = gallery
 *   detalii/    <- product_images.type = detail
 *
 * Safe by default (dry-run). Pass --apply to actually delete:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" database/prune_media.php --apply
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
\Dotenv\Dotenv::createImmutable($root)->safeLoad();
$settings = require $root . '/config/settings.php';
$loc = $settings['db']['local'];

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $loc['host'], $loc['port'], $loc['name']),
    $loc['user'],
    $loc['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$apply = in_array('--apply', $argv, true);
$folderType = ['cover' => null, 'culori' => 'color', 'motociclete' => 'gallery', 'detalii' => 'detail'];

$totalKeep = 0;
$totalDel  = 0;
$bytesDel  = 0;

foreach (['yamaha', 'cfmoto'] as $brand) {
    foreach ($folderType as $folder => $type) {
        $dir = "$root/media/$brand/$folder";
        if (!is_dir($dir)) {
            continue;
        }

        // Allowed filenames (lowercased) for this folder.
        if ($type === null) {
            $rows = $pdo->prepare("SELECT DISTINCT cover_image FROM products WHERE brand = ? AND cover_image IS NOT NULL");
            $rows->execute([$brand]);
        } else {
            $rows = $pdo->prepare(
                "SELECT DISTINCT pi.filename FROM product_images pi
                 JOIN products p ON p.id = pi.product_id
                 WHERE p.brand = ? AND pi.type = ?"
            );
            $rows->execute([$brand, $type]);
        }
        $allowed = [];
        foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $f) {
            $allowed[mb_strtolower((string) $f)] = true;
        }

        $keep = 0;
        $del  = 0;
        foreach (new DirectoryIterator($dir) as $fi) {
            if ($fi->isDot() || !$fi->isFile()) {
                continue;
            }
            if (isset($allowed[mb_strtolower($fi->getFilename())])) {
                $keep++;
                continue;
            }
            $del++;
            $bytesDel += $fi->getSize();
            if ($apply) {
                @unlink($fi->getPathname());
            }
        }
        $totalKeep += $keep;
        $totalDel  += $del;
        printf("%-8s %-12s keep=%-5d delete=%-5d\n", $brand, $folder, $keep, $del);
    }
}

$mb = round($bytesDel / 1048576, 1);
echo "\n" . ($apply ? "DELETED" : "WOULD DELETE (dry-run)") . ": $totalDel files (~{$mb} MB), keeping $totalKeep.\n";
if (!$apply) {
    echo "Re-run with --apply to actually delete.\n";
}
