<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterSyncTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Repository;
use App\Newsletter\Sync;

$pdo  = nl_isolate();
$repo = new Repository(nl_db());
$sync = new Sync($repo);

$row = static fn (string $email, string $name = ''): array => ['email' => $email, 'name' => $name];
$sources = static fn (?array $acc, ?array $foot, array $garage): array =>
    ['bs_account' => $acc, 'bs_footer' => $foot, 'garage' => $garage];

// --- dry-run nu scrie nimic --------------------------------------------------
$r = $sync->run($sources([$row('ana@nl-test.invalid', 'Ana')], [], []), false);
check('dry-run: raportează 1 abonat nou', $r['new_subscribers'] === 1 && $r['added']['bs_account'] === 2);
check('dry-run: nu scrie în DB', $repo->findByEmail('ana@nl-test.invalid') === null);

// --- prima rulare ------------------------------------------------------------
$r = $sync->run($sources(
    [$row(' Ana@NL-Test.invalid ', 'Ana'), $row('guest-emag-1@bikershop.ro'), $row('nu-e-email')],
    [$row('ana@nl-test.invalid'), $row('florin@nl-test.invalid')],
    [$row('ana@nl-test.invalid', 'Ana P'), $row('gabi@nl-test.invalid', 'Gabi')]
), true);
check('run: fără abandon', $r['aborted'] === null);
check('run: 3 abonați noi (ana o singură dată)', $r['new_subscribers'] === 3);
check('run: adresa fictivă numărată ca blocată', $r['blocked'] === 1);
check('run: adresa invalidă numărată', $r['invalid'] === 1);
check('run: adresa blocată nu ajunge în DB', $repo->findByEmail('guest-emag-1@bikershop.ro') === null);

$ana = $repo->findByEmail('ana@nl-test.invalid');
$subs = $repo->subscriptions((int) $ana['id']);
check('cont BikerShop: ambele liste, sursa bs_account',
    ($subs['oferte']['source'] ?? '') === 'bs_account' && ($subs['stiri']['source'] ?? '') === 'bs_account');
check('nume luat din prima sursă', $ana['name'] === 'Ana');

$gabi = $repo->findByEmail('gabi@nl-test.invalid');
$gsubs = $repo->subscriptions((int) $gabi['id']);
check('garage: doar lista stiri', isset($gsubs['stiri']) && !isset($gsubs['oferte']) && $gsubs['stiri']['source'] === 'garage');

$florin = $repo->findByEmail('florin@nl-test.invalid');
check('footer: ambele liste, sursa bs_footer',
    ($repo->subscriptions((int) $florin['id'])['oferte']['source'] ?? '') === 'bs_footer');

// --- a doua rulare identică nu schimbă nimic ---------------------------------
$r = $sync->run($sources(
    [$row('ana@nl-test.invalid')], [$row('florin@nl-test.invalid')], [$row('gabi@nl-test.invalid')]
), true);
check('re-rulare: nimic nou', $r['new_subscribers'] === 0 && array_sum($r['added']) === 0 && $r['unsubscribed'] === 0);

// --- un dezabonat rămâne dezabonat -------------------------------------------
$repo->unsubscribe((int) $florin['id'], 'oferte');
$sync->run($sources([$row('ana@nl-test.invalid')], [$row('florin@nl-test.invalid')], []), true);
check('dezabonat: sincronizarea nu îl reactivează',
    $repo->subscriptions((int) $florin['id'])['oferte']['status'] === 'unsubscribed');

// --- bounced nu primește abonamente noi --------------------------------------
$bad = $repo->ensureSubscriber('respins@nl-test.invalid', null, 'active');
$repo->setStatus((int) $bad['id'], 'bounced');
$sync->run($sources([$row('ana@nl-test.invalid'), $row('respins@nl-test.invalid')], [$row('florin@nl-test.invalid')], []), true);
check('bounced: rămâne bounced, fără abonamente',
    $repo->findByEmail('respins@nl-test.invalid')['status'] === 'bounced'
    && $repo->subscriptions((int) $bad['id']) === []);

// --- pending devine activ când apare într-o sursă de încredere ---------------
$pend = $repo->ensureSubscriber('nou@nl-test.invalid', null, 'pending');
$r = $sync->run($sources([$row('ana@nl-test.invalid'), $row('nou@nl-test.invalid')], [$row('florin@nl-test.invalid')], []), true);
check('pending: activat de sincronizare',
    $r['activated'] === 1 && $repo->findByEmail('nou@nl-test.invalid')['status'] === 'active');

// --- contul nu mai are bifa în BikerShop -------------------------------------
$r = $sync->run($sources([$row('nou@nl-test.invalid')], [$row('florin@nl-test.invalid')], []), true);
check('bifă scoasă: raportat 1 dezabonat', $r['unsubscribed'] === 1);
check('bifă scoasă: abonamentele bs_account devin dezabonate',
    $repo->subscriptions((int) $ana['id'])['oferte']['status'] === 'unsubscribed'
    && $repo->subscriptions((int) $ana['id'])['stiri']['status'] === 'unsubscribed');
check('bifă scoasă: alte surse rămân neatinse',
    $repo->subscriptions((int) $gabi['id'])['stiri']['status'] === 'active');

// --- BikerShop indisponibil --------------------------------------------------
$before = $repo->activeEmailsBySource('bs_account');
$r = $sync->run($sources(null, [], []), true);
check('sursă null: abandon cu motiv', is_string($r['aborted']) && $r['aborted'] !== '');
check('sursă null: nimeni dezabonat', $repo->activeEmailsBySource('bs_account') === $before);

// --- garda împotriva unui răspuns parțial ------------------------------------
$many = [];
for ($i = 1; $i <= 30; $i++) {
    $many[] = $row("client{$i}@nl-test.invalid");
}
$sync->run($sources($many, [], []), true);
$active = count($repo->activeEmailsBySource('bs_account'));
$r = $sync->run($sources(array_slice($many, 0, 25), [], []), true);
check('gardă: abandon când sursa scade cu peste 10% (25 din 30)', is_string($r['aborted']));
$r = $sync->run($sources(array_slice($many, 0, 28), [], []), true);
check('gardă: o scădere mică (28 din 30) trece și dezabonează 2', $r['aborted'] === null && $r['unsubscribed'] === 2);
$active = count($repo->activeEmailsBySource('bs_account'));
$r = $sync->run($sources(array_slice($many, 0, 5), [], []), true);
check('gardă: abandon când sursa scade sub jumătate', is_string($r['aborted']));
check('gardă: nimeni dezabonat', count($repo->activeEmailsBySource('bs_account')) === $active);

nl_done();
