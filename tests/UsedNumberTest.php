<?php

declare(strict_types=1);

require __DIR__ . '/_nl.php';

use App\Admin\UsedController;

// Numerele din formularul de anunț: punctul e separator de mii DOAR în grupuri
// de câte trei cifre („6.500"); altfel e separator zecimal („6500.50").
$cases = [
    '6500'      => 6500.0,
    '6.500'     => 6500.0,
    '12.400'    => 12400.0,
    '1.234.567' => 1234567.0,
    '6 500'     => 6500.0,
    '6500,50'   => 6500.5,
    '6.500,50'  => 6500.5,
    '6500.50'   => 6500.5,
    '6500.00'   => 6500.0,
    '12400.5'   => 12400.5,
    '0'         => 0.0,
];
foreach ($cases as $in => $expected) {
    check("„{$in}” → {$expected}", UsedController::number((string) $in) === $expected);
}
check('gol → null', UsedController::number('') === null && UsedController::number('   ') === null);
check('text → invalid (-1)', UsedController::number('abc') === -1.0);
check('negativ → invalid (-1)', UsedController::number('-5') === -1.0);
check('două virgule → invalid (-1)', UsedController::number('6,5,0') === -1.0);
check('tablou în loc de text → invalid (-1)', UsedController::number(['x']) === -1.0);

nl_done();
