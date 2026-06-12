<?php
declare(strict_types=1);
// Seeds finance.page_title + page_html (UniCredit conditions). Run with Laragon PHP 8.1.
// Usage: C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/seed_finance.php

require __DIR__ . '/../vendor/autoload.php';
if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}
$settings = require __DIR__ . '/../config/settings.php';
$db = new App\Database($settings['db']);
$pdo = $db->local();

$title = 'Finanțare prin UniCredit Consumer Financing';

$html = <<<'HTML'
<h2>Credit Partener 100% Online</h2>
<ol>
  <li>Selectezi numărul de rate dorit și oferta de Credit Partener 100% Online.</li>
  <li>Vei fi direcționat către platforma UniCredit Consumer Financing pentru identificare online.</li>
  <li>Completezi datele necesare analizei și semnezi documentele la distanță, digital, cu semnătură electronică calificată.</li>
  <li>În cazul aprobării creditului, bunurile selectate îți vor fi livrate la adresa din România indicată.</li>
</ol>
<p>Totul online, fără drumuri la bancă!</p>

<h3>Care sunt documentele necesare?</h3>
<ul>
  <li>Carte de identitate, în original</li>
  <li>Adresă de e-mail</li>
  <li>Număr de telefon</li>
</ul>

<h3>Cine poate solicita creditul?</h3>
<p>Poți solicita un credit online acordat de UniCredit Consumer Financing dacă:</p>
<ul>
  <li>ai între 18 și 75 de ani (vârsta până la care creditul trebuie să fie rambursat în întregime);</li>
  <li>ești cetățean român, născut și rezident în România;</li>
  <li>veniturile tale pot fi interogate în baza de date ANAF*.</li>
</ul>
<p>Se iau în considerare următoarele surse de venit: venituri din salarii; venituri din pensii.</p>
<p><small>*Aplicabil pentru categoriile de venituri ce pot fi interogate în bazele de date ale ANAF, în baza exprimării acordului de consultare și prelucrare a informațiilor.</small></p>

<h3>Detalii privind finanțarea</h3>
<table>
  <tr><th>Produs financiar</th><td>Low DAE 1 60M (1.000 &gt; 120.000 Lei)</td></tr>
  <tr><th>Dobândă anuală (fixă)</th><td>13%</td></tr>
  <tr><th>Comision de analiză dosar</th><td>0 Lei (fără comision)</td></tr>
  <tr><th>Comision lunar de administrare credit</th><td>10 Lei</td></tr>
  <tr><th>Perioada de creditare</th><td>12 → 60 luni</td></tr>
  <tr><th>DAE</th><td>14,5%</td></tr>
</table>
<p>Ai răspuns pe loc**, dacă sunt îndeplinite condițiile de eligibilitate potrivit normelor interne și documentația de credit este completă.</p>
<p><small>**Fac excepție situațiile în care decizia de creditare nu poate fi luată pe loc din motive independente de voința UniCredit Consumer Financing IFN S.A. sau situațiile în care este necesară o analiză suplimentară a cererii. Creditorul are dreptul de a analiza și de a aproba sau respinge solicitarea de acordare a creditului de consum, în conformitate cu normele interne și reglementările legale.</small></p>

<h3>Unde și cum plătești ratele?</h3>
<p>Achiți ratele aferente Creditului Partener 100% Online: în orice sucursală UniCredit Bank S.A., online prin Online Banking sau Mobile Banking (dacă ai contractate aceste servicii de la UniCredit Bank S.A.), precum și în locațiile semnalizate cu sigla PayPoint și SelfPay.</p>

<h3>Contact UniCredit Consumer Financing</h3>
<p>E-mail: support-online@unicredit.ro · Telefon: 021.200.97.11 (apel cu tarif normal în rețeaua fixă Orange România Communications) · Program: Luni–Vineri, 09:00–21:00.</p>

<p><small>UNICREDIT CONSUMER FINANCING IFN S.A., societate administrată în sistem dualist, înregistrată la Registrul Comerțului sub nr. J40/13865/14.08.2008, CUI 24332910, înscrisă în Registrul General al Băncii Naționale a României sub numărul RG-PJR-41-110247/24.10.2008, Registrul Special sub numărul RS-PJR-41-110065/09.02.2010 și în Registrul Instituțiilor de Plată sub numărul IP-RO-0009/02.03.2015, cu sediul în București, sector 1, Bulevardul Expoziției nr. 1F, etaj 6, capital social subscris și vărsat: 103.269.200 Lei, tel. +40 21 200 2020.</small></p>

<p><small>Acest calculator este orientativ. Valoarea exactă a ratei și aprobarea creditului depind de analiza UniCredit Consumer Financing IFN S.A.</small></p>
HTML;

$stmt = $pdo->prepare('UPDATE finance SET page_title = :t, page_html = :h WHERE id = 1');
$stmt->execute([':t' => $title, ':h' => $html]);
echo "finance row seeded (page_html " . strlen($html) . " bytes)\n";
