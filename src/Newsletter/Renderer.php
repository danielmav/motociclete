<?php

declare(strict_types=1);

namespace App\Newsletter;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Produce HTML-ul și varianta text a unui mesaj din datele rezolvate de Content.
 * Folosește un mediu Twig propriu (șabloanele din templates/email/newsletter), cu
 * funcția `utm(url, bloc)`. HTML-ul rezultat conține marcajele %%UNSUB_URL%%,
 * %%PREFS_URL%%, %%VIEW_URL%% și %%EMAIL%%, completate per destinatar la trimitere.
 */
final class Renderer
{
    private const MARKERS = ['UNSUB_URL', 'PREFS_URL', 'VIEW_URL', 'EMAIL', 'TOKEN'];

    private Environment $twig;
    private string $siteUrl;
    private string $campaign = '';

    public function __construct(string $templatesDir, string $siteUrl)
    {
        $this->siteUrl = rtrim($siteUrl, '/');
        $this->twig = new Environment(new FilesystemLoader($templatesDir), [
            'autoescape'       => 'html',
            'strict_variables' => true,
            'cache'            => false,
        ]);
        $this->twig->addFunction(new TwigFunction(
            'utm',
            fn (string $url, string $content): string => Links::utm($url, $this->campaign, $content)
        ));
    }

    /**
     * @param array<string,mixed> $content rezultatul Content::resolve()
     * @param array<string,mixed> $brand address, schedule, departments[{label, phone}]
     * @return array{html:string,text:string}
     */
    public function render(array $content, string $campaign, array $brand = []): array
    {
        $this->campaign = $campaign;
        $html = $this->twig->render($content['type'] . '.twig', [
            'c'     => $content,
            'site'  => $this->siteUrl,
            'img'   => $this->siteUrl . '/assets/img/newsletter',
            'nav'   => [
                ['label' => 'Motociclete Yamaha', 'url' => $this->siteUrl . '/yamaha/motociclete'],
                ['label' => 'Scutere Yamaha',     'url' => $this->siteUrl . '/yamaha/scutere'],
                ['label' => 'ATV-uri Yamaha',     'url' => $this->siteUrl . '/yamaha/atvuri'],
                ['label' => 'Motociclete CFMOTO', 'url' => $this->siteUrl . '/cfmoto'],
                ['label' => 'Echipament moto',    'url' => 'https://bikershop.ro/'],
            ],
            'brand' => $brand + ['address' => '', 'schedule' => '', 'departments' => []],
            'year'  => date('Y'),
        ]);
        return ['html' => $html, 'text' => $this->text($content)];
    }

    /** @param array<string,string> $vars UNSUB_URL, PREFS_URL, VIEW_URL, EMAIL */
    public static function personalize(string $body, array $vars, bool $html = true): string
    {
        $map = [];
        foreach (self::MARKERS as $key) {
            if (array_key_exists($key, $vars)) {
                $value = (string) $vars[$key];
                $map['%%' . $key . '%%'] = $html ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
            }
        }
        return strtr($body, $map);
    }

    /** Scoate blocul personal al footerului (pentru pagina publică „vezi în browser"). */
    public static function stripPersonal(string $html): string
    {
        return (string) preg_replace('~<!--nl:personal-->.*?<!--/nl:personal-->~s', '', $html);
    }

    /** @param array<string,mixed> $c */
    private function text(array $c): string
    {
        $plain = static fn (string $html): string => trim((string) preg_replace(
            "/\n{3,}/",
            "\n\n",
            html_entity_decode(strip_tags((string) preg_replace('~</p>|<br\s*/?>~i', "\n\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ));
        $lines = [$plain((string) $c['news']['title_html']), ''];
        $body = $plain((string) $c['news']['body_html']);
        if ($body !== '') {
            $lines[] = $body;
            $lines[] = '';
        }
        if ($c['news']['link'] !== '') {
            $lines[] = $c['news']['button'] . ': ' . Links::utm((string) $c['news']['link'], $this->campaign, 'stire');
            $lines[] = '';
        }
        $item = function (array $x, string $block, int $i) use (&$lines): void {
            $price = $x['price'] !== '' ? ' — ' . $x['price'] : '';
            $old   = $x['price_old'] !== null ? ' (în loc de ' . $x['price_old'] . ')' : '';
            $lines[] = $x['name'] . $price . $old;
            $lines[] = Links::utm((string) $x['url'], $this->campaign, $block . '-' . $i);
            $lines[] = '';
        };
        foreach ($c['models'] as $i => $m) {
            $item($m, 'model', $i + 1);
        }
        foreach ($c['products'] as $i => $p) {
            $item($p, 'produs', $i + 1);
        }
        $lines[] = '--';
        $lines[] = 'Dual Motors — ' . $this->siteUrl;
        $lines[] = 'Mesaj trimis către %%EMAIL%%.';
        $lines[] = 'Dezabonare: %%UNSUB_URL%%';
        $lines[] = 'Preferințe: %%PREFS_URL%%';
        return implode("\n", $lines) . "\n";
    }
}
