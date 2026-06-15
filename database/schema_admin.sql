-- Admin back-office tables. Non-destructive (CREATE IF NOT EXISTS) so it can be
-- (re)run any time without touching catalog data. Grows as admin modules land.

-- Multi-user admin login (same level for everyone; no roles).
CREATE TABLE IF NOT EXISTS `admin_users` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(64)  NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `name`          VARCHAR(120) NOT NULL DEFAULT '',
    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `last_login_at` DATETIME NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Blog categories (optional grouping for `news`; news.category_id added by migrate_admin.php).
CREATE TABLE IF NOT EXISTS `news_categories` (
    `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`     VARCHAR(120) NOT NULL,
    `slug`     VARCHAR(140) NOT NULL,
    `position` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_newscat_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Events (new public section) + gallery.
CREATE TABLE IF NOT EXISTS `events` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`       VARCHAR(255) NOT NULL,
    `slug`        VARCHAR(255) NOT NULL,
    `excerpt`     TEXT NULL,
    `body_html`   MEDIUMTEXT NULL,
    `location`    VARCHAR(255) NULL,
    `starts_at`   DATETIME NULL,
    `ends_at`     DATETIME NULL,
    `cover_image` VARCHAR(255) NULL,            -- filename in /media/evenimente
    `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
    `position`    INT NOT NULL DEFAULT 0,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_event_slug` (`slug`),
    KEY `idx_event_active` (`is_active`, `starts_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `event_images` (
    `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_id` INT UNSIGNED NOT NULL,
    `filename` VARCHAR(255) NOT NULL,           -- filename in /media/evenimente
    `position` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_evimg_event` (`event_id`),
    CONSTRAINT `fk_evimg_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Static HTML pages (terms, privacy, about...) editable in admin, served at /{slug}.
CREATE TABLE IF NOT EXISTS `pages` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug`       VARCHAR(160) NOT NULL,
    `title`      VARCHAR(255) NOT NULL,
    `body_html`  MEDIUMTEXT NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_page_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contact endpoints per department (sales moto / equipment / service / accounting...).
CREATE TABLE IF NOT EXISTS `contact_departments` (
    `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `label`    VARCHAR(120) NOT NULL,
    `email`    VARCHAR(190) NULL,
    `phone`    VARCHAR(60)  NULL,
    `position` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every outgoing email sent from the site is persisted here (admin Messages).
CREATE TABLE IF NOT EXISTS `email_log` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `to_addr`    VARCHAR(190) NOT NULL,
    `subject`    VARCHAR(255) NOT NULL,
    `body`       MEDIUMTEXT NULL,
    `context`    VARCHAR(40) NULL,               -- e.g. otp, lead, service, contact
    `status`     VARCHAR(16) NOT NULL DEFAULT 'sent',  -- sent | logged | failed
    `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_email_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
