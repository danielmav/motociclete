<?php

declare(strict_types=1);

/**
 * Plain-PHP test runner for FitmentMatcher (no PHPUnit — proiectul e fără dev-deps).
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/FitmentMatcherTest.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\BikerShop\FitmentMatcher;

$failures = 0;
$count    = 0;

function check(string $label, bool $ok): void
{
    global $failures, $count;
    $count++;
    if ($ok) {
        echo "  ✓ {$label}\n";
    } else {
        $failures++;
        echo "  ✗ {$label}\n";
    }
}

// --- score(): closeness ------------------------------------------------------

// "MT-09" should match the plain "MT-09 ABS" better than a longer variant.
check(
    'score: fewer extra tokens scores higher',
    FitmentMatcher::score('MT-09', 'MT-09 ABS')
    > FitmentMatcher::score('MT-09', 'MT-09 ABS Sport Tracker')
);

// --- score(): displacement gating -------------------------------------------

// Grizzly 700 must prefer a 700 candidate over a 110 one (real bad match).
check(
    'score: matching displacement beats conflicting displacement',
    FitmentMatcher::score('Grizzly 700 EPS', 'YFM 700 Grizzly')
    > FitmentMatcher::score('Grizzly 700 EPS', 'YFM 110 Grizzly')
);

// A conflicting-displacement match should score worse than a same-family,
// no-displacement candidate (the displacement conflict is a strong negative).
check(
    'score: displacement conflict is penalised',
    FitmentMatcher::score('Grizzly 700 EPS', 'YFM 110 Grizzly') < 0.5
);

// --- pickBest(): selection ---------------------------------------------------

$grizzly = FitmentMatcher::pickBest('Grizzly 700 EPS', 2024, [
    ['id' => 1, 'name' => 'YFM 110 Grizzly',  'years' => []],
    ['id' => 2, 'name' => 'YFM 125 2X4 Grizzly', 'years' => []],
    ['id' => 3, 'name' => 'YFM 700 Grizzly', 'years' => ['2024' => 999]],
]);
check('pickBest: chooses highest-scoring candidate', $grizzly['model_id'] === 3);
check('pickBest: resolves year_id from chosen model', $grizzly['year_id'] === 999);
check('pickBest: confident on a clean displacement+year match', $grizzly['confident'] === true);

// Year disambiguation: two near-identical names, only one carries the year.
$tracer = FitmentMatcher::pickBest('TRACER 7', 2026, [
    ['id' => 10, 'name' => 'MT-07 ABS Tracer 7', 'years' => []],
    ['id' => 11, 'name' => 'MT-07 ABS Tracer 7', 'years' => ['2026' => 5001]],
]);
check('pickBest: year presence breaks a tie', $tracer['model_id'] === 11);
check('pickBest: sets year_id from the tie-winner', $tracer['year_id'] === 5001);

// Low confidence: only conflicting-displacement candidates available.
$bad = FitmentMatcher::pickBest('Grizzly 700 EPS', 2024, [
    ['id' => 1, 'name' => 'YFM 110 Grizzly', 'years' => []],
    ['id' => 2, 'name' => 'YFM 125 2X4 Grizzly', 'years' => []],
]);
check('pickBest: not confident when all candidates conflict on displacement', $bad['confident'] === false);

// No candidates at all.
$none = FitmentMatcher::pickBest('Whatever', 2025, []);
check('pickBest: null model on empty candidate list', $none['model_id'] === null);
check('pickBest: not confident on empty candidate list', $none['confident'] === false);

// Ambiguity flag: multiple plausible (non-conflicting) candidates.
$mt09 = FitmentMatcher::pickBest('MT-09', 2026, [
    ['id' => 20, 'name' => 'MT-09 ABS', 'years' => []],
    ['id' => 21, 'name' => 'MT-09 ABS Tracer', 'years' => []],
]);
check('pickBest: flags ambiguity when several plausible matches', $mt09['ambiguous'] === true);

// --- displacementOf(): exposed for SQL candidate ordering --------------------

check('displacementOf: extracts cc-like number', FitmentMatcher::displacementOf('Grizzly 700 EPS') === 700);
check('displacementOf: null when no displacement', FitmentMatcher::displacementOf('MT-09') === null);
check('displacementOf: ignores small numbers', FitmentMatcher::displacementOf('TRACER 7') === null);
check('displacementOf: reads mid-name displacement', FitmentMatcher::displacementOf('NMAX 125 Tech MAX') === 125);

echo "\n";
echo $failures === 0
    ? "ALL {$count} CHECKS PASSED\n"
    : "{$failures}/{$count} CHECKS FAILED\n";

exit($failures === 0 ? 0 : 1);
