<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterContentTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Content;

$catalog = [
    'yamaha/r7-2026' => ['name' => 'R7', 'price' => 10500, 'old_price' => 10900, 'cover' => '/media/yamaha/cover/r7.jpg',
        'excerpt' => '', 'description' => '<p>Noul R7 este aici.</p>', 'url' => '/yamaha/motociclete/supersport/r7-2026', 'is_active' => 1],
    'yamaha/mt-07-2026' => ['name' => 'MT-07', 'price' => 8990, 'old_price' => null, 'cover' => '/media/yamaha/cover/mt07.jpg',
        'excerpt' => 'Naked de referință.', 'description' => '', 'url' => '/yamaha/motociclete/hyper-naked/mt-07-2026', 'is_active' => 1],
    'cfmoto/450mt-2026' => ['name' => '450MT', 'price' => 0, 'old_price' => null, 'cover' => '',
        'excerpt' => 'Adventure.', 'description' => '', 'url' => '/cfmoto/adventure/450mt-2026', 'is_active' => 0],
];
$shop = [
    722786 => ['id' => 722786, 'name' => 'Jacheta Dainese Tempest 4', 'image' => 'https://bikershop.ro/1-large_default/jacheta.jpg',
        'price' => 1268.0, 'price_old' => 1585.0, 'reduction_pct' => 20, 'url' => 'https://bikershop.ro/722786-jacheta.html'],
    20771 => ['id' => 20771, 'name' => 'Geaca Brera', 'image' => 'https://bikershop.ro/2-large_default/geaca.jpg',
        'price' => 2200.0, 'price_old' => null, 'reduction_pct' => null, 'url' => 'https://bikershop.ro/20771-geaca.html'],
];
$asked = [];
$content = new Content(
    static fn (string $brand, string $slug): ?array => $catalog["$brand/$slug"] ?? null,
    static function (array $specs) use ($shop, &$asked): array {
        $asked = $specs;
        $out = [];
        foreach ($specs as $s) {
            if (isset($shop[$s['id']])) {
                $out[$s['id']] = $shop[$s['id']];
            }
        }
        return $out;
    },
    'https://www.motociclete.com.ro'
);

$throws = static function (callable $fn): string {
    try {
        $fn();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
    return '';
};

// --- productSpec(): id + variantă din URL ------------------------------------
check('URL cu variantă → id și attr',
    Content::productSpec('https://bikershop.ro/jachete/722786-79528-jacheta-dainese.html') + ['x' => 1]
    === ['url' => 'https://bikershop.ro/jachete/722786-79528-jacheta-dainese.html', 'id' => 722786, 'attr' => 79528, 'x' => 1]);
$plain = Content::productSpec('https://bikershop.ro/27821-casca-agv.html');
check('URL fără variantă → doar id', $plain['id'] === 27821 && !isset($plain['attr']));
check('ID simplu', Content::productSpec(' 20771 ')['id'] === 20771);
check('URL fără id → excepție', $throws(fn () => Content::productSpec('https://bikershop.ro/765-integrale')) !== '');

// --- modele ------------------------------------------------------------------
$m = $content->models(['https://www.motociclete.com.ro/yamaha/motociclete/supersport/r7-2026?utm=x', 'mt-07-2026']);
check('model cu reducere: preț nou + preț vechi', $m[0]['price'] === '10.500 €' && $m[0]['price_old'] === '10.900 €');
check('model fără reducere: fără preț vechi', $m[1]['price'] === '8.990 €' && $m[1]['price_old'] === null);
check('descriere din excerpt sau din descriere', $m[0]['desc'] === 'Noul R7 este aici.' && $m[1]['desc'] === 'Naked de referință.');
check('imagine și link absolute',
    $m[0]['image'] === 'https://www.motociclete.com.ro/media/yamaha/cover/r7.jpg'
    && $m[0]['url'] === 'https://www.motociclete.com.ro/yamaha/motociclete/supersport/r7-2026');
check('slug simplu → brandul implicit yamaha', $m[1]['name'] === 'MT-07');

$content->models([['url' => '/cfmoto/adventure/450mt-2026']]);
$poa = $content->models([['url' => '/cfmoto/adventure/450mt-2026']])[0];
check('model fără preț → „Preț la cerere"', $poa['price'] === 'Preț la cerere' && $poa['price_old'] === null);
check('model inactiv și fără copertă → două avertismente', count($content->warnings()) >= 2);
check('model necunoscut → excepție care îl numește',
    str_contains($throws(fn () => $content->models(['yamaha/nu-exista'])), 'yamaha/nu-exista'));
$over = $content->models([['slug' => 'r7-2026', 'nume' => 'R7 2026', 'pret' => '9.999 €', 'pret_vechi' => '10.900 €']])[0];
check('câmpurile completate manual au prioritate', $over['name'] === 'R7 2026' && $over['price'] === '9.999 €' && $over['price_old'] === '10.900 €');

// --- produse -----------------------------------------------------------------
$p = $content->products(['https://bikershop.ro/jachete/722786-79528-jacheta.html', '20771']);
check('varianta din URL ajunge la căutare', $asked[0] === ['id' => 722786, 'attr' => 79528] && $asked[1] === ['id' => 20771, 'attr' => null]);
check('produs cu reducere: preț nou, vechi, procent',
    $p[0]['price'] === '1.268 lei' && $p[0]['price_old'] === '1.585 lei' && $p[0]['pct'] === 20);
check('produs fără reducere: fără preț vechi', $p[1]['price'] === '2.200 lei' && $p[1]['price_old'] === null && $p[1]['pct'] === null);
check('linkul lipit (cu varianta) e păstrat', $p[0]['url'] === 'https://bikershop.ro/jachete/722786-79528-jacheta.html');
check('fără link lipit → linkul din magazin', $p[1]['url'] === 'https://bikershop.ro/20771-geaca.html');

$man = $content->products([['id' => 20771, 'pret' => '1999', 'pret_vechi' => '2200']])[0];
check('preț manual + preț vechi manual → procent calculat', $man['price'] === '1.999 lei' && $man['price_old'] === '2.200 lei' && $man['pct'] === 9);
$man2 = $content->products([['id' => 722786, 'pret' => '1500']])[0];
check('doar preț manual → fără preț vechi din magazin', $man2['price'] === '1.500 lei' && $man2['price_old'] === null);

// Review Focus 5: produs inactiv / BikerShop indisponibil
$msg = $throws(fn () => $content->products(['https://bikershop.ro/99999-produs-disparut.html']));
check('produs negăsit și fără date manuale → excepție care îl numește', str_contains($msg, '99999'));
$manual = $content->products([['id' => 99999, 'nume' => 'Produs manual', 'imagine' => 'https://x.test/a.jpg', 'link' => 'https://bikershop.ro/x', 'pret' => '100']])[0];
check('produs negăsit dar completat manual → acceptat cu avertisment',
    $manual['name'] === 'Produs manual' && str_contains(implode(' ', $content->warnings()), '99999'));

// --- resolve() ---------------------------------------------------------------
$base = [
    'subiect' => 'Noutăți de toamnă', 'preheader' => 'Casca AGV K5',
    'stire' => ['titlu_html' => 'Noua AGV K5', 'imagine' => '/media/newsletter/k5.jpg', 'link' => 'https://bikershop.ro/765-integrale',
        'buton' => '', 'paragrafe' => ['Primul paragraf.', '<p>Al <b>doilea</b>.</p>']],
    'modele' => ['r7-2026', 'mt-07-2026'],
    'produse' => [722786, 20771, 722786, 20771, 722786, 20771],
];
$c = $content->resolve('stiri', $base);
check('stiri: 2 modele + 6 produse', count($c['models']) === 2 && count($c['products']) === 6 && $c['type'] === 'stiri');
check('imaginea știrii devine absolută', $c['news']['image'] === 'https://www.motociclete.com.ro/media/newsletter/k5.jpg');
check('butonul gol → „Detalii"', $c['news']['button'] === 'Detalii');
check('paragrafele devin HTML', $c['news']['body_html'] === '<p>Primul paragraf.</p><p>Al <b>doilea</b>.</p>');
check('subiect și preheader', $c['subject'] === 'Noutăți de toamnă' && $c['preheader'] === 'Casca AGV K5');

check('stiri fără subiect → excepție', $throws(fn () => $content->resolve('stiri', ['subiect' => ' '] + $base)) !== '');
check('stiri cu 5 produse → excepție', str_contains($throws(fn () => $content->resolve('stiri', ['produse' => [1, 2, 3, 4, 5]] + $base)), '6'));
check('stiri cu 1 model → excepție', str_contains($throws(fn () => $content->resolve('stiri', ['modele' => ['r7-2026']] + $base)), '2'));
check('stiri fără imagine → excepție',
    $throws(fn () => $content->resolve('stiri', ['stire' => ['imagine' => ''] + $base['stire']] + $base)) !== '');
check('tip necunoscut → excepție', $throws(fn () => $content->resolve('altceva', $base)) !== '');

$of = $content->resolve('oferte', ['subiect' => 'Oferte', 'stire' => ['titlu_html' => 'Reduceri la echipament'], 'produse' => [722786, 20771]]);
check('oferte: fără modele, link implicit spre magazin, imagine goală',
    $of['models'] === [] && count($of['products']) === 2 && $of['news']['link'] === 'https://bikershop.ro/' && $of['news']['image'] === '');
check('oferte cu un singur produs → excepție', $throws(fn () => $content->resolve('oferte', ['subiect' => 'x', 'stire' => ['titlu_html' => 'y'], 'produse' => [722786]])) !== '');

// --- utilitare ---------------------------------------------------------------
check('splitParagraphs: linie goală = paragraf nou', Content::splitParagraphs("Unu\ndoi\n\nTrei") === ["Unu\ndoi", 'Trei']);
check('excerpt: taie la propoziție', Content::excerpt('<p>Prima propoziție. A doua este mult mai lungă decât limita.</p>', 30) === 'Prima propoziție.');

nl_done();
