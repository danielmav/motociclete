<?php
declare(strict_types=1);
// Seeds default contact departments + legal/about pages + social placeholders.
// Idempotent. Run with Laragon PHP 8.1:
//   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/seed_settings.php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_dbutil.php';
if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}
$settings = require __DIR__ . '/../config/settings.php';
$pdo = (new App\Database($settings['db']))->local();
run_sql_file($pdo, __DIR__ . '/schema_admin.sql');

// Departments
$depts = [
    ['Vânzări moto', 'vanzari@motociclete.com.ro', '0722 354 437', 1],
    ['Echipamente & accesorii', 'accesorii@motociclete.com.ro', '0722 354 438', 2],
    ['Service', 'service@motociclete.com.ro', '0722 354 439', 3],
    ['Contabilitate', 'contabilitate@motociclete.com.ro', '', 4],
];
if ((int) $pdo->query('SELECT COUNT(*) FROM contact_departments')->fetchColumn() === 0) {
    $st = $pdo->prepare('INSERT INTO contact_departments (label, email, phone, position) VALUES (?,?,?,?)');
    foreach ($depts as $d) {
        $st->execute($d);
    }
    echo "departments seeded\n";
}

// Legal / about pages (placeholders)
$pages = [
    ['termeni-si-conditii', 'Termeni și condiții', '<p>Conținutul termenilor și condițiilor va fi completat din administrare.</p>'],
    ['confidentialitate', 'Politica de confidențialitate', '<p>Politica de confidențialitate va fi completată din administrare.</p>'],
    ['despre', 'Despre noi', '<p>Dual Motors — dealer autorizat Yamaha și CFMOTO, showroom Pipera, București.</p>'],
];
$st = $pdo->prepare('INSERT IGNORE INTO pages (slug, title, body_html, is_active) VALUES (?,?,?,1)');
foreach ($pages as $p) {
    $st->execute($p);
}
echo "pages ensured\n";

echo "seed_settings: done.\n";
