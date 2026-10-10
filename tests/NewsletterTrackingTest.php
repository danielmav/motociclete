<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterTrackingTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Links;
use App\Newsletter\Renderer;
use App\Newsletter\Tracking;

$p1 = 'https://bikershop.ro/1-casca.html?utm_source=newsletter&utm_medium=email&utm_campaign=nl-1&utm_content=produs-1';
$p2 = 'https://www.motociclete.com.ro/yamaha/motociclete?utm_source=newsletter&utm_medium=email&utm_campaign=nl-1&utm_content=antet';
$fb = 'https://www.facebook.com/dualmotors';
$html = '<a href="' . htmlspecialchars($p1) . '">Cască</a>'
    . '<a class="x" href="' . htmlspecialchars($p1) . '"><img src="https://bikershop.ro/1.jpg"></a>'
    . '<a href="' . htmlspecialchars($p2) . '">Motociclete</a>'
    . '<a href="' . $fb . '">Facebook</a>'
    . '<a href="%%UNSUB_URL%%">Dezabonare</a><a href="%%VIEW_URL%%">Vezi în browser</a>'
    . '<a href="tel:0722354437">Telefon</a><a href="mailto:info@motociclete.com.ro">Email</a>';

// --- funcțiile pure -----------------------------------------------------------
check('hrefs: linkurile http(s) distincte, decodate, în ordine', Links::hrefs($html) === [$p1, $p2, $fb]);
check('hrefs: HTML fără linkuri → listă goală', Links::hrefs('<p>nimic</p>') === []);

$map = [$p1 => 11, $p2 => 12];
$out = Links::tracked($html, $map, 'https://site.test/nl/c');
check('tracked: ambele apariții ale aceluiași link sunt rescrise',
    substr_count($out, 'href="https://site.test/nl/c/11/%%TOKEN%%"') === 2);
check('tracked: al doilea link', str_contains($out, 'href="https://site.test/nl/c/12/%%TOKEN%%"'));
check('tracked: linkul care nu e în hartă rămâne neatins', str_contains($out, 'href="' . $fb . '"'));
check('tracked: marcajele, tel: și mailto: rămân neatinse',
    str_contains($out, 'href="%%UNSUB_URL%%"') && str_contains($out, 'href="%%VIEW_URL%%"')
    && str_contains($out, 'href="tel:0722354437"') && str_contains($out, 'href="mailto:info@motociclete.com.ro"'));
check('tracked: adresa imaginii (src) nu e atinsă', str_contains($out, 'src="https://bikershop.ro/1.jpg"'));
check('tracked: hartă goală → HTML neschimbat', Links::tracked($html, [], 'x') === $html);
check('personalize: tokenul destinatarului intră în adresa de clic',
    str_contains(Renderer::personalize($out, ['TOKEN' => str_repeat('a', 32)]), '/nl/c/11/' . str_repeat('a', 32) . '"'));

// --- baza de date -------------------------------------------------------------
$pdo = nl_isolate();
$tracking = new Tracking(nl_db());
$c = nl_campaign('stiri', $html);

$links = $tracking->register($c, $html);
check('register: câte un rând pentru fiecare link distinct', count($links) === 3 && isset($links[$p1], $links[$p2], $links[$fb]));
check('register: a doua oară întoarce aceleași id-uri, fără rânduri noi',
    $tracking->register($c, $html) === $links && (int) $pdo->query('SELECT COUNT(*) FROM nl_links')->fetchColumn() === 3);
check('register: blocul vine din utm_content', $tracking->link($links[$p1])['block'] === 'produs-1' && $tracking->link($links[$fb])['block'] === null);
check('link: adresa finală', $tracking->link($links[$p2])['url'] === $p2 && (int) $tracking->link($links[$p2])['campaign_id'] === $c);
check('link: id necunoscut → null', $tracking->link(999999) === null);

$other = nl_campaign('stiri', $html);
$otherLinks = $tracking->register($other, $html);
check('register: fiecare campanie are linkurile ei', $otherLinks[$p1] !== $links[$p1]);

$a = nl_subscriber('a@nl-test.invalid');
$b = nl_subscriber('b@nl-test.invalid');
$tracking->click($links[$p1], $a);
$tracking->click($links[$p1], $a);
$tracking->click($links[$p1], $b);
$tracking->click($links[$p2], $a);
$tracking->click($otherLinks[$p1], $b);
check('uniqueClicks: abonați diferiți, pe campanie', $tracking->uniqueClicks($c) === 2 && $tracking->uniqueClicks($other) === 1);
$top = $tracking->topLinks($c);
check('topLinks: cele mai accesate primele, cu abonați unici și total',
    count($top) === 2 && $top[0]['url'] === $p1 && (int) $top[0]['clicks'] === 2 && (int) $top[0]['total'] === 3
    && $top[1]['url'] === $p2 && (int) $top[1]['clicks'] === 1);

nl_done();
