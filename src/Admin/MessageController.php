<?php

declare(strict_types=1);

namespace App\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Unified inbox: site lead forms (`site_messages`), service requests
 * (`service_requests`) and outgoing email log (`email_log`). Filter by source.
 */
final class MessageController extends BaseController
{
    /** GET {base}/mesaje?source=leads|service|email */
    public function index(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $source = (string) ($request->getQueryParams()['source'] ?? 'leads');
        if (!in_array($source, ['leads', 'service', 'bookings', 'email'], true)) {
            $source = 'leads';
        }

        $data = [
            'active'  => 'messages',
            'source'  => $source,
            'counts'  => [
                'leads'    => $this->count('SELECT COUNT(*) FROM site_messages WHERE is_read = 0'),
                'service'  => $this->count("SELECT COUNT(*) FROM service_requests WHERE status = 'nou'"),
                'bookings' => $this->container['service']->newBookingCount(),
                'email'    => $this->count('SELECT COUNT(*) FROM email_log WHERE is_read = 0'),
            ],
            'leads'    => [],
            'requests' => [],
            'bookings' => [],
            'emails'   => [],
        ];
        if ($source === 'leads') {
            $data['leads'] = $this->rows('SELECT * FROM site_messages ORDER BY created_at DESC LIMIT 300');
        } elseif ($source === 'service') {
            $data['requests'] = $this->container['client']->serviceRequests(null);
        } elseif ($source === 'bookings') {
            $data['bookings'] = $this->container['service']->bookings();
        } else {
            $data['emails'] = $this->rows('SELECT * FROM email_log ORDER BY created_at DESC LIMIT 300');
        }
        return $this->render($response, 'admin/messages/index.twig', $data);
    }

    /** POST {base}/mesaje/citit — mark a lead/email read. */
    public function markRead(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if ($this->csrfOk($body)) {
            $table = ($body['table'] ?? '') === 'email' ? 'email_log' : 'site_messages';
            try {
                $this->pdo->prepare("UPDATE {$table} SET is_read = 1 WHERE id = :id")
                    ->execute([':id' => (int) ($body['id'] ?? 0)]);
            } catch (Throwable) {
                // ignore
            }
        }
        return $this->to($response, '/mesaje?source=' . ($body['source'] ?? 'leads'));
    }

    /** POST {base}/mesaje/service-status — change a service request status. */
    public function serviceStatus(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if ($this->csrfOk($body)) {
            $this->container['client']->setRequestStatus((int) ($body['id'] ?? 0), (string) ($body['status'] ?? ''));
        }
        return $this->to($response, '/mesaje?source=service');
    }

    /** POST {base}/mesaje/booking-status — change a service booking status. */
    public function bookingStatus(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if ($this->csrfOk($body)) {
            $this->container['service']->setBookingStatus((int) ($body['id'] ?? 0), (string) ($body['status'] ?? ''));
        }
        return $this->to($response, '/mesaje?source=bookings');
    }

    private function count(string $sql): int
    {
        try {
            return (int) $this->pdo->query($sql)->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function rows(string $sql): array
    {
        try {
            return $this->pdo->query($sql)->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }
}
