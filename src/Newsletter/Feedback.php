<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\Database;

/**
 * Aplică răspunsurile releului (respingeri, reclamații de spam) pe abonat și pe
 * rândul de trimitere, și ține jurnalul brut al notificărilor (`nl_events`).
 *
 * - respingere definitivă → abonatul devine `bounced` (exclus din toate listele);
 * - reclamație de spam → `complained`;
 * - respingere temporară → se notează pe mesaj; la SOFT_LIMIT campanii consecutive
 *   respinse temporar, abonatul devine `bounced`.
 */
final class Feedback
{
    public const SOFT_LIMIT = 3;
    private const PAYLOAD_MAX = 20000;

    public function __construct(private Database $db, private Repository $repo, private Sends $sends)
    {
    }

    public function log(string $provider, string $type, ?string $email, ?string $messageId, ?string $payload): void
    {
        $this->db->local()->prepare(
            'INSERT INTO nl_events (provider, type, email, message_id, payload) VALUES (:p, :t, :e, :m, :b)'
        )->execute([
            ':p' => mb_substr($provider, 0, 20),
            ':t' => mb_substr($type, 0, 30),
            ':e' => $email !== null ? mb_substr($email, 0, 190) : null,
            ':m' => $messageId !== null ? mb_substr($messageId, 0, 190) : null,
            ':b' => $payload !== null ? mb_substr($payload, 0, self::PAYLOAD_MAX) : null,
        ]);
    }

    /**
     * @param array{type:string,email:string,message_id:?string,detail?:string} $event
     * @return string ce s-a întâmplat: bounced, complained, soft, soft_limit, delivery, unknown, ignored
     */
    public function apply(array $event): string
    {
        $type = (string) $event['type'];
        if ($type === 'delivery') {
            return 'delivery';
        }
        if (!in_array($type, ['bounce_hard', 'bounce_soft', 'complaint'], true)) {
            return 'ignored';
        }
        // ID-ul mesajului e reperul sigur (notificarea poate purta altă formă a adresei);
        // fără el, luăm ultimul mesaj plecat către adresa din notificare.
        $send = $this->sends->findForFeedback($event['message_id'] ?? null, null);
        if ($send !== null) {
            $sub = $this->repo->find((int) $send['subscriber_id']);
        } else {
            $sub  = $this->repo->findByEmail(strtolower(trim((string) $event['email'])));
            $send = $sub !== null ? $this->sends->findForFeedback(null, (int) $sub['id']) : null;
        }
        if ($sub === null) {
            return 'unknown';
        }
        $id = (int) $sub['id'];

        if ($type === 'complaint') {
            $this->repo->setStatus($id, 'complained');
            $result = 'complained';
            $mark   = 'complained';
        } elseif ($type === 'bounce_hard') {
            if ($sub['status'] !== 'complained') {
                $this->repo->setStatus($id, 'bounced');
            }
            $result = 'bounced';
            $mark   = 'bounced';
        } else {
            $result = 'soft';
            $mark   = 'soft_bounced';
        }

        if ($send !== null) {
            $changed = $this->sends->markFeedback((int) $send['id'], $mark);
            if ($type === 'bounce_soft' && $changed) {
                $this->repo->addSoftBounce($id);
                $last = $this->sends->lastOutcomes($id, self::SOFT_LIMIT);
                if (count($last) === self::SOFT_LIMIT && array_unique($last) === ['soft_bounced']
                    && !in_array($sub['status'], ['bounced', 'complained'], true)) {
                    $this->repo->setStatus($id, 'bounced');
                    $result = 'soft_limit';
                }
            }
            if ($reason = $this->sends->health((int) $send['campaign_id'])) {
                $this->sends->pause((int) $send['campaign_id'], $reason);
            }
        }
        return $result;
    }
}
