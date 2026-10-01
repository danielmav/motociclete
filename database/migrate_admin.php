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
run_sql_file($pdo, __DIR__ . '/schema_yamaha_catalog.sql');
run_sql_file($pdo, __DIR__ . '/schema_cfmoto_feed.sql');

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
ensure_column($pdo, 'products', 'rabla_eligible', 'ALTER TABLE `products` ADD COLUMN `rabla_eligible` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`');
ensure_column($pdo, 'site_messages', 'anonymized_at', 'ALTER TABLE `site_messages` ADD COLUMN `anonymized_at` DATETIME NULL');
ensure_column($pdo, 'service_bookings', 'anonymized_at', 'ALTER TABLE `service_bookings` ADD COLUMN `anonymized_at` DATETIME NULL');
// Sincronizare CFMOTO (feed ATVROM): ID-urile din feed (mai multe = variante), codul de
// produs în stilul BikerShop și referința furnizorului. `feed_id` (INT) a fost înlocuit de `feed_ids`.
ensure_column($pdo, 'products', 'feed_ids', 'ALTER TABLE `products` ADD COLUMN `feed_ids` VARCHAR(255) NULL AFTER `bs_product_id`');
ensure_column($pdo, 'products', 'sku', 'ALTER TABLE `products` ADD COLUMN `sku` VARCHAR(64) NULL AFTER `feed_ids`, ADD KEY `idx_sku` (`sku`)');
ensure_column($pdo, 'products', 'supplier_ref', 'ALTER TABLE `products` ADD COLUMN `supplier_ref` VARCHAR(255) NULL AFTER `sku`');
$old = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'feed_id'")->fetchColumn();
if ((int) $old > 0) {
    $pdo->exec("UPDATE products SET feed_ids = feed_id WHERE feed_ids IS NULL AND feed_id IS NOT NULL");
    $pdo->exec('ALTER TABLE `products` DROP COLUMN `feed_id`');
    echo "  - products.feed_id (-> feed_ids)\n";
}
ensure_column($pdo, 'product_images', 'caption', 'ALTER TABLE `product_images` ADD COLUMN `caption` VARCHAR(160) NULL AFTER `filename`');

echo "migrate_admin: done.\n";
