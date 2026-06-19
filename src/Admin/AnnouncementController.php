<?php

declare(strict_types=1);

namespace App\Admin;

use App\Announcement\Repository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin CRUD for site-wide pop-up announcements (`announcements`): WYSIWYG body +
 * optional start/end datetime window. id 0 = "new" (shared convention).
 */
final class AnnouncementController extends BaseController
{
    private function repo(): Repository
    {
        return $this->container['announcements'];
    }

    /** GET {base}/anunturi */
    public function index(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        return $this->render($response, 'admin/announcements/index.twig', [
            'active'        => 'announcements',
            'announcements' => $this->repo()->adminAll(),
            'saved'         => isset($request->getQueryParams()['ok']),
        ]);
    }

    /** GET {base}/anunturi/{id} — form (id 0 = new). */
    public function form(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $id = (int) ($args['id'] ?? 0);
        return $this->render($response, 'admin/announcements/form.twig', [
            'active'       => 'announcements',
            'announcement' => $id > 0 ? $this->repo()->find($id) : null,
            'id'           => $id,
        ]);
    }

    /** POST {base}/anunturi/{id} */
    public function save(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, '/anunturi');
        }
        $id     = (int) ($args['id'] ?? 0);
        $starts = trim((string) ($body['starts_at'] ?? ''));
        $ends   = trim((string) ($body['ends_at'] ?? ''));

        $data = [
            'title'     => trim((string) ($body['title'] ?? '')),
            'body_html' => trim((string) ($body['body_html'] ?? '')),
            'starts_at' => $starts !== '' ? str_replace('T', ' ', $starts) : null,
            'ends_at'   => $ends !== '' ? str_replace('T', ' ', $ends) : null,
            'is_active' => empty($body['is_active']) ? 0 : 1,
            'position'  => (int) ($body['position'] ?? 0),
        ];
        $this->repo()->save($id > 0 ? $id : null, $data);
        return $this->to($response, '/anunturi?ok=1');
    }

    /** POST {base}/anunturi/{id}/delete */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        if ($this->csrfOk($this->body($request))) {
            $this->repo()->delete((int) ($args['id'] ?? 0));
        }
        return $this->to($response, '/anunturi?ok=1');
    }
}
