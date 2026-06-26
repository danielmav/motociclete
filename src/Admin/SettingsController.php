<?php

declare(strict_types=1);

namespace App\Admin;

use App\Content\Repository as Content;
use App\Support\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin Settings: currency/VAT (settings table), financing (finance row),
 * socials + address (settings keys), contact departments (contact_departments),
 * and static legal/about pages (pages).
 */
final class SettingsController extends BaseController
{
    private const SOCIAL_KEYS = ['social_facebook', 'social_instagram', 'social_youtube', 'social_tiktok', 'address', 'schedule', 'phone_general', 'map_url'];

    private function settings(): Settings
    {
        return $this->container['app_settings'];
    }

    private function content(): Content
    {
        return $this->container['content'];
    }

    /** GET {base}/setari */
    public function index(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $vals = [];
        foreach (self::SOCIAL_KEYS as $k) {
            $vals[$k] = $this->settings()->get($k, '');
        }
        return $this->render($response, 'admin/settings/index.twig', [
            'active'      => 'settings',
            'cur'         => $this->settings()->currency(),
            'vals'        => $vals,
            'departments' => $this->content()->departments(),
            'saved'       => isset($request->getQueryParams()['ok']),
        ]);
    }

    /** POST {base}/setari — currency + finance + socials/address. */
    public function save(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, '/setari');
        }
        $s = $this->settings();

        // Cursurile valutare (eur_ron_rate_yamaha / _cfmoto) sunt AUTO din BNR/BRD
        // (database/update_currency.php) → read-only aici; nu se scriu din formular.
        $vat = (int) ($body['vat_pct'] ?? 0);
        if ($vat >= 0 && $vat <= 100) {
            $s->set('vat_pct', (string) $vat);
        }
        $s->set('price_includes_vat', empty($body['price_includes_vat']) ? '0' : '1');

        foreach (self::SOCIAL_KEYS as $k) {
            $s->set($k, trim((string) ($body[$k] ?? '')));
        }

        return $this->to($response, '/setari?ok=1');
    }

    /** POST {base}/setari/departament */
    public function saveDepartment(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if ($this->csrfOk($body) && trim((string) ($body['label'] ?? '')) !== '') {
            $this->content()->saveDepartment(
                ((int) ($body['id'] ?? 0)) ?: null,
                trim((string) $body['label']),
                trim((string) ($body['email'] ?? '')),
                trim((string) ($body['phone'] ?? '')),
                (int) ($body['position'] ?? 0)
            );
        }
        return $this->to($response, '/setari?ok=1');
    }

    /** POST {base}/setari/departament/{id}/delete */
    public function deleteDepartment(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        if ($this->csrfOk($this->body($request))) {
            $this->content()->deleteDepartment((int) ($args['id'] ?? 0));
        }
        return $this->to($response, '/setari?ok=1');
    }

    // -- Pages ----------------------------------------------------------------

    /** GET {base}/setari/pagini */
    public function pages(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        return $this->render($response, 'admin/settings/pages.twig', [
            'active' => 'settings',
            'pages'  => $this->content()->pages(),
            'saved'  => isset($request->getQueryParams()['ok']),
        ]);
    }

    /** GET {base}/setari/pagini/{id} */
    public function pageForm(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $id = (int) ($args['id'] ?? 0);
        return $this->render($response, 'admin/settings/page_form.twig', [
            'active' => 'settings',
            'page'   => $id > 0 ? $this->content()->pageById($id) : null,
            'id'     => $id,
        ]);
    }

    /** POST {base}/setari/pagini/{id} */
    public function savePage(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, '/setari/pagini');
        }
        $id = (int) ($args['id'] ?? 0);
        $this->content()->savePage(
            $id > 0 ? $id : null,
            slugify((string) ($body['slug'] ?? '')) ?: slugify((string) ($body['title'] ?? '')),
            trim((string) ($body['title'] ?? '')),
            trim((string) ($body['body_html'] ?? '')),
            empty($body['is_active']) ? 0 : 1
        );
        return $this->to($response, '/setari/pagini?ok=1');
    }

    /** POST {base}/setari/pagini/{id}/delete */
    public function deletePage(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        if ($this->csrfOk($this->body($request))) {
            $this->content()->deletePage((int) ($args['id'] ?? 0));
        }
        return $this->to($response, '/setari/pagini?ok=1');
    }
}
