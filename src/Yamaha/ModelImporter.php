<?php

declare(strict_types=1);

namespace App\Yamaha;

use App\BikerShop\Client;
use Throwable;

/**
 * Preia un MODEL Yamaha (motocicletă) din backendul public `hyperdrive` și îl
 * transformă într-un „draft" ce pre-completează formularul de produs din admin.
 *
 * NU scrie în baza de date: produce doar un array shaped (+ descarcă imaginile în
 * /media/yamaha/...). Salvarea efectivă rămâne în mâna operatorului prin formularul
 * existent (App\Admin\ProductController::save), care reutilizează validările,
 * slug-ul, construirea tabelelor de specs și hook-ul de sync accesorii.
 *
 * Două endpointuri (confirmate din pagina yamaha-motor.eu):
 *   1. Produs:   /products/yme-prod-ro/slug=<slug>?locale=ro-RO
 *      → name, key(=PID accesorii), variants[0].{sku,images,prices,attributes}.
 *        attributes.techSpecifications = grupuri de specificații (ro-RO),
 *        attributes.features = listă de chei pt. blocurile de text.
 *   2. Text:     /custom-objects/yme-prod-ro/keys=<features>?locale=ro-RO
 *      → blocuri {header, body, images} (descrierile/„caracteristici cheie").
 *
 * Degradare grațioasă: dacă Yamaha pică, întoarce ok=false fără excepții.
 */
final class ModelImporter
{
    private const PRODUCT_URL = 'https://hyperdrive.yamaha-motor.eu/products/yme-prod-ro/slug=%SLUG%?locale=ro-RO';
    private const TEXT_URL     = 'https://hyperdrive.yamaha-motor.eu/custom-objects/yme-prod-ro/keys=%KEYS%?locale=ro-RO';
    private const LOCALE       = 'ro-RO';

    /** type imagine -> subfolder media (oglindă a schemei catalogului). */
    private const FOLDER = ['cover' => 'cover', 'color' => 'culori', 'gallery' => 'motociclete', 'detail' => 'detalii'];

    private string $mediaBase; // .../media
    private Client $bs;

    public function __construct(string $mediaBase, Client $bs)
    {
        $this->mediaBase = rtrim($mediaBase, '/\\');
        $this->bs = $bs;
    }

    /**
     * Extrage slug-ul Yamaha dintr-un URL de PDP (sau acceptă un slug brut).
     * Ex: https://www.yamaha-motor.eu/ro/ro/motorcycles/competition/pdp/<slug>/#frag
     */
    public static function slugFromUrl(string $input): string
    {
        $input = trim($input);
        // taie fragment + query
        $input = preg_replace('/[#?].*$/', '', $input) ?? $input;
        $input = rtrim($input, '/');
        if (stripos($input, '/pdp/') !== false) {
            $input = substr($input, (int) stripos($input, '/pdp/') + 5);
        } elseif (str_contains($input, '/')) {
            $input = substr($input, (int) strrpos($input, '/') + 1);
        }
        // .model.json sau alte sufixe AEM
        $input = preg_replace('/\.(model\.)?json$/i', '', $input) ?? $input;
        return strtolower(trim($input, '/'));
    }

    /**
     * Preia + shape-uiește un model. Nu aruncă excepții.
     * @return array{ok:bool,error:?string,draft:array<string,mixed>}
     */
    public function fetch(string $urlOrSlug, ?int $year = null): array
    {
        $out = ['ok' => false, 'error' => null, 'draft' => []];
        $slug = self::slugFromUrl($urlOrSlug);
        if ($slug === '') {
            $out['error'] = 'Link/slug Yamaha invalid';
            return $out;
        }

        $product = $this->getJson(str_replace('%SLUG%', rawurlencode($slug), self::PRODUCT_URL));
        if (!is_array($product) || empty($product['name'])) {
            $out['error'] = 'Modelul nu a putut fi preluat de la Yamaha (slug greșit sau Yamaha indisponibil)';
            return $out;
        }

        // Slug-ul Yamaha din URL e folosit verbatim pentru fetch, dar pentru portal îl
        // normalizăm prin slugify (transliterare ASCII) — altfel un slug Yamaha cu accente
        // (ex. ténéré-700-world-raid) ar ajunge nemapată în formular. Idempotent pe ASCII curat.
        $cleanSlug = slugify($slug);

        $variant = $product['variants'][0] ?? [];
        $attrs = [];
        foreach (($variant['attributes'] ?? []) as $a) {
            if (isset($a['name'])) {
                $attrs[(string) $a['name']] = $a['value'] ?? null;
            }
        }

        $pid = (string) ($product['key'] ?? '');
        $specs = $this->shapeSpecs($attrs['techSpecifications'] ?? null);

        // Blocuri de text (descrieri) cu imaginile lor INLINE + lista de URL-uri de descărcat.
        [$detailsHtml, $featureImages] = $this->fetchFeatures($attrs['features'] ?? []);

        // Imaginile de feature NU mai merg în galerie (detail) — apar inline în „Caracteristici".
        $images = $this->shapeImages($variant['images'] ?? [], []);

        $out['ok'] = true;
        $out['draft'] = [
            'name'         => $this->clean((string) $product['name']),
            // Slogan = headerul scurt al modelului (poate fi în engleză dacă Yamaha nu l-a tradus).
            'subtitle'     => $this->loc($attrs['productShortStoryHeader'] ?? null),
            'slug'         => $cleanSlug,
            'year'         => $year,
            'price'        => 0,                       // prețul RO e POA — se completează manual
            'discount_pct' => 0,
            'licence'      => '',
            // Descriere scurtă = introul scurt; descriere lungă = introul lung A/B/C (fallback story body).
            'excerpt'      => $this->paras($attrs, ['productShortIntro']),
            'description'  => $this->paras($attrs, ['productLongIntroA', 'productLongIntroB', 'productLongIntroC'])
                ?: $this->paras($attrs, ['productStoryBodyA', 'productStoryBodyB', 'productStoryBodyC']),
            'promo_html'   => '',
            'details_html' => $detailsHtml,
            'variants_json' => $this->shapeVariants($product['variants'] ?? []),
            'video'        => '',
            'keywords'     => '',
            'yamaha_pid'   => preg_match('/\d+/', $pid, $m) ? $m[0] : '',
            'bs_product_id' => $this->resolveBs($cleanSlug, (string) $product['name'], $year),
            'specs'        => $specs,                  // [engine|chassis|dimensions|connectivity => [[label,value],...]]
            'images'       => $images,                 // URL-uri în acest stadiu; downloadImages() le face nume de fișier
            'feature_images' => $featureImages,        // URL-uri remote din details_html; downloadImages() le localizează
        ];
        return $out;
    }

    /**
     * Descarcă imaginile din draft în /media/yamaha/... și înlocuiește URL-urile
     * cu numele fișierelor (ca în schema product_images). Cover devine un nume simplu.
     * @param array<string,mixed> $draft
     * @return array<string,mixed>
     */
    public function downloadImages(array $draft): array
    {
        $imgs = $draft['images'] ?? [];
        // cover (un singur URL)
        $coverUrl = (string) ($imgs['cover'] ?? '');
        $draft['cover_image'] = $coverUrl !== '' ? ($this->grab($coverUrl, 'cover') ?? '') : '';
        // liste
        foreach (['color', 'gallery', 'detail'] as $t) {
            $files = [];
            foreach ((array) ($imgs[$t] ?? []) as $url) {
                $f = $this->grab((string) $url, $t);
                if ($f !== null && !in_array($f, $files, true)) {
                    $files[] = $f;
                }
            }
            $draft['images'][$t] = $files;
        }
        unset($draft['images']['cover']);

        // Imaginile inline din „Caracteristici": descarcă-le în /media/yamaha/detalii/ și
        // rescrie URL-urile remote din details_html cu calea publică locală.
        $html = (string) ($draft['details_html'] ?? '');
        foreach ((array) ($draft['feature_images'] ?? []) as $url) {
            $url = (string) $url;
            $f = $this->grab($url, 'detail');
            if ($f !== null) {
                $html = str_replace($url, '/media/yamaha/' . self::FOLDER['detail'] . '/' . $f, $html);
            }
        }
        $draft['details_html'] = $html;
        unset($draft['feature_images']);
        return $draft;
    }

    // -- shaping --------------------------------------------------------------

    /**
     * techSpecifications → 4 coloane (engine/chassis/dimensions/connectivity).
     * Fiecare grup: {name(multi-locale), specifications:[{name, localizedValue},...]}.
     * @return array<string,array<int,array{label:string,value:string}>>
     */
    private function shapeSpecs(mixed $techSpecifications): array
    {
        $out = ['engine' => [], 'chassis' => [], 'dimensions' => [], 'connectivity' => []];
        if (!is_array($techSpecifications)) {
            return $out;
        }
        foreach ($techSpecifications as $group) {
            if (!is_array($group)) {
                continue;
            }
            $groupName = $this->loc($this->field($group, 'name'));
            $rowsRaw = $this->field($group, 'specifications');
            if (!is_array($rowsRaw) || $rowsRaw === []) {
                continue;
            }
            $col = $this->mapSpecGroup($groupName);
            foreach ($rowsRaw as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $label = $this->loc($this->field($row, 'name'));
                $value = $this->loc($this->field($row, 'localizedValue'));
                if ($label !== '') {
                    $out[$col][] = ['label' => $label, 'value' => $value];
                }
            }
        }
        return $out;
    }

    /** Mapează numele unui grup Yamaha la una din cele 4 secțiuni ale noastre. */
    private function mapSpecGroup(string $name): string
    {
        $n = $this->fold($name);
        if (preg_match('/motor|engine|transmis|propuls|performan/', $n)) {
            return 'engine';
        }
        if (preg_match('/sasiu|chassis|cadru|suspens|frana|brake|roti|wheel|anvelop|tyre/', $n)) {
            return 'chassis';
        }
        if (preg_match('/dimensi|dimension|greutate|weight|capacit|rezervor|fuel/', $n)) {
            return 'dimensions';
        }
        // connectivity = catch-all (screen, connectivity, rider aids, lighting, comfort, storage, smart, ...)
        return 'connectivity';
    }

    /**
     * Categorizează imaginile produsului din etichete (Studio/Static/Action/360/Detail).
     * cover = primul Studio; color = un Studio per culoare distinctă; gallery = Static+Action;
     * detail = imaginile din blocurile de text. 360-Degrees (frame-uri de rotație) sunt ignorate.
     * @param array<int,array<string,mixed>> $productImages
     * @param array<int,string> $featureImages
     * @return array{cover:string,color:array<int,string>,gallery:array<int,string>,detail:array<int,string>}
     */
    private function shapeImages(array $productImages, array $featureImages): array
    {
        $cover = '';
        $color = [];     // url, dedup pe culoare
        $colorSeen = [];
        $gallery = [];
        foreach ($productImages as $im) {
            $url = (string) ($im['url'] ?? '');
            $label = (string) ($im['label'] ?? $url);
            if ($url === '') {
                continue;
            }
            $type = preg_match('/-(Studio|Static|Action|360-Degrees|Detail)-/i', $label, $mm) ? strtolower($mm[1]) : '';
            $colorTok = preg_match('/_([A-Za-z]+)-(?:Studio|Static|Action|360|Detail)/i', $label, $cm) ? strtolower($cm[1]) : '';
            if ($type === 'studio') {
                if ($cover === '') {
                    $cover = $url; // primul studio = cover
                }
                if ($colorTok !== '' && !isset($colorSeen[$colorTok])) {
                    $colorSeen[$colorTok] = true;
                    $color[] = $url; // un studio reprezentativ per culoare
                }
            } elseif ($type === 'static' || $type === 'action') {
                $gallery[] = $url;
            }
            // 360-degrees / detail din produs: ignorate (zgomot / duplicat)
        }
        if ($cover === '' && $gallery !== []) {
            $cover = $gallery[0];
        }
        return [
            'cover'   => $cover,
            'color'   => $color,
            'gallery' => $gallery,
            'detail'  => array_values(array_unique($featureImages)),
        ];
    }

    /**
     * Variantele de putere/transmisie → JSON pt. tabul „Preturi" (doar dacă există
     * ≥2 combinații distincte versiune+transmisie). Culorile NU contează (același preț).
     * Fiecare variantă: attributes[{name,value}] + prices[{amount,currencyCode}].
     * @param array<int,array<string,mixed>> $variants
     * @return string JSON [{version,transmission,price}] sau '' dacă o singură variantă.
     */
    private function shapeVariants(array $variants): string
    {
        $rows = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $attrs = [];
            foreach (($variant['attributes'] ?? []) as $a) {
                if (isset($a['name'])) {
                    $attrs[(string) $a['name']] = $a['value'] ?? null;
                }
            }
            $version = $this->clean((string) ($attrs['productMotorcyclePowerVersion'] ?? ''));
            $trans   = $this->clean((string) ($attrs['productMotorcycleTransmission'] ?? ''));
            if ($version === '' && $trans === '') {
                continue;
            }
            $price = 0;
            foreach ((array) ($variant['prices'] ?? []) as $pr) {
                $cc = strtoupper((string) ($pr['currencyCode'] ?? ''));
                if ($cc === '' || $cc === 'EUR') {
                    $price = (int) round((float) ($pr['amount'] ?? 0));
                    break;
                }
            }
            // Dedup pe combinația versiune+transmisie (culorile = sub-variante).
            $key = $version . '|' . $trans;
            if (!isset($rows[$key])) {
                $rows[$key] = ['version' => $version, 'transmission' => $trans, 'price' => $price];
            } elseif ($rows[$key]['price'] === 0 && $price > 0) {
                $rows[$key]['price'] = $price;
            }
        }
        if (count($rows) < 2) {
            return '';
        }
        return (string) json_encode(array_values($rows), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Blocurile de text (custom-objects) → HTML pt. „Caracteristici cheie" + imaginile lor.
     * @param array<int,mixed> $keys
     * @return array{0:string,1:array<int,string>}
     */
    private function fetchFeatures(array $keys): array
    {
        $keys = array_values(array_filter(array_map(static fn ($k) => preg_replace('/\D/', '', (string) $k), $keys)));
        if ($keys === []) {
            return ['', []];
        }
        $url = str_replace('%KEYS%', implode('%7C', $keys), self::TEXT_URL);
        $data = $this->getJson($url);
        if (!is_array($data)) {
            return ['', []];
        }
        // index pe cheie ca să păstrăm ordinea din `features`
        $byKey = [];
        foreach ($data as $obj) {
            if (is_array($obj) && isset($obj['key'])) {
                $byKey[(string) $obj['key']] = $obj['value'] ?? [];
            }
        }
        $html = '';
        $images = [];
        foreach ($keys as $k) {
            $v = $byKey[$k] ?? null;
            if (!is_array($v)) {
                continue;
            }
            $header = $this->clean((string) ($v['header'] ?? ''));
            $body = $this->clean((string) ($v['body'] ?? ''));
            if ($header !== '') {
                $html .= '<h3>' . htmlspecialchars($header, ENT_QUOTES, 'UTF-8') . '</h3>';
            }
            if ($body !== '') {
                $html .= '<p>' . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) . '</p>';
            }
            foreach ((array) ($v['images'] ?? []) as $img) {
                $img = (string) $img;
                if ($img === '') {
                    continue;
                }
                $images[] = $img;
                // Imaginea apare INLINE în „Caracteristici". URL-ul remote e rescris cu
                // calea locală în downloadImages() (după descărcare în /media/yamaha/detalii/).
                $html .= '<figure><img src="' . htmlspecialchars($img, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($header, ENT_QUOTES, 'UTF-8') . '" loading="lazy"></figure>';
            }
        }
        return [$html, $images];
    }

    /**
     * Best-effort: leagă modelul de produsul-motocicletă de pe BikerShop (bs_product_id)
     * după referință == slug (ca migrate_bs_models.php). Dacă nu găsește, null —
     * maparea fină (fuzzy pe nume) rămâne pe seama `database/migrate_bs_models.php`.
     */
    private function resolveBs(string $slug, string $name, ?int $year): ?int
    {
        try {
            $cands = [$slug];
            if ($year) {
                $cands[] = $slug . '-' . $year;
                $cands[] = slugify($name) . '-' . $year;
            }
            $cands[] = slugify($name);
            $map = $this->bs->productIdsByReferences(array_values(array_unique($cands)));
            foreach ($cands as $c) {
                if (isset($map[$c])) {
                    return (int) $map[$c];
                }
            }
        } catch (Throwable) {
            // ignore
        }
        return null;
    }

    // -- helpers --------------------------------------------------------------

    /** Valoarea unui câmp {name,value} dintr-o listă de perechi. */
    private function field(array $pairs, string $name): mixed
    {
        foreach ($pairs as $it) {
            if (is_array($it) && ($it['name'] ?? null) === $name) {
                return $it['value'] ?? null;
            }
        }
        return null;
    }

    /** Extrage textul ro-RO (fallback en / prima valoare) dintr-o valoare multi-locale. */
    private function loc(mixed $val): string
    {
        if (is_array($val)) {
            $val = $val[self::LOCALE] ?? $val['en'] ?? (reset($val) ?: '');
        }
        return $this->clean((string) $val);
    }

    /** Normalizează spațiile/CR. */
    private function clean(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace(["\r", "\n"], ' ', $s)));
    }

    /**
     * Construiește HTML pt. câmpurile WYSIWYG (excerpt/description): un <p> per
     * atribut non-gol, în ordinea dată. Textul e plain → escapat.
     * @param array<string,mixed> $attrs
     * @param array<int,string> $names
     */
    private function paras(array $attrs, array $names): string
    {
        $html = '';
        foreach ($names as $n) {
            $t = $this->loc($attrs[$n] ?? null);
            if ($t !== '') {
                $html .= '<p>' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</p>';
            }
        }
        return $html;
    }

    /** Lowercase + fără diacritice pt. potrivirea numelor de grup. */
    private function fold(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        return strtr($s, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
    }

    /** @return array<string,mixed>|null */
    private function getJson(string $url): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0',
                'Accept: application/json',
                'Referer: https://www.yamaha-motor.eu/',
                'Origin: https://www.yamaha-motor.eu',
            ],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return $body === false ? null : json_decode((string) $body, true);
    }

    /**
     * Descarcă o imagine în /media/yamaha/<folder>/ și întoarce numele fișierului
     * (sau null la eșec). Sare peste descărcare dacă fișierul există deja.
     */
    private function grab(string $url, string $type): ?string
    {
        if ($url === '') {
            return null;
        }
        $folder = self::FOLDER[$type] ?? $type;
        $name = basename((string) parse_url($url, PHP_URL_PATH));
        $name = preg_replace('/[^A-Za-z0-9._-]/', '-', $name) ?? $name;
        if ($name === '' || !preg_match('/\.(jpe?g|png|webp)$/i', $name)) {
            return null;
        }
        $dir = $this->mediaBase . '/yamaha/' . $folder;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $dest = $dir . '/' . $name;
        if (is_file($dest) && filesize($dest) > 0) {
            return $name; // deja descărcat
        }
        $fp = @fopen($dest, 'wb');
        if (!$fp) {
            return null;
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0', 'Referer: https://www.yamaha-motor.eu/'],
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if ($ok === false || $code >= 400 || !is_file($dest) || filesize($dest) === 0) {
            @unlink($dest);
            return null;
        }
        return $name;
    }
}
