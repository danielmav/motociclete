<?php

declare(strict_types=1);

namespace App\Admin;

use App\Hero\Repository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin CRUD for the home-page hero slides (`hero_slides`). Convention shared by
 * the content modules: id 0 = "new". Image is a single file stored as a /media
 * web path (data-store="url").
 */
final class HeroController extends BaseController
{
    private function repo(): Repository
    {
        return $this->container['hero'];
    }

    /** GET {base}/hero */
    public function index(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        return $this->render($response, 'admin/hero/index.twig', [
            'active' => 'hero',
            'slides' => $this->repo()->adminAll(),
            'saved'  => isset($request->getQueryParams()['ok']),
        ]);
    }

    /** GET {base}/hero/{id} — form (id 0 = new). */
    public function form(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $id = (int) ($args['id'] ?? 0);
        $slide = $id > 0 ? $this->repo()->find($id) : null;
        $stats = [];
        if ($slide && !empty($slide['stats_json'])) {
            $decoded = json_decode((string) $slide['stats_json'], true);
            $stats = is_array($decoded) ? $decoded : [];
        }
        return $this->render($response, 'admin/hero/form.twig', [
            'active' => 'hero',
            'slide'  => $slide,
            'id'     => $id,
            'stats'  => $stats,
        ]);
    }

    /** POST {base}/hero/{id} — create (id 0) or update. */
    public function save(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, '/hero');
        }
        $id = (int) ($args['id'] ?? 0);

        // stats rows -> JSON (value/label pairs, non-empty)
        $vals = (array) ($body['stat_value'] ?? []);
        $labs = (array) ($body['stat_label'] ?? []);
        $stats = [];
        foreach ($vals as $i => $v) {
            $v = trim((string) $v);
            $l = trim((string) ($labs[$i] ?? ''));
            if ($v !== '' || $l !== '') {
                $stats[] = ['value' => $v, 'label' => $l];
            }
        }

        $data = [
            'position'   => (int) ($body['position'] ?? $this->repo()->nextPosition()),
            'is_active'  => empty($body['is_active']) ? 0 : 1,
            'kicker'     => trim((string) ($body['kicker'] ?? '')),
            'title_html' => trim((string) ($body['title_html'] ?? '')),
            'subtitle'   => trim((string) ($body['subtitle'] ?? '')),
            'cta_label'  => trim((string) ($body['cta_label'] ?? '')),
            'cta_href'   => trim((string) ($body['cta_href'] ?? '')),
            'image'      => trim((string) ($body['image'] ?? '')),
            'image_alt'  => trim((string) ($body['image_alt'] ?? '')),
            'ghost'      => trim((string) ($body['ghost'] ?? '')),
            'stats_json' => $stats ? json_encode($stats, JSON_UNESCAPED_UNICODE) : null,
        ];

        $this->repo()->save($id > 0 ? $id : null, $data);
        return $this->to($response, '/hero?ok=1');
    }

    /** POST {base}/hero/{id}/delete */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        if ($this->csrfOk($this->body($request))) {
            $this->repo()->delete((int) ($args['id'] ?? 0));
        }
        return $this->to($response, '/hero?ok=1');
    }
}
