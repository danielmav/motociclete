<?php

declare(strict_types=1);

/**
 * One-off news migration: legacy `noutati` (+ `imagini_noutati`) din ambele DB-uri
 * sursă (dualmotors_motociclete + dualmotors_cfmoto) -> tabela `news` din baza
 * portalului (`motociclete` local / `dualmotors_motociclete2026` pe server).
 *
 * Scopul: portalul nu mai depinde la runtime de bazele legacy (pot fi șterse).
 * Imaginile rămân servite de pe site-ul live.
 *
 * Run cu Laragon PHP 8.1 (are pdo_mysql):
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" database/migrate_news.php
 *
 * Idempotent: face TRUNCATE + re-import. Apoi: dump local -> import în
 * dualmotors_motociclete2026 pe server (vezi DEPLOY.md).
 */

use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';            // PSR-4 + helpers (slugify) + Dotenv
Dotenv::createImmutable($root)->safeLoad();
$settings = require $root . '/config/settings.php';

$dm  = $settings['db']['dm'];
$loc = $settings['db']['local'];

if (empty($dm['host'])) {
    fwrite(STDERR, "DM_* source DB credentials not configured in .env\n");
    exit(1);
}

$opt = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$srcDsn = fn (string $db) => sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dm['host'], $dm['port'], $db);

$local = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $loc['host'], $loc['port'], $loc['name']),
    $loc['user'],
    $loc['pass'],
    $opt
);

// Surse: baza + brand + baza URL imagini de pe site-ul live.
$sources = [
    ['brand' => 'yamaha', 'db' => $dm['db_moto'],   'img' => 'https://www.motociclete.com.ro/images/noutati/'],
    ['brand' => 'cfmoto', 'db' => $dm['db_cfmoto'], 'img' => 'https://www.motociclete.com.ro/cfmoto/images/noutati/'],
];

// Tabela `news` (idempotent; nu depinde de re-aplicarea schema.sql).
$local->exec(
    "CREATE TABLE IF NOT EXISTS `news` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `brand` VARCHAR(16) NOT NULL DEFAULT '',
        `title` VARCHAR(512) NOT NULL,
        `slug` VARCHAR(255) NOT NULL,
        `excerpt` TEXT NULL,
        `body` MEDIUMTEXT NULL,
        `image_url` VARCHAR(512) NULL,
        `published_at` DATETIME NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `legacy_id` INT UNSIGNED NULL,
        PRIMARY KEY (`id`),
        KEY `idx_news_active_date` (`is_active`, `published_at`),
        KEY `idx_news_slug` (`slug`),
        KEY `idx_news_legacy` (`brand`, `legacy_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$local->exec("TRUNCATE TABLE `news`");

$ins = $local->prepare(
    "INSERT INTO news (brand, title, slug, excerpt, body, image_url, published_at, is_active, legacy_id)
     VALUES (:brand, :title, :slug, :excerpt, :body, :image_url, :published_at, 1, :legacy_id)"
);

$excerptOf = function (string $html): string {
    $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));
    return mb_strlen($plain) > 200 ? mb_substr($plain, 0, 200) . '…' : $plain;
};

$total = 0;
foreach ($sources as $src) {
    echo "── {$src['brand']} ({$src['db']}) ";
    try {
        $pdo = new PDO($srcDsn($src['db']), $dm['user'], $dm['pass'], $opt);
    } catch (Throwable $e) {
        echo "→ skip (conn: {$e->getMessage()})\n";
        continue;
    }

    try {
        $rows = $pdo->query(
            "SELECT n.id_noutate, n.titlu, n.noutate_text, n.noutate_datetime, n.adaugat,
                    (SELECT i.imagine FROM imagini_noutati i
                      WHERE i.id_noutate = n.id_noutate
                      ORDER BY i.imagine_principala DESC, i.id_imagine ASC LIMIT 1) AS imagine
             FROM noutati n
             WHERE n.active = 1
             ORDER BY n.noutate_datetime DESC"
        )->fetchAll();
    } catch (Throwable $e) {
        echo "→ skip (query: {$e->getMessage()})\n";
        continue;
    }

    $n = 0;
    foreach ($rows as $r) {
        $title = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $r['titlu'])));
        if ($title === '') {
            continue;
        }
        $ts = (int) $r['noutate_datetime'];
        $publishedAt = $ts > 0 ? date('Y-m-d H:i:s', $ts) : ($r['adaugat'] ?: null);
        $ins->execute([
            ':brand'        => $src['brand'],
            ':title'        => $title,
            ':slug'         => slugify($title),
            ':excerpt'      => $excerptOf((string) $r['noutate_text']),
            ':body'         => (string) $r['noutate_text'],
            ':image_url'    => $r['imagine'] ? $src['img'] . rawurlencode((string) $r['imagine']) : null,
            ':published_at' => $publishedAt,
            ':legacy_id'    => (int) $r['id_noutate'],
        ]);
        $n++;
    }
    $total += $n;
    echo "→ {$n} noutăți\n";
}

echo "─────────────────────────────────────────\n";
echo "  Total importat în `news`: {$total}\n";
echo "  Următor: dump `{$loc['name']}` → import în dualmotors_motociclete2026 pe server.\n";
