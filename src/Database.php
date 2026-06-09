<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

/**
 * PDO connection factory for the two databases the portal talks to:
 *   - local()     : the Dual Motors portal database (read/write)
 *   - bikershop()  : the BikerShop PrestaShop 9 database (READ ONLY)
 *
 * Connections are lazy and memoized. bikershop() returns null when no
 * credentials are configured, so the site degrades gracefully instead of
 * fataling (used by BikerShop\Client).
 */
final class Database
{
    private static ?PDO $local = null;
    private static ?PDO $bikershop = null;
    private static bool $bikershopTried = false;
    private static ?PDO $news = null;
    private static bool $newsTried = false;

    /** @param array<string,mixed> $config the 'db' settings array */
    public function __construct(private array $config) {}

    public function local(): PDO
    {
        if (self::$local instanceof PDO) {
            return self::$local;
        }
        return self::$local = $this->connect($this->config['local']);
    }

    /** Returns a read-only PDO to BikerShop, or null if unavailable. */
    public function bikershop(): ?PDO
    {
        if (self::$bikershopTried) {
            return self::$bikershop;
        }
        self::$bikershopTried = true;

        $cfg = $this->config['bikershop'];
        if (empty($cfg['host']) || empty($cfg['name']) || empty($cfg['user'])) {
            return self::$bikershop = null; // not configured yet
        }

        try {
            self::$bikershop = $this->connect($cfg);
        } catch (PDOException) {
            self::$bikershop = null; // never let BikerShop downtime break the portal
        }
        return self::$bikershop;
    }

    /**
     * Read-only PDO to the legacy `dualmotors_motociclete` DB (reuses DM_* creds),
     * used at runtime for the blog/news section. Null when unavailable.
     */
    public function news(): ?PDO
    {
        if (self::$newsTried) {
            return self::$news;
        }
        self::$newsTried = true;

        $cfg = $this->config['dm'] ?? [];
        if (empty($cfg['host']) || empty($cfg['db_moto']) || empty($cfg['user'])) {
            return self::$news = null;
        }

        try {
            self::$news = $this->connect([
                'host' => $cfg['host'],
                'port' => $cfg['port'] ?? '3306',
                'name' => $cfg['db_moto'],
                'user' => $cfg['user'],
                'pass' => $cfg['pass'] ?? '',
            ]);
        } catch (PDOException) {
            self::$news = null; // blog never breaks the portal
        }
        return self::$news;
    }

    /** @param array<string,mixed> $cfg */
    private function connect(array $cfg): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $cfg['host'],
            $cfg['port'],
            $cfg['name']
        );

        return new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
}
