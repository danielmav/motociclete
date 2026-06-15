<?php

declare(strict_types=1);

namespace App\Service;

use App\Database;
use PDO;
use Throwable;

/**
 * Service page data: the structured price list (`service_prices`, grouped) and
 * the anonymous public appointment submissions (`service_bookings`). The page
 * description/heading/note live as `settings` keys (Support\Settings).
 */
final class Repository
{
    private ?PDO $pdo;
    private const BOOKING_COLS = ['name', 'email', 'phone', 'marca', 'model', 'an_fabricatie', 'sasiu', 'kilometri', 'lucrari', 'ip'];

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

    // -- Prices ---------------------------------------------------------------

    /** Flat ordered rows. */
    public function prices(): array
    {
        return $this->all("SELECT id, group_label, label, price, position FROM service_prices ORDER BY position, id");
    }

    /**
     * Prices grouped by group_label, preserving first-seen group order.
     * @return array<int,array{label:string,rows:array<int,array{label:string,price:string}>}>
     */
    public function groupedPrices(): array
    {
        $groups = [];
        foreach ($this->prices() as $r) {
            $g = (string) $r['group_label'];
            if (!isset($groups[$g])) {
                $groups[$g] = ['label' => $g, 'rows' => []];
            }
            $groups[$g]['rows'][] = ['label' => (string) $r['label'], 'price' => (string) ($r['price'] ?? '')];
        }
        return array_values($groups);
    }

    /**
     * Replace the whole price list from the structured admin editor.
     * @param array<int,array{group_label:string,label:string,price:string}> $rows
     */
    public function replacePrices(array $rows): void
    {
        try {
            $this->pdo->exec("DELETE FROM service_prices");
            $ins = $this->pdo->prepare("INSERT INTO service_prices (group_label, label, price, position) VALUES (:g, :l, :p, :pos)");
            $pos = 0;
            foreach ($rows as $r) {
                $group = trim((string) ($r['group_label'] ?? ''));
                $label = trim((string) ($r['label'] ?? ''));
                if ($group === '' || $label === '') {
                    continue;
                }
                $ins->execute([
                    ':g' => $group,
                    ':l' => $label,
                    ':p' => trim((string) ($r['price'] ?? '')),
                    ':pos' => $pos++,
                ]);
            }
        } catch (Throwable) {
            // ignore
        }
    }

    // -- Bookings -------------------------------------------------------------

    /** @param array<string,mixed> $d */
    public function createBooking(array $d): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        $params = [];
        foreach (self::BOOKING_COLS as $c) {
            $params[':' . $c] = $d[$c] ?? null;
        }
        $names = implode(', ', array_map(static fn ($c) => "`$c`", self::BOOKING_COLS));
        $ph = implode(', ', array_map(static fn ($c) => ":$c", self::BOOKING_COLS));
        $this->pdo->prepare("INSERT INTO service_bookings ($names) VALUES ($ph)")->execute($params);
        return (int) $this->pdo->lastInsertId();
    }

    public function bookings(int $limit = 300): array
    {
        return $this->all("SELECT * FROM service_bookings ORDER BY created_at DESC LIMIT " . (int) $limit);
    }

    public function newBookingCount(): int
    {
        try {
            return (int) $this->pdo->query("SELECT COUNT(*) FROM service_bookings WHERE status = 'nou'")->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    public function setBookingStatus(int $id, string $status): void
    {
        if (!in_array($status, ['nou', 'confirmat', 'inchis'], true)) {
            return;
        }
        try {
            $this->pdo->prepare("UPDATE service_bookings SET status = :s, is_read = 1 WHERE id = :id")
                ->execute([':s' => $status, ':id' => $id]);
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
}
