<?php

declare(strict_types=1);

namespace App\BikerShop;

/**
 * Pure (DB-free) ranking for LeoPartsFilter model matching.
 *
 * Numele noastre sunt de marketing ("Grizzly 700 EPS"), iar PartsEurope folosește
 * taxonomia internă ("YFM 700 Grizzly"). Potrivirea brută cu LIKE întoarce mai mulți
 * candidați; aici îi scoram și alegem pe cel mai bun în loc de „primul alfabetic".
 *
 * Semnale: suprapunere de token-uri + acord pe cilindree (gating tare) + prezența
 * anului produsului ca tiebreaker. Vezi tests/FitmentMatcherTest.php.
 */
final class FitmentMatcher
{
    /** Sub acest scor nu considerăm potrivirea de încredere. */
    private const CONFIDENCE_THRESHOLD = 0.45;

    /** Bonus de departajare când candidatul are exact anul produsului. */
    private const YEAR_BONUS = 0.15;

    /**
     * Similaritate între numele produsului și al unui candidat (poate fi negativă
     * când cilindreele intră în conflict).
     */
    public static function score(string $productName, string $candidateName): float
    {
        $p = self::tokens($productName);
        $c = self::tokens($candidateName);
        if ($p === [] || $c === []) {
            return 0.0;
        }

        $shared    = count(array_intersect($p, $c));
        $coverage  = $shared / count($p);   // cât din produs e acoperit
        $precision = $shared / count($c);   // cât de „curat" e candidatul
        $base      = 0.6 * $coverage + 0.4 * $precision;

        return $base + self::displacementSignal($productName, $candidateName);
    }

    /**
     * Alege cel mai bun candidat pentru un produs/an dat.
     *
     * @param list<array{id:int,name:string,years?:array<string,int>}> $candidates
     * @return array{model_id:?int,year_id:?int,score:float,confident:bool,ambiguous:bool,candidates:list<string>}
     */
    public static function pickBest(string $productName, int $year, array $candidates): array
    {
        $empty = [
            'model_id'   => null,
            'year_id'    => null,
            'score'      => 0.0,
            'confident'  => false,
            'ambiguous'  => false,
            'candidates' => [],
        ];
        if ($candidates === []) {
            return $empty;
        }

        $scored = [];
        foreach ($candidates as $cand) {
            $years    = $cand['years'] ?? [];
            $hasYear  = isset($years[(string) $year]);
            $score    = self::score($productName, $cand['name']);
            $conflict = self::displacementConflict($productName, $cand['name']);
            $scored[] = [
                'id'        => $cand['id'],
                'name'      => $cand['name'],
                'score'     => $score,
                'rank'      => $score + ($hasYear ? self::YEAR_BONUS : 0.0),
                'year_id'   => $hasYear ? $years[(string) $year] : null,
                'plausible' => $score >= self::CONFIDENCE_THRESHOLD && !$conflict,
            ];
        }

        usort($scored, fn (array $a, array $b) => $b['rank'] <=> $a['rank']);
        $best = $scored[0];

        $plausibleCount = count(array_filter($scored, fn (array $s) => $s['plausible']));

        return [
            'model_id'   => $best['id'],
            'year_id'    => $best['year_id'],
            'score'      => $best['score'],
            'confident'  => $best['plausible'],
            'ambiguous'  => $plausibleCount > 1,
            'candidates' => array_column($scored, 'name'),
        ];
    }

    /** Cilindreea produsului, expusă pentru ordonarea candidaților în SQL. */
    public static function displacementOf(string $name): ?int
    {
        return self::displacement($name);
    }

    /** Lowercase alfanumeric tokens. "MT-09 ABS" -> ["mt","09","abs"]. */
    private static function tokens(string $name): array
    {
        $name = strtolower($name);
        $parts = preg_split('/[^a-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique($parts));
    }

    /** Cilindreea (primul număr „cc-like", >= 50) din nume, dacă există. */
    private static function displacement(string $name): ?int
    {
        foreach (self::tokens($name) as $tok) {
            if (ctype_digit($tok)) {
                $n = (int) $tok;
                if ($n >= 50 && $n <= 2500) {
                    return $n;
                }
            }
        }
        return null;
    }

    private static function displacementConflict(string $a, string $b): bool
    {
        $da = self::displacement($a);
        $db = self::displacement($b);
        return $da !== null && $db !== null && $da !== $db;
    }

    private static function displacementSignal(string $productName, string $candidateName): float
    {
        $dp = self::displacement($productName);
        $dc = self::displacement($candidateName);

        if ($dp !== null && $dc !== null) {
            return $dp === $dc ? 0.3 : -0.6;
        }
        if ($dp !== null && $dc === null) {
            return -0.1; // produsul are cilindree, candidatul n-o menționează
        }
        return 0.0;
    }
}
