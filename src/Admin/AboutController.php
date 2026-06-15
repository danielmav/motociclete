<?php

declare(strict_types=1);

namespace App\Admin;

use App\About\Repository;
use App\Support\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin for the "Despre noi" page: intro heading + rich text (settings keys),
 * the showroom gallery (about_images) and the team members (team_members).
 * Images upload to /media/despre via context "about".
 */
final class AboutController extends BaseController
{
    private function repo(): Repository
    {
        return $this->container['about'];
    }

    private function settings(): Settings
    {
        return $this->container['app_settings'];
    }

    // -- Intro + gallery ------------------------------------------------------

    /** GET {base}/despre */
    public function index(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        return $this->render($response, 'admin/about/index.twig', [
            'active'  => 'about',
            'heading' => $this->settings()->get('about_heading', ''),
            'intro'   => $this->settings()->get('about_intro_html', ''),
            'gallery' => $this->repo()->galleryImages(),
            'team'    => $this->repo()->allTeam(),
            'saved'   => isset($request->getQueryParams()['ok']),
        ]);
    }

    /** POST {base}/despre — save intro + gallery. */
    public function save(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, '/despre');
        }
        $this->settings()->set('about_heading', trim((string) ($body['heading'] ?? '')));
        $this->settings()->set('about_intro_html', trim((string) ($body['intro_html'] ?? '')));
        $this->repo()->replaceGallery((array) ($body['gallery'] ?? []));
        return $this->to($response, '/despre?ok=1');
    }

    // -- Team members ---------------------------------------------------------

    /** GET {base}/echipa/{id} — form (id 0 = new). */
    public function memberForm(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $id = (int) ($args['id'] ?? 0);
        return $this->render($response, 'admin/about/member_form.twig', [
            'active' => 'about',
            'member' => $id > 0 ? $this->repo()->findMember($id) : null,
            'id'     => $id,
        ]);
    }

    /** POST {base}/echipa/{id} */
    public function memberSave(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, '/despre');
        }
        $id = (int) ($args['id'] ?? 0);
        $data = [
            'name'      => trim((string) ($body['name'] ?? '')),
            'role'      => trim((string) ($body['role'] ?? '')) ?: null,
            'phone'     => trim((string) ($body['phone'] ?? '')) ?: null,
            'email'     => trim((string) ($body['email'] ?? '')) ?: null,
            'photo'     => trim((string) ($body['photo'] ?? '')) ?: null,
            'position'  => (int) ($body['position'] ?? 0),
            'is_active' => empty($body['is_active']) ? 0 : 1,
        ];
        if ($data['name'] !== '') {
            $this->repo()->saveMember($id > 0 ? $id : null, $data);
        }
        return $this->to($response, '/despre?ok=1');
    }

    /** POST {base}/echipa/{id}/delete */
    public function memberDelete(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        if ($this->csrfOk($this->body($request))) {
            $this->repo()->deleteMember((int) ($args['id'] ?? 0));
        }
        return $this->to($response, '/despre?ok=1');
    }
}
