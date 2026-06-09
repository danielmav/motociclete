<?php

declare(strict_types=1);

/**
 * One-off news migration: legacy `noutati` + `imagini_noutati` din ambele DB-uri
 * sursă (dualmotors_motociclete + dualmotors_cfmoto) -> tabelele `news` +
 * `news_images` din baza portalului (`motociclete` local / `dualmotors_motociclete2026`).
 *
 * Scopul: portalul nu mai depinde la runtime de bazele legacy (pot fi șterse).
 * Imaginile sunt servite din /media/noutati-moto/ (mutate manual din /images/noutati).
 *
 * Run cu Laragon PHP 8.1 (are pdo_mysql):
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" database/migrate_news.php
 *
 * Idempotent: drop + recreate + re-import. Apoi: dump local -> import în
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

// Surse: baza + brand. (Imaginile sunt servite local din /media/noutati-moto/.)
$sources = [
    ['brand' => 'yamaha', 'db' => $dm['db_moto']],
    ['brand' => 'cfmoto', 'db' => $dm['db_cfmoto']],
];

// Tabelele (drop + recreate, ca structura să fie mereu corectă).
$local->exec('SET FOREIGN_KEY_CHECKS = 0');
$local->exec('DROP TABLE IF EXISTS `news_images`');
$local->exec('DROP TABLE IF EXISTS `news`');
$local->exec('SET FOREIGN_KEY_CHECKS = 1');
$local->exec(
    "CREATE TABLE `news` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `brand` VARCHAR(16) NOT NULL DEFAULT '',
        `title` VARCHAR(512) NOT NULL,
        `slug` VARCHAR(255) NOT NULL,
        `excerpt` TEXT NULL,
        `body` MEDIUMTEXT NULL,
        `published_at` DATETIME NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `legacy_id` INT UNSIGNED NULL,
        PRIMARY KEY (`id`),
        KEY `idx_news_active_date` (`is_active`, `published_at`),
        KEY `idx_news_slug` (`slug`),
        KEY `idx_news_legacy` (`brand`, `legacy_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$local->exec(
    "CREATE TABLE `news_images` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `news_id` INT UNSIGNED NOT NULL,
        `filename` VARCHAR(255) NOT NULL,
        `is_cover` TINYINT(1) NOT NULL DEFAULT 0,
        `position` INT NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_newsimg_news` (`news_id`, `is_cover`),
        CONSTRAINT `fk_newsimg_news` FOREIGN KEY (`news_id`) REFERENCES `news` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$insNews = $local->prepare(
    "INSERT INTO news (brand, title, slug, excerpt, body, published_at, is_active, legacy_id)
     VALUES (:brand, :title, :slug, :excerpt, :body, :published_at, 1, :legacy_id)"
);
$insImg = $local->prepare(
    "INSERT INTO news_images (news_id, filename, is_cover, position)
     VALUES (:news_id, :filename, :is_cover, :position)"
);

$excerptOf = function (string $html): string {
    $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));
    return mb_strlen($plain) > 200 ? mb_substr($plain, 0, 200) . '…' : $plain;
};
$cleanName = fn (?string $name): string => basename(str_replace('\\', '/', trim((string) $name)));

$totalNews = 0;
$totalImg = 0;
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
            "SELECT n.id_noutate, n.titlu, n.noutate_text, n.noutate_datetime, n.adaugat
             FROM noutati n WHERE n.active = 1 ORDER BY n.noutate_datetime DESC"
        )->fetchAll();
    } catch (Throwable $e) {
        echo "→ skip (query: {$e->getMessage()})\n";
        continue;
    }

    $imgStmt = $pdo->prepare(
        "SELECT imagine, imagine_principala FROM imagini_noutati
         WHERE id_noutate = :id AND imagine IS NOT NULL AND imagine <> ''
         ORDER BY imagine_principala DESC, id_imagine ASC"
    );

    $n = 0;
    $im = 0;
    foreach ($rows as $r) {
        $title = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $r['titlu'])));
        if ($title === '') {
            continue;
        }
        $ts = (int) $r['noutate_datetime'];
        $publishedAt = $ts > 0 ? date('Y-m-d H:i:s', $ts) : ($r['adaugat'] ?: null);
        $insNews->execute([
            ':brand'        => $src['brand'],
            ':title'        => $title,
            ':slug'         => slugify($title),
            ':excerpt'      => $excerptOf((string) $r['noutate_text']),
            ':body'         => (string) $r['noutate_text'],
            ':published_at' => $publishedAt,
            ':legacy_id'    => (int) $r['id_noutate'],
        ]);
        $newsId = (int) $local->lastInsertId();
        $n++;

        $imgStmt->execute([':id' => (int) $r['id_noutate']]);
        $pos = 0;
        $seen = [];
        foreach ($imgStmt->fetchAll() as $img) {
            $file = $cleanName($img['imagine']);
            if ($file === '' || isset($seen[$file])) {
                continue; // sări peste duplicate în cadrul aceluiași articol
            }
            $seen[$file] = true;
            $insImg->execute([
                ':news_id'  => $newsId,
                ':filename' => $file,
                ':is_cover' => ((int) $img['imagine_principala'] === 1) ? 1 : 0,
                ':position' => $pos++,
            ]);
            $im++;
        }
    }
    $totalNews += $n;
    $totalImg += $im;
    echo "→ {$n} noutăți, {$im} imagini\n";
}

echo "─────────────────────────────────────────\n";
echo "  Total în `news`: {$totalNews} · `news_images`: {$totalImg}\n";
echo "  Următor: verifică/curăță /media/noutati-moto (database/prune_news_media.php),\n";
echo "  apoi dump local `{$loc['name']}` (news + news_images) → import pe server.\n";
