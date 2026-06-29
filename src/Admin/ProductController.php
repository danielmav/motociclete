<?php

declare(strict_types=1);

namespace App\Admin;

use App\Catalog\Repository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin CRUD for catalog products (`products` + `product_images`). Rich text via
 * WYSIWYG (excerpt/description/details_html); specs (engine/chassis/dimensions/
 * connectivity) edited as structured label→value rows and stored as HTML tables.
 * Images: cover (cover_image) + color/gallery/detail (product_images). Saving
 * busts the cached mega menu.
 */
final class ProductController extends BaseController
{
    private const SPECS = ['engine' => 'specs_engine', 'chassis' => 'specs_chassis', 'dimensions' => 'specs_dimensions', 'connectivity' => 'specs_connectivity'];

    private function repo(): Repository
    {
        return $this->container['catalog'];
    }

    /** GET {base}/produse */
    public function index(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $qp = $request->getQueryParams();
        $brand = (string) ($qp['brand'] ?? '');
        $brand = in_array($brand, ['yamaha', 'cfmoto'], true) ? $brand : null;
        $categoryId = ((int) ($qp['category_id'] ?? 0)) ?: null;
        return $this->render($response, 'admin/products/index.twig', [
            'active'      => 'products',
            'brand'       => $brand,
            'category_id' => $categoryId,
            'list_qs'     => $this->listQuery($categoryId, $brand),
            'products'    => $this->repo()->adminProducts($brand, $categoryId),
            'categories'  => $this->categorySelect(),
            'saved'       => isset($qp['ok']),
            'yerr'        => isset($qp['yerr']),
        ]);
    }

    /** GET {base}/produse/{id} — form (id 0 = new). */
    public function form(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $id = (int) ($args['id'] ?? 0);
        $q = $request->getQueryParams();
        $p = $id > 0 ? $this->repo()->productById($id) : null;
        $brand = $p['brand'] ?? ($q['brand'] ?? 'yamaha');

        $specRows = [];
        foreach (self::SPECS as $key => $col) {
            $specRows[$key] = $p ? $this->specRows($p[$col] ?? '') : [];
        }
        $variantRows = $p ? $this->variantRows($p['variants_json'] ?? '') : [];
        $images = [];
        if ($p) {
            foreach (['color', 'gallery', 'detail'] as $t) {
                $images[$t] = $this->repo()->productImages($id, $t);
            }
        }

        // Draft pre-completat din importul Yamaha (vezi importYamaha()). Se consumă o
        // singură dată; pre-completează formularul gol, operatorul verifică + salvează.
        $fromYamaha = false;
        if ($id === 0 && isset($q['from_yamaha']) && !empty($_SESSION['yamaha_draft'])) {
            $d = $_SESSION['yamaha_draft'];
            unset($_SESSION['yamaha_draft']);
            $fromYamaha = true;
            $brand = 'yamaha';
            $p = [
                'brand'         => 'yamaha',
                'category_id'   => $d['category_id'] ?? null,
                'name'          => $d['name'] ?? '',
                'subtitle'      => $d['subtitle'] ?? '',
                'slug'          => $d['slug'] ?? '',
                'year'          => $d['year'] ?? null,
                'price'         => $d['price'] ?? 0,
                'discount_pct'  => $d['discount_pct'] ?? 0,
                'licence'       => $d['licence'] ?? '',
                'cover_image'   => $d['cover_image'] ?? '',
                'excerpt'       => $d['excerpt'] ?? '',
                'description'   => $d['description'] ?? '',
                'promo_html'    => $d['promo_html'] ?? '',
                'details_html'  => $d['details_html'] ?? '',
                'variants_json' => $d['variants_json'] ?? '',
                'video'         => $d['video'] ?? '',
                'keywords'      => $d['keywords'] ?? '',
                'yamaha_pid'    => $d['yamaha_pid'] ?? '',
                'bs_product_id' => $d['bs_product_id'] ?? null,
                'is_active'     => 1,
                'position'      => 0,
            ];
            foreach (self::SPECS as $key => $col) {
                $specRows[$key] = $d['specs'][$key] ?? [];
            }
            $variantRows = $this->variantRows($d['variants_json'] ?? '');
            foreach (['color', 'gallery', 'detail'] as $t) {
                $images[$t] = array_map(static fn ($f) => ['filename' => $f], $d['images'][$t] ?? []);
            }
        }

        // Lista la care duce „Înapoi” (și implicit categoria din care face parte modelul):
        // categoria produsului dacă există, altfel filtrul cu care s-a deschis formularul.
        $backCat = ($p['category_id'] ?? null) ?: (((int) ($q['category_id'] ?? 0)) ?: null);
        $backBrand = $p['brand'] ?? ($q['brand'] ?? null);
        $backBrand = in_array($backBrand, ['yamaha', 'cfmoto'], true) ? $backBrand : null;

        return $this->render($response, 'admin/products/form.twig', [
            'active'     => 'products',
            'p'          => $p,
            'id'         => $id,
            'brand'      => $brand,
            'back_qs'    => $this->listQuery($backCat, $backBrand),
            'categories' => $this->categorySelect(),
            'specRows'   => $specRows,
            'variantRows' => $variantRows,
            'images'     => $images,
            'fromYamaha' => $fromYamaha,
            'saved'      => isset($q['ok']),
            'sync'       => isset($q['acc']) ? ['fetched' => (int) $q['acc'], 'new' => (int) ($q['accnew'] ?? 0), 'unmatched' => (int) ($q['accunm'] ?? 0)] : null,
            'syncErr'    => isset($q['accerr']),
        ]);
    }

    /**
     * POST {base}/produse/import-yamaha — preia un model de pe yamaha-motor.eu și
     * pre-completează formularul de produs (nu scrie în DB; operatorul salvează).
     */
    public function importYamaha(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, '/produse');
        }
        $url = trim((string) ($body['yamaha_url'] ?? ''));
        if ($url === '') {
            return $this->to($response, '/produse?yerr=1');
        }
        $year  = ((int) ($body['year'] ?? 0)) ?: null;
        $catId = ((int) ($body['category_id'] ?? 0)) ?: null;

        $importer = $this->container['yamaha_model_importer'];
        $res = $importer->fetch($url, $year);
        if (!$res['ok']) {
            return $this->to($response, '/produse?yerr=1');
        }
        $draft = $importer->downloadImages($res['draft']);
        $draft['category_id'] = $catId;
        $draft['year'] = $year ?? ($draft['year'] ?? null);
        $_SESSION['yamaha_draft'] = $draft;
        return $this->to($response, '/produse/0?from_yamaha=1');
    }

    /** POST {base}/produse/{id} */
    public function save(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, '/produse');
        }
        $id = (int) ($args['id'] ?? 0);
        $brand = in_array($body['brand'] ?? '', ['yamaha', 'cfmoto'], true) ? $body['brand'] : 'yamaha';
        $prev = $id > 0 ? $this->repo()->productById($id) : null;
        $prevPid = (string) ($prev['yamaha_pid'] ?? '');
        $prevSlug = (string) ($prev['slug'] ?? '');
        $prevBrand = (string) ($prev['brand'] ?? $brand);

        $data = [
            'brand'        => $brand,
            'category_id'  => ((int) ($body['category_id'] ?? 0)) ?: null,
            'name'         => trim((string) ($body['name'] ?? '')),
            'subtitle'     => trim((string) ($body['subtitle'] ?? '')),
            'slug'         => slugify((string) ($body['slug'] ?? '')) ?: slugify((string) ($body['name'] ?? '')),
            'year'         => ((int) ($body['year'] ?? 0)) ?: null,
            'price'        => (int) ($body['price'] ?? 0),
            'discount_pct' => (float) str_replace(',', '.', (string) ($body['discount_pct'] ?? '0')),
            'licence'      => trim((string) ($body['licence'] ?? '')) ?: null,
            'cover_image'  => trim((string) ($body['cover_image'] ?? '')) ?: null,
            'excerpt'      => trim((string) ($body['excerpt'] ?? '')),
            'description'  => trim((string) ($body['description'] ?? '')),
            'promo_html'   => trim((string) ($body['promo_html'] ?? '')),
            'details_html' => trim((string) ($body['details_html'] ?? '')),
            'variants_json' => $this->buildVariantsJson(
                (array) ($body['var_version'] ?? []),
                (array) ($body['var_transmission'] ?? []),
                (array) ($body['var_price'] ?? [])
            ),
            'video'        => trim((string) ($body['video'] ?? '')) ?: null,
            'keywords'     => trim((string) ($body['keywords'] ?? '')),
            'is_active'    => empty($body['is_active']) ? 0 : 1,
            'position'     => (int) ($body['position'] ?? 0),
            // PID Yamaha (doar cifre) pt. importul accesoriilor originale; gol -> NULL.
            'yamaha_pid'   => ($brand === 'yamaha' && preg_match('/\d+/', (string) ($body['yamaha_pid'] ?? ''), $mm)) ? $mm[0] : null,
            // Produsul-motocicletă de pe BikerShop (tab OEM). Câmp ascuns pre-completat
            // → se păstrează la editări; setat de import sau de migrate_bs_models.php.
            'bs_product_id' => ((int) ($body['bs_product_id'] ?? 0)) ?: null,
        ];
        foreach (self::SPECS as $key => $col) {
            $data[$col] = $this->buildSpecTable(
                (array) ($body['spec_' . $key . '_label'] ?? []),
                (array) ($body['spec_' . $key . '_value'] ?? [])
            );
        }

        $pid = $this->repo()->saveProduct($id > 0 ? $id : null, $data);

        // 301 automat: dacă slug-ul s-a schimbat, URL-ul vechi va redirecta la cel nou.
        // Brandul vechi (din URL-ul vechi) e cel relevant pentru maparea slug-ului retras.
        if ($id > 0) {
            $this->repo()->recordSlugChange($pid, $prevBrand, $prevSlug, $data['slug']);
        }

        foreach (['color', 'gallery', 'detail'] as $t) {
            $this->repo()->replaceImages($pid, $t, (array) ($body[$t] ?? []));
        }

        $this->bustMenuCache();

        // Sincronizează accesoriile originale Yamaha DOAR când PID-ul e nou sau s-a
        // schimbat (model nou ori PID editat) — evită cereri la Yamaha la fiecare
        // editare de descriere/preț. Protejat: nu strică salvarea dacă Yamaha pică.
        $newPid = (string) ($data['yamaha_pid'] ?? '');
        $sync = '';
        if ($brand === 'yamaha' && $newPid !== '' && ($id <= 0 || $newPid !== $prevPid)) {
            $sync = $this->syncQuery($this->container['accessories_importer']->importForModel($pid, true));
        }
        return $this->to($response, '/produse/' . $pid . '?ok=1' . $sync);
    }

    /** POST {base}/produse/{id}/sync-accesorii — resincronizare manuală a accesoriilor. */
    public function syncAccessories(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        if (!$this->csrfOk($this->body($request))) {
            return $this->to($response, '/produse');
        }
        $id = (int) ($args['id'] ?? 0);
        $sync = $this->syncQuery($this->container['accessories_importer']->importForModel($id, true));
        return $this->to($response, '/produse/' . $id . '?ok=1' . $sync);
    }

    /** @param array<string,mixed> $r Build the ?acc=…&accnew=… query for the flash banner. */
    private function syncQuery(array $r): string
    {
        return $r['ok']
            ? sprintf('&acc=%d&accnew=%d&accunm=%d', $r['fetched'], $r['new'], $r['unmatched'])
            : '&accerr=1';
    }

    /** POST {base}/produse/{id}/delete */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if ($this->csrfOk($body)) {
            $this->repo()->deleteProduct((int) ($args['id'] ?? 0));
            $this->bustMenuCache();
        }
        // Întoarce-te în aceeași categorie/brand din care s-a șters.
        $catId = ((int) ($body['category_id'] ?? 0)) ?: null;
        $brand = in_array($body['brand'] ?? '', ['yamaha', 'cfmoto'], true) ? $body['brand'] : null;
        $qs = $this->listQuery($catId, $brand);
        return $this->to($response, '/produse' . ($qs === '' ? '?ok=1' : $qs . '&ok=1'));
    }

    // -- helpers --------------------------------------------------------------

    /** Query string (?brand=…&category_id=…) pentru a păstra filtrul listei de produse. */
    private function listQuery(?int $catId, ?string $brand): string
    {
        $qs = [];
        if ($brand !== null && $brand !== '') {
            $qs['brand'] = $brand;
        }
        if ($catId) {
            $qs['category_id'] = $catId;
        }
        return $qs === [] ? '' : '?' . http_build_query($qs);
    }

    /** [{id,brand,label}] for the category <select> (hierarchy in the label). */
    private function categorySelect(): array
    {
        $out = [];
        foreach ($this->repo()->adminCategories() as $c) {
            $out[] = [
                'id'    => (int) $c['id'],
                'brand' => $c['brand'],
                'label' => ($c['parent_name'] ? $c['parent_name'] . ' › ' : '') . $c['name'],
            ];
        }
        return $out;
    }

    /** Parse an HTML spec table into [{label,value}] rows. */
    private function specRows(?string $html): array
    {
        $html = trim((string) $html);
        if ($html === '') {
            return [];
        }
        $rows = [];
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');
        libxml_clear_errors();
        foreach ($doc->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $c) {
                if ($c->nodeType === XML_ELEMENT_NODE && in_array(strtolower($c->nodeName), ['td', 'th'], true)) {
                    $cells[] = trim($c->textContent);
                }
            }
            if (count($cells) >= 2) {
                $rows[] = ['label' => $cells[0], 'value' => $cells[1]];
            } elseif (count($cells) === 1 && $cells[0] !== '') {
                $rows[] = ['label' => $cells[0], 'value' => ''];
            }
        }
        return $rows;
    }

    /** Build an HTML spec table from label/value arrays (empty -> ''). */
    private function buildSpecTable(array $labels, array $values): string
    {
        $out = '';
        foreach ($labels as $i => $l) {
            $l = trim((string) $l);
            $v = trim((string) ($values[$i] ?? ''));
            if ($l === '' && $v === '') {
                continue;
            }
            $out .= '<tr><th>' . htmlspecialchars($l, ENT_QUOTES, 'UTF-8') . '</th><td>' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
        return $out === '' ? '' : '<table>' . $out . '</table>';
    }

    /** Decode variants_json into editor rows [{version,transmission,price}]. */
    private function variantRows(?string $json): array
    {
        $rows = json_decode((string) $json, true);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $out[] = [
                'version'      => (string) ($r['version'] ?? ''),
                'transmission' => (string) ($r['transmission'] ?? ''),
                'price'        => (int) ($r['price'] ?? 0),
            ];
        }
        return $out;
    }

    /** Build variants_json from the parallel editor arrays (empty -> ''). */
    private function buildVariantsJson(array $versions, array $transmissions, array $prices): string
    {
        $rows = [];
        foreach ($versions as $i => $v) {
            $version = trim((string) $v);
            $trans   = trim((string) ($transmissions[$i] ?? ''));
            $price   = (int) preg_replace('/\D/', '', (string) ($prices[$i] ?? ''));
            if ($version === '' && $trans === '' && $price === 0) {
                continue;
            }
            $rows[] = ['version' => $version, 'transmission' => $trans, 'price' => $price];
        }
        return $rows === [] ? '' : (string) json_encode($rows, JSON_UNESCAPED_UNICODE);
    }
}
