<?php

declare(strict_types=1);

namespace App\News;

use App\Database;
use PDO;
use Throwable;

/**
 * Reads the blog/news from the portal's OWN DB (`news` table), populated from the
 * legacy `noutati` tables by database/migrate_news.php. Read-only here, graceful
 * degradation (returns []/null). Images are absolute URLs (served by the live site).
 */
final class Repository
{
    private ?PDO $pdo;

    private const MONTHS = [1 => 'ian', 'feb', 'mar', 'apr', 'mai', 'iun', 'iul', 'aug', 'sep', 'oct', 'nov', 'dec'];

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
            "SELECT id, title, slug, excerpt, image_url, published_at
             FROM news
             WHERE is_active = 1
             ORDER BY published_at DESC, id DESC
             LIMIT " . (int) $limit
        );
        return array_map([$this, 'shapeCard'], $rows);
    }

    /** A single article by id (with full HTML body), or null. @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->one(
            "SELECT id, title, slug, excerpt, body, image_url, published_at
             FROM news
             WHERE is_active = 1 AND id = :id",
            [':id' => $id]
        );
        if (!$row) {
            return null;
        }
        $card = $this->shapeCard($row);
        $card['body'] = (string) $row['body'];
        return $card;
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
            'image'   => $r['image_url'] ?: null,
            'url'     => '/blog/' . $id . '-' . ($r['slug'] ?: 'articol'),
        ];
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
