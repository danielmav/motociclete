<?php
declare(strict_types=1);
// Creates hero_slides (schema_hero.sql) and seeds the 4 home-page slides.
// Idempotent: only inserts when the table is empty. Run with Laragon PHP 8.1:
//   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/seed_hero.php

require __DIR__ . '/../vendor/autoload.php';
if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}
$settings = require __DIR__ . '/../config/settings.php';
$db = new App\Database($settings['db']);
$pdo = $db->local();

// 1) Schema (non-destructive)
$pdo->exec(file_get_contents(__DIR__ . '/schema_hero.sql'));

// 2) Seed only if empty (don't clobber admin edits later)
$count = (int) $pdo->query('SELECT COUNT(*) FROM hero_slides')->fetchColumn();
if ($count > 0) {
    echo "hero_slides already has {$count} rows — leaving as is.\n";
    return;
}

$slides = [
    [
        'position'   => 1,
        'kicker'     => '23 de ani pe două roți',
        'title_html' => 'Showroom-ul tău<br>Yamaha &amp; <span class="herov2__accent">CFMOTO</span>',
        'subtitle'   => 'Dealer autorizat Yamaha și CFMOTO. Showroom Pipera, București — plus tot echipamentul și piesele potrivite pentru ea, din BikerShop.',
        'cta_label'  => 'Vezi gama 2026',
        'cta_href'   => '/#modele',
        'image'      => '/media/hero/showroom.jpg',
        'image_alt'  => 'Showroom Dual Motors Pipera',
        'ghost'      => '2026',
        'stats_json' => json_encode([
            ['value' => '23', 'label' => 'ani experiență'],
            ['value' => '2',  'label' => 'branduri ca dealer'],
            ['value' => '7',  'label' => 'branduri importate'],
        ], JSON_UNESCAPED_UNICODE),
    ],
    [
        'position'   => 2,
        'kicker'     => 'Mobilitate urbană',
        'title_html' => 'Orașul, pe<br><span class="herov2__accent">scuter Yamaha</span>',
        'subtitle'   => 'De la TMAX la XMAX și NMAX — scutere Yamaha pentru o navetă rapidă, agilă și eficientă prin București.',
        'cta_label'  => 'Vezi scuterele',
        'cta_href'   => '/yamaha/scutere',
        'image'      => '/media/hero/scuter.jpg',
        'image_alt'  => 'Scuter Yamaha',
        'ghost'      => 'MAX',
        'stats_json' => null,
    ],
    [
        'position'   => 3,
        'kicker'     => 'Caracter îndrăzneț',
        'title_html' => 'Mai mult, pentru<br>mai puțin. <span class="herov2__accent">CFMOTO</span>',
        'subtitle'   => 'Naked, sport, touring și heritage — gama CFMOTO îmbină design curajos cu un raport preț-dotări greu de egalat.',
        'cta_label'  => 'Descoperă CFMOTO',
        'cta_href'   => '/cfmoto/naked',
        'image'      => '/media/hero/cfmoto.jpg',
        'image_alt'  => 'Motocicletă CFMOTO',
        'ghost'      => 'CF',
        'stats_json' => null,
    ],
    [
        'position'   => 4,
        'kicker'     => 'Accesorii originale',
        'title_html' => 'Făcute pentru<br><span class="herov2__accent">Yamaha ta</span>',
        'subtitle'   => 'Accesorii și echipament original Yamaha — fit perfect, calitate de fabrică și montaj în service-ul nostru.',
        'cta_label'  => 'Vezi accesoriile',
        'cta_href'   => '/piese',
        'image'      => '/media/hero/accesorii.jpg',
        'image_alt'  => 'Accesorii originale Yamaha',
        'ghost'      => 'OEM',
        'stats_json' => null,
    ],
];

$stmt = $pdo->prepare(
    'INSERT INTO hero_slides (position, is_active, kicker, title_html, subtitle, cta_label, cta_href, image, image_alt, ghost, stats_json)
     VALUES (:position, 1, :kicker, :title_html, :subtitle, :cta_label, :cta_href, :image, :image_alt, :ghost, :stats_json)'
);
foreach ($slides as $s) {
    $stmt->execute($s);
}
echo "Seeded " . count($slides) . " hero slides.\n";
