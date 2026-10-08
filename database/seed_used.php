<?php
declare(strict_types=1);
// Mărci și categorii inițiale pentru /rulate. Idempotent: umple doar tabele goale.
//   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/seed_used.php

require __DIR__ . '/../vendor/autoload.php';
if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}
$settings = require __DIR__ . '/../config/settings.php';
$pdo = (new App\Database($settings['db']))->local();

$seed = [
    'used_brands'     => ['Yamaha', 'CFMOTO', 'Honda'],
    'used_categories' => ['Motociclete', 'Scutere', 'ATV'],
];
foreach ($seed as $table => $names) {
    if ((int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() > 0) {
        echo "  = $table: are deja date\n";
        continue;
    }
    $ins = $pdo->prepare("INSERT INTO `$table` (name, slug, position) VALUES (:n, :s, :p)");
    foreach ($names as $i => $name) {
        $ins->execute([':n' => $name, ':s' => slugify($name), ':p' => $i]);
    }
    echo "  + $table: " . count($names) . "\n";
}
echo "seed_used: done.\n";
