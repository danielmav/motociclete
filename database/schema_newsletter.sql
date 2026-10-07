-- Newsletter propriu: abonati + apartenenta la liste. Idempotent (CREATE IF NOT EXISTS).
-- Rulat de database/migrate_admin.php.

CREATE TABLE IF NOT EXISTS `nl_subscribers` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`           VARCHAR(190) NOT NULL,
    `name`            VARCHAR(160) NULL,
    `token`           CHAR(32) NOT NULL,
    `status`          ENUM('pending','active','bounced','complained') NOT NULL DEFAULT 'active',
    `soft_bounces`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `signup_ip`       VARCHAR(45) NULL,
    `confirm_sent_at` DATETIME NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `confirmed_at`    DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_nl_email` (`email`),
    UNIQUE KEY `uniq_nl_token` (`token`),
    KEY `idx_nl_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nl_subscriptions` (
    `subscriber_id`   INT UNSIGNED NOT NULL,
    `list_key`        ENUM('oferte','stiri') NOT NULL,
    `status`          ENUM('active','unsubscribed') NOT NULL DEFAULT 'active',
    `source`          ENUM('bs_account','bs_footer','portal','garage','manual','brevo') NOT NULL,
    `subscribed_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `unsubscribed_at` DATETIME NULL,
    PRIMARY KEY (`subscriber_id`, `list_key`),
    KEY `idx_nl_list_status` (`list_key`, `status`),
    KEY `idx_nl_source` (`source`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nl_campaigns` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `list_key`     ENUM('oferte','stiri') NOT NULL,
    `type`         ENUM('stiri','oferte') NOT NULL,
    `subject`      VARCHAR(200) NOT NULL,
    `preheader`    VARCHAR(200) NULL,
    `view_key`     CHAR(16) NOT NULL,
    `input_json`   MEDIUMTEXT NULL,
    `html`         MEDIUMTEXT NULL,
    `body_text`    MEDIUMTEXT NULL,
    `status`       ENUM('draft','queued','sending','paused','sent') NOT NULL DEFAULT 'draft',
    `pause_reason` VARCHAR(255) NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NULL,
    `queued_at`    DATETIME NULL,
    `finished_at`  DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_nl_campaign_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
