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

    /**
     * Linkurile http(s) distincte dintr-un HTML, decodate (`&amp;` → `&`), în ordinea
     * apariției. Marcajele de personalizare (%%…%%), `mailto:` și `tel:` nu intră.
     * @return array<int,string>
     */
    public static function hrefs(string $html): array
    {
        preg_match_all('/\bhref="([^"]+)"/i', $html, $m);
        $out = [];
        foreach ($m[1] as $raw) {
            $url = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('~^https?://~i', $url) && !str_contains($url, '%%')) {
                $out[$url] = true;
            }
        }
        return array_map('strval', array_keys($out));
    }

    /**
     * Înlocuiește linkurile din hartă cu adresa de numărare a clicurilor,
     * `{prefix}/{id}/%%TOKEN%%`; tokenul destinatarului se completează la trimitere.
     * @param array<string,int> $map URL final (decodat) → id din nl_links
     */
    public static function tracked(string $html, array $map, string $prefix): string
    {
        if (!$map) {
            return $html;
        }
        return (string) preg_replace_callback('/\bhref="([^"]+)"/i', static function (array $m) use ($map, $prefix): string {
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return isset($map[$url]) ? 'href="' . $prefix . '/' . $map[$url] . '/%%TOKEN%%"' : $m[0];
        }, $html);
    }
}
