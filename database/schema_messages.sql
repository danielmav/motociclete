-- Tables for the product-page lead forms + UniCredit financing config.
-- CREATE IF NOT EXISTS + non-destructive: safe to re-run, NOT dropped on catalog re-migration.

-- Leads submitted from the product page (Cere ofertă / Programează test ride).
CREATE TABLE IF NOT EXISTS `site_messages` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type`         ENUM('oferta','test_ride') NOT NULL,
    `brand`        VARCHAR(32)  NULL,
    `product_slug` VARCHAR(191) NULL,
    `product_name` VARCHAR(191) NULL,
    `name`         VARCHAR(120) NOT NULL,
    `email`        VARCHAR(191) NOT NULL,
    `phone`        VARCHAR(40)  NOT NULL,
    `message`      TEXT NULL,
    `licence`      VARCHAR(16)  NULL,
    `ip`           VARCHAR(45)  NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `anonymized_at`  DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_type_created` (`type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-row financing config (UniCredit). Admin-editable later. page_html holds
-- the conditions page body; seeded by database/seed_finance.php (diacritics via PDO).
CREATE TABLE IF NOT EXISTS `finance` (
    `id`           TINYINT UNSIGNED NOT NULL,
    `nominal_rate` DECIMAL(5,2) NOT NULL DEFAULT 13.00,
    `dae`          DECIMAL(5,2) NOT NULL DEFAULT 14.50,
    `admin_fee`    DECIMAL(8,2) NOT NULL DEFAULT 10.00,
    `calc_rate`    DECIMAL(5,2) NOT NULL DEFAULT 14.50,
    `terms`        VARCHAR(64)  NOT NULL DEFAULT '12,18,24,36,48,60',
    `page_title`   VARCHAR(255) NULL,
    `page_html`    MEDIUMTEXT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `finance`
    (`id`, `nominal_rate`, `dae`, `admin_fee`, `calc_rate`, `terms`)
VALUES
    (1, 13.00, 14.50, 10.00, 14.50, '12,18,24,36,48,60');
