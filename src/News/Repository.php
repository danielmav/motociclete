<?php

declare(strict_types=1);

namespace App\News;

use App\Database;
use PDO;
use Throwable;

/**
 * Reads the blog/news from the portal's OWN DB (`news` + `news_images`), populated
 * from the legacy tables by database/migrate_news.php. Read-only, graceful
 * degradation. Imaginile sunt servite local din /media/noutati-moto/.
 */
final class Repository
{
    private ?PDO $pdo;

    private const IMG_BASE = '/media/noutati-moto/';
    private const MONTHS = [1 => 'ian', 'feb', 'mar', 'apr', 'mai', 'iun', 'iul', 'aug', 'sep', 'oct', 'nov', 'dec'];

    /** Subquery: cover filename (is_cover first, then position). */
    private const COVER_SUBQUERY =
        "(SELECT i.filename FROM news_images i WHERE i.news_id = n.id
          ORDER BY i.is_cover DESC, i.position ASC, i.id ASC LIMIT 1)";

    public function __construct(Database $db)
    {
        try {
            $this->pdo = $db->local();
        } catch (Throwable) {
            $this->pdo = null;
        }
    }

    public function isAvailable(): bool
    {
        return $this->pdo instanceof PDO;
    }

    /** Latest active articles, shaped as cards. @return array<int,array<string,mixed>> */
    public function latest(int $limit = 3): array
    {
        $rows = $this->all(
            "SELECT n.id, n.title, n.slug, n.excerpt, n.published_at,
                    " . self::COVER_SUBQUERY . " AS cover
             FROM news n
             WHERE n.is_active = 1
             ORDER BY n.published_at DESC, n.id DESC
             LIMIT " . (int) $limit
        );
        return array_map([$this, 'shapeCard'], $rows);
    }

    /** Total active articles (for pagination). */
    public function count(): int
    {
        $r = $this->one("SELECT COUNT(*) AS c FROM news WHERE is_active = 1");
        return $r ? (int) $r['c'] : 0;
    }

    /** One page of articles (newest first), shaped as cards. @return array<int,array<string,mixed>> */
    public function page(int $page, int $perPage): array
    {
        $perPage = max(1, $perPage);
        $offset  = max(0, ($page - 1) * $perPage);
        $rows = $this->all(
            "SELECT n.id, n.title, n.slug, n.excerpt, n.published_at,
                    " . self::COVER_SUBQUERY . " AS cover
             FROM news n
             WHERE n.is_active = 1
             ORDER BY n.published_at DESC, n.id DESC
             LIMIT " . (int) $perPage . " OFFSET " . (int) $offset
        );
        return array_map([$this, 'shapeCard'], $rows);
    }

    /**
     * Search active articles by title, excerpt and body. Every word must match
     * (AND). Shaped as cards. @return array<int,array<string,mixed>>
     */
    public function search(string $query, int $limit = 12): array
    {
        $parts = preg_split('/\s+/', trim($query)) ?: [];
        $words = array_values(array_filter($parts, static fn ($w) => mb_strlen($w) >= 2));
        if (!$words || !$this->isAvailable()) {
            return [];
        }
        $conds = [];
        $params = [];
        foreach ($words as $w) {
            $conds[] = "(n.title LIKE ? OR n.excerpt LIKE ? OR n.body LIKE ?)";
            $like = '%' . $w . '%';
            array_push($params, $like, $like, $like);
        }
        $params[] = $query . '%';
        $rows = $this->all(
            "SELECT n.id, n.title, n.slug, n.excerpt, n.published_at,
                    " . self::COVER_SUBQUERY . " AS cover
             FROM news n
             WHERE n.is_active = 1 AND " . implode(' AND ', $conds) . "
             ORDER BY (n.title LIKE ?) DESC, n.published_at DESC, n.id DESC
             LIMIT " . (int) $limit,
            $params
        );
        return array_map([$this, 'shapeCard'], $rows);
    }

    /** A single article by id (with body + gallery), or null. @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->one(
            "SELECT n.id, n.title, n.slug, n.excerpt, n.body, n.published_at,
                    " . self::COVER_SUBQUERY . " AS cover
             FROM news n
             WHERE n.is_active = 1 AND n.id = :id",
            [':id' => $id]
        );
        if (!$row) {
            return null;
        }
        $card = $this->shapeCard($row);
        $card['body']   = (string) $row['body'];
        $card['images'] = $this->galleryImages($id);
        return $card;
    }

    /** Non-cover images for the article gallery. @return array<int,string> web paths */
    private function galleryImages(int $id): array
    {
        $rows = $this->all(
            "SELECT filename FROM news_images
             WHERE news_id = :id AND is_cover = 0
             ORDER BY position ASC, id ASC",
            [':id' => $id]
        );
        return array_values(array_filter(array_map(fn ($r) => $this->imgPath($r['filename']), $rows)));
    }

    /** @return array<string,mixed> */
    private function shapeCard(array $r): array
    {
        $id = (int) $r['id'];
        return [
            'id'      => $id,
            'title'   => (string) $r['title'],
            'excerpt' => (string) $r['excerpt'],
            'date'    => $this->roDate((string) ($r['published_at'] ?? '')),
            'image'   => $this->imgPath($r['cover'] ?? null),
            'url'     => '/blog/' . $id . '-' . ($r['slug'] ?: 'articol'),
        ];
    }

    /** Web-relative image path (templates prepend {{ base }}). Null if no file. */
    private function imgPath(?string $filename): ?string
    {
        return $filename ? self::IMG_BASE . rawurlencode($filename) : null;
    }

    /** DATETIME string -> "3 iun 2026". */
    private function roDate(string $dt): string
    {
        $ts = $dt !== '' ? strtotime($dt) : false;
        if ($ts === false) {
            return '';
        }
        return (int) date('j', $ts) . ' ' . (self::MONTHS[(int) date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
    }

    /** @param array<string,mixed> $params @return array<int,array<string,mixed>> */
    private function all(string $sql, array $params = []): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $params @return array<string,mixed>|null */
    private function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }
}
