<?php

declare(strict_types=1);

namespace App\News;

use App\Database;
use PDO;
use Throwable;

/**
 * Reads the blog/news from the legacy `dualmotors_motociclete` DB (`noutati` +
 * `imagini_noutati`). Read-only, graceful degradation (returns []/null) like the
 * other repositories. Images are still served by the legacy site.
 */
final class Repository
{
    private ?PDO $pdo;

    /** Legacy site still hosts the news images. */
    private const IMG_BASE = 'https://www.motociclete.com.ro/images/noutati/';

    private const MONTHS = [1 => 'ian', 'feb', 'mar', 'apr', 'mai', 'iun', 'iul', 'aug', 'sep', 'oct', 'nov', 'dec'];

    public function __construct(Database $db)
    {
        try {
            $this->pdo = $db->news();
        } catch (Throwable) {
            $this->pdo = null;
        }
    }

    public function isAvailable(): bool
    {
        return $this->pdo instanceof PDO;
    }

    /** Latest active articles, shaped as cards. @return array<int,array<string,mixed>> */
    public function latest(int $limit = 3): array
    {
        $rows = $this->all(
            "SELECT n.id_noutate, n.titlu, n.noutate_text, n.noutate_datetime,
                    (SELECT i.imagine FROM imagini_noutati i
                      WHERE i.id_noutate = n.id_noutate
                      ORDER BY i.imagine_principala DESC, i.id_imagine ASC LIMIT 1) AS imagine
             FROM noutati n
             WHERE n.active = 1
             ORDER BY n.noutate_datetime DESC
             LIMIT " . (int) $limit
        );
        return array_map([$this, 'shapeCard'], $rows);
    }

    /** A single article by id (with full HTML body), or null. @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->one(
            "SELECT n.id_noutate, n.titlu, n.noutate_text, n.noutate_datetime,
                    (SELECT i.imagine FROM imagini_noutati i
                      WHERE i.id_noutate = n.id_noutate
                      ORDER BY i.imagine_principala DESC, i.id_imagine ASC LIMIT 1) AS imagine
             FROM noutati n
             WHERE n.active = 1 AND n.id_noutate = :id",
            [':id' => $id]
        );
        if (!$row) {
            return null;
        }
        $card = $this->shapeCard($row);
        $card['body'] = (string) $row['noutate_text'];
        return $card;
    }

    /** @return array<string,mixed> */
    private function shapeCard(array $r): array
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $r['titlu'])));
        $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $r['noutate_text'])));
        $slug  = slugify($title);
        $id    = (int) $r['id_noutate'];

        return [
            'id'      => $id,
            'title'   => $title,
            'excerpt' => mb_strlen($plain) > 160 ? mb_substr($plain, 0, 160) . '…' : $plain,
            'date'    => $this->roDate((int) $r['noutate_datetime']),
            'image'   => $r['imagine'] ? self::IMG_BASE . rawurlencode((string) $r['imagine']) : null,
            'url'     => '/blog/' . $id . '-' . $slug,
        ];
    }

    /** Unix timestamp -> "3 iun 2026". */
    private function roDate(int $ts): string
    {
        if ($ts <= 0) {
            return '';
        }
        return (int) date('j', $ts) . ' ' . (self::MONTHS[(int) date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
    }

    /** @param array<string,mixed> $params @return array<int,array<string,mixed>> */
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

    /** @param array<string,mixed> $params @return array<string,mixed>|null */
    private function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }
}
