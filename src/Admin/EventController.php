<?php

declare(strict_types=1);

namespace App\Admin;

use App\Event\Repository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin CRUD for events (`events` + `event_images`). WYSIWYG body, cover +
 * gallery images, start/end datetime, location.
 */
final class EventController extends BaseController
{
    private function repo(): Repository
    {
        return $this->container['events'];
    }

    /** GET {base}/evenimente */
    public function index(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        return $this->render($response, 'admin/events/index.twig', [
            'active' => 'events',
            'events' => $this->repo()->adminAll(),
            'saved'  => isset($request->getQueryParams()['ok']),
        ]);
    }

    /** GET {base}/evenimente/{id} — form (id 0 = new). */
    public function form(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $id = (int) ($args['id'] ?? 0);
        $event = $id > 0 ? $this->repo()->find($id) : null;
        return $this->render($response, 'admin/events/form.twig', [
            'active'  => 'events',
            'event'   => $event,
            'id'      => $id,
            'gallery' => $id > 0 ? $this->repo()->images($id) : [],
        ]);
    }

    /** POST {base}/evenimente/{id} */
    public function save(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, '/evenimente');
        }
        $id = (int) ($args['id'] ?? 0);
        $starts = trim((string) ($body['starts_at'] ?? ''));
        $ends   = trim((string) ($body['ends_at'] ?? ''));

        $data = [
            'title'       => trim((string) ($body['title'] ?? '')),
            'slug'        => slugify((string) ($body['slug'] ?? '')) ?: slugify((string) ($body['title'] ?? '')),
            'excerpt'     => trim((string) ($body['excerpt'] ?? '')),
            'body_html'   => trim((string) ($body['body_html'] ?? '')),
            'location'    => trim((string) ($body['location'] ?? '')),
            'starts_at'   => $starts !== '' ? str_replace('T', ' ', $starts) : null,
            'ends_at'     => $ends !== '' ? str_replace('T', ' ', $ends) : null,
            'cover_image' => trim((string) ($body['cover_image'] ?? '')) ?: null,
            'is_active'   => empty($body['is_active']) ? 0 : 1,
            'position'    => (int) ($body['position'] ?? 0),
        ];
        $eid = $this->repo()->save($id > 0 ? $id : null, $data);
        $this->repo()->replaceImages($eid, (array) ($body['gallery'] ?? []));
        return $this->to($response, '/evenimente/' . $eid . '?ok=1');
    }

    /** POST {base}/evenimente/{id}/delete */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        if ($this->csrfOk($this->body($request))) {
            $this->repo()->delete((int) ($args['id'] ?? 0));
        }
        return $this->to($response, '/evenimente?ok=1');
    }
}
