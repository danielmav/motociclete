<?php

declare(strict_types=1);

/**
 * Small global helpers (autoloaded via composer "files").
 * Kept intentionally tiny — port of the drivetest spirit.
 */

if (!function_exists('e')) {
    /** HTML-escape a value for safe output. */
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('money_ron')) {
    /** Format an amount as Romanian price, e.g. 9999 -> "9.999 €". */
    function money_ron(float|int $amount, string $currency = '€'): string
    {
        return number_format((float) $amount, 0, ',', '.') . ' ' . $currency;
    }
}

if (!function_exists('money_lei')) {
    /** Format an amount as Romanian RON price, e.g. 90000 -> "90.000 lei". */
    function money_lei(float|int $amount): string
    {
        return number_format((float) $amount, 0, ',', '.') . ' lei';
    }
}

if (!function_exists('price_dual')) {
    /**
     * Turn a stored EUR amount into VAT-inclusive EUR + RON display strings.
     *
     * @param array{rate:float,vat:int,incl:bool} $cur currency config
     * @return array{eur:string,ron:string,eur_raw:int,ron_raw:int}
     */
    function price_dual(float|int $eur, array $cur): array
    {
        $gross = $cur['incl'] ? (float) $eur : (float) $eur * (1 + $cur['vat'] / 100);
        $grossEur = (int) round($gross);
        $grossRon = (int) round($gross * $cur['rate']);
        return [
            'eur'     => money_ron($grossEur, '€'),
            'ron'     => money_lei($grossRon),
            'eur_raw' => $grossEur,
            'ron_raw' => $grossRon,
        ];
    }
}

if (!function_exists('normalize_phone')) {
    /**
     * Canonicalize a Romanian phone number to "07XXXXXXXX" (10 digits) for
     * lookup/matching. Handles spaces, +40 / 0040 / 40 country-code variants and
     * a missing leading zero. Returns null if it isn't a plausible RO mobile.
     */
    function normalize_phone(?string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $raw) ?? '';
        if ($d === '') {
            return null;
        }
        if (str_starts_with($d, '0040')) {
            $d = '0' . substr($d, 4);
        } elseif (str_starts_with($d, '40') && strlen($d) === 11) {
            $d = '0' . substr($d, 2);
        }
        if (strlen($d) === 9 && $d[0] === '7') {
            $d = '0' . $d; // missing leading zero
        }
        return (strlen($d) === 10 && str_starts_with($d, '07')) ? $d : null;
    }
}

if (!function_exists('normalize_email')) {
    /** Lowercase + trim an email for case-insensitive lookup. Null if empty/invalid-ish. */
    function normalize_email(?string $raw): ?string
    {
        $e = strtolower(trim((string) $raw));
        return ($e !== '' && str_contains($e, '@')) ? $e : null;
    }
}

if (!function_exists('slugify')) {
    /** Make a URL-safe slug from a string (diacritics-aware). */
    function slugify(string $text): string
    {
        $map = ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ț' => 't',
                'Ă' => 'a', 'Â' => 'a', 'Î' => 'i', 'Ș' => 's', 'Ț' => 't'];
        $text = strtr($text, $map);
        $text = preg_replace('~[^\pL\d]+~u', '-', $text) ?? '';
        $text = trim(strtolower($text), '-');
        return $text === '' ? 'n-a' : $text;
    }
}
