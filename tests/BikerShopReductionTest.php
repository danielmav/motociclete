<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/BikerShopReductionTest.php
 */

require __DIR__ . '/_nl.php';

use App\BikerShop\Reduction;

$now = strtotime('2026-10-07 12:00:00');
$row = static fn (array $over = []): array => $over + [
    'id_specific_price' => 1, 'id_shop' => 0, 'price' => '-1.000000', 'reduction' => '0.200000',
    'reduction_type' => 'percentage', 'from' => '0000-00-00 00:00:00', 'to' => '0000-00-00 00:00:00',
];

// --- pick(): ce rânduri sunt valabile ----------------------------------------
check('fără rânduri → null', Reduction::pick([], 1, $now) === null);
check('reducere nelimitată în timp → fracția', Reduction::pick([$row()], 1, $now) === 0.2);
check('reducere expirată → null', Reduction::pick([$row(['to' => '2026-05-31 23:59:59'])], 1, $now) === null);
check('reducere viitoare → null', Reduction::pick([$row(['from' => '2026-11-01 00:00:00'])], 1, $now) === null);
check('reducere în fereastră → fracția',
    Reduction::pick([$row(['from' => '2026-10-01 00:00:00', 'to' => '2026-10-31 23:59:59'])], 1, $now) === 0.2);
check('reducere în sumă fixă → ignorată', Reduction::pick([$row(['reduction_type' => 'amount', 'reduction' => '50'])], 1, $now) === null);
check('preț fix → ignorat', Reduction::pick([$row(['price' => '999.000000'])], 1, $now) === null);
check('alt magazin → ignorat', Reduction::pick([$row(['id_shop' => 2])], 1, $now) === null);
check('reducere 0 sau 100% → ignorată',
    Reduction::pick([$row(['reduction' => '0']), $row(['reduction' => '1.000000'])], 1, $now) === null);

// --- pick(): care câștigă -----------------------------------------------------
check('rândul magazinului bate rândul global',
    Reduction::pick([$row(['id_specific_price' => 9, 'reduction' => '0.10']), $row(['id_specific_price' => 2, 'id_shop' => 1, 'reduction' => '0.15'])], 1, $now) === 0.15);
check('la egalitate câștigă cel mai nou (id mai mare)',
    Reduction::pick([$row(['id_specific_price' => 5, 'reduction' => '0.07']), $row(['id_specific_price' => 8, 'reduction' => '0.20'])], 1, $now) === 0.2);
check('un rând expirat mai nou nu ascunde unul valabil',
    Reduction::pick([$row(['id_specific_price' => 5]), $row(['id_specific_price' => 8, 'reduction' => '0.50', 'to' => '2026-01-30 23:59:59'])], 1, $now) === 0.2);

// --- forProduct(): ținta (Review Focus 2) ------------------------------------
$byAttr = [
    0     => [$row(['reduction' => '0.05'])],
    79528 => [$row(['reduction' => '0.20'])],
    79529 => [$row(['reduction' => '0.30', 'to' => '2026-01-01 00:00:00'])], // expirată
];
check('varianta cerută are reducere → a ei', Reduction::forProduct($byAttr, 79528, 79529, 1, $now) === 0.2);
check('varianta cerută are doar reducere expirată → nivelul de produs', Reduction::forProduct($byAttr, 79529, 79528, 1, $now) === 0.05);
check('varianta cerută nu există → nivelul de produs', Reduction::forProduct($byAttr, 11111, 79528, 1, $now) === 0.05);
check('fără variantă cerută → nivelul de produs', Reduction::forProduct($byAttr, null, 79528, 1, $now) === 0.05);
check('fără variantă cerută și fără nivel de produs → varianta implicită',
    Reduction::forProduct([79528 => $byAttr[79528]], null, 79528, 1, $now) === 0.2);
check('varianta cerută fără reducere NU împrumută de la varianta implicită',
    Reduction::forProduct([79528 => $byAttr[79528]], 11111, 79528, 1, $now) === null);

// --- apply() -----------------------------------------------------------------
check('fără reducere: prețul rămâne, restul null',
    Reduction::apply(1585.0, null) === ['price' => 1585.0, 'price_old' => null, 'reduction_pct' => null]);
check('20% din 1585 → 1268, cu preț vechi și procent',
    Reduction::apply(1585.0, 0.2) === ['price' => 1268.0, 'price_old' => 1585.0, 'reduction_pct' => 20]);
$r = Reduction::apply(2339.99, 0.04658);
check('4,658% din 2339,99 → 2230,99 și procent rotunjit la 5', $r['price'] === 2230.99 && $r['reduction_pct'] === 5);

nl_done();
