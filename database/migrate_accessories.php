<?php
declare(strict_types=1);
// Aplică schema accesoriilor Yamaha (idempotent) + adaugă coloana products.yamaha_pid.
// Rulează cu Laragon PHP 8.1:
//   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/migrate_accessories.php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_dbutil.php';
if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}
$settings = require __DIR__ . '/../config/settings.php';
$pdo = (new App\Database($settings['db']))->local();

run_sql_file($pdo, __DIR__ . '/schema_accessories.sql');

// PID-ul produsului Yamaha al modelului (din pagina de accesorii: ?product=<PID>).
// Folosit de import_yamaha_accessories.php pentru a construi URL-ul hyperdrive.
ensure_column($pdo, 'products', 'yamaha_pid', 'ALTER TABLE `products` ADD COLUMN `yamaha_pid` VARCHAR(32) NULL');

echo "migrate_accessories: done.\n";
