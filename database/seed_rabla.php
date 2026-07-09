<?php
declare(strict_types=1);
// Seeds rabla.page_html (Programul RABLA — text pentru anul curent). Diacriticele
// trec prin PDO (NU prin clientul mysql.exe → mojibake). Titlul e generat automat
// din anul curent în template, deci aici se stochează doar body-ul HTML.
// Rulează cu Laragon PHP 8.1:
//   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/seed_rabla.php

require __DIR__ . '/../vendor/autoload.php';
if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}
$settings = require __DIR__ . '/../config/settings.php';
$db = new App\Database($settings['db']);
$pdo = $db->local();

// Sursă: articolul de blog „Programul Rabla 2025 pentru solicitanții persoane fizice"
// (fără referințe la an în corp → valabil și pentru sesiunea următoare; se editează
// din admin la nevoie). Actualizează manual aici la modificarea condițiilor pe an.
$html = <<<'HTML'
<p><strong>Creare cont: <a href="https://inscrierionline.afm.ro/" target="_blank" rel="noopener">https://inscrierionline.afm.ro/</a></strong></p>

<p>După crearea contului, trebuie să urmați pașii următori:</p>

<h2>Pasul 1</h2>

<p>Înscrierea solicitantului se realizează prin încărcarea în aplicaţie a următoarelor documente:</p>

<p>a) cererea de finanţare, descărcată din aplicaţie, completată prin tehnoredactare şi, ulterior, încărcată fără a fi semnată;</p>

<p>b) actul de identitate care atestă identitatea şi domiciliul/reşedinţa în România, eliberat de către autorităţile române, în termen de valabilitate la momentul înscrierii;</p>

<p>c) certificatul de atestare fiscală privind obligaţiile de plată către bugetul de stat, emis pe numele solicitantului de către organul teritorial de specialitate al Ministerului Finanţelor (ANAF), <strong>nu mai vechi de 90 de zile</strong> la momentul înscrierii în program;</p>

<p>d) certificatul de atestare fiscală privind impozitele şi taxele locale şi alte venituri ale bugetului local (DGITL), emis pe numele solicitantului de către autoritatea publică locală în a cărei rază teritorială îşi are domiciliul/reşedinţa, <strong>nu mai vechi de 30 de zile</strong> la momentul înscrierii în program.</p>

<p>- La finalizarea procesului de înscriere în program a solicitantului, persoană fizică, aplicaţia generează automat un număr de înregistrare şi rezervă valoarea ecotichetelor solicitate până la concurenţa bugetului alocat sesiunii de înscriere.</p>

<p>- În termen de <strong>maximum 10 zile</strong> de la obţinerea numărului de înregistrare, solicitanţii sunt obligaţi să selecteze un producător validat (Dual Tours), prin intermediul aplicaţiei.</p>

<h2>Pasul 2</h2>

<p><strong>În termen de 10 zile de la obţinerea numărului de înregistrare</strong>, solicitantul completează seria de şasiu a autovehiculului uzat şi încarcă în aplicaţie următoarele documente:</p>

<p>a) certificatul de înmatriculare a autovehiculului uzat;</p>

<p>b) cartea de identitate a autovehiculului uzat;</p>

<p>c) actul doveditor eliberat de către serviciul public comunitar regim permise de conducere şi înmatriculare a vehiculelor competent teritorial, pentru cazul în care din certificatul de înmatriculare sau din cartea de identitate a autovehiculului uzat nu rezultă anul fabricaţiei, anul primei înmatriculări în România şi/sau categoria autovehiculului uzat;</p>

<p>d) declaraţia cedentului, după caz;</p>

<p>e) certificatul de atestare fiscală privind impozitele şi taxele locale şi alte venituri ale bugetului local, emis pe numele cedentului de către autoritatea publică locală în a cărei rază teritorială îşi are domiciliul, nu mai vechi de 30 de zile la momentul înscrierii în program, după caz;</p>

<h2>Pasul 3</h2>

<p>Distrugerea şi casarea <strong>— În termen de 60 de zile</strong> de la obţinerea statusului „beneficiar aprobat pentru finanţare":</p>

<p>a) beneficiarul are obligaţia să distrugă şi să radieze din evidenţa circulaţiei autovehiculul uzat;</p>

<p>b) beneficiarul are obligaţia să încarce în aplicaţie certificatul de distrugere şi certificatul de radiere şi să introducă data radierii;</p>

<p><strong>Alegerea scuterului/motocicletei dorite: <a href="https://www.motociclete.com.ro/">https://www.motociclete.com.ro/</a></strong></p>
HTML;

// Rândul id=1 e creat de schema_admin.sql (INSERT IGNORE); un simplu UPDATE evită
// placeholder-ul repetat (native prepares, emulate=false → HY093 la :h dublat).
$pdo->prepare('UPDATE rabla SET page_html = :h WHERE id = 1')
    ->execute([':h' => $html]);
echo "rabla row seeded (page_html " . strlen($html) . " bytes)\n";
