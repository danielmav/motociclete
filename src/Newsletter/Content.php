<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\BikerShop\Client;
use App\Catalog\Repository as Catalog;
use App\Database;
use Closure;
use RuntimeException;

/**
 * Datele unui mesaj de newsletter, rezolvate din formularul de admin: știrea, modelele
 * din catalogul local și produsele BikerShop, cu prețul de vânzare și, unde există
 * reducere, prețul vechi. Orice câmp completat manual are prioritate.
 *
 * Căutările sunt injectate ca funcții, ca logica să fie testabilă fără baze de date;
 * `fromServices()` le leagă de catalog și de BikerShop.
 */
final class Content
{
    public const SITE = 'https://www.motociclete.com.ro';

    /** Tip de mesaj → descriere. */
    public const TYPES = [
        'stiri'  => 'Știri: o știre + 2 modele + 6 produse',
        'oferte' => 'Oferte: produse BikerShop',
    ];

    /** @var string[] avertismente ne-fatale de la ultima rezolvare */
    private array $warnings = [];

    /**
     * @param Closure(string,string):?array $findModel (brand, slug) → rândul din catalog
     * @param Closure(array<int,array{id:int,attr:?int}>):array<int,array<string,mixed>> $findProducts → produse indexate pe id
     */
    public function __construct(private Closure $findModel, private Closure $findProducts, private string $site = self::SITE)
    {
    }

    public static function fromServices(Catalog $catalog, Client $bikershop, Database $db, string $site = self::SITE): self
    {
        $findModel = static function (string $brand, string $slug) use ($catalog, $db): ?array {
            $p = $catalog->product($brand, $slug);
            if (!$p && ($canon = $catalog->canonicalForSlugRedirect($brand, $slug))) {
                $p = $catalog->product($brand, basename($canon));
            }
            if (!$p) { // slug fără an (ex. "mt-09" → "mt-09-2026")
                $st = $db->local()->prepare(
                    'SELECT slug FROM products WHERE brand = :b AND is_active = 1 AND slug REGEXP :re ORDER BY year DESC LIMIT 1'
                );
                $st->execute([':b' => $brand, ':re' => '^' . preg_quote($slug) . '-[0-9]{4}$']);
                if ($s = $st->fetchColumn()) {
                    $p = $catalog->product($brand, (string) $s);
                }
            }
            return $p ?: null;
        };
        $findProducts = static function (array $specs) use ($bikershop): array {
            $ids = [];
            $attrs = [];
            foreach ($specs as $s) {
                $ids[] = (int) $s['id'];
                if (!empty($s['attr'])) {
                    $attrs[(int) $s['id']] = (int) $s['attr'];
                }
            }
            $out = [];
            foreach ($bikershop->productsByIds($ids, max(1, count($ids)), $attrs) as $p) {
                $out[(int) $p['id']] = $p;
            }
            return $out;
        };
        return new self($findModel, $findProducts, $site);
    }

    /** @return string[] */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param array<string,mixed> $in subiect, preheader, stire{…}, modele[], produse[]
     * @return array<string,mixed>
     * @throws RuntimeException la date lipsă, model sau produs negăsit
     */
    public function resolve(string $type, array $in): array
    {
        $this->warnings = [];
        if (!isset(self::TYPES[$type])) {
            throw new RuntimeException("Tip de mesaj necunoscut: {$type}");
        }
        $subject = trim((string) ($in['subiect'] ?? ''));
        if ($subject === '') {
            throw new RuntimeException('Lipsește subiectul.');
        }
        $news  = (array) ($in['stire'] ?? []);
        $title = trim((string) ($news['titlu_html'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Lipsește titlul.');
        }
        $button = trim((string) ($news['buton'] ?? ''));
        $out = [
            'type'      => $type,
            'subject'   => $subject,
            'preheader' => trim((string) ($in['preheader'] ?? '')),
            'news'      => [
                'title_html' => $title,
                'image'      => $this->absolute(trim((string) ($news['imagine'] ?? ''))),
                'link'       => trim((string) ($news['link'] ?? '')),
                'button'     => $button !== '' ? $button : 'Detalii',
                'body_html'  => self::paragraphs($news['paragrafe'] ?? $news['text_html'] ?? ''),
            ],
            'models'   => [],
            'products' => [],
        ];
        $productSpecs = array_values((array) ($in['produse'] ?? []));

        if ($type === 'stiri') {
            foreach (['image' => 'imaginea', 'link' => 'linkul'] as $key => $label) {
                if ($out['news'][$key] === '') {
                    throw new RuntimeException("Lipsește {$label} știrii principale.");
                }
            }
            $modelSpecs = array_values((array) ($in['modele'] ?? []));
            if (count($modelSpecs) !== 2) {
                throw new RuntimeException('Sunt necesare exact 2 modele.');
            }
            if (count($productSpecs) !== 6) {
                throw new RuntimeException('Sunt necesare exact 6 produse BikerShop.');
            }
            $out['models'] = $this->models($modelSpecs);
        } else {
            if (count($productSpecs) < 2 || count($productSpecs) > 12) {
                throw new RuntimeException('Mesajul de oferte are între 2 și 12 produse.');
            }
            if ($out['news']['link'] === '') {
                $out['news']['link'] = 'https://bikershop.ro/';
            }
        }
        $out['products'] = $this->products($productSpecs);
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>|string> $specs URL / slug / obiect {slug|url, brand, nume, pret, pret_vechi, descriere, imagine, link}
     * @return array<int,array<string,mixed>>
     */
    public function models(array $specs): array
    {
        return array_map(function ($spec): array {
            $o    = is_array($spec) ? $spec : ['slug' => (string) $spec];
            $ref  = (string) preg_replace('#^https?://[^/]+#', '', trim((string) ($o['url'] ?? $o['slug'] ?? '')));
            $ref  = (string) preg_replace('/[?#].*$/', '', $ref);
            $segs = array_values(array_filter(explode('/', trim($ref, '/')), static fn (string $s): bool => $s !== ''));
            $brand = (string) ($o['brand'] ?? (count($segs) > 1 ? $segs[0] : 'yamaha'));
            $slug  = $segs ? (string) end($segs) : '';
            if ($slug === '') {
                throw new RuntimeException('Model lipsă (completează URL-ul sau slug-ul).');
            }
            $p = ($this->findModel)($brand, $slug);
            if (!$p) {
                throw new RuntimeException("Model negăsit în catalog: {$brand}/{$slug}");
            }
            if ((int) ($p['is_active'] ?? 1) === 0) {
                $this->warnings[] = "{$p['name']} e scos din ofertă.";
            }
            if (empty($o['imagine']) && empty($p['cover'])) {
                $this->warnings[] = "{$p['name']} nu are imagine de copertă în catalog.";
            }
            $price = (int) ($p['price'] ?? 0);
            $old   = (int) ($p['old_price'] ?? 0);
            $manualOld = isset($o['pret_vechi']) && $o['pret_vechi'] !== '';
            return [
                'name'      => (string) ($o['nume'] ?? $p['name']),
                'image'     => (string) ($o['imagine'] ?? ($this->site . ($p['cover'] ?? ''))),
                'price'     => (string) ($o['pret'] ?? ($price > 0 ? self::eur($price) : 'Preț la cerere')),
                'price_old' => $manualOld
                    ? (string) $o['pret_vechi']
                    : (!isset($o['pret']) && $price > 0 && $old > $price ? self::eur($old) : null),
                'desc'      => (string) ($o['descriere'] ?? self::excerpt((string) (($p['excerpt'] ?? '') ?: ($p['description'] ?? '')))),
                'url'       => (string) ($o['link'] ?? ($this->site . $p['url'])),
            ];
        }, array_values($specs));
    }

    /**
     * @param array<int,array<string,mixed>|string|int> $specs id / URL / obiect {id|url, attr, nume, pret, pret_vechi, pret_html, imagine, link}
     * @return array<int,array<string,mixed>>
     */
    public function products(array $specs): array
    {
        $parsed = array_map(static fn ($s): array => self::productSpec($s), array_values($specs));
        $live = ($this->findProducts)(array_map(
            static fn (array $o): array => ['id' => (int) $o['id'], 'attr' => isset($o['attr']) ? (int) $o['attr'] : null],
            $parsed
        ));
        $out = [];
        foreach ($parsed as $o) {
            $id = (int) $o['id'];
            $p  = $live[$id] ?? null;
            if (!$p) {
                $this->warnings[] = "Produsul {$id} nu e activ sau nu există pe BikerShop (ori BikerShop nu răspunde).";
            }
            $missing = [];
            foreach (['nume' => 'name', 'imagine' => 'image', 'link' => 'url'] as $manual => $liveKey) {
                if (empty($o[$manual]) && empty($p[$liveKey]) && !($manual === 'link' && !empty($o['url']))) {
                    $missing[] = $manual;
                }
            }
            if ($missing) {
                throw new RuntimeException(
                    "Produsul {$id} nu a fost găsit pe BikerShop și lipsesc: " . implode(', ', $missing)
                    . '. Verifică linkul sau dacă produsul e activ.'
                );
            }
            $manualPrice = isset($o['pret']) && $o['pret'] !== '';
            $cur = $manualPrice ? (float) $o['pret'] : (float) ($p['price'] ?? 0);
            if (isset($o['pret_vechi']) && $o['pret_vechi'] !== '') {
                $old = (float) $o['pret_vechi'];
            } else {
                // Un preț pus manual nu se compară cu prețul vechi din magazin.
                $old = $manualPrice ? 0.0 : (float) ($p['price_old'] ?? 0);
            }
            $hasOld = $cur > 0 && $old > $cur;
            $out[] = [
                'id'         => $id,
                'name'       => (string) ($o['nume'] ?? $p['name']),
                'image'      => (string) ($o['imagine'] ?? $p['image']),
                'price'      => $cur > 0 ? self::lei($cur) : '',
                'price_old'  => $hasOld ? self::lei($old) : null,
                'pct'        => $hasOld ? (int) round((1 - $cur / $old) * 100) : null,
                'url'        => (string) ($o['link'] ?? $o['url'] ?? $p['url']),
                'price_html' => isset($o['pret_html']) ? (string) $o['pret_html'] : null,
            ];
        }
        return $out;
    }

    /**
     * id (+ varianta) dintr-un URL BikerShop: `/722786-79528-nume.html` → id 722786, attr 79528.
     * @return array<string,mixed>
     */
    public static function productSpec(array|string|int $spec): array
    {
        $spec = is_string($spec) ? trim($spec) : $spec;
        $o = is_array($spec) ? $spec : (is_numeric($spec) ? ['id' => (int) $spec] : ['url' => (string) $spec]);
        if (empty($o['id']) && !empty($o['url']) && preg_match('#/(\d+)(?:-(\d+))?-[^/]*\.html#', (string) $o['url'], $m)) {
            $o['id'] = (int) $m[1];
            if (!isset($o['attr']) && ($m[2] ?? '') !== '') {
                $o['attr'] = (int) $m[2];
            }
        }
        if (empty($o['id'])) {
            throw new RuntimeException('Produs BikerShop fără id: ' . json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return $o;
    }

    // ------------------------------------------------------------ formatare

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

    /** Listă de paragrafe (sau string) → HTML; elementele care încep cu '<' rămân ca atare. */
    public static function paragraphs(array|string $p): string
    {
        $p = is_array($p) ? $p : [$p];
        $p = array_filter(array_map(static fn ($x): string => trim((string) $x), $p), static fn (string $x): bool => $x !== '');
        return implode('', array_map(static fn (string $x): string => str_starts_with($x, '<') ? $x : '<p>' . $x . '</p>', $p));
    }

    /** Text cu paragrafe separate prin linie goală → listă. */
    public static function splitParagraphs(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R\s*\R/', $text) ?: []), static fn (string $x): bool => $x !== ''));
    }

    private function absolute(string $url): string
    {
        return str_starts_with($url, '/') ? $this->site . $url : $url;
    }
}
