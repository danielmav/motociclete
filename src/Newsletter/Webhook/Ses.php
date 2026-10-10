<?php

declare(strict_types=1);

namespace App\Newsletter\Webhook;

/**
 * Amazon SES, cu notificările livrate prin Amazon SNS (abonament HTTPS).
 *
 * SNS trimite un plic JSON: `Type` = SubscriptionConfirmation (o singură dată, la
 * crearea abonamentului) sau Notification, cu notificarea SES ca text JSON în
 * `Message`. Notificarea SES are `notificationType` (notificări clasice) sau
 * `eventType` (event publishing): Bounce, Complaint, Delivery.
 */
final class Ses implements Provider
{
    public function name(): string
    {
        return 'ses';
    }

    public function parse(string $body): array
    {
        $out = ['confirm_url' => null, 'events' => []];
        $envelope = json_decode($body, true);
        if (!is_array($envelope)) {
            return $out;
        }
        $type = (string) ($envelope['Type'] ?? '');
        if ($type === 'SubscriptionConfirmation') {
            $url = (string) ($envelope['SubscribeURL'] ?? '');
            // Vizităm doar adrese SNS: altfel oricine știe secretul ne-ar putea trimite oriunde.
            if (preg_match('~^https://sns\.[a-z0-9-]+\.amazonaws\.com(/|\?)~', $url)) {
                $out['confirm_url'] = $url;
            }
            return $out;
        }
        // „Raw message delivery": notificarea SES vine direct, fără plic.
        $message = $type === 'Notification' ? json_decode((string) ($envelope['Message'] ?? ''), true) : $envelope;
        if (!is_array($message)) {
            return $out;
        }
        $kind = (string) ($message['notificationType'] ?? $message['eventType'] ?? '');
        $id   = isset($message['mail']['messageId']) ? (string) $message['mail']['messageId'] : null;

        if ($kind === 'Bounce') {
            $b = (array) ($message['bounce'] ?? []);
            $eventType = ($b['bounceType'] ?? '') === 'Permanent' ? 'bounce_hard' : 'bounce_soft';
            foreach ((array) ($b['bouncedRecipients'] ?? []) as $r) {
                $detail = trim(($b['bounceType'] ?? '') . '/' . ($b['bounceSubType'] ?? '') . ' ' . ($r['diagnosticCode'] ?? ''));
                $this->add($out, $eventType, $r['emailAddress'] ?? '', $id, $detail);
            }
        } elseif ($kind === 'Complaint') {
            $c = (array) ($message['complaint'] ?? []);
            foreach ((array) ($c['complainedRecipients'] ?? []) as $r) {
                $this->add($out, 'complaint', $r['emailAddress'] ?? '', $id, (string) ($c['complaintFeedbackType'] ?? ''));
            }
        } elseif ($kind === 'Delivery') {
            foreach ((array) ($message['delivery']['recipients'] ?? []) as $email) {
                $this->add($out, 'delivery', $email, $id, '');
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $out */
    private function add(array &$out, string $type, mixed $email, ?string $messageId, string $detail): void
    {
        // Adresa poate veni și ca „Nume <adresa@domeniu>".
        $email = is_string($email) ? $email : '';
        if (preg_match('/<([^<>]+)>\s*$/', $email, $m)) {
            $email = $m[1];
        }
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) {
            return;
        }
        $out['events'][] = ['type' => $type, 'email' => $email, 'message_id' => $messageId, 'detail' => mb_substr($detail, 0, 255)];
    }
}
