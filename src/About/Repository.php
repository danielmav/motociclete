<?php

declare(strict_types=1);

namespace App\About;

use App\Database;
use PDO;
use Throwable;

/**
 * "Despre noi" content: the showroom gallery (`about_images`) and the team
 * cards (`team_members`). The intro heading + rich text live as `settings`
 * keys (read via Support\Settings) so they share the admin Settings store.
 * Images served from /media/despre. Graceful degradation like the other repos.
 */
final class Repository
{
    private ?PDO $pdo;
    public const IMG_BASE = '/media/despre/';
    private const TEAM_COLS = ['name', 'role', 'phone', 'email', 'photo', 'position', 'is_active'];

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

    /** Showroom gallery image URLs, ordered. @return array<int,string> */
    public function galleryUrls(): array
    {
        return array_map(
            fn ($r) => self::IMG_BASE . rawurlencode((string) $r['filename']),
            $this->all("SELECT filename FROM about_images ORDER BY position, id")
        );
    }

    /** Active team members, shaped for the public cards. */
    public function activeTeam(): array
    {
        return array_map([$this, 'shapeMember'], $this->all(
            "SELECT * FROM team_members WHERE is_active = 1 ORDER BY position, id"
        ));
    }

    private function shapeMember(array $r): array
    {
        return [
            'id'    => (int) $r['id'],
            'name'  => (string) $r['name'],
            'role'  => (string) ($r['role'] ?? ''),
            'phone' => (string) ($r['phone'] ?? ''),
            'email' => (string) ($r['email'] ?? ''),
            'photo' => !empty($r['photo']) ? self::IMG_BASE . rawurlencode((string) $r['photo']) : null,
        ];
    }

    // -- Admin: gallery -------------------------------------------------------

    /** Raw gallery rows for the admin image manager. */
    public function galleryImages(): array
    {
        return $this->all("SELECT id, filename, position FROM about_images ORDER BY position, id");
    }

    /** @param array<int,string> $filenames */
    public function replaceGallery(array $filenames): void
    {
        try {
            $this->pdo->exec("DELETE FROM about_images");
            $ins = $this->pdo->prepare("INSERT INTO about_images (filename, position) VALUES (:f, :p)");
            $pos = 0;
            foreach ($filenames as $f) {
                $f = trim((string) $f);
                if ($f !== '') {
                    $ins->execute([':f' => $f, ':p' => $pos++]);
                }
            }
        } catch (Throwable) {
            // ignore
        }
    }

    // -- Admin: team ----------------------------------------------------------

    public function allTeam(): array
    {
        return $this->all("SELECT * FROM team_members ORDER BY position, id");
    }

    public function findMember(int $id): ?array
    {
        return $this->one("SELECT * FROM team_members WHERE id = :id", [':id' => $id]);
    }

    /** @param array<string,mixed> $d */
    public function saveMember(?int $id, array $d): int
    {
        $params = [];
        foreach (self::TEAM_COLS as $c) {
            $params[':' . $c] = $d[$c] ?? null;
        }
        if ($id) {
            $set = implode(', ', array_map(static fn ($c) => "`$c` = :$c", self::TEAM_COLS));
            $params[':id'] = $id;
            $this->pdo->prepare("UPDATE team_members SET $set WHERE id = :id")->execute($params);
            return $id;
        }
        $names = implode(', ', array_map(static fn ($c) => "`$c`", self::TEAM_COLS));
        $ph = implode(', ', array_map(static fn ($c) => ":$c", self::TEAM_COLS));
        $this->pdo->prepare("INSERT INTO team_members ($names) VALUES ($ph)")->execute($params);
        return (int) $this->pdo->lastInsertId();
    }

    public function deleteMember(int $id): void
    {
        try {
            $this->pdo->prepare("DELETE FROM team_members WHERE id = :id")->execute([':id' => $id]);
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
