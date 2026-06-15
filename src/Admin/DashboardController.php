<?php

declare(strict_types=1);

namespace App\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Admin landing page: quick counts + shortcuts.
 */
final class DashboardController extends BaseController
{
    /** GET {base} */
    public function index(Request $request, Response $response): Response
    {
        if ($denied = $this->requireAuth($response)) {
            return $denied;
        }
        return $this->render($response, 'admin/dashboard.twig', [
            'stats' => [
                'products' => $this->count('SELECT COUNT(*) FROM products WHERE is_active = 1'),
                'news'     => $this->count('SELECT COUNT(*) FROM news WHERE is_active = 1'),
                'events'   => $this->count('SELECT COUNT(*) FROM events'),
                'messages' => $this->count("SELECT COUNT(*) FROM site_messages"),
                'requests' => $this->count("SELECT COUNT(*) FROM service_requests WHERE status = 'nou'"),
            ],
        ]);
    }

    /** COUNT(*) helper that tolerates a missing table (returns 0). */
    private function count(string $sql): int
    {
        try {
            return (int) $this->pdo->query($sql)->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }
}
