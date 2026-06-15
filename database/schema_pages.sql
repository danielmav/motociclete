-- ===========================================================================
-- schema_pages.sql — tables for the admin-managed "Despre noi" + "Service"
-- pages. All CREATE TABLE IF NOT EXISTS (non-destructive, cross-engine:
-- MySQL 8 local + MariaDB staging). Run via database/migrate_admin.php.
--
-- The intro/description rich-text blocks live as `settings` keys (Support\Settings):
--   about_heading, about_intro_html,
--   service_heading, service_desc_html, service_note_html
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- about_images — showroom gallery on the "Despre noi" intro section.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `about_images` (
    `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `filename` VARCHAR(255) NOT NULL,
    `position` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_about_images_pos` (`position`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- team_members — "Echipa Dual Motors" cards (name, role, phone, email, photo).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `team_members` (
    `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`      VARCHAR(160) NOT NULL,
    `role`      VARCHAR(200) NULL,
    `phone`     VARCHAR(40)  NULL,
    `email`     VARCHAR(190) NULL,
    `photo`     VARCHAR(255) NULL,
    `position`  INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `idx_team_pos` (`position`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- history_entries / history_images — "Istoria Dual Motors" timeline (year,
-- title, rich text, gallery). Mirrors events / event_images.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `history_entries` (
    `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `year`      SMALLINT UNSIGNED NULL,
    `title`     VARCHAR(255) NOT NULL,
    `body_html` MEDIUMTEXT NULL,
    `position`  INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_history_order` (`is_active`, `year`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `history_images` (
    `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `entry_id` INT UNSIGNED NOT NULL,
    `filename` VARCHAR(255) NOT NULL,
    `position` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_history_images_entry` (`entry_id`, `position`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- service_prices — structured price list rows, grouped (e.g. Motociclete /
-- Scutere). Prices are free text ("2 h / 600 lei", "de la 350 lei", ...).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `service_prices` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `group_label` VARCHAR(150) NOT NULL,
    `label`       VARCHAR(255) NOT NULL,
    `price`       VARCHAR(120) NULL,
    `position`    INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_service_prices_pos` (`position`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- service_bookings — anonymous public service-appointment submissions
-- (separate from My-Garage service_requests, which require a logged-in client).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `service_bookings` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(160) NOT NULL,
    `email`         VARCHAR(190) NULL,
    `phone`         VARCHAR(40)  NULL,
    `marca`         VARCHAR(120) NULL,
    `model`         VARCHAR(160) NULL,
    `an_fabricatie` VARCHAR(20)  NULL,
    `sasiu`         VARCHAR(120) NULL,
    `kilometri`     VARCHAR(40)  NULL,
    `lucrari`       TEXT NULL,
    `status`        ENUM('nou','confirmat','inchis') NOT NULL DEFAULT 'nou',
    `is_read`       TINYINT(1) NOT NULL DEFAULT 0,
    `ip`            VARCHAR(45) NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_service_bookings_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
