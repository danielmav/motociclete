<?php
declare(strict_types=1);
// Seeds the "Service" page: description + note (settings keys) and the
// structured price list (service_prices). Idempotent. UTF-8 file.
// Run with Laragon PHP 8.1:
//   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/seed_service.php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_dbutil.php';
if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}
$settings = require __DIR__ . '/../config/settings.php';
$pdo = (new App\Database($settings['db']))->local();
run_sql_file($pdo, __DIR__ . '/schema_pages.sql');

// --- Description + note (settings keys) -------------------------------------
$desc = <<<HTML
<p>DUAL MOTORS deţine <strong>service autorizat RAR</strong> şi efectuează toate tipurile de lucrări în domeniul moto, ATV, scuter, snowmobil, indiferent de marcă sau model:</p>
<ul>
<li>schimburi ulei / filtre</li>
<li>schimburi consumabile: cauciucuri, plăcuţe frână, kituri lanţ, etc.</li>
<li>reparaţii amortizoare faţă: schimburi semeringuri-ulei / kit-uri, cuzineţi translaţie / kit arc</li>
<li>verificări şi reparaţii instalaţii electrice</li>
<li>verificări geometrie cadre moto</li>
<li>vopsitorie</li>
<li>setări suspensie moto</li>
<li>reparaţii motoare moto</li>
<li>sincronizat carburatoare</li>
<li>reglaje cu teste profesionale</li>
<li>testări motor</li>
<li>tuning (power commander, dynojet, evacuări, suspensii, personalizări, scăriţe, amortizoare direcţie, etc.)</li>
<li>recondiţionări moto, indiferent de marcă şi model</li>
<li>revizii autorizate Yamaha</li>
<li>constatări şi întocmit documentaţie pentru societăţile de asigurări</li>
</ul>
<p>Dual Motors înseamnă peste 26 de ani de experienţă, în mod oficial — pentru că pasiunea şi experienţa noastră în domeniul moto datează cu mult înainte de 2003!</p>
HTML;
$note = <<<HTML
<p><strong>Pachet revizie Yamaha RayZR: 300 lei</strong></p>
<p><strong>Pachet revizie Yamaha XMax / NMax 125: 400 lei</strong></p>
HTML;
$set = $pdo->prepare("INSERT IGNORE INTO settings (skey, svalue) VALUES (:k, :v)");
$set->execute([':k' => 'service_heading', ':v' => 'Nu lăsați motocicleta pe mâna oricui!']);
$set->execute([':k' => 'service_desc_html', ':v' => $desc]);
$set->execute([':k' => 'service_note_html', ':v' => $note]);
echo "service text ensured\n";

// --- Price list -------------------------------------------------------------
$prices = [
    // group, label, price
    ['Motociclete (TVA inclus)', 'Revizie periodică', '2 h / 600 lei'],
    ['Motociclete (TVA inclus)', 'Curăţat / gresat / reglat lanţ', '60 lei'],
    ['Motociclete (TVA inclus)', 'Înlocuit anvelopă + echilibrat / roata faţă', '100 lei'],
    ['Motociclete (TVA inclus)', 'Înlocuit anvelopă + echilibrat / roata spate', '200 lei'],
    ['Motociclete (TVA inclus)', 'Sincronizare 1 – 3 h', '300 – 900 lei'],
    ['Motociclete (TVA inclus)', 'Înlocuit kit lanţ', '400 – 550 lei'],
    ['Motociclete (TVA inclus)', 'ITP — sub 1000 cmc', '250 lei'],
    ['Motociclete (TVA inclus)', 'ITP — peste 1000 cmc', '280 lei'],
    ['Motociclete (TVA inclus)', 'Verificare motocicletă în vederea vânzării / cumpărării', '1 h / 300 lei'],
    ['Motociclete (TVA inclus)', 'Deviz pentru asigurări', 'de la 350 lei'],

    ['Scutere până în 400 cmc (TVA inclus)', 'Revizie periodică', '1 h / 250 lei'],
    ['Scutere până în 400 cmc (TVA inclus)', 'Curăţat / gresat filtrul de aer', '80 lei'],
    ['Scutere până în 400 cmc (TVA inclus)', 'Înlocuit anvelopă + echilibrat / roata faţă', '100 lei'],
    ['Scutere până în 400 cmc (TVA inclus)', 'Înlocuit anvelopă + echilibrat / roata spate', '200 lei'],
    ['Scutere până în 400 cmc (TVA inclus)', 'ITP', '250 lei'],
    ['Scutere până în 400 cmc (TVA inclus)', 'Verificare motocicletă în vederea vânzării / cumpărării', '1 h / 300 lei'],
    ['Scutere până în 400 cmc (TVA inclus)', 'Deviz pentru asigurări', 'de la 350 lei'],
];
if ((int) $pdo->query('SELECT COUNT(*) FROM service_prices')->fetchColumn() === 0) {
    $st = $pdo->prepare('INSERT INTO service_prices (group_label, label, price, position) VALUES (?, ?, ?, ?)');
    foreach ($prices as $i => $p) {
        $st->execute([$p[0], $p[1], $p[2], $i]);
    }
    echo count($prices) . " price rows seeded\n";
}

echo "seed_service: done.\n";
