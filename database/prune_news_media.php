<?php

declare(strict_types=1);

/**
 * Verifică /media/noutati-moto față de tabela `news_images`:
 *  - raportează imaginile din DB care LIPSESC din folder;
 *  - șterge fișierele din folder care NU sunt în DB (orfane) — doar cu --apply.
 *
 * Dry-run implicit (doar raportează). Aplică ștergerea:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" database/prune_news_media.php --apply
 */

use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
$settings = require $root . '/config/settings.php';

$apply = in_array('--apply', $argv, true);
$dir   = $root . '/media/noutati-moto';

if (!is_dir($dir)) {
    fwrite(STDERR, "Folderul nu există: {$dir}\n");
    exit(1);
}

$loc = $settings['db']['local'];
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $loc['host'], $loc['port'], $loc['name']),
    $loc['user'],
    $loc['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Set de nume din DB (lowercase, ca să nu depindă de case pe FS Windows).
$dbNames = [];
foreach ($pdo->query("SELECT DISTINCT filename FROM news_images") as $r) {
    $dbNames[mb_strtolower((string) $r['filename'])] = (string) $r['filename'];
}

// Fișiere din folder.
$files = [];
foreach (scandir($dir) ?: [] as $f) {
    if ($f === '.' || $f === '..' || is_dir($dir . '/' . $f)) {
        continue;
    }
    $files[mb_strtolower($f)] = $f;
}

$missing = [];   // în DB, lipsesc din folder
foreach ($dbNames as $lc => $orig) {
    if (!isset($files[$lc])) {
        $missing[] = $orig;
    }
}
$orphans = [];   // în folder, nu sunt în DB
foreach ($files as $lc => $orig) {
    if (!isset($dbNames[$lc])) {
        $orphans[] = $orig;
    }
}

echo "=== Verificare /media/noutati-moto ===\n";
echo sprintf("  în DB (distinct): %d · în folder: %d\n", count($dbNames), count($files));
echo sprintf("  potrivite: %d\n", count($dbNames) - count($missing));

echo "\n  ✗ în DB dar LIPSESC din folder: " . count($missing) . "\n";
foreach (array_slice($missing, 0, 40) as $m) {
    echo "      {$m}\n";
}
if (count($missing) > 40) {
    echo "      … (+" . (count($missing) - 40) . ")\n";
}

echo "\n  🗑 orfane în folder (nu sunt în DB): " . count($orphans) . "\n";
foreach (array_slice($orphans, 0, 40) as $o) {
    echo "      {$o}\n";
}
if (count($orphans) > 40) {
    echo "      … (+" . (count($orphans) - 40) . ")\n";
}

if (!$apply) {
    echo "\n[DRY-RUN] Nimic șters. Rulează cu --apply pentru a șterge cele " . count($orphans) . " orfane.\n";
    exit(0);
}

$deleted = 0;
foreach ($orphans as $o) {
    if (@unlink($dir . '/' . $o)) {
        $deleted++;
    }
}
echo "\n✓ Șterse {$deleted} fișiere orfane.\n";
if ($missing) {
    echo "⚠ Atenție: " . count($missing) . " imagini din DB nu există în folder (articolele lor vor avea imagini lipsă).\n";
}
