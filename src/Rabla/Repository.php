<?php

declare(strict_types=1);

namespace App\Rabla;

use App\Database;
use PDO;
use Throwable;

/**
 * Single place that reads the `rabla` config row (id = 1) holding the editable
 * "Programul RABLA" content shown in a modal on eligible product pages. The
 * displayed title (Programul RABLA {an curent}) is generated in the template, so
 * only `page_html` is stored. Degrades gracefully (returns null) if the DB or
 * table is unavailable.
 */
final class Repository
{
    private ?PDO $pdo;
    /** @var array<string,mixed>|null */
    private ?array $cfg = null;
    private bool $loaded = false;

    public function __construct(Database $db)
    {
        try {
            $this->pdo = $db->local();
        } catch (Throwable) {
            $this->pdo = null;
        }
    }

    /** @return array<string,mixed>|null the single rabla config row */
    public function config(): ?array
    {
        if ($this->loaded) {
            return $this->cfg;
        }
        $this->loaded = true;
        if (!$this->pdo) {
            return $this->cfg = null;
        }
        try {
            $row = $this->pdo->query('SELECT * FROM rabla WHERE id = 1')->fetch();
            $this->cfg = $row ?: null;
        } catch (Throwable) {
            $this->cfg = null;
        }
        return $this->cfg;
    }
}
