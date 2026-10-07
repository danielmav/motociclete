<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\BikerShop\Client;
use App\Catalog\Repository as Catalog;
use App\Database;
use RuntimeException;

/**
 * Generator de newsletter Brevo (Developer mode): produce YAML-ul complet al editorului
 * pornind de la fragmentele tăiate din template (`{partsDir}/{head,news,mid1,model,mid2,product_row,tail}.yml`)
 * și de la un input asociativ (aceeași formă ca documente/newsletter/input.example.json):
 *
 *   subiect, stire{titlu_html, imagine, link, buton, paragrafe[]|text_html},
 *   modele[2] (URL/slug/brand-slug sau obiect {slug|url, brand, nume, pret, descriere, imagine, link}),
 *   produse[6] (id/URL bikershop sau obiect {id|url, attr, nume, pret, pret_vechi, pret_html, imagine, link}).
 *
 * Rezolvarea modelelor și a produselor (inclusiv prețurile reduse) e în clasa Content (același namespace).
 *
 * Nu parsează YAML: lucrează pe text (escapare ' → '', UUID-uri noi pe blocurile duplicate).
 * Modelele vin din catalogul local, produsele LIVE de pe BikerShop; orice câmp e suprascriibil.
 * Folosit de database/newsletter_brevo.php (CLI) și Admin\NewsletterController.
 */
final class Generator
{
    public const SITE = 'https://www.motociclete.com.ro';

    /** @var string[] avertismente (ne-fatale) acumulate la ultima generare */
    private array $warnings = [];
    /** @var string[] rezumat lizibil (modele/produse rezolvate) */
    private array $summary = [];

    public function __construct(
        private Catalog $catalog,
        private Client $bikershop,
        private Database $db,
        private string $partsDir,
        private string $site = self::SITE,
    ) {
    }

    public function partsAvailable(): bool
    {
        foreach (['head', 'news', 'mid1', 'model', 'mid2', 'product_row', 'tail'] as $p) {
            if (!is_file($this->partsDir . "/$p.yml")) {
                return false;
            }
        }
        return true;
    }

    /** @return string[] */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return string[] */
    public function summary(): array
    {
        return $this->summary;
    }

    /** @throws RuntimeException la input incomplet / model negăsit / fragment lipsă */
    public function generate(array $in): string
    {
        $this->warnings = [];
        $this->summary  = [];

        $modelSpecs   = array_values($in['modele'] ?? []);
        $productSpecs = array_values($in['produse'] ?? []);
        if (count($modelSpecs) !== 2) {
            throw new RuntimeException('Sunt necesare exact 2 modele.');
        }
        if (count($productSpecs) !== 6) {
            throw new RuntimeException('Sunt necesare exact 6 produse BikerShop.');
        }
        if (!$this->bikershop->isAvailable()) {
            $this->warnings[] = 'Baza BikerShop e indisponibilă — se folosesc doar câmpurile completate manual.';
        }
        $content = Content::fromServices($this->catalog, $this->bikershop, $this->db, $this->site);
        $models = array_map(static fn (array $m): array => [
            'IMAGE' => $m['image'],
            'NAME'  => $m['name'],
            'PRICE' => $m['price_old'] !== null ? '<s>' . $m['price_old'] . '</s> ' . $m['price'] : $m['price'],
            'DESC'  => $m['desc'],
            'URL'   => $m['url'],
        ], $content->models($modelSpecs));
        $products = array_map(static function (array $p): array {
            $html = $p['price_html'];
            if ($html === null) {
                $html = $p['price_old'] !== null
                    ? '<p><strong>' . $p['price'] . '</strong> <s>' . $p['price_old'] . '</s> -' . $p['pct'] . '%</p>'
                    : '<p><strong>' . $p['price'] . '</strong></p>';
            }
            return ['IMAGE' => $p['image'], 'NAME' => $p['name'], 'PRICE_HTML' => $html, 'URL' => $p['url']];
        }, $content->products($productSpecs));
        $this->warnings = array_merge($this->warnings, $content->warnings());
        $news = $in['stire'] ?? [];
        foreach (['titlu_html' => 'titlul', 'imagine' => 'imaginea', 'link' => 'linkul'] as $k => $label) {
            if (empty($news[$k])) {
                throw new RuntimeException("Lipsește $label știrii principale.");
            }
        }

        $out  = $this->fill($this->part('head'), [
            'SUBJECT'         => $in['subiect'] ?? '',
            'NEWS_TITLE_HTML' => str_starts_with(trim((string) $news['titlu_html']), '<')
                ? $news['titlu_html']
                : '<h1 class="default-heading1">' . $news['titlu_html'] . '</h1>',
        ]);
        $out .= $this->fill($this->part('news'), [
            'NEWS_LINK'      => $news['link'],
            'NEWS_IMAGE'     => $this->absolute((string) $news['imagine']),
            'NEWS_BODY_HTML' => self::paragraphs($news['paragrafe'] ?? $news['text_html'] ?? ''),
            'NEWS_BUTTON'    => ($news['buton'] ?? '') !== '' ? $news['buton'] : 'Detalii',
        ]);
        $out .= $this->part('mid1');
        foreach ($models as $m) {
            $out .= self::freshIds($this->fill($this->part('model'), $m));
        }
        $out .= $this->part('mid2');
        foreach (array_chunk($products, 3) as $row) {
            $vars = [];
            foreach ($row as $p) {
                foreach ($p as $k => $v) {
                    $vars[$k][] = $v;
                }
            }
            $out .= self::freshIds($this->fill($this->part('product_row'), $vars));
        }
        $out .= $this->part('tail');

        if (preg_match_all('/\{\{[A-Z_]+\}\}/', $out, $mm)) {
            throw new RuntimeException('Placeholdere necompletate: ' . implode(', ', array_unique($mm[0])));
        }

        foreach ($models as $m) {
            $this->summary[] = "Model: {$m['NAME']} — {$m['PRICE']} — {$m['URL']}";
        }
        foreach ($products as $p) {
            $this->summary[] = "Produs: {$p['NAME']} — " . strip_tags($p['PRICE_HTML']) . " — {$p['URL']}";
        }
        return $out;
    }

    // ------------------------------------------------------------ template text

    private function part(string $name): string
    {
        $f = $this->partsDir . "/$name.yml";
        if (!is_file($f)) {
            throw new RuntimeException("Lipsește fragmentul de template $f");
        }
        return rtrim((string) file_get_contents($f), "\n") . "\n";
    }

    /** Escapare pentru string YAML single-quoted (' → ''); fără newline-uri. */
    private static function y(string $s): string
    {
        return str_replace(["\r\n", "\r", "\n", "'"], [' ', ' ', ' ', "''"], $s);
    }

    /** Înlocuiește fiecare {{KEY}} cu valorile în ordine (a n-a apariție → a n-a valoare). */
    private function fill(string $tpl, array $vars): string
    {
        foreach ($vars as $key => $vals) {
            $vals = is_array($vals) ? array_values($vals) : [$vals];
            $i = 0;
            $tpl = (string) preg_replace_callback('/\{\{' . preg_quote((string) $key, '/') . '\}\}/', function () use (&$i, $vals) {
                $v = $vals[$i] ?? end($vals);
                $i++;
                return self::y((string) $v);
            }, $tpl);
        }
        return $tpl;
    }

    /** Blocurile Brevo au id-uri UUID; duplicatele (model ×2, rând produse ×2) primesc id-uri noi. */
    private static function freshIds(string $yml): string
    {
        return (string) preg_replace_callback('/^(\s+id: )[0-9a-f-]{36}$/m', fn ($m) => $m[1] . self::uuid(), $yml);
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    // ------------------------------------------------------------ formatting

    public static function eur(int|float $v): string
    {
        return Content::eur($v);
    }

    public static function lei(int|float $v): string
    {
        return Content::lei($v);
    }

    public static function excerpt(string $html, int $max = 260): string
    {
        return Content::excerpt($html, $max);
    }

    /** Listă de paragrafe (sau string) → HTML; elementele care încep cu '<' sunt lăsate ca atare. */
    public static function paragraphs(array|string $p): string
    {
        return Content::paragraphs($p);
    }

    /** Text cu paragrafe separate prin linie goală → listă (pentru formularul admin). */
    public static function splitParagraphs(string $text): array
    {
        return Content::splitParagraphs($text);
    }

    private function absolute(string $url): string
    {
        return str_starts_with($url, '/') ? $this->site . $url : $url;
    }
}
