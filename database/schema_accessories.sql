-- Accesorii originale Yamaha — sursă de adevăr în portal (relația accesoriu↔model).
-- Populare: database/import_yamaha_accessories.php (din endpointul hyperdrive Yamaha).
-- Afișare: src/Accessories/Repository.php (pagina produs, partea OEM Yamaha).
-- Cumpărarea rămâne pe BikerShop → prețul/imaginea/URL-ul vin live prin bs_product_id
-- (match pe referință). NEDISTRUCTIV (CREATE IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS `yamaha_accessories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `yamaha_id` VARCHAR(64) NOT NULL,            -- id-ul produsului din JSON-ul Yamaha
    `reference` VARCHAR(64) NOT NULL DEFAULT '', -- referința (= ps_product.reference pe bikershop)
    `name` VARCHAR(512) NOT NULL DEFAULT '',
    `price_eur` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `accessory_type` VARCHAR(128) NOT NULL DEFAULT '',
    `bs_product_id` INT UNSIGNED NULL,           -- produsul corespunzător pe bikershop (NULL = neimportat încă)
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_yamaha_id` (`yamaha_id`),
    KEY `idx_acc_reference` (`reference`),
    KEY `idx_acc_bs` (`bs_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Relația M2M accesoriu ↔ model local (un accesoriu se potrivește mai multor modele).
CREATE TABLE IF NOT EXISTS `yamaha_accessory_models` (
    `accessory_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,          -- products.id (model local)
    `position` INT NOT NULL DEFAULT 0,           -- ordinea din feed (popularitate)
    PRIMARY KEY (`accessory_id`, `product_id`),
    KEY `idx_accmod_product` (`product_id`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
