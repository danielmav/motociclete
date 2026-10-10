<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterSendsTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Campaigns;
use App\Newsletter\Repository;
use App\Newsletter\Sends;

$pdo   = nl_isolate();
$sends = new Sends(nl_db());
$repo  = new Repository(nl_db());
$campaigns = new Campaigns(nl_db());

$status = static fn (int $c): string => (string) $campaigns->find($c)['status'];
$sendRow = static function (int $c, int $u) use ($pdo): array {
    return $pdo->query("SELECT * FROM nl_sends WHERE campaign_id = {$c} AND subscriber_id = {$u}")->fetch() ?: [];
};

// --- destinatari -------------------------------------------------------------
$a = nl_subscriber('a@nl-test.invalid', ['stiri', 'oferte']);
$b = nl_subscriber('b@nl-test.invalid', ['stiri']);
$onlyOffers = nl_subscriber('oferte@nl-test.invalid', ['oferte']);
$pending    = nl_subscriber('pending@nl-test.invalid', ['stiri'], 'pending');
$bounced    = nl_subscriber('bounced@nl-test.invalid', ['stiri']);
$repo->setStatus($bounced, 'bounced');
$complained = nl_subscriber('complained@nl-test.invalid', ['stiri']);
$repo->setStatus($complained, 'complained');
$unsub = nl_subscriber('unsub@nl-test.invalid', ['stiri']);
$repo->unsubscribe($unsub, 'stiri');

check('recipientCount: doar abonații activi cu abonament activ pe listă', $sends->recipientCount('stiri') === 2);
check('recipientCount: cealaltă listă', $sends->recipientCount('oferte') === 2);

// --- enqueue -----------------------------------------------------------------
$c = nl_campaign('stiri');
check('enqueue: întoarce numărul de destinatari', $sends->enqueue($c) === 2);
$ids = array_map('intval', $pdo->query("SELECT subscriber_id FROM nl_sends WHERE campaign_id = {$c} ORDER BY subscriber_id")->fetchAll(PDO::FETCH_COLUMN));
check('enqueue: rânduri doar pentru a și b', $ids === [$a, $b]);
check('enqueue: campania devine queued, cu dată', $status($c) === 'queued' && $campaigns->find($c)['queued_at'] !== null);

$threw = false;
try {
    $sends->enqueue($c);
} catch (RuntimeException) {
    $threw = true;
}
check('enqueue a doua oară: refuzat, fără rânduri duble',
    $threw && (int) $pdo->query("SELECT COUNT(*) FROM nl_sends WHERE campaign_id = {$c}")->fetchColumn() === 2);

$noHtml = $campaigns->create('stiri', 'stiri', 'Fără mesaj');
$threw = false;
try {
    $sends->enqueue($noHtml);
} catch (RuntimeException) {
    $threw = true;
}
check('enqueue fără mesaj generat: refuzat, rămâne ciornă', $threw && $status($noHtml) === 'draft');

$pdo->exec("UPDATE nl_subscriptions SET status = 'unsubscribed' WHERE list_key = 'oferte'");
$empty = nl_campaign('oferte');
$threw = false;
try {
    $sends->enqueue($empty);
} catch (RuntimeException) {
    $threw = true;
}
check('enqueue pe o listă goală: refuzat, rămâne ciornă', $threw && $status($empty) === 'draft');

// --- tranșe și marcaje -------------------------------------------------------
$batch = $sends->batch($c, 10);
check('batch: rândurile din coadă, cu adresa și tokenul abonatului',
    count($batch) === 2 && $batch[0]['email'] === 'a@nl-test.invalid' && strlen((string) $batch[0]['token']) === 32
    && $batch[0]['sub_status'] === 'active' && $batch[0]['list_status'] === 'active');
check('batch: limita', count($sends->batch($c, 1)) === 1);
$first = (int) $batch[0]['id'];
$second = (int) $batch[1]['id'];
check('batch: rândurile excluse sunt sărite', (int) $sends->batch($c, 10, [$first])[0]['id'] === $second);

check('claim: reușește prima dată', $sends->claim($first) === true);
check('claim: a doua oară e refuzat', $sends->claim($first) === false);
check('batch: un rând rezervat nu mai apare', count($sends->batch($c, 10)) === 1);

$sends->markSent($first, 'msg-1');
$row = $sendRow($c, $a);
check('markSent: stare, dată și ID de mesaj', $row['status'] === 'sent' && $row['sent_at'] !== null && $row['message_id'] === 'msg-1');

$sends->claim($second);
check('markRetry: prima încercare revine în coadă', $sends->markRetry($second, 'timeout') === 'queued');
$sends->claim($second);
check('markRetry: a doua tot în coadă', $sends->markRetry($second, 'timeout') === 'queued');
$sends->claim($second);
check('markRetry: a treia rămâne eșuată, cu eroarea', $sends->markRetry($second, 'timeout 3') === 'failed'
    && $sendRow($c, $b)['error'] === 'timeout 3' && (int) $sendRow($c, $b)['attempts'] === 3);

check('started: queued → sending', (function () use ($sends, $c, $status): bool {
    $sends->started($c);
    return $status($c) === 'sending';
})());
check('finishIfDone: nimic în coadă → campania e trimisă', $sends->finishIfDone($c) === true
    && $status($c) === 'sent' && $campaigns->find($c)['finished_at'] !== null);
check('finishIfDone: a doua oară nu mai schimbă nimic', $sends->finishIfDone($c) === false);

// --- rânduri întrerupte ------------------------------------------------------
$c2 = nl_campaign('stiri');
$sends->enqueue($c2);
$rows = $sends->batch($c2, 10);
$sends->claim((int) $rows[0]['id']);
$sends->claim((int) $rows[1]['id']);
$pdo->exec('UPDATE nl_sends SET claimed_at = NOW() - INTERVAL 30 MINUTE WHERE id = ' . (int) $rows[0]['id']);
check('finishIfDone: cu rânduri în lucru, campania nu se închide', $sends->finishIfDone($c2) === false);
check('releaseStale: doar rândul vechi devine eșuat', $sends->releaseStale(15) === 1
    && $sendRow($c2, $a)['status'] === 'failed' && $sendRow($c2, $b)['status'] === 'sending');
check('rândul întrerupt nu se întoarce în coadă', $sends->batch($c2, 10) === []);

// --- pauză, reluare, oprire ---------------------------------------------------
$c3 = nl_campaign('stiri');
$sends->enqueue($c3);
check('activeCampaigns: campaniile în coadă, cu mesajul', in_array($c3, array_map(static fn (array $r): int => (int) $r['id'], $sends->activeCampaigns()), true)
    && array_key_exists('html', $sends->activeCampaigns()[0]));
check('pause: queued → paused, cu motiv', $sends->pause($c3, 'test') === true
    && $status($c3) === 'paused' && $campaigns->find($c3)['pause_reason'] === 'test');
check('activeCampaigns: fără cele în pauză', !in_array($c3, array_map(static fn (array $r): int => (int) $r['id'], $sends->activeCampaigns()), true));
check('pause pe o campanie deja în pauză: false', $sends->pause($c3, 'x') === false);
check('resume: paused → sending, motiv șters', $sends->resume($c3) === true
    && $status($c3) === 'sending' && $campaigns->find($c3)['pause_reason'] === null);
check('resume pe o campanie care nu e în pauză: false', $sends->resume($c3) === false);
check('pause pe o ciornă: false', $sends->pause($noHtml, 'x') === false);

check('failStreak: crește la eșec', $sends->failStreak($c3, true) === 1 && $sends->failStreak($c3, true) === 2);
check('failStreak: se resetează la succes', $sends->failStreak($c3, false) === 0);

$one = (int) $sends->batch($c3, 1)[0]['id'];
$sends->claim($one);
$sends->markSent($one, null);
check('cancel: ce n-a plecat e scos din coadă', $sends->cancel($c3) === 1
    && $status($c3) === 'sent' && $sendRow($c3, $b)['status'] === 'skipped' && $sendRow($c3, $a)['status'] === 'sent');
check('cancel pe o campanie terminată: 0', $sends->cancel($c3) === 0);

// --- statistici și sănătate ---------------------------------------------------
$big = nl_campaign('stiri');
$ins = $pdo->prepare('INSERT INTO nl_sends (campaign_id, subscriber_id, status, sent_at) VALUES (:c, :u, :s, IF(:d = 1, NOW(), NULL))');
$fill = static function (int $campaign, array $counts) use ($ins): void {
    $u = 100000;
    foreach ($counts as $st => $n) {
        for ($i = 0; $i < $n; $i++) {
            $ins->execute([':c' => $campaign, ':u' => $u++, ':s' => $st, ':d' => in_array($st, ['sent', 'soft_bounced', 'bounced', 'complained'], true) ? 1 : 0]);
        }
    }
};
$fill($big, ['sent' => 180, 'bounced' => 6, 'soft_bounced' => 3, 'complained' => 1, 'failed' => 4, 'skipped' => 2, 'queued' => 5]);
$st = $sends->stats($big);
check('stats: destinatari și trimise (inclusiv cele respinse ulterior)', $st['recipients'] === 201 && $st['sent'] === 190);
check('stats: pe stări', $st['bounced'] === 6 && $st['soft_bounced'] === 3 && $st['complained'] === 1
    && $st['failed'] === 4 && $st['skipped'] === 2 && $st['queued'] === 5);
check('health: sub 200 de mesaje trimise nu judecă ratele', $sends->health($big) === null);

$fill2 = nl_campaign('stiri');
$fill($fill2, ['sent' => 190, 'bounced' => 12]);
check('health: 5,9% respingeri → motiv de pauză', str_contains((string) $sends->health($fill2), 'respingere'));
$fill3 = nl_campaign('stiri');
$fill($fill3, ['sent' => 299, 'complained' => 1]);
check('health: 0,33% reclamații → motiv de pauză', str_contains((string) $sends->health($fill3), 'reclamații'));
$fill4 = nl_campaign('stiri');
$fill($fill4, ['sent' => 291, 'bounced' => 9]);
check('health: 3% respingeri, fără reclamații → în regulă', $sends->health($fill4) === null);

check('sentLast24h: numără mesajele plecate', $sends->sentLast24h() >= 190 + 202 + 300 + 300);
$pdo->exec('UPDATE nl_sends SET sent_at = NOW() - INTERVAL 25 HOUR WHERE sent_at IS NOT NULL');
check('sentLast24h: cele mai vechi de o zi nu contează', $sends->sentLast24h() === 0);

$repo->setSubscription($unsub, 'stiri', 'manual');
$repo->unsubscribe($unsub, 'stiri', $big);
check('stats: dezabonările venite din campanie', $sends->stats($big)['unsubscribed'] === 1 && $sends->stats($fill2)['unsubscribed'] === 0);

// --- răspunsurile releului ----------------------------------------------------
$c4 = nl_campaign('stiri');
$pdo->exec("INSERT INTO nl_sends (campaign_id, subscriber_id, status, sent_at, message_id) VALUES ({$c4}, {$a}, 'sent', NOW(), 'ses-abc')");
$sendA = (int) $pdo->lastInsertId();
check('findForFeedback: după ID-ul mesajului', (int) ($sends->findForFeedback('ses-abc', null)['id'] ?? 0) === $sendA);
check('findForFeedback: fără ID, ultimul mesaj plecat către abonat', (int) ($sends->findForFeedback(null, $a)['id'] ?? 0) === $sendA);
check('findForFeedback: ID necunoscut și fără abonat → null', $sends->findForFeedback('nimic', null) === null);
check('markFeedback: sent → soft_bounced', $sends->markFeedback($sendA, 'soft_bounced') === true);
check('markFeedback: soft_bounced → complained', $sends->markFeedback($sendA, 'complained') === true);
check('markFeedback: nu coboară o stare mai gravă', $sends->markFeedback($sendA, 'bounced') === false
    && $sendRow($c4, $a)['status'] === 'complained');
check('markFeedback: stare necunoscută → false', $sends->markFeedback($sendA, 'sent') === false);
check('lastOutcomes: cel mai nou primul', $sends->lastOutcomes($a, 2) === ['complained', 'sent']);
$h = $sends->history($a);
check('history: campaniile primite, cu subiect', count($h) === 4 && $h[0]['subject'] === 'Campanie de test' && (int) $h[0]['campaign_id'] === $c4);

nl_done();
