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

-- Coada de trimitere: un rând per destinatar per campanie. Cheia unică garantează că
-- nimeni nu primește o campanie de două ori. `sending` = rezervat chiar înainte de SMTP;
-- un rând rămas așa (proces întrerupt) NU se retrimite.
CREATE TABLE IF NOT EXISTS `nl_sends` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaign_id`   INT UNSIGNED NOT NULL,
    `subscriber_id` INT UNSIGNED NOT NULL,
    `status`        ENUM('queued','sending','sent','failed','skipped','soft_bounced','bounced','complained') NOT NULL DEFAULT 'queued',
    `attempts`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `message_id`    VARCHAR(190) NULL,
    `error`         VARCHAR(255) NULL,
    `queued_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `claimed_at`    DATETIME NULL,
    `sent_at`       DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_nl_send` (`campaign_id`, `subscriber_id`),
    KEY `idx_nl_send_status` (`campaign_id`, `status`),
    KEY `idx_nl_send_subscriber` (`subscriber_id`),
    KEY `idx_nl_send_message` (`message_id`),
    KEY `idx_nl_send_sent` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nl_links` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaign_id` INT UNSIGNED NOT NULL,
    `url_hash`    CHAR(40) NOT NULL,
    `url`         TEXT NOT NULL,
    `block`       VARCHAR(60) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_nl_link` (`campaign_id`, `url_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nl_clicks` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `link_id`       INT UNSIGNED NOT NULL,
    `subscriber_id` INT UNSIGNED NOT NULL,
    `clicked_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_nl_click_link` (`link_id`, `subscriber_id`),
    KEY `idx_nl_click_time` (`clicked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jurnalul brut al notificărilor primite de la releu (diagnostic; șters după 90 de zile).
CREATE TABLE IF NOT EXISTS `nl_events` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `provider`   VARCHAR(20) NOT NULL,
    `type`       VARCHAR(30) NOT NULL,
    `email`      VARCHAR(190) NULL,
    `message_id` VARCHAR(190) NULL,
    `payload`    MEDIUMTEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_nl_event_time` (`created_at`),
    KEY `idx_nl_event_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
