<?php

declare(strict_types=1);

namespace App\Admin;

use App\Service\Repository;
use App\Support\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin for the Service page: heading + description + note (settings keys) and
 * the structured price list (service_prices) edited as repeatable rows
 * (group / label / price as parallel arrays).
 */
final class ServiceController extends BaseController
{
    private function repo(): Repository
    {
        return $this->container['service'];
    }

    private function settings(): Settings
    {
        return $this->container['app_settings'];
    }

    /** GET {base}/service */
    public function index(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        return $this->render($response, 'admin/service/index.twig', [
            'active'  => 'service',
            'heading' => $this->settings()->get('service_heading', ''),
            'desc'    => $this->settings()->get('service_desc_html', ''),
            'note'    => $this->settings()->get('service_note_html', ''),
            'prices'  => $this->repo()->prices(),
            'saved'   => isset($request->getQueryParams()['ok']),
        ]);
    }

    /** POST {base}/service */
    public function save(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, '/service');
        }
        $this->settings()->set('service_heading', trim((string) ($body['heading'] ?? '')));
        $this->settings()->set('service_desc_html', trim((string) ($body['desc_html'] ?? '')));
        $this->settings()->set('service_note_html', trim((string) ($body['note_html'] ?? '')));

        $groups = (array) ($body['price_group'] ?? []);
        $labels = (array) ($body['price_label'] ?? []);
        $values = (array) ($body['price_value'] ?? []);
        $rows = [];
        foreach ($labels as $i => $label) {
            $rows[] = [
                'group_label' => (string) ($groups[$i] ?? ''),
                'label'       => (string) $label,
                'price'       => (string) ($values[$i] ?? ''),
            ];
        }
        $this->repo()->replacePrices($rows);

        return $this->to($response, '/service?ok=1');
    }
}
