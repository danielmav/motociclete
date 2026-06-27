<?php
declare(strict_types=1);
// Seeds default contact departments + legal/about pages + social placeholders.
// Idempotent. Run with Laragon PHP 8.1:
//   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/seed_settings.php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_dbutil.php';
if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}
$settings = require __DIR__ . '/../config/settings.php';
$pdo = (new App\Database($settings['db']))->local();
run_sql_file($pdo, __DIR__ . '/schema_admin.sql');

// Departments
$depts = [
    ['Vânzări moto', 'vanzari@motociclete.com.ro', '0722 354 437', 1],
    ['Echipamente & accesorii', 'accesorii@motociclete.com.ro', '0722 354 438', 2],
    ['Service', 'service@motociclete.com.ro', '0722 354 439', 3],
    ['Contabilitate', 'contabilitate@motociclete.com.ro', '', 4],
];
if ((int) $pdo->query('SELECT COUNT(*) FROM contact_departments')->fetchColumn() === 0) {
    $st = $pdo->prepare('INSERT INTO contact_departments (label, email, phone, position) VALUES (?,?,?,?)');
    foreach ($depts as $d) {
        $st->execute($d);
    }
    echo "departments seeded\n";
}

// Legal / about pages (placeholders)
$pages = [
    ['termeni-si-conditii', 'Termeni și condiții', '<p>Conținutul termenilor și condițiilor va fi completat din administrare.</p>'],
    ['confidentialitate', 'Politica de confidențialitate', '<p>Politica de confidențialitate va fi completată din administrare.</p>'],
    ['politica-cookies', 'Politica de cookie-uri', '<h2>Ce sunt cookie-urile</h2>
<p>Cookie-urile sunt fișiere text mici stocate pe dispozitivul tău. Le folosim pentru funcționarea site-ului și, cu acordul tău, pentru analiză.</p>
<h2>Cookie-uri pe care le folosim</h2>
<table>
<thead><tr><th>Cookie</th><th>Categorie</th><th>Scop</th><th>Durată</th></tr></thead>
<tbody>
<tr><td>dm_garage</td><td>Necesar</td><td>Sesiunea de autentificare „Garajul meu"</td><td>Sesiune</td></tr>
<tr><td>dm_consent</td><td>Necesar</td><td>Reține preferința ta privind cookie-urile</td><td>12 luni</td></tr>
<tr><td>__cf_bm</td><td>Necesar</td><td>Securitate / anti-bot (Cloudflare)</td><td>30 min</td></tr>
<tr><td>_ga, _ga_*</td><td>Analitic</td><td>Google Analytics 4 — statistici anonime de utilizare</td><td>până la 13 luni</td></tr>
</tbody>
</table>
<h2>Gestionarea consimțământului</h2>
<p>Îți poți schimba oricând alegerea din linkul „Setări cookie-uri" din subsolul site-ului. Cookie-urile analitice se activează doar după acordul tău explicit.</p>
<p>Vezi și <a href="/confidentialitate">Politica de confidențialitate</a>.</p>'],
    ['despre', 'Despre noi', '<p>Dual Motors — dealer autorizat Yamaha și CFMOTO, showroom Pipera, București.</p>'],
];
$st = $pdo->prepare('INSERT IGNORE INTO pages (slug, title, body_html, is_active) VALUES (?,?,?,1)');
foreach ($pages as $p) {
    $st->execute($p);
}
echo "pages ensured\n";

echo "seed_settings: done.\n";
