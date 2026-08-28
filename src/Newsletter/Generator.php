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
 *   produse[6] (id/URL bikershop sau obiect {id|url, nume, pret, pret_vechi, pret_html, imagine, link}).
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

        $models = array_map(fn ($m) => $this->resolveModel($m), array_values($in['modele'] ?? []));
        if (count($models) !== 2) {
            throw new RuntimeException('Sunt necesare exact 2 modele.');
        }
        $products = $this->resolveProducts($this->productSpecs(array_values($in['produse'] ?? [])));
        if (count($products) !== 6) {
            throw new RuntimeException('Sunt necesare exact 6 produse BikerShop.');
        }
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
        return number_format((float) $v, 0, ',', '.') . ' €';
    }

    public static function lei(int|float $v): string
    {
        return number_format((float) $v, 0, ',', '.') . ' lei';
    }

    public static function excerpt(string $html, int $max = 260): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (mb_strlen($t) <= $max) {
            return $t;
        }
        $t = mb_substr($t, 0, $max);
        $dot = mb_strrpos($t, '. ');
        if ($dot !== false && $dot > $max / 2) {
            return mb_substr($t, 0, $dot + 1);
        }
        $sp = mb_strrpos($t, ' ');
        return rtrim(mb_substr($t, 0, $sp ?: $max), ' ,;:') . '…';
    }

    /** Listă de paragrafe (sau string) → HTML; elementele care încep cu '<' sunt lăsate ca atare. */
    public static function paragraphs(array|string $p): string
    {
        $p = is_array($p) ? $p : [$p];
        $p = array_filter(array_map(fn ($x) => trim((string) $x), $p), fn ($x) => $x !== '');
        return implode('', array_map(fn ($x) => str_starts_with($x, '<') ? $x : '<p>' . $x . '</p>', $p));
    }

    /** Text cu paragrafe separate prin linie goală → listă (pentru formularul admin). */
    public static function splitParagraphs(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R\s*\R/', $text) ?: []), fn ($x) => $x !== ''));
    }

    private function absolute(string $url): string
    {
        return str_starts_with($url, '/') ? $this->site . $url : $url;
    }

    // ------------------------------------------------------------ modele

    private function resolveModel(array|string $spec): array
    {
        $o = is_array($spec) ? $spec : ['slug' => $spec];
        $ref = (string) ($o['url'] ?? $o['slug'] ?? '');
        $ref = (string) preg_replace('#^https?://[^/]+#', '', trim($ref));
        $segs = array_values(array_filter(explode('/', trim($ref, '/'))));
        $brand = (string) ($o['brand'] ?? (count($segs) > 1 ? $segs[0] : 'yamaha'));
        $slug  = (string) (end($segs) ?: '');
        if ($slug === '') {
            throw new RuntimeException('Model lipsă (completează URL-ul sau slug-ul).');
        }

        $p = $this->catalog->product($brand, $slug);
        if (!$p && ($canon = $this->catalog->canonicalForSlugRedirect($brand, $slug))) {
            $p = $this->catalog->product($brand, basename($canon));
        }
        if (!$p) { // slug fără an (ex. "mt-09" → "mt-09-2026")
            $st = $this->db->local()->prepare(
                "SELECT slug FROM products WHERE brand = :b AND is_active = 1 AND slug REGEXP :re ORDER BY year DESC LIMIT 1"
            );
            $st->execute([':b' => $brand, ':re' => '^' . preg_quote($slug) . '-[0-9]{4}$']);
            if ($s = $st->fetchColumn()) {
                $p = $this->catalog->product($brand, (string) $s);
            }
        }
        if (!$p) {
            throw new RuntimeException("Model negăsit în catalog: $brand/$slug");
        }
        if ((int) $p['is_active'] === 0) {
            $this->warnings[] = "{$p['name']} e scos din ofertă (is_active=0).";
        }
        if (empty($o['imagine']) && empty($p['cover'])) {
            $this->warnings[] = "{$p['name']} nu are imagine cover în catalog.";
        }
        return [
            'IMAGE' => $o['imagine'] ?? ($this->site . ($p['cover'] ?? '')),
            'NAME'  => $o['nume'] ?? $p['name'],
            'PRICE' => $o['pret'] ?? ((int) $p['price'] > 0 ? self::eur((int) $p['price']) : 'Preț la cerere'),
            'DESC'  => $o['descriere'] ?? self::excerpt((string) ($p['excerpt'] ?: $p['description'])),
            'URL'   => $o['link'] ?? ($this->site . $p['url']),
        ];
    }

    // ------------------------------------------------------------ produse BikerShop

    private function productSpecs(array $list): array
    {
        return array_map(function ($spec) {
            $spec = is_string($spec) ? trim($spec) : $spec;
            $o = is_array($spec) ? $spec : (is_numeric($spec) ? ['id' => (int) $spec] : ['url' => (string) $spec]);
            if (empty($o['id']) && !empty($o['url']) && preg_match('#/(\d+)-[^/]*\.html#', (string) $o['url'], $m)) {
                $o['id'] = (int) $m[1];
            }
            if (empty($o['id'])) {
                throw new RuntimeException('Produs BikerShop fără id: ' . json_encode($spec, JSON_UNESCAPED_UNICODE));
            }
            return $o;
        }, $list);
    }

    private function resolveProducts(array $specs): array
    {
        $ids  = array_map(fn ($o) => (int) $o['id'], $specs);
        $live = [];
        foreach ($this->bikershop->productsByIds($ids, count($ids)) as $p) {
            $live[$p['id']] = $p;
        }
        if (!$live && !$this->bikershop->isAvailable()) {
            $this->warnings[] = 'Baza BikerShop e indisponibilă — se folosesc doar câmpurile completate manual.';
        }
        $out = [];
        foreach ($specs as $o) {
            $p = $live[$o['id']] ?? null;
            if (!$p) {
                $this->warnings[] = "Produsul {$o['id']} nu e activ / nu există pe BikerShop.";
            }
            $missing = [];
            foreach (['nume' => 'name', 'imagine' => 'image', 'link' => 'url'] as $k => $lk) {
                if (empty($o[$k]) && empty($p[$lk]) && !($k === 'link' && !empty($o['url']))) {
                    $missing[] = $k;
                }
            }
            if ($missing) {
                throw new RuntimeException("Produsul {$o['id']}: lipsesc " . implode(', ', $missing) . '.');
            }
            $priceHtml = $o['pret_html'] ?? null;
            if ($priceHtml === null) {
                $cur = isset($o['pret']) && $o['pret'] !== '' ? (float) $o['pret'] : (float) ($p['price'] ?? 0);
                $now = $cur > 0 ? self::lei($cur) : '';
                if (!empty($o['pret_vechi'])) {
                    $old = (float) $o['pret_vechi'];
                    $pct = $cur > 0 && $old > $cur ? ' -' . round((1 - $cur / $old) * 100) . '%' : '';
                    $priceHtml = '<p><strong>' . $now . '</strong> <s>' . self::lei($old) . '</s>' . $pct . '</p>';
                } else {
                    $priceHtml = '<p><strong>' . $now . '</strong></p>';
                }
            }
            $out[] = [
                'IMAGE'      => $o['imagine'] ?? $p['image'],
                'NAME'       => $o['nume'] ?? $p['name'],
                'PRICE_HTML' => $priceHtml,
                'URL'        => $o['link'] ?? $o['url'] ?? $p['url'],
            ];
        }
        return $out;
    }
}
