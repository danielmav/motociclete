<?php
declare(strict_types=1);
// Applies the admin schema (idempotent) + conditional column adds. Run with Laragon PHP 8.1:
//   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/migrate_admin.php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_dbutil.php';
if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}
$settings = require __DIR__ . '/../config/settings.php';
$pdo = (new App\Database($settings['db']))->local();

run_sql_file($pdo, __DIR__ . '/schema_admin.sql');
run_sql_file($pdo, __DIR__ . '/schema_pages.sql');

// Widen settings.svalue to TEXT (older schemas had VARCHAR(255) → truncated long HTML).
$col = $pdo->query(
    "SELECT DATA_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'svalue'"
)->fetchColumn();
if ($col !== false && strtolower((string) $col) !== 'text') {
    $pdo->exec('ALTER TABLE `settings` MODIFY `svalue` TEXT NULL');
    echo "  ~ settings.svalue -> TEXT\n";
}

// Non-destructive column adds (cross-engine: checked via information_schema).
ensure_column($pdo, 'news', 'category_id', 'ALTER TABLE `news` ADD COLUMN `category_id` INT UNSIGNED NULL');
ensure_column($pdo, 'site_messages', 'is_read', 'ALTER TABLE `site_messages` ADD COLUMN `is_read` TINYINT(1) NOT NULL DEFAULT 0');
ensure_column($pdo, 'products', 'promo_html', 'ALTER TABLE `products` ADD COLUMN `promo_html` LONGTEXT NULL AFTER `description`');
ensure_column($pdo, 'products', 'variants_json', 'ALTER TABLE `products` ADD COLUMN `variants_json` TEXT NULL AFTER `details_html`');
ensure_column($pdo, 'site_messages', 'anonymized_at', 'ALTER TABLE `site_messages` ADD COLUMN `anonymized_at` DATETIME NULL');
ensure_column($pdo, 'service_bookings', 'anonymized_at', 'ALTER TABLE `service_bookings` ADD COLUMN `anonymized_at` DATETIME NULL');

echo "migrate_admin: done.\n";
