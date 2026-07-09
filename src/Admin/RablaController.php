<?php

declare(strict_types=1);

namespace App\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Admin „Programul RABLA": editable content (single `rabla` row, id = 1) shown in
 * a modal on RABLA-eligible product pages. Title is auto-generated with the
 * current year in the template, so only the body HTML is stored.
 */
final class RablaController extends BaseController
{
    /** GET {base}/rabla */
    public function index(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        return $this->render($response, 'admin/rabla/index.twig', [
            'active' => 'rabla',
            'rabla'  => $this->row(),
            'saved'  => isset($request->getQueryParams()['ok']),
        ]);
    }

    /** POST {base}/rabla */
    public function save(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, '/rabla');
        }
        try {
            // Placeholdere distincte: native prepares (emulate=false) nu permit
            // repetarea aceluiași placeholder în același statement (HY093).
            $html = trim((string) ($body['page_html'] ?? ''));
            $this->pdo->prepare(
                "INSERT INTO rabla (id, page_html) VALUES (1, :ins)
                 ON DUPLICATE KEY UPDATE page_html = :upd"
            )->execute([':ins' => $html, ':upd' => $html]);
        } catch (Throwable) {
            // ignore
        }
        return $this->to($response, '/rabla?ok=1');
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        try {
            $r = $this->pdo->query("SELECT * FROM rabla WHERE id = 1")->fetch();
            return $r ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}
