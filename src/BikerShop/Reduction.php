<?php

declare(strict_types=1);

namespace App\BikerShop;

/**
 * Reducerile din magazin (`ps_specific_price`), fără acces la DB: alege reducerea
 * valabilă și o aplică pe prețul brut. Tratăm doar forma folosită pe BikerShop:
 * procentuală, cu TVA inclus, fără preț fix, pentru toți clienții. Orice altceva
 * (sumă fixă, preț fix, alt magazin, în afara ferestrei) e ignorat, iar prețul
 * rămâne cel de listă.
 */
final class Reduction
{
    /**
     * @param array<int,array<string,mixed>> $rows rândurile UNEI ținte (un produs sau o variantă)
     * @return float|null fracția reducerii (0 < r < 1)
     */
    public static function pick(array $rows, int $shopId, int $now): ?float
    {
        $best = null;
        $bestKey = null;
        foreach ($rows as $r) {
            if (($r['reduction_type'] ?? '') !== 'percentage' || (float) ($r['price'] ?? -1) >= 0) {
                continue;
            }
            $reduction = (float) ($r['reduction'] ?? 0);
            if ($reduction <= 0 || $reduction >= 1) {
                continue;
            }
            $shop = (int) ($r['id_shop'] ?? 0);
            if ($shop !== 0 && $shop !== $shopId) {
                continue;
            }
            $from = self::ts($r['from'] ?? null);
            $to   = self::ts($r['to'] ?? null);
            if (($from !== null && $from > $now) || ($to !== null && $to < $now)) {
                continue;
            }
            // Rândul magazinului bate rândul global; la egalitate, cel mai nou.
            $key = [$shop === $shopId ? 1 : 0, (int) ($r['id_specific_price'] ?? 0)];
            if ($bestKey === null || $key > $bestKey) {
                $bestKey = $key;
                $best = $reduction;
            }
        }
        return $best;
    }

    /**
     * Ținta: varianta cerută, apoi nivelul de produs; fără variantă cerută: nivelul
     * de produs, apoi varianta implicită (ce arată pagina produsului).
     * @param array<int,array<int,array<string,mixed>>> $byAttr id_product_attribute => rânduri
     */
    public static function forProduct(array $byAttr, ?int $attr, ?int $defaultAttr, int $shopId, int $now): ?float
    {
        $order = $attr !== null ? [$attr, 0] : [0, $defaultAttr];
        foreach ($order as $target) {
            if ($target === null || !isset($byAttr[$target])) {
                continue;
            }
            $reduction = self::pick($byAttr[$target], $shopId, $now);
            if ($reduction !== null) {
                return $reduction;
            }
        }
        return null;
    }

    /** @return array{price:float,price_old:?float,reduction_pct:?int} */
    public static function apply(float $gross, ?float $reduction): array
    {
        if ($reduction === null) {
            return ['price' => $gross, 'price_old' => null, 'reduction_pct' => null];
        }
        return [
            'price'         => round($gross * (1 - $reduction), 2),
            'price_old'     => $gross,
            'reduction_pct' => (int) round($reduction * 100),
        ];
    }

    /** Data PrestaShop → timestamp; `0000-00-00…` și golul înseamnă „fără limită". */
    private static function ts(mixed $value): ?int
    {
        $value = (string) $value;
        if ($value === '' || str_starts_with($value, '0000')) {
            return null;
        }
        $t = strtotime($value);
        return $t === false ? null : $t;
    }
}
