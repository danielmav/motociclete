<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\Database;
use PDO;
use RuntimeException;

/**
 * Coada de trimitere (`nl_sends`) și stările campaniei legate de trimitere
 * (queued → sending → sent, cu pauză). Singurul loc care atinge `nl_sends`.
 *
 * Trimiterea e „cel mult o dată": un rând trece în `sending` înainte de SMTP și nu
 * se mai retrimite dacă procesul moare pe drum. Erorile de DB nu sunt înghițite aici.
 */
final class Sends
{
    public const MAX_ATTEMPTS = 3;
    /** Atâtea eșecuri la rând pun campania în pauză. */
    public const FAIL_STREAK_PAUSE = 20;
    /** Ratele de respingere/reclamații se judecă abia de la atâtea mesaje trimise. */
    public const HEALTH_MIN_SENT = 200;
    public const MAX_BOUNCE_RATE = 0.05;
    public const MAX_COMPLAINT_RATE = 0.003;

    /** Stările unui mesaj care a plecat efectiv (au `sent_at`). */
    private const DELIVERED = ['sent', 'soft_bounced', 'bounced', 'complained'];
    /** Un răspuns al releului nu coboară o stare mai gravă deja înregistrată. */
    private const FEEDBACK_OVER = [
        'soft_bounced' => ['sent'],
        'bounced'      => ['sent', 'soft_bounced'],
        'complained'   => ['sent', 'soft_bounced', 'bounced'],
    ];

    public function __construct(private Database $db)
    {
    }

    private function pdo(): PDO
    {
        return $this->db->local();
    }

    // -- Punerea la trimis ----------------------------------------------------

    /** Câți abonați ar primi acum o campanie pe lista dată. */
    public function recipientCount(string $list): int
    {
        $s = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM nl_subscriptions p JOIN nl_subscribers u ON u.id = p.subscriber_id
             WHERE p.list_key = :l AND p.status = 'active' AND u.status = 'active'"
        );
        $s->execute([':l' => $list]);
        return (int) $s->fetchColumn();
    }

    /**
     * Creează rândurile de trimitere și trece ciorna în `queued`.
     * @return int numărul de destinatari
     * @throws RuntimeException dacă nu e ciornă, nu are mesaj generat sau lista e goală
     */
    public function enqueue(int $campaignId): int
    {
        $s = $this->pdo()->prepare('SELECT list_key, status, html FROM nl_campaigns WHERE id = :id');
        $s->execute([':id' => $campaignId]);
        $c = $s->fetch();
        if (!$c || $c['status'] !== 'draft') {
            throw new RuntimeException('Campania a fost deja pusă la trimis.');
        }
        if (trim((string) ($c['html'] ?? '')) === '') {
            throw new RuntimeException('Campania nu are încă un mesaj generat. Salvează formularul.');
        }
        // Întâi rândurile (cheia unică le face idempotente), apoi starea: cronul nu vede
        // niciodată o campanie `queued` fără destinatari, pe care ar închide-o ca trimisă.
        $ins = $this->pdo()->prepare(
            "INSERT IGNORE INTO nl_sends (campaign_id, subscriber_id)
             SELECT :c, p.subscriber_id
             FROM nl_subscriptions p JOIN nl_subscribers u ON u.id = p.subscriber_id
             WHERE p.list_key = :l AND p.status = 'active' AND u.status = 'active'
             ORDER BY p.subscriber_id"
        );
        $ins->execute([':c' => $campaignId, ':l' => $c['list_key']]);

        $n = $this->pdo()->prepare('SELECT COUNT(*) FROM nl_sends WHERE campaign_id = :c');
        $n->execute([':c' => $campaignId]);
        $count = (int) $n->fetchColumn();
        if ($count === 0) {
            throw new RuntimeException('Lista nu are niciun abonat activ.');
        }
        $u = $this->pdo()->prepare(
            "UPDATE nl_campaigns SET status = 'queued', queued_at = NOW(), pause_reason = NULL, fail_streak = 0
             WHERE id = :id AND status = 'draft'"
        );
        $u->execute([':id' => $campaignId]);
        if ($u->rowCount() !== 1) {
            throw new RuntimeException('Campania a fost deja pusă la trimis.');
        }
        return $count;
    }

    public function pause(int $campaignId, string $reason): bool
    {
        $s = $this->pdo()->prepare(
            "UPDATE nl_campaigns SET status = 'paused', pause_reason = :r
             WHERE id = :id AND status IN ('queued', 'sending')"
        );
        $s->execute([':r' => mb_substr($reason, 0, 255), ':id' => $campaignId]);
        return $s->rowCount() === 1;
    }

    public function resume(int $campaignId): bool
    {
        $s = $this->pdo()->prepare(
            "UPDATE nl_campaigns SET status = 'sending', pause_reason = NULL, fail_streak = 0
             WHERE id = :id AND status = 'paused'"
        );
        $s->execute([':id' => $campaignId]);
        return $s->rowCount() === 1;
    }

    /**
     * Oprește definitiv o campanie începută: ce n-a plecat nu mai pleacă.
     * @return int câte mesaje au fost scoase din coadă
     */
    public function cancel(int $campaignId): int
    {
        $c = $this->pdo()->prepare(
            "UPDATE nl_campaigns SET status = 'sent', finished_at = NOW(), pause_reason = 'Oprită manual.'
             WHERE id = :id AND status IN ('queued', 'sending', 'paused')"
        );
        $c->execute([':id' => $campaignId]);
        if ($c->rowCount() !== 1) {
            return 0;
        }
        $s = $this->pdo()->prepare(
            "UPDATE nl_sends SET status = 'skipped', error = 'Campanie oprită manual.'
             WHERE campaign_id = :id AND status = 'queued'"
        );
        $s->execute([':id' => $campaignId]);
        return $s->rowCount();
    }

    // -- Procesarea cozii -----------------------------------------------------

    /** @return array<int,array<string,mixed>> campaniile de trimis, cele mai vechi primele */
    public function activeCampaigns(): array
    {
        return $this->pdo()->query(
            "SELECT id, list_key, subject, view_key, html, body_text, status
             FROM nl_campaigns WHERE status IN ('queued', 'sending') ORDER BY queued_at, id"
        )->fetchAll();
    }

    /**
     * Următoarele rânduri din coadă, cu starea CURENTĂ a abonatului și a abonamentului
     * (cineva se poate dezabona între punerea la trimis și trimitere).
     * @param array<int,int> $excludeIds rânduri deja încercate în rularea aceasta
     * @return array<int,array<string,mixed>>
     */
    public function batch(int $campaignId, int $limit, array $excludeIds = []): array
    {
        $limit = max(1, $limit);
        $not = '';
        if ($excludeIds) {
            $not = ' AND s.id NOT IN (' . implode(',', array_map('intval', $excludeIds)) . ')';
        }
        $s = $this->pdo()->prepare(
            "SELECT s.id, s.subscriber_id, s.attempts, u.email, u.token, u.status AS sub_status, p.status AS list_status
             FROM nl_sends s
             JOIN nl_campaigns c ON c.id = s.campaign_id
             LEFT JOIN nl_subscribers u ON u.id = s.subscriber_id
             LEFT JOIN nl_subscriptions p ON p.subscriber_id = s.subscriber_id AND p.list_key = c.list_key
             WHERE s.campaign_id = :c AND s.status = 'queued'{$not}
             ORDER BY s.id LIMIT {$limit}"
        );
        $s->execute([':c' => $campaignId]);
        return $s->fetchAll();
    }

    /** Rezervă rândul chiar înainte de trimitere. False = l-a luat altcineva. */
    public function claim(int $sendId): bool
    {
        $s = $this->pdo()->prepare(
            "UPDATE nl_sends SET status = 'sending', attempts = attempts + 1, claimed_at = NOW()
             WHERE id = :id AND status = 'queued'"
        );
        $s->execute([':id' => $sendId]);
        return $s->rowCount() === 1;
    }

    public function started(int $campaignId): void
    {
        $this->pdo()->prepare("UPDATE nl_campaigns SET status = 'sending' WHERE id = :id AND status = 'queued'")
            ->execute([':id' => $campaignId]);
    }

    public function markSent(int $sendId, ?string $messageId): void
    {
        $this->pdo()->prepare(
            "UPDATE nl_sends SET status = 'sent', sent_at = NOW(), message_id = :m, error = NULL
             WHERE id = :id AND status = 'sending'"
        )->execute([':m' => $messageId !== null ? mb_substr($messageId, 0, 190) : null, ':id' => $sendId]);
    }

    /**
     * Trimiterea a eșuat ÎNAINTE ca releul să accepte mesajul: rândul revine în coadă
     * până la MAX_ATTEMPTS încercări, apoi rămâne eșuat.
     * @return string starea nouă: 'queued' sau 'failed'
     */
    public function markRetry(int $sendId, string $error): string
    {
        $this->pdo()->prepare(
            "UPDATE nl_sends
             SET status = IF(attempts >= " . self::MAX_ATTEMPTS . ", 'failed', 'queued'), error = :e
             WHERE id = :id AND status = 'sending'"
        )->execute([':e' => mb_substr($error, 0, 255), ':id' => $sendId]);
        $s = $this->pdo()->prepare('SELECT status FROM nl_sends WHERE id = :id');
        $s->execute([':id' => $sendId]);
        return (string) $s->fetchColumn();
    }

    public function markSkipped(int $sendId, string $reason): void
    {
        $this->pdo()->prepare(
            "UPDATE nl_sends SET status = 'skipped', error = :e WHERE id = :id AND status IN ('queued', 'sending')"
        )->execute([':e' => mb_substr($reason, 0, 255), ':id' => $sendId]);
    }

    /** Ține socoteala eșecurilor consecutive ale campaniei. @return int șirul curent */
    public function failStreak(int $campaignId, bool $failed): int
    {
        $this->pdo()->prepare(
            'UPDATE nl_campaigns SET fail_streak = ' . ($failed ? 'fail_streak + 1' : '0') . ' WHERE id = :id'
        )->execute([':id' => $campaignId]);
        $s = $this->pdo()->prepare('SELECT fail_streak FROM nl_campaigns WHERE id = :id');
        $s->execute([':id' => $campaignId]);
        return (int) $s->fetchColumn();
    }

    /**
     * Rândurile rămase în `sending` de la un proces întrerupt devin eșuate. Nu se
     * retrimit: nu știm dacă mesajul a plecat, iar un duplicat e mai rău decât o lipsă.
     */
    public function releaseStale(int $minutes): int
    {
        $minutes = max(1, $minutes);
        return (int) $this->pdo()->exec(
            "UPDATE nl_sends SET status = 'failed', error = 'Întrerupt în timpul trimiterii; nu a fost retrimis.'
             WHERE status = 'sending' AND claimed_at < (NOW() - INTERVAL {$minutes} MINUTE)"
        );
    }

    /** Închide campania când nu mai are nimic de trimis. */
    public function finishIfDone(int $campaignId): bool
    {
        $s = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM nl_sends WHERE campaign_id = :c AND status IN ('queued', 'sending')"
        );
        $s->execute([':c' => $campaignId]);
        if ((int) $s->fetchColumn() > 0) {
            return false;
        }
        $u = $this->pdo()->prepare(
            "UPDATE nl_campaigns SET status = 'sent', finished_at = NOW()
             WHERE id = :id AND status IN ('queued', 'sending')"
        );
        $u->execute([':id' => $campaignId]);
        return $u->rowCount() === 1;
    }

    /** Câte mesaje au plecat în ultimele 24 de ore, din toate campaniile. */
    public function sentLast24h(): int
    {
        return (int) $this->pdo()->query(
            'SELECT COUNT(*) FROM nl_sends WHERE sent_at > (NOW() - INTERVAL 24 HOUR)'
        )->fetchColumn();
    }

    // -- Sănătate și statistici -----------------------------------------------

    /** Motivul pentru care campania trebuie oprită (rate prea mari) sau null. */
    public function health(int $campaignId): ?string
    {
        $st = $this->stats($campaignId);
        if ($st['sent'] < self::HEALTH_MIN_SENT) {
            return null;
        }
        $bounces = ($st['bounced'] + $st['soft_bounced']) / $st['sent'];
        if ($bounces > self::MAX_BOUNCE_RATE) {
            return sprintf('Rata de respingere a ajuns la %s%% (limita: 5%%).', number_format($bounces * 100, 1, ',', ''));
        }
        $complaints = $st['complained'] / $st['sent'];
        if ($complaints > self::MAX_COMPLAINT_RATE) {
            return sprintf('Rata de reclamații de spam a ajuns la %s%% (limita: 0,3%%).', number_format($complaints * 100, 2, ',', ''));
        }
        return null;
    }

    /**
     * @return array{recipients:int,queued:int,sending:int,sent:int,failed:int,skipped:int,soft_bounced:int,bounced:int,complained:int,unsubscribed:int}
     *         `sent` = toate mesajele plecate, inclusiv cele respinse ulterior
     */
    public function stats(int $campaignId): array
    {
        $out = ['recipients' => 0, 'queued' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0,
                'soft_bounced' => 0, 'bounced' => 0, 'complained' => 0, 'unsubscribed' => 0];
        $s = $this->pdo()->prepare('SELECT status, COUNT(*) n FROM nl_sends WHERE campaign_id = :c GROUP BY status');
        $s->execute([':c' => $campaignId]);
        foreach ($s->fetchAll() as $r) {
            $status = (string) $r['status'];
            $n      = (int) $r['n'];
            $out['recipients'] += $n;
            if (in_array($status, self::DELIVERED, true)) {
                $out['sent'] += $n;
            }
            if ($status !== 'sent') {
                $out[$status] = $n;
            }
        }
        $u = $this->pdo()->prepare(
            'SELECT COUNT(DISTINCT subscriber_id) FROM nl_subscriptions WHERE unsub_campaign_id = :c'
        );
        $u->execute([':c' => $campaignId]);
        $out['unsubscribed'] = (int) $u->fetchColumn();
        return $out;
    }

    // -- Răspunsurile releului ------------------------------------------------

    /**
     * Rândul de trimitere la care se referă o notificare: după ID-ul mesajului, altfel
     * cel mai recent mesaj plecat către abonat.
     * @return array<string,mixed>|null
     */
    public function findForFeedback(?string $messageId, ?int $subscriberId): ?array
    {
        if ($messageId !== null && $messageId !== '') {
            $s = $this->pdo()->prepare('SELECT * FROM nl_sends WHERE message_id = :m ORDER BY id DESC LIMIT 1');
            $s->execute([':m' => $messageId]);
            if ($row = $s->fetch()) {
                return $row;
            }
        }
        if ($subscriberId === null) {
            return null;
        }
        $s = $this->pdo()->prepare(
            'SELECT * FROM nl_sends WHERE subscriber_id = :u AND sent_at IS NOT NULL ORDER BY sent_at DESC, id DESC LIMIT 1'
        );
        $s->execute([':u' => $subscriberId]);
        return $s->fetch() ?: null;
    }

    /** @param string $status soft_bounced, bounced sau complained. @return bool dacă starea s-a schimbat */
    public function markFeedback(int $sendId, string $status): bool
    {
        if (!isset(self::FEEDBACK_OVER[$status])) {
            return false;
        }
        $over = "'" . implode("','", self::FEEDBACK_OVER[$status]) . "'";
        $s = $this->pdo()->prepare("UPDATE nl_sends SET status = :s WHERE id = :id AND status IN ({$over})");
        $s->execute([':s' => $status, ':id' => $sendId]);
        return $s->rowCount() === 1;
    }

    /** @return array<int,string> stările ultimelor $n mesaje plecate către abonat, cel mai nou primul */
    public function lastOutcomes(int $subscriberId, int $n): array
    {
        $n = max(1, $n);
        $s = $this->pdo()->prepare(
            "SELECT status FROM nl_sends WHERE subscriber_id = :u AND sent_at IS NOT NULL
             ORDER BY sent_at DESC, id DESC LIMIT {$n}"
        );
        $s->execute([':u' => $subscriberId]);
        return array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<int,array<string,mixed>> campaniile primite de un abonat, cele mai noi primele */
    public function history(int $subscriberId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $s = $this->pdo()->prepare(
            "SELECT s.campaign_id, s.status, s.sent_at, s.error, c.subject
             FROM nl_sends s JOIN nl_campaigns c ON c.id = s.campaign_id
             WHERE s.subscriber_id = :u ORDER BY s.id DESC LIMIT {$limit}"
        );
        $s->execute([':u' => $subscriberId]);
        return $s->fetchAll();
    }
}
