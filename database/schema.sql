-- ---------------------------------------------------------------------------
-- motociclete.com.ro — portal catalog schema (local DB `motociclete`)
-- Milestone 2. Unified, improved schema for Yamaha + CFMOTO.
--
-- The legacy site kept three separate image tables (culori / imagini / detalii)
-- and one products table per brand. Here we unify both brands (a `brand` column)
-- and collapse the image tables into one `product_images` with a `type`
-- discriminator. Run by database/migrate_catalog.php (drops + recreates).
--
-- charset utf8mb4 throughout; InnoDB for real foreign keys.
-- ---------------------------------------------------------------------------

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `product_images`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `categories`;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- categories — 2-level hierarchy per brand (parent_id NULL = top level)
-- ---------------------------------------------------------------------------
CREATE TABLE `categories` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `brand`       VARCHAR(16)  NOT NULL,                 -- 'yamaha' | 'cfmoto'
    `parent_id`   INT UNSIGNED NULL,                     -- self FK; NULL = top level
    `name`        VARCHAR(120) NOT NULL,
    `slug`        VARCHAR(140) NOT NULL,
    `description` TEXT NULL,
    `position`    INT NOT NULL DEFAULT 0,
    `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
    `legacy_id`   INT UNSIGNED NULL,                     -- original id_categories
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_cat_slug` (`brand`, `parent_id`, `slug`),
    KEY `idx_cat_brand_parent` (`brand`, `parent_id`),
    KEY `idx_cat_legacy` (`brand`, `legacy_id`),
    CONSTRAINT `fk_cat_parent` FOREIGN KEY (`parent_id`)
        REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- products — one row per model (active products of both brands)
-- ---------------------------------------------------------------------------
CREATE TABLE `products` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `brand`              VARCHAR(16)  NOT NULL,          -- 'yamaha' | 'cfmoto'
    `category_id`        INT UNSIGNED NULL,              -- leaf (sub)category
    `name`               VARCHAR(190) NOT NULL,          -- nume
    `subtitle`           VARCHAR(190) NULL,              -- titlu
    `slug`               VARCHAR(190) NOT NULL,          -- = url_string_short
    `year`               SMALLINT UNSIGNED NULL,
    `price`              INT UNSIGNED NOT NULL DEFAULT 0,
    `discount_pct`       DECIMAL(5,2) NOT NULL DEFAULT 0.00, -- reducere (%)
    `licence`            VARCHAR(8) NULL,                -- permis (A1/A2/A)
    `cover_image`        VARCHAR(255) NULL,              -- imagine_principala (color filename)
    `excerpt`            TEXT NULL,                      -- descriere_scurta (HTML)
    `description`        LONGTEXT NULL,                  -- descriere_lunga (HTML)
    `details_html`       LONGTEXT NULL,                  -- detalii (HTML w/ CDN imgs)
    `specs_engine`       LONGTEXT NULL,                  -- motor (HTML table)
    `specs_chassis`      LONGTEXT NULL,                  -- sasiu
    `specs_dimensions`   LONGTEXT NULL,                  -- dimensiuni
    `specs_connectivity` LONGTEXT NULL,                  -- conectivitate
    `video`              VARCHAR(32) NULL,               -- YouTube id
    `keywords`           TEXT NULL,                      -- cuvinte_cheie
    `is_active`          TINYINT(1) NOT NULL DEFAULT 1,
    `position`           INT NOT NULL DEFAULT 0,
    `legacy_id`          INT UNSIGNED NULL,              -- original id_product
    `legacy_url`         VARCHAR(255) NULL,              -- url_string_full (301 map)
    `lp_make_id`         INT UNSIGNED NULL,              -- BikerShop id_leopartsfilter_make
    `lp_model_id`        INT UNSIGNED NULL,              -- BikerShop id_leopartsfilter_model
    `lp_year_id`         INT UNSIGNED NULL,              -- BikerShop id_leopartsfilter_year
    `bs_product_id`      INT UNSIGNED NULL,              -- BikerShop id_product al motocicletei (produse asociate via advrider_related)
    `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_prod_slug` (`brand`, `slug`),
    KEY `idx_prod_category` (`category_id`),
    KEY `idx_prod_brand_active` (`brand`, `is_active`),
    KEY `idx_prod_legacy_url` (`legacy_url`),
    KEY `idx_prod_lp_model` (`lp_model_id`),
    CONSTRAINT `fk_prod_category` FOREIGN KEY (`category_id`)
        REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- product_images — unifies legacy culori / imagini / detalii tables
-- ---------------------------------------------------------------------------
CREATE TABLE `product_images` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `type`       ENUM('color','gallery','detail') NOT NULL,
    `filename`   VARCHAR(255) NOT NULL,                  -- stored (sanitized) filename
    `position`   INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_img_product_type` (`product_id`, `type`),
    CONSTRAINT `fk_img_product` FOREIGN KEY (`product_id`)
        REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- settings — small key/value store (exchange rate, VAT). NOT dropped on
-- re-migration, so the admin-managed values survive a catalog re-import.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `skey`   VARCHAR(64) NOT NULL,
    `svalue` TEXT NULL,                -- TEXT: ține HTML lung (intro Despre, descriere Service)
    PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`skey`, `svalue`) VALUES
    ('eur_ron_rate', '5.00'),       -- curs EUR->RON, actualizat din admin
    ('vat_pct', '21'),              -- TVA %
    ('price_includes_vat', '1');    -- 1 = prețurile EUR din DB includ deja TVA (confirmat cu clientul)

-- ---------------------------------------------------------------------------
-- news (blog "Pe Două Roți") — migrat din legacy `noutati` (moto + cfmoto) prin
-- database/migrate_news.php. NOT dropped on catalog re-migration (CREATE IF NOT
-- EXISTS); migrate_news face TRUNCATE + re-import. Imaginile rămân pe site-ul live.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `news` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `brand`        VARCHAR(16)  NOT NULL DEFAULT '',     -- sursa: 'yamaha' | 'cfmoto'
    `title`        VARCHAR(512) NOT NULL,
    `slug`         VARCHAR(255) NOT NULL,
    `excerpt`      TEXT NULL,
    `body`         MEDIUMTEXT NULL,                       -- HTML articol
    `published_at` DATETIME NULL,
    `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
    `legacy_id`    INT UNSIGNED NULL,                     -- id_noutate original
    PRIMARY KEY (`id`),
    KEY `idx_news_active_date` (`is_active`, `published_at`),
    KEY `idx_news_slug` (`slug`),
    KEY `idx_news_legacy` (`brand`, `legacy_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- news_images — imaginile fiecărui articol (din legacy `imagini_noutati`).
-- `is_cover` = imagine_principala. Fișierele sunt în /media/noutati-moto/ (gitignored).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `news_images` (
    `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `news_id`   INT UNSIGNED NOT NULL,
    `filename`  VARCHAR(255) NOT NULL,                    -- nume fișier (verbatim; url-encodat la afișare)
    `is_cover`  TINYINT(1) NOT NULL DEFAULT 0,
    `position`  INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_newsimg_news` (`news_id`, `is_cover`),
    CONSTRAINT `fk_newsimg_news` FOREIGN KEY (`news_id`)
        REFERENCES `news` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
