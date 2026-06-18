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
        $brand = (string) ($request->getQueryParams()['brand'] ?? '');
        $brand = in_array($brand, ['yamaha', 'cfmoto'], true) ? $brand : null;
        return $this->render($response, 'admin/products/index.twig', [
            'active'   => 'products',
            'brand'    => $brand,
            'products' => $this->repo()->adminProducts($brand),
            'saved'    => isset($request->getQueryParams()['ok']),
        ]);
    }

    /** GET {base}/produse/{id} — form (id 0 = new). */
    public function form(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $id = (int) ($args['id'] ?? 0);
        $p = $id > 0 ? $this->repo()->productById($id) : null;
        $brand = $p['brand'] ?? ($request->getQueryParams()['brand'] ?? 'yamaha');

        $specRows = [];
        foreach (self::SPECS as $key => $col) {
            $specRows[$key] = $p ? $this->specRows($p[$col] ?? '') : [];
        }
        $images = [];
        if ($p) {
            foreach (['color', 'gallery', 'detail'] as $t) {
                $images[$t] = $this->repo()->productImages($id, $t);
            }
        }

        return $this->render($response, 'admin/products/form.twig', [
            'active'     => 'products',
            'p'          => $p,
            'id'         => $id,
            'brand'      => $brand,
            'categories' => $this->categorySelect(),
            'specRows'   => $specRows,
            'images'     => $images,
        ]);
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
            'details_html' => trim((string) ($body['details_html'] ?? '')),
            'video'        => trim((string) ($body['video'] ?? '')) ?: null,
            'keywords'     => trim((string) ($body['keywords'] ?? '')),
            'is_active'    => empty($body['is_active']) ? 0 : 1,
            'position'     => (int) ($body['position'] ?? 0),
            // PID Yamaha (doar cifre) pt. importul accesoriilor originale; gol -> NULL.
            'yamaha_pid'   => ($brand === 'yamaha' && preg_match('/\d+/', (string) ($body['yamaha_pid'] ?? ''), $mm)) ? $mm[0] : null,
        ];
        foreach (self::SPECS as $key => $col) {
            $data[$col] = $this->buildSpecTable(
                (array) ($body['spec_' . $key . '_label'] ?? []),
                (array) ($body['spec_' . $key . '_value'] ?? [])
            );
        }

        $pid = $this->repo()->saveProduct($id > 0 ? $id : null, $data);

        foreach (['color', 'gallery', 'detail'] as $t) {
            $this->repo()->replaceImages($pid, $t, (array) ($body[$t] ?? []));
        }

        $this->bustMenuCache();
        return $this->to($response, '/produse/' . $pid . '?ok=1');
    }

    /** POST {base}/produse/{id}/delete */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        if ($this->csrfOk($this->body($request))) {
            $this->repo()->deleteProduct((int) ($args['id'] ?? 0));
            $this->bustMenuCache();
        }
        return $this->to($response, '/produse?ok=1');
    }

    // -- helpers --------------------------------------------------------------

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
}
