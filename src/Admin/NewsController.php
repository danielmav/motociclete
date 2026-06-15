<?php

declare(strict_types=1);

namespace App\Admin;

use App\News\Repository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin CRUD for the blog (`news` + `news_images`) and blog categories
 * (`news_categories`). WYSIWYG body, cover + gallery images.
 */
final class NewsController extends BaseController
{
    private function repo(): Repository
    {
        return $this->container['news'];
    }

    /** GET {base}/blog */
    public function index(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        return $this->render($response, 'admin/news/index.twig', [
            'active'     => 'news',
            'articles'   => $this->repo()->adminAll(),
            'categories' => $this->repo()->categories(),
            'saved'      => isset($request->getQueryParams()['ok']),
        ]);
    }

    /** GET {base}/blog/{id} — form (id 0 = new). */
    public function form(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $id = (int) ($args['id'] ?? 0);
        $article = $id > 0 ? $this->repo()->adminFind($id) : null;
        $imgs = $id > 0 ? $this->repo()->adminImages($id) : [];
        $cover = null;
        $gallery = [];
        foreach ($imgs as $im) {
            if ((int) $im['is_cover'] === 1 && $cover === null) {
                $cover = $im['filename'];
            } else {
                $gallery[] = $im;
            }
        }
        return $this->render($response, 'admin/news/form.twig', [
            'active'     => 'news',
            'article'    => $article,
            'id'         => $id,
            'categories' => $this->repo()->categories(),
            'cover'      => $cover,
            'gallery'    => $gallery,
        ]);
    }

    /** POST {base}/blog/{id} */
    public function save(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, '/blog');
        }
        $id = (int) ($args['id'] ?? 0);
        $date = trim((string) ($body['published_at'] ?? ''));

        $data = [
            'brand'        => in_array($body['brand'] ?? '', ['yamaha', 'cfmoto'], true) ? $body['brand'] : '',
            'category_id'  => ((int) ($body['category_id'] ?? 0)) ?: null,
            'title'        => trim((string) ($body['title'] ?? '')),
            'slug'         => slugify((string) ($body['slug'] ?? '')) ?: slugify((string) ($body['title'] ?? '')),
            'excerpt'      => trim((string) ($body['excerpt'] ?? '')),
            'body'         => trim((string) ($body['body'] ?? '')),
            'published_at' => $date !== '' ? str_replace('T', ' ', $date) : null,
            'is_active'    => empty($body['is_active']) ? 0 : 1,
        ];
        $nid = $this->repo()->adminSave($id > 0 ? $id : null, $data);
        $this->repo()->replaceNewsImages(
            $nid,
            trim((string) ($body['cover'] ?? '')) ?: null,
            (array) ($body['gallery'] ?? [])
        );
        return $this->to($response, '/blog/' . $nid . '?ok=1');
    }

    /** POST {base}/blog/{id}/delete */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        if ($this->csrfOk($this->body($request))) {
            $this->repo()->adminDelete((int) ($args['id'] ?? 0));
        }
        return $this->to($response, '/blog?ok=1');
    }

    /** POST {base}/blog/categorie — add/update a blog category. */
    public function saveCategory(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if ($this->csrfOk($body)) {
            $name = trim((string) ($body['name'] ?? ''));
            if ($name !== '') {
                $this->repo()->saveCategory(
                    ((int) ($body['id'] ?? 0)) ?: null,
                    $name,
                    slugify((string) ($body['slug'] ?? '')) ?: slugify($name),
                    (int) ($body['position'] ?? 0)
                );
            }
        }
        return $this->to($response, '/blog?ok=1');
    }

    /** POST {base}/blog/categorie/{id}/delete */
    public function deleteCategory(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        if ($this->csrfOk($this->body($request))) {
            $this->repo()->deleteCategory((int) ($args['id'] ?? 0));
        }
        return $this->to($response, '/blog?ok=1');
    }
}
