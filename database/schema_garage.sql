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

-- ---------------------------------------------------------------------------
-- client_bikes — o motocicletă deținută (seed din `clienti`, una per rând clienti).
-- Datele bogate (km, culoare, plăcuță…) sunt întreținute de ADMIN. Legăturile spre
-- clienti/products sunt SOFT (fără FK) — products se re-creează la re-migrare.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `client_bikes` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `clienti_id`    INT UNSIGNED NOT NULL,        -- soft link -> clienti.id
    `product_id`    INT UNSIGNED NULL,            -- soft link -> products.id (din `unitate`)
    `model_label`   VARCHAR(190) NULL,            -- fallback (clienti.unitate) când nu e match
    `year`          SMALLINT UNSIGNED NULL,
    `vin`           VARCHAR(32) NULL,
    `color`         VARCHAR(80) NULL,
    `plate`         VARCHAR(20) NULL,             -- număr înmatriculare
    `mileage_km`    INT UNSIGNED NULL,
    `purchase_date` DATE NULL,
    `nickname`      VARCHAR(80) NULL,
    `notes`         TEXT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_bike_clienti` (`clienti_id`),  -- 1 rând clienti = 1 motocicletă
    KEY `idx_bikes_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- service_records — istoric revizii/reparații (introdus de admin).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `service_records` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bike_id`      INT UNSIGNED NOT NULL,
    `service_date` DATE NULL,
    `mileage_km`   INT UNSIGNED NULL,
    `type`         VARCHAR(40) NULL,             -- revizie/reparație/anvelope/...
    `description`  TEXT NULL,
    `cost_ron`     DECIMAL(10,2) NULL,
    `performed_by` VARCHAR(120) NULL,
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sr_bike` (`bike_id`, `service_date`),
    CONSTRAINT `fk_sr_bike` FOREIGN KEY (`bike_id`) REFERENCES `client_bikes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- incidents — accidente/evenimente (introduse de admin).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `incidents` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bike_id`       INT UNSIGNED NOT NULL,
    `incident_date` DATE NULL,
    `severity`      VARCHAR(20) NULL,            -- minor/mediu/major
    `description`   TEXT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_inc_bike` (`bike_id`, `incident_date`),
    CONSTRAINT `fk_inc_bike` FOREIGN KEY (`bike_id`) REFERENCES `client_bikes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- service_requests — cereri de programare trimise de client; dealerul e notificat.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `service_requests` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bike_id`        INT UNSIGNED NULL,
    `clienti_id`     INT UNSIGNED NOT NULL,
    `preferred_date` DATE NULL,
    `problem`        TEXT NULL,
    `status`         ENUM('nou','confirmat','inchis') NOT NULL DEFAULT 'nou',
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_srq_status` (`status`, `created_at`),
    KEY `idx_srq_clienti` (`clienti_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- client_otp — coduri OTP passwordless pentru login (expiră, single-use, rate-limit).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `client_otp` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `identifier` VARCHAR(256) NOT NULL,          -- email_norm
    `code_hash`  CHAR(64) NOT NULL,              -- sha256(cod)
    `expires_at` DATETIME NOT NULL,
    `attempts`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `used_at`    DATETIME NULL,
    `ip`         VARCHAR(45) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_otp_ident` (`identifier`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
