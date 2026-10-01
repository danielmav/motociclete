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
 * - Legătura produs↔feed = `products.feed_id` (= coloana ID din feed). Produsele nelegate
 *   se leagă automat pe nume normalizat + an (vezi link()); cazurile ambigue se leagă
 *   manual (admin, câmpul „ID feed ATVROM", sau CLI --link=PID:FEED).
 * - Produse noi: rândurile din feed nelegate ȘI nevăzute niciodată (`cfmoto_feed_seen`)
 *   → produs INACTIV complet (descriere, specificații, imagini). Un produs nou șters din
 *   admin NU se recreează (rândul rămâne „văzut").
 * - Produsele legate care dispar din feed doar se RAPORTEAZĂ (nu se dezactivează).
 */
final class FeedSync
{
    public const FEED_URL = 'https://advrider.ro/_catalog/csv/magicparser/atvrom/unitati.php';
    private const VAT = 1.21;
    private const BRANDS = ['CFMOTO', 'CFLITE'];

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
            $out[$id] = [
                'id'          => $id,
                'title'       => $title,
                'name'        => trim((string) preg_replace("/\s*'\d{2}\s*$/", '', $title)),
                'brand'       => strtoupper(trim($row['BRAND'])),
                'year'        => $year ?: null,
                'list_eur'    => $list,
                'sale_eur'    => $sale > 0 && $sale < $list ? $sale : $list,
                'description' => trim($row['DESCRIPTION']),
                'link'        => trim($row['LINK']),
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

    /** @return array<int,array<string,mixed>> produsele CFMOTO din portal */
    public function products(): array
    {
        return $this->pdo->query(
            "SELECT id, name, slug, year, price, discount_pct, is_active, feed_id
             FROM products WHERE brand = 'cfmoto' ORDER BY is_active DESC, id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Propune legături pentru produsele fără feed_id: nume normalizat identic ȘI același an
     * (sau anul lipsă în portal și un singur rând în feed). Un rând de feed se leagă de un
     * singur produs (prioritate: activ). Ambiguități → raportate, nelegate.
     * @return array{links:array<int,int>, ambiguous:array<int,string>, reserved:array<int,bool>}
     */
    public function proposeLinks(array $feed, array $products): array
    {
        $taken = [];
        foreach ($products as $p) {
            if ($p['feed_id']) {
                $taken[(int) $p['feed_id']] = true;
            }
        }
        $byName = [];
        foreach ($feed as $f) {
            $byName[self::normName($f['name'])][] = $f;
        }
        $links = [];
        $ambiguous = [];
        $reserved = [];   // rânduri de feed candidate la o legare ambiguă → nu se creează ca noi
        foreach ($products as $p) {
            if ($p['feed_id']) {
                continue;
            }
            $key = self::normName($p['name']);
            $free = static fn (array $c): array => array_values(array_filter(
                $c,
                static fn ($f) => !isset($taken[$f['id']]) && (!$p['year'] || (int) $f['year'] === (int) $p['year'])
            ));
            $cands = $free($byName[$key] ?? []);
            // Fallback: numele din portal e prefixul unor variante din feed
            // („800MT-X" → „800MT-X LOW SEAT" / „TALL SEAT"). Unic → leagă; altfel ambiguu.
            if (!$cands && $key !== '') {
                foreach ($byName as $k => $rows) {
                    if (str_starts_with((string) $k, $key)) {
                        array_push($cands, ...$free($rows));
                    }
                }
            }
            if (count($cands) === 1) {
                $links[(int) $p['id']] = $cands[0]['id'];
                $taken[$cands[0]['id']] = true;
            } elseif (count($cands) > 1) {
                foreach ($cands as $f) {
                    $reserved[$f['id']] = true;
                }
                $ambiguous[(int) $p['id']] = implode(', ', array_map(static fn ($f) => "#{$f['id']} {$f['title']}", $cands));
            }
        }
        return ['links' => $links, 'ambiguous' => $ambiguous, 'reserved' => $reserved];
    }

    public function link(int $productId, ?int $feedId): void
    {
        $this->pdo->prepare("UPDATE products SET feed_id = :f WHERE id = :id AND brand = 'cfmoto'")
            ->execute([':f' => $feedId, ':id' => $productId]);
    }

    // ===================== prețuri =====================

    /**
     * Diferențele de preț pentru produsele legate + produsele legate care lipsesc din feed.
     * @return array{changes:array<int,array<string,mixed>>, missing:array<int,array<string,mixed>>}
     */
    public function priceDiff(array $feed, array $products): array
    {
        $changes = [];
        $missing = [];
        foreach ($products as $p) {
            $fid = (int) ($p['feed_id'] ?? 0);
            if (!$fid) {
                continue;
            }
            if (!isset($feed[$fid])) {
                $missing[] = $p;
                continue;
            }
            $new = self::pricing($feed[$fid]);
            if ((int) $p['price'] !== $new['price'] || abs((float) $p['discount_pct'] - $new['discount_pct']) > 0.009) {
                $changes[] = $p + ['new_price' => $new['price'], 'new_pct' => $new['discount_pct'], 'list' => $new['list'], 'feed_title' => $feed[$fid]['title']];
            }
        }
        return ['changes' => $changes, 'missing' => $missing];
    }

    public function applyPrice(int $productId, int $price, float $pct): void
    {
        $this->pdo->prepare("UPDATE products SET price = :p, discount_pct = :d WHERE id = :id")
            ->execute([':p' => $price, ':d' => $pct, ':id' => $productId]);
    }

    // ===================== produse noi =====================

    /** Rândurile din feed nelegate de niciun produs și nevăzute încă. @return array<int,array<string,mixed>> */
    public function newRows(array $feed, array $products): array
    {
        $linked = [];
        foreach ($products as $p) {
            if ($p['feed_id']) {
                $linked[(int) $p['feed_id']] = true;
            }
        }
        $seen = array_flip(array_map('intval', $this->pdo->query("SELECT feed_id FROM cfmoto_feed_seen")->fetchAll(PDO::FETCH_COLUMN)));
        return array_filter($feed, static fn ($f) => !isset($linked[$f['id']]) && !isset($seen[$f['id']]));
    }

    /** Marchează rândurile din feed ca „văzute" (+ produsul legat). */
    public function markSeen(array $feed, array $products): void
    {
        $byFeed = [];
        foreach ($products as $p) {
            if ($p['feed_id']) {
                $byFeed[(int) $p['feed_id']] = (int) $p['id'];
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

    /** Datele complete ale unui produs nou (fără imagini), din rândul de feed. */
    public function shapeProduct(array $f): array
    {
        $a = $f['attrs'];
        $pr = self::pricing($f);
        $brandWord = $f['brand'] === 'CFLITE' ? 'CFLITE' : 'CFMOTO';
        $name = (string) preg_replace('/^(CFMOTO|CFLITE)\s+/i', $brandWord . ' ', $f['name']);

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
        $slugBase = slugify($name) . ($f['year'] ? '-' . $f['year'] : '');

        return [
            'brand'        => 'cfmoto',
            'category_id'  => $this->categoryFor((string) ($a['Tip motocicleta'] ?? '')),
            'name'         => $name,
            'slug'         => $this->uniqueSlug($slugBase),
            'year'         => $f['year'],
            'price'        => $pr['price'],
            'discount_pct' => $pr['discount_pct'],
            'licence'      => $this->licence($a),
            'excerpt'      => $excerpt,
            'description'  => $description,
            'rabla_eligible' => isset($a['Programul Rabla']) ? 1 : 0,
            'feed_id'      => $f['id'],
        ] + $specs;
    }

    /**
     * Creează produsul INACTIV + descarcă imaginile (prima = cover, toate = galerie).
     * @return int id-ul produsului nou
     */
    public function createProduct(array $f): int
    {
        $d = $this->shapeProduct($f);
        $imgs = [];
        foreach ($f['images'] as $url) {
            $base = strtolower(basename((string) parse_url($url, PHP_URL_PATH)));
            if (str_starts_with($base, 'banner')) {
                continue; // bannere „unbox video" etc., nu fotografii de produs
            }
            if ($file = $this->grab($url, 'motociclete', $f['id'])) {
                $imgs[] = $file;
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

        $cols = ['brand', 'category_id', 'name', 'slug', 'year', 'price', 'discount_pct', 'licence', 'cover_image',
            'excerpt', 'description', 'specs_engine', 'specs_chassis', 'specs_dimensions', 'is_active', 'rabla_eligible', 'position', 'feed_id'];
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
