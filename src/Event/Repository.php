<?php

declare(strict_types=1);

namespace App\Event;

use App\Database;
use PDO;
use Throwable;

/**
 * Events (`events` + `event_images`). Public reads (list + single) and admin
 * CRUD. Images live in /media/evenimente. Graceful degradation like the other
 * repositories.
 */
final class Repository
{
    private ?PDO $pdo;
    private const IMG_BASE = '/media/evenimente/';
    private const COLS = ['title', 'slug', 'excerpt', 'body_html', 'location', 'starts_at', 'ends_at', 'cover_image', 'is_active', 'position'];

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

    // -- Public --------------------------------------------------------------

    /** Active events, newest start first, shaped as cards. */
    public function published(int $limit = 50): array
    {
        $rows = $this->all(
            "SELECT id, title, slug, excerpt, location, starts_at, ends_at, cover_image
             FROM events WHERE is_active = 1
             ORDER BY COALESCE(starts_at, created_at) DESC, id DESC
             LIMIT " . (int) $limit
        );
        return array_map([$this, 'shapeCard'], $rows);
    }

    /** A single active event by slug (with gallery), or null. */
    public function bySlug(string $slug): ?array
    {
        $row = $this->one("SELECT * FROM events WHERE slug = :s AND is_active = 1", [':s' => $slug]);
        if (!$row) {
            return null;
        }
        $card = $this->shapeCard($row);
        $card['body_html'] = (string) $row['body_html'];
        $card['gallery'] = array_map(
            fn ($r) => self::IMG_BASE . rawurlencode((string) $r['filename']),
            $this->all("SELECT filename FROM event_images WHERE event_id = :id ORDER BY position, id", [':id' => (int) $row['id']])
        );
        return $card;
    }

    private function shapeCard(array $r): array
    {
        return [
            'id'       => (int) $r['id'],
            'title'    => (string) $r['title'],
            'slug'     => (string) $r['slug'],
            'excerpt'  => (string) ($r['excerpt'] ?? ''),
            'location' => (string) ($r['location'] ?? ''),
            'starts_at' => $r['starts_at'] ?? null,
            'ends_at'  => $r['ends_at'] ?? null,
            'image'    => !empty($r['cover_image']) ? self::IMG_BASE . rawurlencode((string) $r['cover_image']) : null,
            'url'      => '/evenimente/' . ($r['slug'] ?: $r['id']),
        ];
    }

    // -- Admin ---------------------------------------------------------------

    public function adminAll(): array
    {
        return $this->all("SELECT * FROM events ORDER BY COALESCE(starts_at, created_at) DESC, id DESC");
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM events WHERE id = :id", [':id' => $id]);
    }

    public function images(int $eventId): array
    {
        return $this->all("SELECT id, filename, position FROM event_images WHERE event_id = :id ORDER BY position, id", [':id' => $eventId]);
    }

    /** @param array<string,mixed> $d */
    public function save(?int $id, array $d): int
    {
        $params = [];
        foreach (self::COLS as $c) {
            $params[':' . $c] = $d[$c] ?? null;
        }
        if ($id) {
            $set = implode(', ', array_map(static fn ($c) => "`$c` = :$c", self::COLS));
            $params[':id'] = $id;
            $this->pdo->prepare("UPDATE events SET $set WHERE id = :id")->execute($params);
            return $id;
        }
        $names = implode(', ', array_map(static fn ($c) => "`$c`", self::COLS));
        $ph = implode(', ', array_map(static fn ($c) => ":$c", self::COLS));
        $this->pdo->prepare("INSERT INTO events ($names) VALUES ($ph)")->execute($params);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        try {
            $this->pdo->prepare("DELETE FROM events WHERE id = :id")->execute([':id' => $id]);
        } catch (Throwable) {
            // cascade removes images
        }
    }

    public function replaceImages(int $eventId, array $filenames): void
    {
        try {
            $this->pdo->prepare("DELETE FROM event_images WHERE event_id = :id")->execute([':id' => $eventId]);
            $ins = $this->pdo->prepare("INSERT INTO event_images (event_id, filename, position) VALUES (:e, :f, :p)");
            $pos = 0;
            foreach ($filenames as $f) {
                $f = trim((string) $f);
                if ($f === '') {
                    continue;
                }
                $ins->execute([':e' => $eventId, ':f' => $f, ':p' => $pos++]);
            }
        } catch (Throwable) {
            // ignore
        }
    }

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

    private function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }
}
