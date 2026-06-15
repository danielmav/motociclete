<?php

declare(strict_types=1);

namespace App\Content;

use App\Database;
use PDO;
use Throwable;

/**
 * Static pages (`pages`) + contact departments (`contact_departments`). Used by
 * the admin Settings module and the public PageController / footer.
 */
final class Repository
{
    private ?PDO $pdo;

    public function __construct(Database $db)
    {
        try {
            $this->pdo = $db->local();
        } catch (Throwable) {
            $this->pdo = null;
        }
    }

    // -- Pages ----------------------------------------------------------------

    /** Active page by slug (public). @return array<string,mixed>|null */
    public function pageBySlug(string $slug): ?array
    {
        return $this->one("SELECT * FROM pages WHERE slug = :s AND is_active = 1", [':s' => $slug]);
    }

    public function pages(): array
    {
        return $this->all("SELECT id, slug, title, is_active, updated_at FROM pages ORDER BY title");
    }

    /** Active pages for footer links. @return array<int,array{slug:string,title:string}> */
    public function activePages(): array
    {
        return $this->all("SELECT slug, title FROM pages WHERE is_active = 1 ORDER BY title");
    }

    public function pageById(int $id): ?array
    {
        return $this->one("SELECT * FROM pages WHERE id = :id", [':id' => $id]);
    }

    public function savePage(?int $id, string $slug, string $title, string $html, int $active): int
    {
        if ($id) {
            $this->pdo->prepare("UPDATE pages SET slug = :s, title = :t, body_html = :h, is_active = :a WHERE id = :id")
                ->execute([':s' => $slug, ':t' => $title, ':h' => $html, ':a' => $active, ':id' => $id]);
            return $id;
        }
        $this->pdo->prepare("INSERT INTO pages (slug, title, body_html, is_active) VALUES (:s, :t, :h, :a)")
            ->execute([':s' => $slug, ':t' => $title, ':h' => $html, ':a' => $active]);
        return (int) $this->pdo->lastInsertId();
    }

    public function deletePage(int $id): void
    {
        try {
            $this->pdo->prepare("DELETE FROM pages WHERE id = :id")->execute([':id' => $id]);
        } catch (Throwable) {
            // ignore
        }
    }

    // -- Departments ----------------------------------------------------------

    public function departments(): array
    {
        return $this->all("SELECT * FROM contact_departments ORDER BY position, id");
    }

    public function saveDepartment(?int $id, string $label, string $email, string $phone, int $position): int
    {
        if ($id) {
            $this->pdo->prepare("UPDATE contact_departments SET label = :l, email = :e, phone = :p, position = :pos WHERE id = :id")
                ->execute([':l' => $label, ':e' => $email, ':p' => $phone, ':pos' => $position, ':id' => $id]);
            return $id;
        }
        $this->pdo->prepare("INSERT INTO contact_departments (label, email, phone, position) VALUES (:l, :e, :p, :pos)")
            ->execute([':l' => $label, ':e' => $email, ':p' => $phone, ':pos' => $position]);
        return (int) $this->pdo->lastInsertId();
    }

    public function deleteDepartment(int $id): void
    {
        try {
            $this->pdo->prepare("DELETE FROM contact_departments WHERE id = :id")->execute([':id' => $id]);
        } catch (Throwable) {
            // ignore
        }
    }

    private function all(string $sql, array $params = []): array
    {
        if (!$this->pdo instanceof PDO) {
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
