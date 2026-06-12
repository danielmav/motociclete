<?php

declare(strict_types=1);

namespace App\Finance;

use App\Database;
use PDO;
use Throwable;

/**
 * Single place that reads the `finance` config row and computes the UniCredit
 * monthly-rate table for a given RON price. Degrades gracefully (returns null
 * config / empty rates) if the DB or table is unavailable.
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

    public function isAvailable(): bool
    {
        return $this->config() !== null;
    }

    /** @return array<string,mixed>|null the single finance config row */
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
            $row = $this->pdo->query('SELECT * FROM finance WHERE id = 1')->fetch();
            $this->cfg = $row ?: null;
        } catch (Throwable) {
            $this->cfg = null;
        }
        return $this->cfg;
    }

    /** @return int[] available loan terms in months, e.g. [12,18,24,36,48,60] */
    public function terms(): array
    {
        $cfg = $this->config();
        $raw = $cfg ? (string) $cfg['terms'] : '12,18,24,36,48,60';
        $terms = array_values(array_filter(array_map('intval', explode(',', $raw)), fn ($n) => $n > 0));
        return $terms ?: [12, 18, 24, 36, 48, 60];
    }

    /**
     * Monthly instalment for each term, for a RON (VAT-inclusive) price.
     * @return array<int,float> term(months) => monthly rate (2 decimals)
     */
    public function ratesFor(float $priceRon): array
    {
        $cfg = $this->config();
        $rate = $cfg ? ((float) $cfg['calc_rate']) / 100 : 0.145;
        $out = [];
        foreach ($this->terms() as $n) {
            $out[$n] = round(credit_annuity($priceRon, $rate, $n), 2);
        }
        return $out;
    }
}
