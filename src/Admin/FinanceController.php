<?php

declare(strict_types=1);

namespace App\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Admin Finance: the UniCredit financing config (single `finance` row, id = 1) +
 * the /finantare page content. Moved out of Settings into its own page.
 */
final class FinanceController extends BaseController
{
    /** GET {base}/finantare */
    public function index(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        return $this->render($response, 'admin/finance/index.twig', [
            'active'  => 'finance',
            'finance' => $this->financeRow(),
            'saved'   => isset($request->getQueryParams()['ok']),
        ]);
    }

    /** POST {base}/finantare */
    public function save(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, '/finantare');
        }
        try {
            $this->pdo->prepare(
                "INSERT INTO finance (id, nominal_rate, dae, admin_fee, calc_rate, terms, page_title, page_html)
                 VALUES (1, :nr, :dae, :fee, :calc, :terms, :pt, :ph)
                 ON DUPLICATE KEY UPDATE nominal_rate=:nr, dae=:dae, admin_fee=:fee, calc_rate=:calc, terms=:terms, page_title=:pt, page_html=:ph"
            )->execute([
                ':nr'    => (float) str_replace(',', '.', (string) ($body['nominal_rate'] ?? '13')),
                ':dae'   => (float) str_replace(',', '.', (string) ($body['dae'] ?? '14.5')),
                ':fee'   => (float) str_replace(',', '.', (string) ($body['admin_fee'] ?? '10')),
                ':calc'  => (float) str_replace(',', '.', (string) ($body['calc_rate'] ?? '14.5')),
                ':terms' => trim((string) ($body['terms'] ?? '12,18,24,36,48,60')),
                ':pt'    => trim((string) ($body['page_title'] ?? '')),
                ':ph'    => trim((string) ($body['page_html'] ?? '')),
            ]);
        } catch (Throwable) {
            // ignore
        }
        return $this->to($response, '/finantare?ok=1');
    }

    private function financeRow(): array
    {
        try {
            $r = $this->pdo->query("SELECT * FROM finance WHERE id = 1")->fetch();
            return $r ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}
