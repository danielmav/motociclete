-- Rândurile feed-ului CFMOTO (ATVROM) deja procesate de database/sync_cfmoto_feed.php.
-- Un rând „văzut" nu mai e adăugat ca produs nou (chiar dacă produsul creat din el a fost
-- șters din admin). Legătura activă produs↔feed = `products.feed_id`.
-- NEDISTRUCTIV (CREATE IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS `cfmoto_feed_seen` (
    `feed_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(190) NOT NULL DEFAULT '',
    `product_id` INT UNSIGNED NULL,
    `first_seen` DATETIME NOT NULL,
    `last_seen` DATETIME NOT NULL,
    PRIMARY KEY (`feed_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
