<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\Database;
use PDO;

/**
 * Singurul loc care citește/scrie tabelele de newsletter (`nl_subscribers`,
 * `nl_subscriptions`). Starea globală (pending/active/bounced/complained) stă pe
 * abonat; dezabonarea stă pe abonament și privește o singură listă.
 *
 * Erorile de DB NU sunt înghițite aici: apelanții (controllere, CLI) le prind.
 */
final class Repository
{
    /** Cheie listă → nume afișat. */
    public const LISTS = ['oferte' => 'BikerShop oferte', 'stiri' => 'Dual Motors știri'];

    public function __construct(private Database $db) {}

    private function pdo(): PDO
    {
        return $this->db->local();
    }

    // -- Abonați --------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $s = $this->pdo()->prepare('SELECT * FROM nl_subscribers WHERE id = :id');
        $s->execute([':id' => $id]);
        return $s->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $s = $this->pdo()->prepare('SELECT * FROM nl_subscribers WHERE email = :e');
        $s->execute([':e' => $email]);
        return $s->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findByToken(string $token): ?array
    {
        $s = $this->pdo()->prepare('SELECT * FROM nl_subscribers WHERE token = :t');
        $s->execute([':t' => $token]);
        return $s->fetch() ?: null;
    }

    /**
     * Rândul existent (NESCHIMBAT) sau unul nou cu starea dată.
     * @return array<string,mixed>
     */
    public function ensureSubscriber(string $email, ?string $name, string $status, ?string $ip = null): array
    {
        $row = $this->findByEmail($email);
        if ($row !== null) {
            return $row;
        }
        $this->pdo()->prepare(
            'INSERT INTO nl_subscribers (email, name, token, status, signup_ip) VALUES (:e, :n, :t, :s, :ip)'
        )->execute([
            ':e' => $email, ':n' => $name, ':t' => bin2hex(random_bytes(16)), ':s' => $status, ':ip' => $ip,
        ]);
        $id = (int) $this->pdo()->lastInsertId();
        if ($status === 'active') {
            $this->pdo()->prepare('UPDATE nl_subscribers SET confirmed_at = NOW() WHERE id = :id')->execute([':id' => $id]);
        }
        return (array) $this->find($id);
    }

    public function activate(int $id): void
    {
        $this->pdo()->prepare(
            "UPDATE nl_subscribers
             SET status = 'active', soft_bounces = 0, confirmed_at = COALESCE(confirmed_at, NOW())
             WHERE id = :id"
        )->execute([':id' => $id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->pdo()->prepare('UPDATE nl_subscribers SET status = :s WHERE id = :id')
            ->execute([':s' => $status, ':id' => $id]);
    }

    // -- Abonamente -----------------------------------------------------------

    /** @return array<string,array<string,mixed>> indexat pe list_key */
    public function subscriptions(int $id): array
    {
        $s = $this->pdo()->prepare(
            'SELECT list_key, status, source, subscribed_at, unsubscribed_at
             FROM nl_subscriptions WHERE subscriber_id = :id'
        );
        $s->execute([':id' => $id]);
        $out = [];
        foreach ($s->fetchAll() as $row) {
            $out[(string) $row['list_key']] = $row;
        }
        return $out;
    }

    /** Inserează doar dacă nu există rând (nu reactivează un dezabonat). */
    public function addSubscription(int $id, string $list, string $source): bool
    {
        $s = $this->pdo()->prepare(
            'INSERT IGNORE INTO nl_subscriptions (subscriber_id, list_key, source) VALUES (:id, :l, :s)'
        );
        $s->execute([':id' => $id, ':l' => $list, ':s' => $source]);
        return $s->rowCount() === 1;
    }

    /**
     * Acțiune explicită a omului: creează abonamentul sau îl reactivează pe unul
     * dezabonat. Un abonament deja activ rămâne neatins: sursa și data lui sunt
     * dovada consimțământului. (`status` se atribuie ULTIMUL: atribuirile se
     * evaluează de la stânga la dreapta și celelalte citesc starea veche.)
     */
    public function setSubscription(int $id, string $list, string $source): void
    {
        $this->pdo()->prepare(
            "INSERT INTO nl_subscriptions (subscriber_id, list_key, status, source)
             VALUES (:id, :l, 'active', :s)
             ON DUPLICATE KEY UPDATE
                 source          = IF(status = 'unsubscribed', VALUES(source), source),
                 subscribed_at   = IF(status = 'unsubscribed', NOW(), subscribed_at),
                 unsubscribed_at = NULL,
                 status          = 'active'"
        )->execute([':id' => $id, ':l' => $list, ':s' => $source]);
    }

    /** Dezabonează un abonament existent și activ. */
    public function unsubscribe(int $id, string $list): bool
    {
        $s = $this->pdo()->prepare(
            "UPDATE nl_subscriptions SET status = 'unsubscribed', unsubscribed_at = NOW()
             WHERE subscriber_id = :id AND list_key = :l AND status = 'active'"
        );
        $s->execute([':id' => $id, ':l' => $list]);
        return $s->rowCount() > 0;
    }

    /** Excludere: creează rândul ca dezabonat sau îl trece pe dezabonat. */
    public function suppress(int $id, string $list, string $source): void
    {
        $this->pdo()->prepare(
            "INSERT INTO nl_subscriptions (subscriber_id, list_key, status, source, unsubscribed_at)
             VALUES (:id, :l, 'unsubscribed', :s, NOW())
             ON DUPLICATE KEY UPDATE status = 'unsubscribed',
                                     unsubscribed_at = COALESCE(unsubscribed_at, NOW())"
        )->execute([':id' => $id, ':l' => $list, ':s' => $source]);
    }

    /** Dezabonează toate abonamentele active ale unui abonat venite dintr-o sursă. */
    public function unsubscribeSource(int $id, string $source): int
    {
        $s = $this->pdo()->prepare(
            "UPDATE nl_subscriptions SET status = 'unsubscribed', unsubscribed_at = NOW()
             WHERE subscriber_id = :id AND source = :s AND status = 'active'"
        );
        $s->execute([':id' => $id, ':s' => $source]);
        return $s->rowCount();
    }

    /** @return array<int,string> adresele cu cel puțin un abonament activ din sursa dată */
    public function activeEmailsBySource(string $source): array
    {
        $s = $this->pdo()->prepare(
            "SELECT DISTINCT u.email
             FROM nl_subscriptions s JOIN nl_subscribers u ON u.id = s.subscriber_id
             WHERE s.source = :s AND s.status = 'active'
             ORDER BY u.email"
        );
        $s->execute([':s' => $source]);
        return array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN));
    }

    // -- Admin ----------------------------------------------------------------

    /**
     * @return array{subscribers:array<string,int>,lists:array<string,array{active:int,unsubscribed:int,by_source:array<string,int>}>}
     */
    public function counts(): array
    {
        $out = [
            'subscribers' => ['pending' => 0, 'active' => 0, 'bounced' => 0, 'complained' => 0],
            'lists'       => [],
        ];
        foreach (array_keys(self::LISTS) as $list) {
            $out['lists'][$list] = ['active' => 0, 'unsubscribed' => 0, 'by_source' => []];
        }
        foreach ($this->pdo()->query('SELECT status, COUNT(*) n FROM nl_subscribers GROUP BY status') as $r) {
            $out['subscribers'][(string) $r['status']] = (int) $r['n'];
        }
        // Doar abonații activi contează ca destinatari.
        $rows = $this->pdo()->query(
            "SELECT s.list_key, s.status, s.source, COUNT(*) n
             FROM nl_subscriptions s JOIN nl_subscribers u ON u.id = s.subscriber_id
             WHERE u.status = 'active'
             GROUP BY s.list_key, s.status, s.source"
        );
        foreach ($rows as $r) {
            $list = (string) $r['list_key'];
            $n    = (int) $r['n'];
            $out['lists'][$list][(string) $r['status']] += $n;
            if ($r['status'] === 'active') {
                $src = (string) $r['source'];
                $out['lists'][$list]['by_source'][$src] = ($out['lists'][$list]['by_source'][$src] ?? 0) + $n;
            }
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> abonați + cheia `subs` (abonamentele lor) */
    public function search(string $q, int $limit = 50): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $s = $this->pdo()->prepare(
            "SELECT * FROM nl_subscribers WHERE email LIKE :q ORDER BY email LIMIT {$limit}"
        );
        $s->execute([':q' => '%' . addcslashes($q, '%_\\') . '%']);
        $rows = $s->fetchAll();
        foreach ($rows as &$row) {
            $row['subs'] = $this->subscriptions((int) $row['id']);
        }
        unset($row);
        return $rows;
    }

    // -- Limite la abonarea publică ------------------------------------------

    public function recentSignupsFromIp(string $ip, int $minutes): int
    {
        $minutes = max(1, $minutes);
        $s = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM nl_subscribers
             WHERE signup_ip = :ip AND created_at > (NOW() - INTERVAL {$minutes} MINUTE)"
        );
        $s->execute([':ip' => $ip]);
        return (int) $s->fetchColumn();
    }

    /**
     * Rezervă ATOMIC trimiterea unui email de confirmare: true doar pentru o singură
     * cerere la $minutes minute per adresă, oricâte ar veni în paralel.
     */
    public function claimConfirmSend(int $id, int $minutes): bool
    {
        $minutes = max(1, $minutes);
        $s = $this->pdo()->prepare(
            "UPDATE nl_subscribers SET confirm_sent_at = NOW()
             WHERE id = :id
               AND (confirm_sent_at IS NULL OR confirm_sent_at < (NOW() - INTERVAL {$minutes} MINUTE))"
        );
        $s->execute([':id' => $id]);
        return $s->rowCount() === 1;
    }

    /** Există o cerere de confirmare neconsumată, mai nouă de $days zile? */
    public function confirmPending(int $id, int $days): bool
    {
        $days = max(1, $days);
        $s = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM nl_subscribers
             WHERE id = :id AND confirm_sent_at > (NOW() - INTERVAL {$days} DAY)"
        );
        $s->execute([':id' => $id]);
        return (int) $s->fetchColumn() > 0;
    }

    /** Consumă cererea de confirmare: linkul nu mai poate fi refolosit. */
    public function clearConfirm(int $id): void
    {
        $this->pdo()->prepare('UPDATE nl_subscribers SET confirm_sent_at = NULL WHERE id = :id')
            ->execute([':id' => $id]);
    }

    /** Câte emailuri de confirmare au plecat, pe tot situl, în ultimele $minutes minute. */
    public function confirmsSentSince(int $minutes): int
    {
        $minutes = max(1, $minutes);
        return (int) $this->pdo()->query(
            "SELECT COUNT(*) FROM nl_subscribers WHERE confirm_sent_at > (NOW() - INTERVAL {$minutes} MINUTE)"
        )->fetchColumn();
    }
}
