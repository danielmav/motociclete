<?php

declare(strict_types=1);

namespace App\Newsletter;

/**
 * Linkurile din newsletter: parametri UTM pe linkurile către siturile noastre, ca
 * vizitele și vânzările din newsletter să se vadă în Google Analytics pe ambele.
 * Linkurile externe, `tel:`/`mailto:` și marcajele de personalizare rămân neatinse.
 */
final class Links
{
    /** Gazdele care primesc UTM (motociclete.test = dezvoltare locală). */
    private const HOSTS = [
        'motociclete.com.ro', 'www.motociclete.com.ro',
        'bikershop.ro', 'www.bikershop.ro',
        'motociclete.test',
    ];

    public static function utm(string $url, string $campaign, string $content): string
    {
        if (!preg_match('~^https?://~i', $url)) {
            return $url;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!in_array($host, self::HOSTS, true)) {
            return $url;
        }
        $fragment = '';
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }
        if (preg_match('/[?&]utm_source=/', $url)) {
            return $url . $fragment;
        }
        $utm = 'utm_source=newsletter&utm_medium=email'
            . '&utm_campaign=' . rawurlencode($campaign)
            . '&utm_content=' . rawurlencode($content);
        return $url . (str_contains($url, '?') ? '&' : '?') . $utm . $fragment;
    }
}
