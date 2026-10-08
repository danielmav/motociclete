-- Vehicule rulate (second hand). Modul separat de catalog. Vezi
-- docs/superpowers/specs/2026-10-08-vehicule-rulate-design.md

CREATE TABLE IF NOT EXISTS `used_brands` (
    `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`     VARCHAR(120) NOT NULL,
    `slug`     VARCHAR(140) NOT NULL,
    `position` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_usedbrand_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `used_categories` (
    `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`     VARCHAR(120) NOT NULL,
    `slug`     VARCHAR(140) NOT NULL,
    `position` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_usedcat_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `used_vehicles` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`            VARCHAR(255) NOT NULL,
    `slug`             VARCHAR(255) NOT NULL,
    `brand_id`         INT UNSIGNED NOT NULL,
    `category_id`      INT UNSIGNED NOT NULL,
    `price_eur`        DECIMAL(10,2) NULL,
    `year`             SMALLINT UNSIGNED NULL,
    `km`               INT UNSIGNED NULL,
    `cc`               SMALLINT UNSIGNED NULL,
    `description_html` MEDIUMTEXT NULL,
    `video`            VARCHAR(255) NULL,
    `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
    `expires_at`       DATETIME NOT NULL,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_used_public` (`is_active`, `expires_at`),
    KEY `idx_used_cat` (`category_id`),
    KEY `idx_used_brand` (`brand_id`),
    CONSTRAINT `fk_used_brand` FOREIGN KEY (`brand_id`) REFERENCES `used_brands` (`id`),
    CONSTRAINT `fk_used_cat` FOREIGN KEY (`category_id`) REFERENCES `used_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `used_images` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `vehicle_id` INT UNSIGNED NOT NULL,
    `filename`   VARCHAR(255) NOT NULL,
    `is_cover`   TINYINT(1) NOT NULL DEFAULT 0,
    `position`   INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_usedimg_vehicle` (`vehicle_id`, `position`),
    CONSTRAINT `fk_usedimg_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `used_vehicles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
