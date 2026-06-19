<?php

declare(strict_types=1);

namespace App\Announcement;

use App\Database;
use PDO;
use Throwable;

/**
 * Site-wide pop-up announcements (`announcements`): admin-managed WYSIWYG message
 * shown within an optional start/end datetime window (program sărbători legale etc.).
 *
 * Same discipline as the other repositories: prepared statements, graceful
 * degradation (returns null/[] if the table is missing or the DB is down).
 */
final class Repository
{
    private const COLS = ['title', 'body_html', 'starts_at', 'ends_at', 'is_active', 'position'];

    private ?PDO $pdo;

    public function __construct(Database $db)
    {
        try {
            $this->pdo = $db->local();
        } catch (Throwable) {
            $this->pdo = null;
        }
    }

    /**
     * The announcement to show right now: active and within its (optional) window.
     * Lowest position wins, then most recent. Null when there is nothing to show.
     * @return array<string,mixed>|null
     */
    public function current(): ?array
    {
        if (!$this->pdo instanceof PDO) {
            return null;
        }
        try {
            $stmt = $this->pdo->query(
                'SELECT id, title, body_html, updated_at
                 FROM announcements
                 WHERE is_active = 1
                   AND (starts_at IS NULL OR starts_at <= NOW())
                   AND (ends_at   IS NULL OR ends_at   >= NOW())
                 ORDER BY position ASC, id DESC
                 LIMIT 1'
            );
            return $stmt ? ($stmt->fetch() ?: null) : null;
        } catch (Throwable) {
            return null;
        }
    }

    // -- Admin CRUD -----------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function adminAll(): array
    {
        if (!$this->pdo) {
            return [];
        }
        try {
            return $this->pdo->query('SELECT * FROM announcements ORDER BY position, id DESC')->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if (!$this->pdo) {
            return null;
        }
        try {
            $s = $this->pdo->prepare('SELECT * FROM announcements WHERE id = :id');
            $s->execute([':id' => $id]);
            return $s->fetch() ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Insert (id null) or update; returns the row id. @param array<string,mixed> $d */
    public function save(?int $id, array $d): int
    {
        $params = [];
        foreach (self::COLS as $c) {
            $params[':' . $c] = $d[$c] ?? null;
        }
        if ($id) {
            $set = implode(', ', array_map(static fn ($c) => "$c = :$c", self::COLS));
            $params[':id'] = $id;
            $this->pdo->prepare("UPDATE announcements SET $set WHERE id = :id")->execute($params);
            return $id;
        }
        $names = implode(', ', self::COLS);
        $ph = implode(', ', array_map(static fn ($c) => ":$c", self::COLS));
        $this->pdo->prepare("INSERT INTO announcements ($names) VALUES ($ph)")->execute($params);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        try {
            $this->pdo->prepare('DELETE FROM announcements WHERE id = :id')->execute([':id' => $id]);
        } catch (Throwable) {
            // ignore
        }
    }

    public function nextPosition(): int
    {
        try {
            return ((int) $this->pdo->query('SELECT COALESCE(MAX(position),0) FROM announcements')->fetchColumn()) + 1;
        } catch (Throwable) {
            return 1;
        }
    }
}
