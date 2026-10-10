<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterSenderTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Campaigns;
use App\Newsletter\Repository;
use App\Newsletter\Sender;
use App\Newsletter\Sends;
use App\Newsletter\Tracking;

$pdo   = nl_isolate();
$sends = new Sends(nl_db());
$repo  = new Repository(nl_db());
$campaigns = new Campaigns(nl_db());
$site  = 'https://www.motociclete.com.ro';

/** Transport fals: ține minte ce a primit; adresele din $fail eșuează. */
$outbox = [];
$fail   = [];
$send = static function (string $to, string $subject, string $html, string $text, array $headers) use (&$outbox, &$fail): array {
    if (in_array($to, $fail, true) || in_array('*', $fail, true)) {
        return ['ok' => false, 'error' => 'SMTP 451 temporar', 'message_id' => null];
    }
    $outbox[] = compact('to', 'subject', 'html', 'text', 'headers');
    return ['ok' => true, 'error' => '', 'message_id' => 'msg-' . count($outbox)];
};
$pauses = 0;
$sender = new Sender($sends, new Tracking(nl_db()), $send, $site . '/', static function () use (&$pauses): void {
    $pauses++;
});
$status = static fn (int $c): string => (string) $campaigns->find($c)['status'];
$sendStatus = static function (int $c, int $u) use ($pdo): string {
    return (string) $pdo->query("SELECT status FROM nl_sends WHERE campaign_id = {$c} AND subscriber_id = {$u}")->fetchColumn();
};
$to = static fn (array $box): array => array_column($box, 'to');

// --- o campanie, cinci destinatari, limită de 2 pe rulare ---------------------
$subs = [];
foreach (['a', 'b', 'c', 'd', 'e'] as $n) {
    $subs[$n] = nl_subscriber($n . '@nl-test.invalid');
}
$link = 'https://bikershop.ro/1-casca.html?utm_source=newsletter&utm_content=produs-1';
$c = nl_campaign('stiri', '<a href="' . htmlspecialchars($link) . '">Cască</a> <a href="%%UNSUB_URL%%">Dezabonare</a> %%EMAIL%% <a href="%%VIEW_URL%%">browser</a>');
$sends->enqueue($c);

$r = $sender->run(2, 1000);
check('rularea 1: trimite exact limita pe rulare', $r['sent'] === 2 && $to($outbox) === ['a@nl-test.invalid', 'b@nl-test.invalid']);
check('rularea 1: campania e în curs', $status($c) === 'sending' && $r['finished'] === []);
check('pauză între mesaje, nu și după ultimul', $pauses === 1);

$token = (string) $repo->find($subs['a'])['token'];
$m = $outbox[0];
$unsub = $site . '/newsletter/dezabonare/' . $token . '?l=stiri&c=' . $c;
check('headerul List-Unsubscribe duce la dezabonarea de pe lista campaniei',
    $m['headers']['List-Unsubscribe'] === '<' . $unsub . '>' && $m['headers']['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click');
check('marcajele sunt completate pentru destinatar',
    str_contains($m['html'], 'href="' . htmlspecialchars($unsub) . '"') && str_contains($m['html'], 'a@nl-test.invalid')
    && str_contains($m['html'], $site . '/newsletter/c/' . $c . '-' . $campaigns->find($c)['view_key']) && !str_contains($m['html'], '%%'));
$linkId = (int) $pdo->query("SELECT id FROM nl_links WHERE campaign_id = {$c}")->fetchColumn();
check('linkul spre magazin trece prin adresa de numărare, cu tokenul destinatarului',
    $linkId > 0 && str_contains($m['html'], 'href="' . $site . '/nl/c/' . $linkId . '/' . $token . '"') && !str_contains($m['html'], 'bikershop.ro/1-casca'));
check('varianta text e personalizată', str_contains($m['text'], 'a@nl-test.invalid') && str_contains($m['text'], $unsub));
check('ID-ul mesajului e păstrat', $pdo->query("SELECT message_id FROM nl_sends WHERE campaign_id = {$c} AND subscriber_id = {$subs['a']}")->fetchColumn() === 'msg-1');

// Între rulări: c se dezabonează, d e marcat respins.
$repo->unsubscribe($subs['c'], 'stiri');
$repo->setStatus($subs['d'], 'bounced');
$r = $sender->run(10, 1000);
check('rularea 2: cei dezabonați sau excluși între timp nu primesc nimic',
    $r['sent'] === 1 && $r['skipped'] === 2 && $to($outbox) === ['a@nl-test.invalid', 'b@nl-test.invalid', 'e@nl-test.invalid']
    && $sendStatus($c, $subs['c']) === 'skipped' && $sendStatus($c, $subs['d']) === 'skipped');
check('rularea 2: campania e închisă', $r['finished'] === [$c] && $status($c) === 'sent' && $campaigns->find($c)['finished_at'] !== null);
$r = $sender->run(10, 1000);
check('rularea 3: nimic de făcut, nimeni nu primește a doua oară', $r['sent'] === 0 && count($outbox) === 3);

// --- limita pe 24 de ore -------------------------------------------------------
$outbox = [];
$repo->setSubscription($subs['c'], 'stiri', 'manual');
$repo->activate($subs['d']);
$c2 = nl_campaign('stiri');
$sends->enqueue($c2);
$r = $sender->run(10, 5);
check('limita pe 24 h: 3 plecate deja, mai pleacă doar 2', $r['sent'] === 2 && count($outbox) === 2 && $r['day_limit'] === false);
$r = $sender->run(10, 5);
check('limita pe 24 h atinsă: nu mai pleacă nimic, campania rămâne în curs',
    $r['sent'] === 0 && $r['day_limit'] === true && $status($c2) === 'sending');
$r = $sender->run(10, 1000);
check('cu limita ridicată, restul pleacă și campania se închide', $r['sent'] === 3 && $status($c2) === 'sent');
sort($outbox);
check('fiecare destinatar o singură dată', count(array_unique($to($outbox))) === 5 && count($outbox) === 5);

// --- eșecuri și reîncercări ----------------------------------------------------
$outbox = [];
$fail = ['b@nl-test.invalid'];
$c3 = nl_campaign('stiri');
$sends->enqueue($c3);
$r = $sender->run(10, 1000);
check('eșec temporar: ceilalți pleacă, rândul revine în coadă', $r['sent'] === 4 && $r['retry'] === 1 && $r['failed'] === 0
    && $sendStatus($c3, $subs['b']) === 'queued' && $status($c3) === 'sending');
$r = $sender->run(10, 1000);
check('a doua încercare eșuată: tot în coadă', $r['retry'] === 1 && $sendStatus($c3, $subs['b']) === 'queued');
$r = $sender->run(10, 1000);
check('a treia încercare eșuată: rămâne eșuat, campania se închide',
    $r['failed'] === 1 && $sendStatus($c3, $subs['b']) === 'failed' && $status($c3) === 'sent');
check('eroarea e păstrată pe rând', str_contains((string) $pdo->query("SELECT error FROM nl_sends WHERE campaign_id = {$c3} AND subscriber_id = {$subs['b']}")->fetchColumn(), '451'));

// --- 20 de eșecuri la rând → pauză ----------------------------------------------
$outbox = [];
for ($i = 0; $i < 25; $i++) {
    nl_subscriber("m{$i}@nl-test.invalid");
}
$fail = ['*'];
$c4 = nl_campaign('stiri');
$sends->enqueue($c4);
$r = $sender->run(100, 100000);
check('20 de eșecuri la rând: campania intră în pauză, cu motiv',
    $status($c4) === 'paused' && isset($r['paused'][$c4]) && $r['retry'] === 20 && $outbox === []
    && str_contains((string) $campaigns->find($c4)['pause_reason'], '451'));
$r = $sender->run(100, 100000);
check('o campanie în pauză nu trimite nimic', $r['sent'] === 0 && $r['retry'] === 0);
$fail = [];
$sends->resume($c4);
$r = $sender->run(100, 100000);
check('după reluare pleacă toate, o singură dată',
    $r['sent'] === 30 && $status($c4) === 'sent' && count(array_unique($to($outbox))) === 30);

// --- proces întrerupt: rândul rămas în lucru nu se retrimite ---------------------
$outbox = [];
$c5 = nl_campaign('stiri');
$sends->enqueue($c5);
$stuck = (int) $sends->batch($c5, 1)[0]['id'];
$sends->claim($stuck);
$pdo->exec("UPDATE nl_sends SET claimed_at = NOW() - INTERVAL 20 MINUTE WHERE id = {$stuck}");
$r = $sender->run(100, 100000);
check('rândul întrerupt devine eșuat și nu se retrimite',
    $r['stale'] === 1 && count($outbox) === 29 && !in_array('a@nl-test.invalid', $to($outbox), true)
    && $sendStatus($c5, $subs['a']) === 'failed' && $status($c5) === 'sent');

// --- transport care aruncă -----------------------------------------------------
$boom = new Sender($sends, new Tracking(nl_db()), static function (): array {
    throw new RuntimeException('conexiune pierdută');
}, $site);
$c6 = nl_campaign('oferte');
nl_subscriber('x@nl-test.invalid', ['oferte']);
$sends->enqueue($c6);
$r = $boom->run(10, 100000);
check('o excepție a transportului e tratată ca eșec temporar', $r['retry'] === 1 && $status($c6) === 'sending');

// --- rate prea mari → pauză înainte de trimitere --------------------------------
$sends->pause($c6, 'test');
$outbox = [];
$c7 = nl_campaign('oferte');
$sends->enqueue($c7);
$ins = $pdo->prepare("INSERT INTO nl_sends (campaign_id, subscriber_id, status, sent_at) VALUES ({$c7}, :u, :s, NOW())");
for ($i = 0; $i < 200; $i++) {
    $ins->execute([':u' => 500000 + $i, ':s' => $i < 20 ? 'bounced' : 'sent']);
}
$r = $sender->run(10, 100000);
check('10% respingeri: campania intră în pauză fără să mai trimită',
    $status($c7) === 'paused' && $outbox === [] && str_contains((string) ($r['paused'][$c7] ?? ''), 'respingere'));

nl_done();
