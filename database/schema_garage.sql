-- ---------------------------------------------------------------------------
-- motociclete.com.ro — schema „My Garage" + OEM parts map (local DB `motociclete`)
--
-- SEPARAT de schema.sql: toate tabelele aici sunt CREATE IF NOT EXISTS și NU se
-- ating la re-migrarea catalogului (migrate_catalog.php face DROP doar pe
-- products/categories/product_images). Legăturile către `products` sunt SOFT
-- (coloană indexată, fără FK), fiindcă `products` e re-creat la re-migrare și
-- id-urile se pot schimba → se re-rezolvă rulând din nou scripturile de seed.
--
-- Rulează o singură dată (idempotent) cu clientul mysql:
--   mysql -u root motociclete < database/schema_garage.sql
-- ---------------------------------------------------------------------------

-- Map produs local -> id-uri produs BikerShop care sunt piese OEM (din
-- ps_advrider_related_diagram_cache). Precomputat de migrate_oem_fitment.php
-- fiindcă path_value nu e indexat în BikerShop (LIKE = full scan ~12s). Runtime
-- citește id-urile de aici și aduce detaliile live din BikerShop pe PK (rapid).
CREATE TABLE IF NOT EXISTS `oem_product_map` (
    `product_id`    INT UNSIGNED NOT NULL,        -- local products.id (soft link)
    `bs_id_product` INT UNSIGNED NOT NULL,        -- BikerShop ps_product.id_product
    `position`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`product_id`, `bs_id_product`),
    KEY `idx_oemmap_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
