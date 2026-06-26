<?php

declare(strict_types=1);

namespace App\Support;

use App\Database;
use PDO;
use Throwable;

/**
 * Tiny key/value settings store backed by the local `settings` table.
 * Used for admin-managed values like the EUR->RON exchange rate and VAT.
 * Reads are memoized for the request; writes update the table + cache.
 */
final class Settings
{
    private ?PDO $pdo;
    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(Database $db)
    {
        try {
            $this->pdo = $db->local();
        } catch (Throwable) {
            $this->pdo = null;
        }
    }

    /** @return array<string,string> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        if (!$this->pdo) {
            return $this->cache = [];
        }
        try {
            $this->cache = $this->pdo->query("SELECT skey, svalue FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable) {
            $this->cache = [];
        }
        return $this->cache;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->all()[$key] ?? $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $v = $this->get($key);
        return $v === null ? $default : (float) $v;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->get($key);
        return $v === null ? $default : (int) $v;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->get($key);
        return $v === null ? $default : in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public function set(string $key, string $value): bool
    {
        if (!$this->pdo) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO settings (skey, svalue) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)"
            );
            $stmt->execute([':k' => $key, ':v' => $value]);
            if ($this->cache !== null) {
                $this->cache[$key] = $value;
            }
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Currency config for price display. `rate` = fallback; per-brand rates are
     * auto-updated from BNR (Yamaha) / BRD (CFMOTO) by database/update_currency.php.
     * @return array{rate:float,rate_yamaha:float,rate_cfmoto:float,vat:int,incl:bool}
     */
    public function currency(): array
    {
        $base = $this->float('eur_ron_rate', 5.0);
        return [
            'rate'        => $base,
            'rate_yamaha' => $this->float('eur_ron_rate_yamaha', $base),
            'rate_cfmoto' => $this->float('eur_ron_rate_cfmoto', $base),
            'vat'         => $this->int('vat_pct', 21),
            'incl'        => $this->bool('price_includes_vat', false),
        ];
    }

    /** Pick the display rate for a brand (falls back to the generic `eur_ron_rate`). */
    public static function rateForBrand(array $cur, ?string $brand): float
    {
        if ($brand === 'yamaha' && !empty($cur['rate_yamaha'])) {
            return (float) $cur['rate_yamaha'];
        }
        if ($brand === 'cfmoto' && !empty($cur['rate_cfmoto'])) {
            return (float) $cur['rate_cfmoto'];
        }
        return (float) ($cur['rate'] ?? 5.0);
    }
}
