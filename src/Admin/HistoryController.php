<?php

declare(strict_types=1);

namespace App\Admin;

use App\History\Repository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin CRUD for the "Istoria Dual Motors" timeline (`history_entries` +
 * `history_images`). WYSIWYG body, gallery; ordered by year then position.
 * Images upload to /media/despre via context "about".
 */
final class HistoryController extends BaseController
{
    private function repo(): Repository
    {
        return $this->container['history'];
    }

    /** GET {base}/istoric */
    public function index(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        return $this->render($response, 'admin/history/index.twig', [
            'active'  => 'about',
            'entries' => $this->repo()->adminAll(),
            'saved'   => isset($request->getQueryParams()['ok']),
        ]);
    }

    /** GET {base}/istoric/{id} — form (id 0 = new). */
    public function form(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $id = (int) ($args['id'] ?? 0);
        return $this->render($response, 'admin/history/form.twig', [
            'active'  => 'about',
            'entry'   => $id > 0 ? $this->repo()->find($id) : null,
            'id'      => $id,
            'gallery' => $id > 0 ? $this->repo()->images($id) : [],
        ]);
    }

    /** POST {base}/istoric/{id} */
    public function save(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, '/istoric');
        }
        $id = (int) ($args['id'] ?? 0);
        $year = (int) ($body['year'] ?? 0);
        $data = [
            'year'      => $year > 0 ? $year : null,
            'title'     => trim((string) ($body['title'] ?? '')),
            'body_html' => trim((string) ($body['body_html'] ?? '')),
            'position'  => (int) ($body['position'] ?? 0),
            'is_active' => empty($body['is_active']) ? 0 : 1,
        ];
        $eid = $this->repo()->save($id > 0 ? $id : null, $data);
        $this->repo()->replaceImages($eid, (array) ($body['gallery'] ?? []));
        return $this->to($response, '/istoric/' . $eid . '?ok=1');
    }

    /** POST {base}/istoric/{id}/delete */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        if ($this->csrfOk($this->body($request))) {
            $this->repo()->delete((int) ($args['id'] ?? 0));
        }
        return $this->to($response, '/istoric?ok=1');
    }
}
