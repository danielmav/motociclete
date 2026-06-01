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
