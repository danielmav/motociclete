<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterWebhookTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Campaigns;
use App\Newsletter\Feedback;
use App\Newsletter\Repository;
use App\Newsletter\Sends;
use App\Newsletter\Webhook\Ses;

$ses = new Ses();
/** Plicul SNS în jurul unei notificări SES. */
$sns = static fn (array $message): string => (string) json_encode([
    'Type' => 'Notification', 'MessageId' => 'sns-1', 'TopicArn' => 'arn:aws:sns:eu-central-1:1:nl',
    'Message' => json_encode($message),
]);
$bounce = static fn (string $type, array $emails, string $key = 'notificationType'): array => [
    $key => 'Bounce',
    'bounce' => ['bounceType' => $type, 'bounceSubType' => 'General',
        'bouncedRecipients' => array_map(static fn (string $e): array => ['emailAddress' => $e, 'diagnosticCode' => 'smtp; 550 5.1.1 user unknown'], $emails)],
    'mail' => ['messageId' => 'ses-123'],
];

// --- Ses::parse ---------------------------------------------------------------
$r = $ses->parse($sns($bounce('Permanent', ['Ion@Exemplu.ro'])));
check('respingere definitivă', $r['confirm_url'] === null && count($r['events']) === 1
    && $r['events'][0]['type'] === 'bounce_hard' && $r['events'][0]['email'] === 'ion@exemplu.ro'
    && $r['events'][0]['message_id'] === 'ses-123' && str_contains($r['events'][0]['detail'], '550'));
check('respingere temporară', $ses->parse($sns($bounce('Transient', ['a@b.ro'])))['events'][0]['type'] === 'bounce_soft');
check('respingere nedeterminată: tratată ca temporară', $ses->parse($sns($bounce('Undetermined', ['a@b.ro'])))['events'][0]['type'] === 'bounce_soft');
check('mai mulți destinatari într-o notificare', count($ses->parse($sns($bounce('Permanent', ['a@b.ro', 'c@d.ro'])))['events']) === 2);
check('formatul „event publishing" (eventType)', $ses->parse($sns($bounce('Permanent', ['a@b.ro'], 'eventType')))['events'][0]['type'] === 'bounce_hard');
check('adresă cu nume: „Ion <ion@b.ro>"', $ses->parse($sns($bounce('Permanent', ['Ion Pop <ion@b.ro>'])))['events'][0]['email'] === 'ion@b.ro');

$complaint = ['notificationType' => 'Complaint', 'mail' => ['messageId' => 'ses-9'],
    'complaint' => ['complaintFeedbackType' => 'abuse', 'complainedRecipients' => [['emailAddress' => 'x@y.ro']]]];
$r = $ses->parse($sns($complaint));
check('reclamație de spam', $r['events'][0]['type'] === 'complaint' && $r['events'][0]['email'] === 'x@y.ro' && $r['events'][0]['detail'] === 'abuse');
$delivery = ['notificationType' => 'Delivery', 'mail' => ['messageId' => 'ses-9'], 'delivery' => ['recipients' => ['x@y.ro']]];
check('livrare', $ses->parse($sns($delivery))['events'][0]['type'] === 'delivery');
check('notificare fără plic SNS (raw delivery)', $ses->parse((string) json_encode($complaint))['events'][0]['type'] === 'complaint');

$confirm = static fn (string $url): string => (string) json_encode(['Type' => 'SubscriptionConfirmation', 'SubscribeURL' => $url]);
$good = 'https://sns.eu-central-1.amazonaws.com/?Action=ConfirmSubscription&Token=abc';
check('confirmarea abonamentului SNS: adresa e întoarsă', $ses->parse($confirm($good)) === ['confirm_url' => $good, 'events' => []]);
check('confirmare cu adresă din afara SNS: ignorată',
    $ses->parse($confirm('https://evil.example/?x=sns.eu-central-1.amazonaws.com'))['confirm_url'] === null
    && $ses->parse($confirm('https://sns.eu-central-1.amazonaws.com.evil.example/'))['confirm_url'] === null
    && $ses->parse($confirm('http://sns.eu-central-1.amazonaws.com/?a=1'))['confirm_url'] === null);

$none = ['confirm_url' => null, 'events' => []];
check('JSON stricat → niciun eveniment', $ses->parse('{nu e json') === $none && $ses->parse('') === $none && $ses->parse('"text"') === $none);
check('tip necunoscut → niciun eveniment', $ses->parse($sns(['notificationType' => 'Open', 'mail' => []])) === $none);
check('Message care nu e JSON → niciun eveniment', $ses->parse((string) json_encode(['Type' => 'Notification', 'Message' => 'salut'])) === $none);
check('respingere fără destinatari → niciun eveniment', $ses->parse($sns(['notificationType' => 'Bounce', 'bounce' => ['bounceType' => 'Permanent']])) === $none);

// --- Feedback::apply ----------------------------------------------------------
$pdo   = nl_isolate();
$repo  = new Repository(nl_db());
$sends = new Sends(nl_db());
$campaigns = new Campaigns(nl_db());
$feedback  = new Feedback(nl_db(), $repo, $sends);

$subStatus = static fn (int $id): string => (string) $repo->find($id)['status'];
$sent = static function (int $campaign, int $sub, ?string $messageId = null) use ($pdo): int {
    $pdo->prepare("INSERT INTO nl_sends (campaign_id, subscriber_id, status, sent_at, message_id) VALUES (:c, :u, 'sent', NOW(), :m)")
        ->execute([':c' => $campaign, ':u' => $sub, ':m' => $messageId]);
    return (int) $pdo->lastInsertId();
};
$sendStatus = static fn (int $id): string => (string) $pdo->query("SELECT status FROM nl_sends WHERE id = {$id}")->fetchColumn();
$ev = static fn (string $type, string $email, ?string $mid = null): array => ['type' => $type, 'email' => $email, 'message_id' => $mid, 'detail' => ''];

$c1 = nl_campaign('stiri');
$a = nl_subscriber('a@nl-test.invalid');
$sa = $sent($c1, $a, 'ses-a');
check('respingere definitivă: abonat exclus, mesaj marcat', $feedback->apply($ev('bounce_hard', 'a@nl-test.invalid', 'ses-a')) === 'bounced'
    && $subStatus($a) === 'bounced' && $sendStatus($sa) === 'bounced');

$b = nl_subscriber('b@nl-test.invalid');
$sb = $sent($c1, $b, 'ses-b');
check('reclamație: abonat exclus, mesaj marcat', $feedback->apply($ev('complaint', 'B@nl-test.invalid ')) === 'complained'
    && $subStatus($b) === 'complained' && $sendStatus($sb) === 'complained');
check('respingere după reclamație: reclamația rămâne', $feedback->apply($ev('bounce_hard', 'b@nl-test.invalid', 'ses-b')) === 'bounced'
    && $subStatus($b) === 'complained' && $sendStatus($sb) === 'complained');

$cnt = (int) $pdo->query('SELECT COUNT(*) FROM nl_subscribers')->fetchColumn();
check('adresă necunoscută: nimic nu se schimbă', $feedback->apply($ev('bounce_hard', 'nimeni@nl-test.invalid', 'ses-zzz')) === 'unknown'
    && (int) $pdo->query('SELECT COUNT(*) FROM nl_subscribers')->fetchColumn() === $cnt);
check('livrare: fără efect', $feedback->apply($ev('delivery', 'a@nl-test.invalid')) === 'delivery');
check('tip necunoscut: ignorat', $feedback->apply($ev('open', 'a@nl-test.invalid')) === 'ignored');

// Potrivire după ID-ul mesajului, chiar dacă adresa din notificare e alta.
$d = nl_subscriber('d@nl-test.invalid');
$sd = $sent($c1, $d, 'ses-d');
check('ID-ul mesajului are prioritate față de adresă', $feedback->apply($ev('bounce_hard', 'alias@alt-domeniu.invalid', 'ses-d')) === 'bounced'
    && $subStatus($d) === 'bounced' && $sendStatus($sd) === 'bounced');

// Abonat fără niciun mesaj plecat (ex. emailul de confirmare a fost respins).
$e = nl_subscriber('e@nl-test.invalid');
check('respingere definitivă fără mesaj de campanie: abonatul e exclus oricum',
    $feedback->apply($ev('bounce_hard', 'e@nl-test.invalid')) === 'bounced' && $subStatus($e) === 'bounced');

// Respingeri temporare: excludere abia la a treia campanie consecutivă.
$s = nl_subscriber('soft@nl-test.invalid');
$s1 = $sent($c1, $s);
check('prima respingere temporară: abonatul rămâne activ', $feedback->apply($ev('bounce_soft', 'soft@nl-test.invalid')) === 'soft'
    && $subStatus($s) === 'active' && $sendStatus($s1) === 'soft_bounced' && (int) $repo->find($s)['soft_bounces'] === 1);
check('aceeași notificare primită de două ori nu se numără dublu', $feedback->apply($ev('bounce_soft', 'soft@nl-test.invalid')) === 'soft'
    && (int) $repo->find($s)['soft_bounces'] === 1);
$c2 = nl_campaign('stiri');
$sent($c2, $s);
$pdo->exec("UPDATE nl_sends SET sent_at = NOW() - INTERVAL 2 DAY WHERE campaign_id = {$c1}");
check('a doua campanie respinsă temporar: încă activ', $feedback->apply($ev('bounce_soft', 'soft@nl-test.invalid')) === 'soft' && $subStatus($s) === 'active');
$c3 = nl_campaign('stiri');
$pdo->exec("UPDATE nl_sends SET sent_at = NOW() - INTERVAL 1 DAY WHERE campaign_id = {$c2}");
$sent($c3, $s);
check('a treia campanie consecutivă: abonatul e exclus', $feedback->apply($ev('bounce_soft', 'soft@nl-test.invalid')) === 'soft_limit' && $subStatus($s) === 'bounced');

// O campanie livrată între două respingeri temporare întrerupe șirul.
$t = nl_subscriber('t@nl-test.invalid');
$pdo->exec("INSERT INTO nl_sends (campaign_id, subscriber_id, status, sent_at) VALUES
    ({$c1}, {$t}, 'soft_bounced', NOW() - INTERVAL 3 DAY), ({$c2}, {$t}, 'sent', NOW() - INTERVAL 2 DAY)");
$c4 = nl_campaign('stiri');
$pdo->exec("INSERT INTO nl_sends (campaign_id, subscriber_id, status, sent_at) VALUES ({$c3}, {$t}, 'soft_bounced', NOW() - INTERVAL 1 DAY)");
$sent($c4, $t);
check('șir întrerupt de o livrare reușită: abonatul rămâne activ', $feedback->apply($ev('bounce_soft', 't@nl-test.invalid')) === 'soft' && $subStatus($t) === 'active');

// Rate prea mari → campania în curs intră în pauză.
$c5 = nl_campaign('stiri');
$pdo->exec("UPDATE nl_campaigns SET status = 'sending' WHERE id = {$c5}");
$ins = $pdo->prepare("INSERT INTO nl_sends (campaign_id, subscriber_id, status, sent_at) VALUES ({$c5}, :u, :s, NOW())");
for ($i = 0; $i < 199; $i++) {
    $ins->execute([':u' => 700000 + $i, ':s' => $i < 10 ? 'bounced' : 'sent']);
}
$f = nl_subscriber('f@nl-test.invalid');
$sent($c5, $f, 'ses-f');
$feedback->apply($ev('bounce_hard', 'f@nl-test.invalid', 'ses-f'));
check('a 11-a respingere din 200 (5,5%): campania intră în pauză',
    $campaigns->find($c5)['status'] === 'paused' && str_contains((string) $campaigns->find($c5)['pause_reason'], 'respingere'));

// --- jurnal ---------------------------------------------------------------------
$feedback->log('ses', 'bounce_hard', 'a@nl-test.invalid', 'ses-a', str_repeat('x', 30000));
$row = $pdo->query('SELECT * FROM nl_events ORDER BY id DESC LIMIT 1')->fetch();
check('log: rând în nl_events, cu corpul trunchiat', $row['provider'] === 'ses' && $row['type'] === 'bounce_hard'
    && $row['email'] === 'a@nl-test.invalid' && $row['message_id'] === 'ses-a' && strlen((string) $row['payload']) === 20000);

nl_done();
