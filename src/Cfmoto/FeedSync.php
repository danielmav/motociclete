<?php

declare(strict_types=1);

namespace App\Cfmoto;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Sincronizare catalog CFMOTO din feed-ul importatorului ATVROM (CSV `;`, toate
 * brandurile, ~1.300 rânduri). Feed-ul = sursa de adevăr pentru prețuri.
 *
 * - Doar motociclete: BRAND ∈ {CFMOTO, CFLITE}, CATEGORY = MOTOCICLETE, VIZIBIL = 1,
 *   fără rulate (SKIP_SH = -1 / sufix „SH").
 * - Prețurile din feed sunt EUR FĂRĂ TVA → ×1,21. `products.price` = prețul de vânzare
 *   (SALE_PRICE), `discount_pct` = reducerea față de PRICE (Repository::oldPrice() reface
 *   prețul vechi tăiat din ele).
 * - Legătura produs↔feed = `products.feed_ids` (ID-urile din feed, separate prin virgulă).
 *   Un produs cu ≥2 rânduri = model cu VARIANTE (ex. 800MT-X LOW SEAT / TALL SEAT) →
 *   `variants_json` (tabelul „Variante și prețuri", ca la Yamaha), prețul produsului = cea
 *   mai mică variantă. Nelegatele se leagă automat pe nume normalizat + an (vezi
 *   proposeLinks()); cazurile ambigue se leagă manual (admin sau CLI --link).
 * - `products.sku` = cod produs în stilul referințelor BikerShop (`cfmoto-1000mt-high-2026`,
 *   culoarea e pe combinație acolo): referința BikerShop dacă modelul există acolo, altfel
 *   generat. `products.supplier_ref` = referința ATVROM (slug-ul din LINK, fără culoare).
 * - Produse noi: rândurile din feed nelegate ȘI nevăzute niciodată (`cfmoto_feed_seen`)
 *   → produs INACTIV complet (descriere, specificații, imagini), numele „<model> - <an>".
 *   Un produs nou șters din admin NU se recreează (rândul rămâne „văzut").
 * - Produsele legate care dispar din feed doar se RAPORTEAZĂ (nu se dezactivează).
 */
final class FeedSync
{
    public const FEED_URL = 'https://advrider.ro/_catalog/csv/magicparser/atvrom/unitati.php';
    private const VAT = 1.21;
    private const BRANDS = ['CFMOTO', 'CFLITE'];

    /** Sufixele care fac dintr-un rând de feed o VARIANTĂ a aceluiași model (înălțimea șeii). */
    private const VARIANT_RE = '/\s+((?:LOW|TALL|HIGH|STANDARD|STD)\s+SEAT)\s*$/i';

    /** Cuvinte omise din codul de produs generat (ca în referințele BikerShop). */
    private const SKU_STOP = ['abs', 'euro', '5', 'seat', 'edition', 'ed'];

    /** „Tip motocicleta" din feed → slug-ul categoriei CFMOTO de pe portal (primul care se potrivește). */
    private const TYPE_CATEGORY = [
        'heritage'   => 'heritage',
        'cruiser'    => 'heritage',
        'touring'    => 'touring-travel',
        'dual sport' => 'naked',
        'sport'      => 'sport',
        'naked'      => 'naked',
    ];

    /** Atribut din feed → [coloana de specificații, etichetă afișată]. */
    private const SPEC_MAP = [
        'Motor'            => ['specs_engine', 'Motor'],
        'Capacitate'       => ['specs_engine', 'Capacitate'],
        'Putere'           => ['specs_engine', 'Putere'],
        'Putere constructiva' => ['specs_engine', 'Putere constructivă'],
        'Racire'           => ['specs_engine', 'Răcire'],
        'Combustibil'      => ['specs_engine', 'Combustibil'],
        'Sistem pornire'   => ['specs_engine', 'Sistem pornire'],
        'Cutie de viteze'  => ['specs_engine', 'Cutie de viteze'],
        'Transmisie'       => ['specs_engine', 'Transmisie'],
        'Omologare'        => ['specs_engine', 'Omologare'],
        'Consum'           => ['specs_engine', 'Consum'],
        'Emisii CO2/km'    => ['specs_engine', 'Emisii CO2/km'],
        'Viteza maxima'    => ['specs_engine', 'Viteză maximă'],
        'Suspensie fata'   => ['specs_chassis', 'Suspensie față'],
        'Suspensie spate'  => ['specs_chassis', 'Suspensie spate'],
        'Frana fata'       => ['specs_chassis', 'Frână față'],
        'Frana spate'      => ['specs_chassis', 'Frână spate'],
        'Anvelopa fata'    => ['specs_chassis', 'Anvelopă față'],
        'Anvelopa spate'   => ['specs_chassis', 'Anvelopă spate'],
        'Lungime'          => ['specs_dimensions', 'Lungime'],
        'Latime'           => ['specs_dimensions', 'Lățime'],
        'Inaltime'         => ['specs_dimensions', 'Înălțime'],
        'Inaltime sa'      => ['specs_dimensions', 'Înălțime șa'],
        'Ampatament'       => ['specs_dimensions', 'Ampatament'],
        'Garda la sol'     => ['specs_dimensions', 'Gardă la sol'],
        'Greutate'         => ['specs_dimensions', 'Greutate'],
        'Rezervor'         => ['specs_dimensions', 'Rezervor'],
        'Culori'           => ['specs_dimensions', 'Culori'],
    ];

    /** @var array<string,string> cheie normName|an → referința BikerShop */
    private array $bsRefs = [];

    public function __construct(private PDO $pdo, private string $mediaBase) {}

    // ===================== feed =====================

    /** Descarcă feed-ul (CSV) — cu reîncercare; aruncă excepție dacă pică. */
    public static function download(string $url = self::FEED_URL): string
    {
        $last = '';
        for ($i = 0; $i < 3; $i++) {
            if ($i > 0) {
                sleep(5 * $i);
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_HTTPHEADER     => ['User-Agent: Mozilla/5.0 (motociclete.com.ro feed sync)'],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body !== false && $code === 200 && str_starts_with((string) $body, 'ID;TITLE;')) {
                return (string) $body;
            }
            $last = "HTTP {$code} {$err}";
        }
        throw new RuntimeException("Feed indisponibil ({$last})");
    }

    /**
     * Rândurile de motociclete CFMOTO/CFLITE noi (nu rulate), indexate pe ID.
     * @return array<int,array<string,mixed>>
     */
    public static function parse(string $csv): array
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csv);
        rewind($fh);
        $head = fgetcsv($fh, 0, ';');
        if (!$head || $head[0] !== 'ID') {
            throw new RuntimeException('Feed: antet CSV invalid');
        }
        $out = [];
        while (($r = fgetcsv($fh, 0, ';')) !== false) {
            if (count($r) !== count($head)) {
                continue;
            }
            $row = array_combine($head, $r);
            if (!in_array(strtoupper(trim($row['BRAND'])), self::BRANDS, true)
                || strtoupper(trim($row['CATEGORY'])) !== 'MOTOCICLETE'
                || trim($row['VIZIBIL']) !== '1'
                || trim($row['SKIP_SH']) === '-1'
                || preg_match('/\bSH\s*$/', $row['TITLE'])) {
                continue;
            }
            $id = (int) $row['ID'];
            $list = (float) $row['PRICE_EUR'];
            $sale = (float) $row['SALE_PRICE_EUR'];
            if ($id <= 0 || $list <= 0) {
                continue;
            }
            $attrs = [];
            foreach (explode(',', $row['ATTRIBUTES']) as $pair) {
                [$k, $v] = array_pad(explode(':', $pair, 2), 2, '');
                $k = trim($k);
                $v = trim((string) preg_replace('/\s{2,}/', ', ', $v));
                if ($k !== '' && $v !== '') {
                    $attrs[$k] = $v;
                }
            }
            $title = trim($row['TITLE']);
            $year = (int) ($attrs['An'] ?? 0);
            if (!$year && preg_match("/'(\d{2})\s*$/", $title, $m)) {
                $year = 2000 + (int) $m[1];
            }
            $name = trim((string) preg_replace("/\s*'\d{2}\s*$/", '', $title));
            $variant = preg_match(self::VARIANT_RE, $name, $vm) ? strtoupper((string) preg_replace('/\s+/', ' ', $vm[1])) : '';
            $link = trim($row['LINK']);
            $out[$id] = [
                'id'          => $id,
                'title'       => $title,
                'name'        => $name,
                'base'        => $variant !== '' ? trim((string) preg_replace(self::VARIANT_RE, '', $name)) : $name,
                'variant'     => $variant,
                'brand'       => strtoupper(trim($row['BRAND'])),
                'year'        => $year ?: null,
                'list_eur'    => $list,
                'sale_eur'    => $sale > 0 && $sale < $list ? $sale : $list,
                'description' => trim($row['DESCRIPTION']),
                'link'        => $link,
                'ref'         => basename((string) parse_url($link, PHP_URL_PATH)),
                'images'      => array_values(array_filter(array_map('trim', explode(',', $row['IMAGES'])))),
                'attrs'       => $attrs,
            ];
        }
        fclose($fh);
        return $out;
    }

    /** Prețul de vânzare (cu TVA) + reducerea, în formatul coloanelor `products`. */
    public static function pricing(array $f): array
    {
        $list = (int) round($f['list_eur'] * self::VAT);
        $sale = (int) round($f['sale_eur'] * self::VAT);
        $pct = $sale < $list ? round((1 - $sale / $list) * 100, 2) : 0.0;
        return ['price' => $sale, 'discount_pct' => $pct, 'list' => $list];
    }

    /** ID-urile de feed dintr-o valoare „252559, 186369" → [252559, 186369]. @return int[] */
    public static function ids(?string $s): array
    {
        preg_match_all('/\d+/', (string) $s, $m);
        return array_values(array_unique(array_map('intval', $m[0])));
    }

    // ===================== legare produs ↔ feed =====================

    /** Cheie de comparație: fără brand, an, „Euro 5+", punctuație. */
    public static function normName(string $s): string
    {
        $s = strtolower($s);
        $s = (string) preg_replace("/'\d{2}\b|\b20\d{2}\b/", ' ', $s);
        $s = (string) preg_replace('/\beuro\s*5\s*\+?|\bcfmoto\b|\bcflite\b/', ' ', $s);
        $s = (string) preg_replace('/[^a-z0-9]+/', ' ', $s);
        // anul scurt rămas la final („800MT Sport 25", „675SR-R 26")
        $s = (string) preg_replace('/\s(2[0-9])\s*$/', ' ', $s);
        return str_replace(' ', '', trim($s));
    }

    /** @return array<int,array<string,mixed>> produsele CFMOTO din portal (+ `ids` = feed_ids parsate) */
    public function products(): array
    {
        $rows = $this->pdo->query(
            "SELECT id, name, slug, year, price, discount_pct, is_active, feed_ids, sku, supplier_ref, variants_json
             FROM products WHERE brand = 'cfmoto' ORDER BY is_active DESC, id"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['ids'] = self::ids($r['feed_ids']);
        }
        return $rows;
    }

    /**
     * Propune legături pentru produsele nelegate, pe nume normalizat + an:
     *  1. nume complet identic, un singur rând → leagă;
     *  2. numele = modelul de bază al unor VARIANTE (LOW/TALL SEAT…) → leagă toate variantele;
     *  3. numele = prefixul unui singur rând → leagă; mai multe → AMBIGUU (raportat, nelegat,
     *     iar rândurile candidate nu se creează ca produse noi).
     * Un rând de feed se leagă de un singur produs (prioritate: activ).
     * @return array{links:array<int,int[]>, ambiguous:array<int,string>, reserved:array<int,bool>}
     */
    public function proposeLinks(array $feed, array $products): array
    {
        $taken = [];
        foreach ($products as $p) {
            foreach ($p['ids'] as $fid) {
                $taken[$fid] = true;
            }
        }
        $links = [];
        $ambiguous = [];
        $reserved = [];
        foreach ($products as $p) {
            if ($p['ids']) {
                continue;
            }
            $key = self::normName($p['name']);
            if ($key === '') {
                continue;
            }
            $free = array_filter($feed, static fn ($f) => !isset($taken[$f['id']]) && (!$p['year'] || (int) $f['year'] === (int) $p['year']));

            $cands = array_filter($free, static fn ($f) => self::normName($f['name']) === $key);
            if (count($cands) !== 1) {
                $variants = array_filter($free, static fn ($f) => $f['variant'] !== '' && self::normName($f['base']) === $key);
                if ($variants) {
                    $cands = $variants;
                } elseif (!$cands) {
                    $cands = array_filter($free, static fn ($f) => str_starts_with(self::normName($f['name']), $key));
                    if (count($cands) > 1) {
                        foreach ($cands as $f) {
                            $reserved[$f['id']] = true;
                        }
                        $ambiguous[(int) $p['id']] = implode(', ', array_map(static fn ($f) => "#{$f['id']} {$f['title']}", $cands));
                        continue;
                    }
                }
            }
            if ($cands) {
                $links[(int) $p['id']] = array_keys($cands);
                foreach ($cands as $f) {
                    $taken[$f['id']] = true;
                }
            }
        }
        return ['links' => $links, 'ambiguous' => $ambiguous, 'reserved' => $reserved];
    }

    /** @param int[] $feedIds */
    public function link(int $productId, array $feedIds): void
    {
        $this->pdo->prepare("UPDATE products SET feed_ids = :f WHERE id = :id AND brand = 'cfmoto'")
            ->execute([':f' => $feedIds ? implode(',', $feedIds) : null, ':id' => $productId]);
    }

    // ===================== prețuri / variante / coduri =====================

    /**
     * Ce trebuie scris pe un produs legat, din rândurile lui de feed: preț (cea mai mică
     * variantă), reducere, variante (doar la ≥2 rânduri) și referința furnizorului.
     * Null dacă niciun rând legat nu mai e în feed.
     */
    public function target(array $p, array $feed): ?array
    {
        $rows = array_values(array_filter(array_map(static fn ($id) => $feed[$id] ?? null, $p['ids'])));
        if (!$rows) {
            return null;
        }
        usort($rows, static fn ($a, $b) => $a['sale_eur'] <=> $b['sale_eur']);
        $pr = self::pricing($rows[0]);
        $variants = $p['variants_json'];
        if (count($rows) >= 2) {
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'version'      => $this->variantLabel($r, $rows),
                    'transmission' => '',
                    'price'        => self::pricing($r)['price'],
                    'sku'          => $this->generateSku($r['name'], $r['year']),
                    'supplier_ref' => $r['ref'],
                    'feed_id'      => $r['id'],
                ];
            }
            $variants = (string) json_encode($out, JSON_UNESCAPED_UNICODE);
        }
        return [
            'price'         => $pr['price'],
            'discount_pct'  => $pr['discount_pct'],
            'list'          => $pr['list'],
            'variants_json' => $variants,
            'supplier_ref'  => implode(',', array_map(static fn ($r) => $r['ref'], $rows)),
            'titles'        => implode(' / ', array_map(static fn ($r) => $r['title'], $rows)),
        ];
    }

    /**
     * Diferențele pentru produsele legate + produsele legate care lipsesc din feed.
     * @return array{changes:array<int,array<string,mixed>>, missing:array<int,array<string,mixed>>}
     */
    public function priceDiff(array $feed, array $products): array
    {
        $changes = [];
        $missing = [];
        foreach ($products as $p) {
            if (!$p['ids']) {
                continue;
            }
            $t = $this->target($p, $feed);
            if ($t === null) {
                $missing[] = $p;
                continue;
            }
            if ((int) $p['price'] !== $t['price']
                || abs((float) $p['discount_pct'] - $t['discount_pct']) > 0.009
                || (string) $p['variants_json'] !== (string) $t['variants_json']
                || (string) $p['supplier_ref'] !== $t['supplier_ref']) {
                $changes[] = $p + ['t' => $t];
            }
        }
        return ['changes' => $changes, 'missing' => $missing];
    }

    public function applyTarget(int $productId, array $t): void
    {
        $this->pdo->prepare(
            "UPDATE products SET price = :p, discount_pct = :d, variants_json = :v, supplier_ref = :r WHERE id = :id"
        )->execute([':p' => $t['price'], ':d' => $t['discount_pct'], ':v' => $t['variants_json'] ?: null, ':r' => $t['supplier_ref'], ':id' => $productId]);
    }

    /**
     * Referințele motocicletelor CFMOTO de pe BikerShop (cod produs reutilizat).
     * @param array<int,array{reference:string,name:string}> $rows
     */
    public function setBikershopRefs(array $rows): void
    {
        foreach ($rows as $r) {
            $year = preg_match('/\b(20\d{2})\s*$/', $r['name'], $m) ? (int) $m[1]
                : (preg_match('/\s(2\d)\s*$/', $r['name'], $m) ? 2000 + (int) $m[1] : 0);
            $this->bsRefs[self::normName($r['name']) . '|' . $year] = $r['reference'];
        }
    }

    /** Codul de produs pentru un produs fără `sku`: referința BikerShop sau generat. */
    public function skuFor(array $p): string
    {
        return $this->bsRefs[self::normName($p['name']) . '|' . (int) $p['year']]
            ?? $this->generateSku((string) $p['name'], $p['year'] ? (int) $p['year'] : null);
    }

    public function setSku(int $productId, string $sku): void
    {
        $this->pdo->prepare("UPDATE products SET sku = :s WHERE id = :id")->execute([':s' => $sku, ':id' => $productId]);
    }

    /**
     * Cod în stilul referințelor BikerShop: brand-model[-prescurtări]-an, fără ABS/Euro/Edition
     * (ex. „CFMOTO 800MT Explore Edition - 2026" → cfmoto-800mt-expl-2026). Fără culoare.
     */
    public function generateSku(string $name, ?int $year): string
    {
        $name = (string) preg_replace("/\s*-\s*20\d{2}\s*$|'\d{2}\s*$|\s2\d\s*$|\b20\d{2}\b/", ' ', $name);
        $name = (string) preg_replace('/\beuro\s*5\s*\+?/i', ' ', $name);
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $parts = [];
        foreach ($words as $i => $w) {
            $s = slugify($w);
            if ($s === '' || in_array($s, self::SKU_STOP, true)) {
                continue;
            }
            // brandul + codul modelului întregi, restul prescurtate la 4 litere
            $parts[] = ($i <= 1 || preg_match('/\d/', $s)) ? $s : substr($s, 0, 4);
        }
        return implode('-', $parts) . ($year ? '-' . $year : '');
    }

    /** Eticheta unei variante: sufixul de șa sau ce diferă de prefixul comun al numelor. */
    private function variantLabel(array $r, array $rows): string
    {
        if ($r['variant'] !== '') {
            return $r['variant'];
        }
        $names = array_map(static fn ($x) => preg_split('/\s+/', $x['name']) ?: [], $rows);
        $common = 0;
        while (true) {
            $w = $names[0][$common] ?? null;
            if ($w === null) {
                break;
            }
            foreach ($names as $n) {
                if (($n[$common] ?? null) !== $w) {
                    break 2;
                }
            }
            $common++;
        }
        $rest = trim(implode(' ', array_slice(preg_split('/\s+/', $r['name']) ?: [], $common)));
        return $rest !== '' ? $rest : $r['name'];
    }

    // ===================== produse noi =====================

    /** Rândurile din feed nelegate de niciun produs și nevăzute încă. @return array<int,array<string,mixed>> */
    public function newRows(array $feed, array $products): array
    {
        $linked = [];
        foreach ($products as $p) {
            foreach ($p['ids'] as $fid) {
                $linked[$fid] = true;
            }
        }
        $seen = array_flip(array_map('intval', $this->pdo->query("SELECT feed_id FROM cfmoto_feed_seen")->fetchAll(PDO::FETCH_COLUMN)));
        return array_filter($feed, static fn ($f) => !isset($linked[$f['id']]) && !isset($seen[$f['id']]));
    }

    /**
     * Grupează rândurile noi: variantele aceluiași model (LOW/TALL SEAT, același an) → un produs.
     * @return array<int,array<int,array<string,mixed>>> listă de grupuri (rândurile unui produs)
     */
    public static function groupRows(array $rows): array
    {
        $groups = [];
        foreach ($rows as $f) {
            $key = $f['variant'] !== '' ? 'v|' . self::normName($f['base']) . '|' . $f['year'] : 'r|' . $f['id'];
            $groups[$key][] = $f;
        }
        // O „variantă" singură în grup rămâne produs separat cu numele complet.
        return array_values(array_map(static function (array $g): array {
            if (count($g) === 1) {
                $g[0]['base'] = $g[0]['name'];
            }
            return $g;
        }, $groups));
    }

    /** Marchează rândurile din feed ca „văzute" (+ produsul legat). */
    public function markSeen(array $feed, array $products): void
    {
        $byFeed = [];
        foreach ($products as $p) {
            foreach ($p['ids'] as $fid) {
                $byFeed[$fid] = (int) $p['id'];
            }
        }
        $st = $this->pdo->prepare(
            "INSERT INTO cfmoto_feed_seen (feed_id, title, product_id, first_seen, last_seen)
             VALUES (:f, :t, :p, NOW(), NOW())
             ON DUPLICATE KEY UPDATE title = VALUES(title), product_id = COALESCE(VALUES(product_id), product_id), last_seen = NOW()"
        );
        foreach ($feed as $f) {
            $st->execute([':f' => $f['id'], ':t' => mb_substr($f['title'], 0, 190), ':p' => $byFeed[$f['id']] ?? null]);
        }
    }

    /**
     * Datele complete ale unui produs nou (fără imagini), dintr-un grup de rânduri de feed
     * (1 rând = model simplu; ≥2 = variante). Textul/specificațiile vin din primul rând.
     */
    public function shapeProduct(array $group): array
    {
        $f = $group[0];
        $a = $f['attrs'];
        $brandWord = $f['brand'] === 'CFLITE' ? 'CFLITE' : 'CFMOTO';
        $model = (string) preg_replace('/^(CFMOTO|CFLITE)\s+/i', $brandWord . ' ', $f['base']);
        $name = $model . ($f['year'] ? ' - ' . $f['year'] : '');

        $specs = ['specs_engine' => '', 'specs_chassis' => '', 'specs_dimensions' => ''];
        foreach (self::SPEC_MAP as $key => [$col, $label]) {
            if (isset($a[$key])) {
                $specs[$col] .= '<tr><th>' . $this->h($label) . '</th><td>' . $this->h($a[$key]) . '</td></tr>';
            }
        }
        foreach ($specs as $col => $rows) {
            $specs[$col] = $rows === '' ? null : '<table>' . $rows . '</table>';
        }

        [$excerpt, $description] = $this->shapeDescription($f['description']);
        $p = ['name' => $name, 'year' => $f['year'], 'ids' => array_column($group, 'id'), 'variants_json' => null];
        $t = $this->target($p, array_column($group, null, 'id'));

        return [
            'brand'        => 'cfmoto',
            'category_id'  => $this->categoryFor((string) ($a['Tip motocicleta'] ?? '')),
            'name'         => $name,
            'slug'         => $this->uniqueSlug(slugify($name)),
            'year'         => $f['year'],
            'price'        => $t['price'],
            'discount_pct' => $t['discount_pct'],
            'variants_json' => $t['variants_json'],
            'licence'      => $this->licence($a),
            'excerpt'      => $excerpt,
            'description'  => $description,
            'rabla_eligible' => isset($a['Programul Rabla']) ? 1 : 0,
            'feed_ids'     => implode(',', $p['ids']),
            'sku'          => $this->skuFor($p),
            'supplier_ref' => $t['supplier_ref'],
        ] + $specs;
    }

    /**
     * Creează produsul INACTIV + descarcă imaginile (prima = cover, toate = galerie).
     * @return int id-ul produsului nou
     */
    public function createProduct(array $group): int
    {
        $d = $this->shapeProduct($group);
        $imgs = [];
        foreach ($group as $f) {
            foreach ($f['images'] as $url) {
                $base = strtolower(basename((string) parse_url($url, PHP_URL_PATH)));
                if (str_starts_with($base, 'banner')) {
                    continue; // bannere „unbox video" etc., nu fotografii de produs
                }
                if (($file = $this->grab($url, 'motociclete', $f['id'])) && !in_array($file, $imgs, true)) {
                    $imgs[] = $file;
                }
            }
        }
        $cover = null;
        if ($imgs) {
            $src = $this->mediaBase . '/cfmoto/motociclete/' . $imgs[0];
            $dir = $this->mediaBase . '/cfmoto/cover';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (@copy($src, $dir . '/' . $imgs[0])) {
                $cover = $imgs[0];
            }
        }
        $pos = (int) $this->pdo->query("SELECT COALESCE(MAX(position), 0) + 1 FROM products WHERE brand = 'cfmoto'")->fetchColumn();

        $cols = ['brand', 'category_id', 'name', 'slug', 'year', 'price', 'discount_pct', 'variants_json', 'licence', 'cover_image',
            'excerpt', 'description', 'specs_engine', 'specs_chassis', 'specs_dimensions', 'is_active', 'rabla_eligible', 'position',
            'feed_ids', 'sku', 'supplier_ref'];
        $d['cover_image'] = $cover;
        $d['is_active'] = 0;
        $d['position'] = $pos;
        $this->pdo->prepare(
            'INSERT INTO products (' . implode(', ', array_map(static fn ($c) => "`$c`", $cols)) . ')
             VALUES (' . implode(', ', array_map(static fn ($c) => ":$c", $cols)) . ')'
        )->execute(array_combine(array_map(static fn ($c) => ":$c", $cols), array_map(static fn ($c) => $d[$c] ?? null, $cols)));
        $id = (int) $this->pdo->lastInsertId();

        $ins = $this->pdo->prepare("INSERT INTO product_images (product_id, type, filename, position) VALUES (:p, 'gallery', :f, :pos)");
        foreach ($imgs as $i => $file) {
            $ins->execute([':p' => $id, ':f' => $file, ':pos' => $i]);
        }
        return $id;
    }

    // ===================== helpers =====================

    private function categoryFor(string $type): ?int
    {
        $t = strtolower($type);
        $slug = 'naked';
        foreach (self::TYPE_CATEGORY as $needle => $cat) {
            if (str_contains($t, $needle)) {
                $slug = $cat;
                break;
            }
        }
        $st = $this->pdo->prepare("SELECT id FROM categories WHERE brand = 'cfmoto' AND slug = :s");
        $st->execute([':s' => $slug]);
        $id = $st->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    /** Categoria de permis din capacitate + putere (A1 ≤125 cmc, A2 ≤35 kW ≈ 47,6 CP). */
    private function licence(array $a): ?string
    {
        $cc = (float) str_replace(',', '.', (string) ($a['Capacitate'] ?? '0'));
        $hp = (float) str_replace(',', '.', (string) ($a['Putere'] ?? '0'));
        if ($cc > 0 && $cc <= 125) {
            return 'A1';
        }
        if ($hp <= 0) {
            return null;
        }
        return $hp <= 47.6 ? 'A2' : 'A';
    }

    /**
     * Descrierea din feed = text simplu, paragrafele despărțite prin 2+ spații, lista
     * „Caracteristici tehnice:" la final + disclaimer. → [excerpt, description HTML].
     */
    private function shapeDescription(string $text): array
    {
        $text = (string) preg_replace('/\s*Pentru mai multe detalii vezi aici\.?/i', '', $text);
        $parts = array_values(array_filter(array_map('trim', preg_split('/\s{2,}/', $text) ?: [])));
        $html = '';
        $inList = false;
        foreach ($parts as $p) {
            if (preg_match('/^Caracteristici tehnice:?$/i', $p)) {
                $html .= '<h3>Caracteristici tehnice</h3><ul>';
                $inList = true;
                continue;
            }
            if ($inList && preg_match('/^[^.]{2,40}:\s/', $p)) {
                $html .= '<li>' . $this->h($p) . '</li>';
                continue;
            }
            if ($inList) {
                $html .= '</ul>';
                $inList = false;
            }
            $html .= '<p>' . $this->h($p) . '</p>';
        }
        if ($inList) {
            $html .= '</ul>';
        }
        $first = $parts[0] ?? '';
        if (mb_strlen($first) > 260) {
            $cut = mb_substr($first, 0, 260);
            $first = mb_substr($cut, 0, (int) mb_strrpos($cut, ' ')) . '…';
        }
        return [$first !== '' ? '<p>' . $this->h($first) . '</p>' : '', $html];
    }

    private function uniqueSlug(string $base): string
    {
        $st = $this->pdo->prepare("SELECT 1 FROM products WHERE brand = 'cfmoto' AND slug = :s");
        $slug = $base;
        for ($i = 2; ; $i++) {
            $st->execute([':s' => $slug]);
            if (!$st->fetchColumn()) {
                return $slug;
            }
            $slug = $base . '-' . $i;
        }
    }

    private function grab(string $url, string $folder, int $feedId): ?string
    {
        $name = basename((string) parse_url($url, PHP_URL_PATH));
        $name = 'atvrom-' . $feedId . '-' . (preg_replace('/[^A-Za-z0-9._-]/', '-', $name) ?? $name);
        if (!preg_match('/\.(jpe?g|png|webp)$/i', $name)) {
            return null;
        }
        $dir = $this->mediaBase . '/cfmoto/' . $folder;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $dest = $dir . '/' . $name;
        if (is_file($dest) && filesize($dest) > 0) {
            return $name;
        }
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_HTTPHEADER     => ['User-Agent: Mozilla/5.0'],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code !== 200 || strlen((string) $body) < 1000) {
                return null;
            }
            file_put_contents($dest, $body);
            return $name;
        } catch (Throwable) {
            return null;
        }
    }

    private function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
