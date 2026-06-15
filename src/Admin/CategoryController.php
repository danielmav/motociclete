<?php

declare(strict_types=1);

namespace App\Admin;

use App\Catalog\Repository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin CRUD for catalog categories (`categories`, 2-level per brand). Saving
 * busts the cached mega menu so the change shows immediately on the site.
 */
final class CategoryController extends BaseController
{
    private function repo(): Repository
    {
        return $this->container['catalog'];
    }

    /** GET {base}/categorii */
    public function index(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        return $this->render($response, 'admin/categories/index.twig', [
            'active'     => 'categories',
            'categories' => $this->repo()->adminCategories(),
            'saved'      => isset($request->getQueryParams()['ok']),
        ]);
    }

    /** GET {base}/categorii/{id} — form (id 0 = new). */
    public function form(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $id  = (int) ($args['id'] ?? 0);
        $cat = $id > 0 ? $this->repo()->categoryById($id) : null;
        $brand = $cat['brand'] ?? ($request->getQueryParams()['brand'] ?? 'yamaha');
        return $this->render($response, 'admin/categories/form.twig', [
            'active' => 'categories',
            'cat'    => $cat,
            'id'     => $id,
            'brand'  => $brand,
            'tops'   => [
                'yamaha' => $this->repo()->topCategoriesAdmin('yamaha'),
                'cfmoto' => $this->repo()->topCategoriesAdmin('cfmoto'),
            ],
        ]);
    }

    /** POST {base}/categorii/{id} */
    public function save(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, '/categorii');
        }
        $id = (int) ($args['id'] ?? 0);
        $parent = (int) ($body['parent_id'] ?? 0);
        $data = [
            'brand'       => in_array($body['brand'] ?? '', ['yamaha', 'cfmoto'], true) ? $body['brand'] : 'yamaha',
            'parent_id'   => $parent > 0 ? $parent : null,
            'name'        => trim((string) ($body['name'] ?? '')),
            'slug'        => slugify((string) ($body['slug'] ?? '')) ?: slugify((string) ($body['name'] ?? '')),
            'description' => trim((string) ($body['description'] ?? '')),
            'position'    => (int) ($body['position'] ?? 0),
            'is_active'   => empty($body['is_active']) ? 0 : 1,
        ];
        $this->repo()->saveCategory($id > 0 ? $id : null, $data);
        $this->bustMenuCache();
        return $this->to($response, '/categorii?ok=1');
    }

    /** POST {base}/categorii/{id}/delete */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        if ($this->csrfOk($this->body($request))) {
            $this->repo()->deleteCategory((int) ($args['id'] ?? 0));
            $this->bustMenuCache();
        }
        return $this->to($response, '/categorii?ok=1');
    }
}
