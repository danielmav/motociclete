-- Snapshot al catalogului public de accesorii Yamaha (endpoint hyperdrive).
-- Sursă de adevăr pentru CODUL complet (SKU) și PREȚUL în EUR al unui accesoriu.
-- Populare: database/fetch_yamaha_catalog.php
-- Folosit de: tests/dup_ref_473.php + database/apply_dup_ref_473.php pentru a corecta
-- referințele trunchiate din categoria 473 de pe BikerShop.
--
-- `sku` = SKU-ul fără cratime (12 caractere) = exact formatul `ps_product.reference`.
-- NEDISTRUCTIV (CREATE IF NOT EXISTS); scriptul face doar upsert.

CREATE TABLE IF NOT EXISTS `yamaha_catalog` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sku` VARCHAR(64) NOT NULL,                  -- normalizat, fără cratime (= ps_product.reference)
    `sku_raw` VARCHAR(64) NOT NULL DEFAULT '',   -- forma originală Yamaha, ex. BR8-HIPER-KT-10
    `sku_base` VARCHAR(64) NOT NULL DEFAULT '',  -- primele 10 caractere (= referința trunchiată greșit)
    `yamaha_id` VARCHAR(64) NOT NULL DEFAULT '', -- id-ul produsului din JSON
    `name` VARCHAR(512) NOT NULL DEFAULT '',
    `price_eur` DECIMAL(10,2) NOT NULL DEFAULT 0, -- 0 = fără preț public (prices: [])
    `accessory_type` VARCHAR(128) NOT NULL DEFAULT '',
    `image_url` VARCHAR(512) NOT NULL DEFAULT '',
    `fetched_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_sku` (`sku`),
    KEY `idx_sku_base` (`sku_base`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
