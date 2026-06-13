-- Hero slides for the home page rotator. Content is DB-driven so it can be
-- managed from the admin later. Non-destructive (CREATE IF NOT EXISTS).
-- Seed/refresh data with database/seed_hero.php (PHP 8.1 Laragon, diacritics via PDO).

CREATE TABLE IF NOT EXISTS hero_slides (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    position    INT NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    kicker      VARCHAR(120)  NOT NULL DEFAULT '',
    title_html  VARCHAR(400)  NOT NULL DEFAULT '',   -- allows the <span class="herov2__accent"> markup
    subtitle    VARCHAR(600)  NOT NULL DEFAULT '',
    cta_label   VARCHAR(80)   NOT NULL DEFAULT '',
    cta_href    VARCHAR(255)  NOT NULL DEFAULT '',
    image       VARCHAR(255)  NOT NULL DEFAULT '',   -- web path, e.g. /media/hero/showroom.jpg (or absolute URL)
    image_alt   VARCHAR(200)  NOT NULL DEFAULT '',
    ghost       VARCHAR(40)   NOT NULL DEFAULT '',   -- decorative outline word
    stats_json  TEXT NULL,                            -- JSON [{"value":"23","label":"ani experiență"}], null = no stats
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_active_pos (is_active, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
