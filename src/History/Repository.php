<?php

declare(strict_types=1);

namespace App\History;

use App\Database;
use PDO;
use Throwable;

/**
 * "Istoria Dual Motors" timeline (`history_entries` + `history_images`).
 * Public reads (active entries, newest year first) and admin CRUD. Images live
 * in /media/despre. Mirrors App\Event\Repository.
 */
final class Repository
{
    private ?PDO $pdo;
    private const IMG_BASE = '/media/despre/';
    private const COLS = ['year', 'title', 'body_html', 'position', 'is_active'];

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

    // -- Public ---------------------------------------------------------------

    /** Active timeline entries (newest year first) with galleries. */
    public function timeline(): array
    {
        $rows = $this->all(
            "SELECT * FROM history_entries WHERE is_active = 1
             ORDER BY `year` DESC, position, id"
        );
        return array_map(function (array $r) {
            $r['gallery'] = $this->galleryFor((int) $r['id']);
            return $r;
        }, $rows);
    }

    /** @return array<int,string> */
    private function galleryFor(int $entryId): array
    {
        return array_map(
            fn ($r) => self::IMG_BASE . self::encodePath((string) $r['filename']),
            $this->all("SELECT filename FROM history_images WHERE entry_id = :id ORDER BY position, id", [':id' => $entryId])
        );
    }

    /** rawurlencode each path segment so sub-folder images (2016/foo.jpg) survive. */
    private static function encodePath(string $name): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $name)));
    }

    // -- Admin ----------------------------------------------------------------

    public function adminAll(): array
    {
        return $this->all("SELECT * FROM history_entries ORDER BY `year` DESC, position, id");
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM history_entries WHERE id = :id", [':id' => $id]);
    }

    /** Raw image rows for the admin image manager. */
    public function images(int $entryId): array
    {
        return $this->all("SELECT id, filename, position FROM history_images WHERE entry_id = :id ORDER BY position, id", [':id' => $entryId]);
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
            $this->pdo->prepare("UPDATE history_entries SET $set WHERE id = :id")->execute($params);
            return $id;
        }
        $names = implode(', ', array_map(static fn ($c) => "`$c`", self::COLS));
        $ph = implode(', ', array_map(static fn ($c) => ":$c", self::COLS));
        $this->pdo->prepare("INSERT INTO history_entries ($names) VALUES ($ph)")->execute($params);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        try {
            $this->pdo->prepare("DELETE FROM history_images WHERE entry_id = :id")->execute([':id' => $id]);
            $this->pdo->prepare("DELETE FROM history_entries WHERE id = :id")->execute([':id' => $id]);
        } catch (Throwable) {
            // ignore
        }
    }

    /** @param array<int,string> $filenames */
    public function replaceImages(int $entryId, array $filenames): void
    {
        try {
            $this->pdo->prepare("DELETE FROM history_images WHERE entry_id = :id")->execute([':id' => $entryId]);
            $ins = $this->pdo->prepare("INSERT INTO history_images (entry_id, filename, position) VALUES (:e, :f, :p)");
            $pos = 0;
            foreach ($filenames as $f) {
                $f = trim((string) $f);
                if ($f !== '') {
                    $ins->execute([':e' => $entryId, ':f' => $f, ':p' => $pos++]);
                }
            }
        } catch (Throwable) {
            // ignore
        }
    }

    // -- Helpers --------------------------------------------------------------

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
