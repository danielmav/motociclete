<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterRendererTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Renderer;

$renderer = new Renderer(dirname(__DIR__) . '/templates/email/newsletter', 'https://www.motociclete.com.ro');
$brand = ['address' => 'Șoseaua Pipera 48, București', 'schedule' => 'Luni – Vineri: 09.30 - 18.00',
    'departments' => [['label' => 'Vânzări moto', 'phone' => '0722 354 437'], ['label' => 'Service', 'phone' => '0724 371 365']]];

$product = static fn (int $i, ?string $old = null, ?int $pct = null, string $name = ''): array => [
    'id' => $i, 'name' => $name !== '' ? $name : "Produs {$i}", 'image' => "https://www.motociclete.com.ro/media/newsletter/bs/p{$i}.jpg",
    'price' => (1000 + $i) . ' lei', 'price_old' => $old, 'pct' => $pct,
    'url' => "https://bikershop.ro/{$i}-produs-{$i}.html", 'price_html' => null,
];
$stiri = [
    'type' => 'stiri', 'subject' => 'Noua AGV K5', 'preheader' => 'Spirit sportiv și protecție',
    'news' => ['title_html' => 'Noua <em>AGV K5</em>', 'image' => 'https://www.motociclete.com.ro/media/newsletter/k5.jpg',
        'link' => 'https://bikershop.ro/765-integrale', 'button' => 'Vezi căștile', 'body_html' => '<p>Text <b>știre</b>.</p>'],
    'models' => [
        ['name' => 'R7', 'image' => 'https://www.motociclete.com.ro/media/yamaha/cover/r7.jpg', 'price' => '10.500 €', 'price_old' => '10.900 €',
            'desc' => 'Noul R7 este aici.', 'url' => 'https://www.motociclete.com.ro/yamaha/motociclete/supersport/r7-2026'],
        ['name' => 'Ténéré 700 "Rally" <2026>', 'image' => 'https://www.motociclete.com.ro/media/yamaha/cover/t7.jpg', 'price' => 'Preț la cerere', 'price_old' => null,
            'desc' => 'Tom & Jerry aprobă.', 'url' => 'https://www.motociclete.com.ro/yamaha/motociclete/adventure/tenere-700'],
    ],
    'products' => [
        $product(1, '1.585 lei', 20, 'Jacheta <b>Dainese</b> & "Tempest"'), $product(2), $product(3), $product(4), $product(5), $product(6, '2.100 lei', 15),
    ],
];

$out  = $renderer->render($stiri, 'nl-7-agv-k5', $brand);
$html = $out['html'];

// --- structură ---------------------------------------------------------------
check('document HTML complet', str_starts_with(ltrim($html), '<!doctype html') && str_contains($html, '</html>'));
check('lățime 600 px și culoarea de brand', str_contains($html, 'max-width:600px') && str_contains($html, '#e3000f'));
check('nu rămân marcaje Twig', !str_contains($html, '{{') && !str_contains($html, '{%'));
check('subiectul e titlul documentului, preheaderul e ascuns',
    str_contains($html, '<title>Noua AGV K5</title>') && str_contains($html, 'Spirit sportiv și protecție'));
check('titlul știrii păstrează HTML-ul din admin', str_contains($html, 'Noua <em>AGV K5</em>'));
check('corpul știrii păstrează HTML-ul din admin', str_contains($html, '<p>Text <b>știre</b>.</p>'));
check('butonul știrii', str_contains($html, 'Vezi căștile'));
check('blocurile fixe apar la știri', str_contains($html, 'desenele tehnice') && str_contains($html, '23 ani de Dual Motors'));
check('imaginile fixe vin de pe situl nostru', str_contains($html, 'https://www.motociclete.com.ro/assets/img/newsletter/logo.png'));
check('datele de contact din setări', str_contains($html, 'Șoseaua Pipera 48') && str_contains($html, '0724 371 365'));

// --- prețuri -----------------------------------------------------------------
check('model cu reducere: ambele prețuri, cel vechi tăiat',
    str_contains($html, '10.500 €') && (bool) preg_match('~line-through[^>]*>\s*10\.900 €~', $html));
check('produs cu reducere: preț vechi tăiat și procent',
    (bool) preg_match('~line-through[^>]*>\s*1\.585 lei~', $html) && str_contains($html, '−20%'));
check('doar produsele cu reducere au preț tăiat', substr_count($html, 'line-through') === 3);
check('modelul fără preț afișează „Preț la cerere"', str_contains($html, 'Preț la cerere'));

// --- escapare (Review Focus 3) -----------------------------------------------
check('numele de produs cu HTML e escapat',
    str_contains($html, 'Jacheta &lt;b&gt;Dainese&lt;/b&gt; &amp; &quot;Tempest&quot;') && !str_contains($html, 'Jacheta <b>Dainese</b>'));
check('numele de model cu HTML e escapat', str_contains($html, 'Ténéré 700 &quot;Rally&quot; &lt;2026&gt;'));
check('descrierea modelului e escapată', str_contains($html, 'Tom &amp; Jerry aprobă.'));

// --- linkuri -----------------------------------------------------------------
check('linkurile produselor au UTM cu poziția',
    str_contains($html, 'https://bikershop.ro/1-produs-1.html?utm_source=newsletter&amp;utm_medium=email&amp;utm_campaign=nl-7-agv-k5&amp;utm_content=produs-1')
    && str_contains($html, 'utm_content=produs-6'));
check('linkurile modelelor au UTM', str_contains($html, 'utm_content=model-1') && str_contains($html, 'utm_content=model-2'));
check('linkul știrii are UTM', str_contains($html, 'https://bikershop.ro/765-integrale?utm_source=newsletter') && str_contains($html, 'utm_content=stire'));
check('toate cele 6 produse apar', substr_count($html, 'utm_content=produs-') >= 6 && str_contains($html, 'Produs 4'));

// --- personalizare -----------------------------------------------------------
check('marcajele de personalizare sunt în HTML',
    str_contains($html, '%%UNSUB_URL%%') && str_contains($html, '%%PREFS_URL%%') && str_contains($html, '%%VIEW_URL%%') && str_contains($html, '%%EMAIL%%'));
$p = Renderer::personalize($html, ['UNSUB_URL' => 'https://x.test/u?a=1&b=2', 'PREFS_URL' => 'https://x.test/p', 'VIEW_URL' => 'https://x.test/v', 'EMAIL' => 'ion@nl-test.invalid']);
check('personalize: înlocuiește toate marcajele', !str_contains($p, '%%') && str_contains($p, 'ion@nl-test.invalid'));
check('personalize: valorile sunt escapate în HTML', str_contains($p, 'https://x.test/u?a=1&amp;b=2'));
check('personalize pe text: fără escapare',
    Renderer::personalize('Dezabonare: %%UNSUB_URL%%', ['UNSUB_URL' => 'https://x.test/u?a=1&b=2'], false) === 'Dezabonare: https://x.test/u?a=1&b=2');
$public = Renderer::stripPersonal($html);
check('stripPersonal: scoate adresa și dezabonarea, păstrează restul',
    !str_contains($public, '%%EMAIL%%') && !str_contains($public, '%%UNSUB_URL%%') && str_contains($public, 'Produs 4') && str_contains($public, '</html>'));

// --- varianta text -----------------------------------------------------------
$text = $out['text'];
check('text: titlu fără HTML, corp, modele și produse',
    str_contains($text, 'Noua AGV K5') && !str_contains($text, '<em>') && !str_contains($text, '<p>') && str_contains($text, 'R7 — 10.500 €') && str_contains($text, 'Produs 4'));
check('text: prețul vechi e menționat', str_contains($text, '1.585 lei'));
check('text: linkuri cu UTM și marcaje de dezabonare',
    str_contains($text, 'https://bikershop.ro/1-produs-1.html?utm_source=newsletter') && str_contains($text, '%%UNSUB_URL%%'));

// --- oferte ------------------------------------------------------------------
$oferte = ['type' => 'oferte', 'subject' => 'Reduceri de toamnă', 'preheader' => '',
    'news' => ['title_html' => 'Reduceri la echipament', 'image' => '', 'link' => 'https://bikershop.ro/', 'button' => 'Vezi toate ofertele',
        'body_html' => '<p>Până la −20%.</p>'],
    'models' => [], 'products' => [$product(1, '1.585 lei', 20), $product(2), $product(3)]];
$oh = $renderer->render($oferte, 'nl-8-reduceri', $brand)['html'];
check('oferte: titlu, intro, produse și buton spre magazin',
    str_contains($oh, 'Reduceri la echipament') && str_contains($oh, 'Până la −20%.') && str_contains($oh, 'Produs 3') && str_contains($oh, 'Vezi toate ofertele'));
check('oferte: fără blocurile fixe de la știri și fără imagine goală',
    !str_contains($oh, '23 ani de Dual Motors') && !str_contains($oh, 'src=""'));
check('oferte: număr impar de produse nu strică tabelul', substr_count($oh, '<tr') === substr_count($oh, '</tr>'));
check('oferte: UTM cu campania ei', str_contains($oh, 'utm_campaign=nl-8-reduceri'));

nl_done();
