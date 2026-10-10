<?php

declare(strict_types=1);

/**
 * Cele două adrese publice fără pagină: redirectul de clic și webhook-ul releului.
 *
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterTrackControllerTest.php
 */

require __DIR__ . '/_nl.php';

use App\Controllers\NewsletterTrackController;
use App\Newsletter\Repository;
use App\Newsletter\Sends;
use App\Newsletter\Tracking;
use App\Newsletter\Transport;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

// --- Transport::mode: singura poartă dintre o campanie și trimiterea reală -------
check('mode: în dezvoltare doar jurnal, orice ar fi în config',
    Transport::mode(['send_enabled' => true, 'smtp_host' => 'smtp.exemplu.ro'], true) === 'log');
check('mode: pe server, fără NL_SEND_ENABLED → oprit (chiar cu SMTP-ul sitului ca rezervă)',
    Transport::mode(['send_enabled' => false, 'smtp_host' => 'localhost'], false) === 'off');
check('mode: activat dar fără gazdă SMTP → oprit', Transport::mode(['send_enabled' => true, 'smtp_host' => ''], false) === 'off');
check('mode: activat și cu releu → trimite', Transport::mode(['send_enabled' => true, 'smtp_host' => 'email-smtp.eu-central-1.amazonaws.com'], false) === 'live');
check('mode: config gol → oprit', Transport::mode([], false) === 'off');

$pdo  = nl_isolate();
$repo = new Repository(nl_db());
$tracking = new Tracking(nl_db());
$secret = bin2hex(random_bytes(12));
$container = static fn (string $s): array => [
    'db' => nl_db(), 'newsletter' => $repo, 'newsletter_tracking' => $tracking, 'newsletter_sends' => new Sends(nl_db()),
    'settings' => ['newsletter' => ['webhook_secret' => $s]],
];
$fetched = [];
$ctl = new NewsletterTrackController($container($secret), null, static function (string $url) use (&$fetched): bool {
    $fetched[] = $url;
    return true;
});
$request = static fn (string $method, string $body = '') => (new ServerRequestFactory())
    ->createServerRequest($method, 'https://www.motociclete.com.ro/x')
    ->withBody((new StreamFactory())->createStream($body));
$response = static fn () => (new ResponseFactory())->createResponse();
$is404 = static function (callable $fn): bool {
    try {
        $fn();
    } catch (HttpNotFoundException) {
        return true;
    }
    return false;
};
$count = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();

// --- clic ---------------------------------------------------------------------
$url = 'https://bikershop.ro/1-casca.html?utm_source=newsletter&utm_content=produs-1';
$c = nl_campaign('stiri', '<a href="' . htmlspecialchars($url) . '">x</a>');
$link = $tracking->register($c, '<a href="' . htmlspecialchars($url) . '">x</a>')[$url];
$a = nl_subscriber('a@nl-test.invalid');
$token = (string) $repo->find($a)['token'];

$r = $ctl->click($request('GET'), $response(), ['link' => (string) $link, 'token' => $token]);
check('clic: 302 către adresa din nl_links, fără indexare',
    $r->getStatusCode() === 302 && $r->getHeaderLine('Location') === $url && str_contains($r->getHeaderLine('X-Robots-Tag'), 'noindex'));
check('clic: înregistrat pe abonat', $count("SELECT COUNT(*) FROM nl_clicks WHERE link_id = {$link} AND subscriber_id = {$a}") === 1);
check('clic: nu schimbă abonamentul', $repo->subscriptions($a)['stiri']['status'] === 'active' && $repo->find($a)['status'] === 'active');

$r = $ctl->click($request('GET'), $response(), ['link' => (string) $link, 'token' => str_repeat('f', 32)]);
check('clic cu token necunoscut: redirecționează, dar nu înregistrează nimic',
    $r->getStatusCode() === 302 && $r->getHeaderLine('Location') === $url && $count('SELECT COUNT(*) FROM nl_clicks') === 1);
check('clic pe un link inexistent: 404', $is404(static fn () => $ctl->click($request('GET'), $response(), ['link' => '99999999', 'token' => $token])));

// --- webhook --------------------------------------------------------------------
$bounce = (string) json_encode(['Type' => 'Notification', 'Message' => json_encode([
    'notificationType' => 'Bounce',
    'bounce' => ['bounceType' => 'Permanent', 'bouncedRecipients' => [['emailAddress' => 'a@nl-test.invalid']]],
    'mail' => ['messageId' => 'ses-1'],
])]);

check('webhook cu secret greșit: 404, nimic schimbat',
    $is404(static fn () => $ctl->webhook($request('POST', $bounce), $response(), ['secret' => bin2hex(random_bytes(12))]))
    && $repo->find($a)['status'] === 'active' && $count('SELECT COUNT(*) FROM nl_events') === 0);
$off = new NewsletterTrackController($container(''));
check('webhook fără secret configurat: 404 chiar și cu secret gol în adresă',
    $is404(static fn () => $off->webhook($request('POST', $bounce), $response(), ['secret' => '']))
    && $is404(static fn () => $off->webhook($request('POST', $bounce), $response(), [])));

$r = $ctl->webhook($request('POST', $bounce), $response(), ['secret' => $secret]);
check('webhook: respingere definitivă → 200, abonat exclus, eveniment în jurnal',
    $r->getStatusCode() === 200 && $repo->find($a)['status'] === 'bounced'
    && $count("SELECT COUNT(*) FROM nl_events WHERE type = 'bounce_hard' AND email = 'a@nl-test.invalid' AND message_id = 'ses-1'") === 1);

$r = $ctl->webhook($request('POST', '{stricat'), $response(), ['secret' => $secret]);
check('webhook: JSON stricat → 200, jurnalizat ca necunoscut', $r->getStatusCode() === 200
    && $count("SELECT COUNT(*) FROM nl_events WHERE type = 'unknown'") === 1);

$unknown = str_replace('a@nl-test.invalid', 'nimeni@nl-test.invalid', $bounce);
$subs = $count('SELECT COUNT(*) FROM nl_subscribers');
$r = $ctl->webhook($request('POST', $unknown), $response(), ['secret' => $secret]);
check('webhook: adresă care nu e abonat → 200, jurnalizat, niciun abonat creat', $r->getStatusCode() === 200
    && $count('SELECT COUNT(*) FROM nl_subscribers') === $subs
    && $count("SELECT COUNT(*) FROM nl_events WHERE type = 'bounce_hard:necunoscut'") === 1);

$sns = 'https://sns.eu-central-1.amazonaws.com/?Action=ConfirmSubscription&Token=abc';
$ctl->webhook($request('POST', (string) json_encode(['Type' => 'SubscriptionConfirmation', 'SubscribeURL' => $sns])), $response(), ['secret' => $secret]);
check('webhook: confirmarea abonamentului SNS e vizitată și jurnalizată',
    $fetched === [$sns] && $count("SELECT COUNT(*) FROM nl_events WHERE type = 'subscription'") === 1);
$ctl->webhook($request('POST', (string) json_encode(['Type' => 'SubscriptionConfirmation', 'SubscribeURL' => 'https://evil.example/x'])), $response(), ['secret' => $secret]);
check('webhook: o adresă de confirmare din afara SNS nu e vizitată', $fetched === [$sns]);

$delivery = (string) json_encode(['notificationType' => 'Delivery', 'mail' => ['messageId' => 'ses-2'], 'delivery' => ['recipients' => ['a@nl-test.invalid']]]);
$ctl->webhook($request('POST', $delivery), $response(), ['secret' => $secret]);
check('webhook: confirmarea de livrare e jurnalizată fără corp',
    $pdo->query("SELECT payload FROM nl_events WHERE type = 'delivery'")->fetchColumn() === null);

nl_done();
