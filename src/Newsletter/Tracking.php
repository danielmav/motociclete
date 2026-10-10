<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\Database;
use PDO;

/**
 * Numărarea clicurilor: linkurile unei campanii (`nl_links`) și clicurile pe ele
 * (`nl_clicks`). Redirectul public duce doar la adrese înregistrate aici, deci nu
 * poate fi folosit ca redirect deschis.
 */
final class Tracking
{
    public function __construct(private Database $db)
    {
    }

    private function pdo(): PDO
    {
        return $this->db->local();
    }

    /**
     * Înregistrează linkurile din HTML-ul campaniei (o singură dată fiecare).
     * @return array<string,int> URL → id
     */
    public function register(int $campaignId, string $html): array
    {
        $ins = $this->pdo()->prepare(
            'INSERT IGNORE INTO nl_links (campaign_id, url_hash, url, block) VALUES (:c, :h, :u, :b)'
        );
        foreach (Links::hrefs($html) as $url) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $block = isset($query['utm_content']) && is_string($query['utm_content']) ? mb_substr($query['utm_content'], 0, 60) : null;
            $ins->execute([':c' => $campaignId, ':h' => sha1($url), ':u' => $url, ':b' => $block]);
        }
        $s = $this->pdo()->prepare('SELECT id, url FROM nl_links WHERE campaign_id = :c');
        $s->execute([':c' => $campaignId]);
        $map = [];
        foreach ($s->fetchAll() as $row) {
            $map[(string) $row['url']] = (int) $row['id'];
        }
        return $map;
    }

    /** @return array<string,mixed>|null */
    public function link(int $linkId): ?array
    {
        $s = $this->pdo()->prepare('SELECT id, campaign_id, url, block FROM nl_links WHERE id = :id');
        $s->execute([':id' => $linkId]);
        return $s->fetch() ?: null;
    }

    public function click(int $linkId, int $subscriberId): void
    {
        $this->pdo()->prepare('INSERT INTO nl_clicks (link_id, subscriber_id) VALUES (:l, :u)')
            ->execute([':l' => $linkId, ':u' => $subscriberId]);
    }

    /** Câți abonați diferiți au dat cel puțin un clic în campanie. */
    public function uniqueClicks(int $campaignId): int
    {
        $s = $this->pdo()->prepare(
            'SELECT COUNT(DISTINCT k.subscriber_id)
             FROM nl_clicks k JOIN nl_links l ON l.id = k.link_id WHERE l.campaign_id = :c'
        );
        $s->execute([':c' => $campaignId]);
        return (int) $s->fetchColumn();
    }

    /** @return array<int,array<string,mixed>> url, block, clicks (abonați diferiți), total; cele mai accesate primele */
    public function topLinks(int $campaignId, int $limit = 15): array
    {
        $limit = max(1, min(100, $limit));
        $s = $this->pdo()->prepare(
            "SELECT l.url, l.block, COUNT(DISTINCT k.subscriber_id) AS clicks, COUNT(k.id) AS total
             FROM nl_links l JOIN nl_clicks k ON k.link_id = l.id
             WHERE l.campaign_id = :c
             GROUP BY l.id, l.url, l.block
             ORDER BY clicks DESC, total DESC, l.id LIMIT {$limit}"
        );
        $s->execute([':c' => $campaignId]);
        return $s->fetchAll();
    }
}
