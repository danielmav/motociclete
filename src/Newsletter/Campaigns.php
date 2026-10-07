<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\Database;
use PDO;
use RuntimeException;

/**
 * Campaniile de newsletter (`nl_campaigns`). O campanie e `draft` cât timp se
 * compune; din momentul în care e pusă la trimis (etapa 3) nu mai poate fi
 * modificată sau ștearsă. Erorile de DB nu sunt înghițite aici.
 */
final class Campaigns
{
    /** Câmpurile pe care le poate scrie formularul de compunere. */
    private const EDITABLE = ['list_key', 'type', 'subject', 'preheader', 'input_json', 'html', 'body_text'];

    public function __construct(private Database $db)
    {
    }

    private function pdo(): PDO
    {
        return $this->db->local();
    }

    public function create(string $list, string $type, string $subject): int
    {
        $this->pdo()->prepare(
            'INSERT INTO nl_campaigns (list_key, type, subject, view_key) VALUES (:l, :t, :s, :k)'
        )->execute([':l' => $list, ':t' => $type, ':s' => $subject, ':k' => bin2hex(random_bytes(8))]);
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $fields doar cheile din EDITABLE sunt scrise
     * @throws RuntimeException dacă campania nu mai e ciornă
     */
    public function update(int $id, array $fields): void
    {
        $row = $this->find($id);
        if ($row === null || $row['status'] !== 'draft') {
            throw new RuntimeException('Campania nu mai poate fi modificată (nu este ciornă).');
        }
        $set = [];
        $params = [':id' => $id];
        foreach (self::EDITABLE as $col) {
            if (array_key_exists($col, $fields)) {
                $set[] = "`{$col}` = :{$col}";
                $params[':' . $col] = $fields[$col];
            }
        }
        if (!$set) {
            return;
        }
        $this->pdo()->prepare(
            'UPDATE nl_campaigns SET ' . implode(', ', $set) . ", updated_at = NOW() WHERE id = :id AND status = 'draft'"
        )->execute($params);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $s = $this->pdo()->prepare('SELECT * FROM nl_campaigns WHERE id = :id');
        $s->execute([':id' => $id]);
        return $s->fetch() ?: null;
    }

    /** Campania pentru pagina publică „vezi în browser" (id + cheie secretă). @return array<string,mixed>|null */
    public function findPublic(int $id, string $key): ?array
    {
        $row = $this->find($id);
        return $row !== null && hash_equals((string) $row['view_key'], $key) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> fără coloanele mari, cele mai noi primele */
    public function all(): array
    {
        return $this->pdo()->query(
            'SELECT id, list_key, type, subject, view_key, status, created_at, updated_at, queued_at, finished_at,
                    (html IS NOT NULL AND html <> \'\') AS has_html
             FROM nl_campaigns ORDER BY id DESC'
        )->fetchAll();
    }

    /** Șterge doar ciornele. */
    public function delete(int $id): bool
    {
        $s = $this->pdo()->prepare("DELETE FROM nl_campaigns WHERE id = :id AND status = 'draft'");
        $s->execute([':id' => $id]);
        return $s->rowCount() === 1;
    }
}
