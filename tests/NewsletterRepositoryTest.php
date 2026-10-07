<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterRepositoryTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Repository;

$pdo  = nl_isolate();
$repo = new Repository(nl_db());

// --- abonați -----------------------------------------------------------------
$a = $repo->ensureSubscriber('ana@nl-test.invalid', 'Ana', 'active');
check('ensureSubscriber: creează rândul', (int) $a['id'] > 0 && $a['email'] === 'ana@nl-test.invalid');
check('ensureSubscriber: token de 32 de caractere hex', (bool) preg_match('/^[a-f0-9]{32}$/', (string) $a['token']));
check('ensureSubscriber: activ → confirmed_at completat', $a['confirmed_at'] !== null);

$again = $repo->ensureSubscriber('ana@nl-test.invalid', 'Alt nume', 'pending');
check('ensureSubscriber: rândul existent rămâne neschimbat',
    (int) $again['id'] === (int) $a['id'] && $again['status'] === 'active' && $again['name'] === 'Ana');

$p = $repo->ensureSubscriber('paul@nl-test.invalid', null, 'pending', '10.0.0.1');
check('ensureSubscriber: pending fără confirmed_at', $p['status'] === 'pending' && $p['confirmed_at'] === null);
check('findByToken: găsește după token', (int) ($repo->findByToken((string) $p['token'])['id'] ?? 0) === (int) $p['id']);
check('findByToken: token necunoscut → null', $repo->findByToken(str_repeat('0', 32)) === null);
check('find: după id', ($repo->find((int) $a['id'])['email'] ?? '') === 'ana@nl-test.invalid');

$repo->activate((int) $p['id']);
$p2 = $repo->findByEmail('paul@nl-test.invalid');
check('activate: pending → active cu confirmed_at', $p2['status'] === 'active' && $p2['confirmed_at'] !== null);

$repo->setStatus((int) $p['id'], 'bounced');
check('setStatus: bounced', $repo->findByEmail('paul@nl-test.invalid')['status'] === 'bounced');

// --- abonamente --------------------------------------------------------------
$id = (int) $a['id'];
check('addSubscription: inserează', $repo->addSubscription($id, 'oferte', 'bs_account') === true);
check('addSubscription: a doua oară nu inserează', $repo->addSubscription($id, 'oferte', 'garage') === false);
check('subscriptions: sursa inițială rămâne', $repo->subscriptions($id)['oferte']['source'] === 'bs_account');

check('unsubscribe: rând activ → true', $repo->unsubscribe($id, 'oferte') === true);
check('unsubscribe: listă fără rând → false', $repo->unsubscribe($id, 'stiri') === false);
check('unsubscribe: nu creează rând', !isset($repo->subscriptions($id)['stiri']));
check('addSubscription: nu reactivează un dezabonat',
    $repo->addSubscription($id, 'oferte', 'bs_account') === false
    && $repo->subscriptions($id)['oferte']['status'] === 'unsubscribed');

$repo->setSubscription($id, 'oferte', 'portal');
$s = $repo->subscriptions($id)['oferte'];
check('setSubscription: reactivează și schimbă sursa',
    $s['status'] === 'active' && $s['source'] === 'portal' && $s['unsubscribed_at'] === null);

$repo->suppress($id, 'stiri', 'brevo');
check('suppress: creează rând dezabonat', $repo->subscriptions($id)['stiri']['status'] === 'unsubscribed');
$repo->suppress($id, 'oferte', 'brevo');
check('suppress: trece un rând activ pe dezabonat', $repo->subscriptions($id)['oferte']['status'] === 'unsubscribed');

// --- pe surse ----------------------------------------------------------------
$b = $repo->ensureSubscriber('bogdan@nl-test.invalid', null, 'active');
$repo->addSubscription((int) $b['id'], 'oferte', 'bs_account');
$repo->addSubscription((int) $b['id'], 'stiri', 'bs_account');
check('activeEmailsBySource: o adresă o singură dată',
    $repo->activeEmailsBySource('bs_account') === ['bogdan@nl-test.invalid']);
check('unsubscribeSource: dezabonează ambele liste', $repo->unsubscribeSource((int) $b['id'], 'bs_account') === 2);
check('activeEmailsBySource: gol după dezabonare', $repo->activeEmailsBySource('bs_account') === []);

// --- totaluri și căutare -----------------------------------------------------
$c = $repo->ensureSubscriber('carmen@nl-test.invalid', 'Carmen', 'active');
$repo->addSubscription((int) $c['id'], 'stiri', 'garage');
$counts = $repo->counts();
check('counts: abonați pe stare', $counts['subscribers']['active'] === 3 && $counts['subscribers']['bounced'] === 1);
check('counts: stiri activ = 1, din garage', $counts['lists']['stiri']['active'] === 1 && $counts['lists']['stiri']['by_source']['garage'] === 1);
check('counts: oferte activ = 0', $counts['lists']['oferte']['active'] === 0);

$found = $repo->search('carmen');
check('search: găsește după fragment', count($found) === 1 && $found[0]['subs']['stiri']['source'] === 'garage');
check('search: % nu e wildcard', $repo->search('%') === []);

// --- limite de abonare -------------------------------------------------------
check('recentSignupsFromIp: numără pe IP', $repo->recentSignupsFromIp('10.0.0.1', 60) === 1);
check('recentSignupsFromIp: alt IP = 0', $repo->recentSignupsFromIp('10.0.0.2', 60) === 0);
check('confirmRecentlySent: fals înainte de trimitere', $repo->confirmRecentlySent($id, 15) === false);
$repo->markConfirmSent($id);
check('confirmRecentlySent: adevărat după trimitere', $repo->confirmRecentlySent($id, 15) === true);

nl_done();
