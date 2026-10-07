<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Content\Repository as Content;
use App\Database;
use App\Support\Mailer;
use App\Support\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Throwable;

/**
 * Public Contact page (/contact): departments (admin-managed, `contact_departments`),
 * map + directions, and the contact form (POST /contact). The form persists to
 * `site_messages` (type `contact`) and emails the chosen department.
 */
final class ContactPageController
{
    private const TOUR_URL = 'https://www.3dpano.ro/tur-virtual/dualmotors/';
    private const MAP_QUERY = 'Dual Motors, Șoseaua Pipera 48, București';

    private Content $content;
    private Settings $settings;
    private Database $db;
    private Mailer $mailer;
    private string $dealer;
    private string $base;

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->content  = $container['content'];
        $this->settings = $container['app_settings'];
        $this->db       = $container['db'];
        $this->mailer   = $container['mailer'];
        $this->dealer   = (string) ($container['settings']['mail']['dealer'] ?? 'info@motociclete.com.ro');
        $this->base     = (string) ($container['settings']['app']['base_path'] ?? '');
    }

    /** GET /contact */
    public function page(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $mapQuery = rawurlencode(self::MAP_QUERY);
        return $this->twig->render($response, 'contact.twig', [
            'departments'    => $this->content->departments(),
            'selected_dept'  => (int) ($q['departament'] ?? 0),
            // Programul e stocat pe o linie, cu „|" între zile.
            'schedule_lines' => array_values(array_filter(array_map('trim', explode('|', $this->settings->get('schedule', ''))))),
            'map_url'        => $this->settings->get('map_url', '') ?: 'https://www.google.com/maps/search/?api=1&query=' . $mapQuery,
            'map_embed'      => 'https://www.google.com/maps?q=' . $mapQuery . '&z=15&output=embed',
            'directions_url' => 'https://www.google.com/maps/dir/?api=1&destination=' . $mapQuery,
            'waze_url'       => 'https://waze.com/ul?q=' . $mapQuery . '&navigate=yes',
            'tour_url'       => self::TOUR_URL,
            'canonical_path' => '/contact',
            // Fallback fără JS: după POST non-AJAX redirectăm aici cu flag-uri.
            'sent'           => isset($q['trimis']),
            'form_error'     => (string) ($q['eroare'] ?? ''),
        ]);
    }

    /** POST /contact — JSON pentru AJAX; redirect cu mesaj pentru POST normal. */
    public function send(Request $request, Response $response): Response
    {
        $d = (array) $request->getParsedBody();

        // Honeypot: silent success for bots.
        if (trim((string) ($d['website'] ?? '')) !== '') {
            return $this->ok($request, $response);
        }

        // GDPR: explicit consent is required to process the message.
        if (trim((string) ($d['consent'] ?? '')) !== '1') {
            return $this->err($request, $response, 'Bifează acordul privind prelucrarea datelor personale.');
        }

        $name    = trim((string) ($d['name'] ?? ''));
        $email   = trim((string) ($d['email'] ?? ''));
        $phone   = trim((string) ($d['phone'] ?? ''));
        $message = trim((string) ($d['message'] ?? ''));

        if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->err($request, $response, 'Completează numele, un email valid și mesajul.');
        }

        // Departamentul ales (id din `contact_departments`); necunoscut → dealer.
        $dept = null;
        $deptId = (int) ($d['department'] ?? 0);
        foreach ($this->content->departments() as $row) {
            if ((int) $row['id'] === $deptId) {
                $dept = $row;
                break;
            }
        }
        $deptLabel = $dept ? (string) $dept['label'] : '';
        $to = $dept && filter_var((string) $dept['email'], FILTER_VALIDATE_EMAIL) ? (string) $dept['email'] : $this->dealer;

        $ip = $this->clientIp($request);
        try {
            $this->db->local()->prepare(
                'INSERT INTO site_messages (type, department, name, email, phone, message, ip)
                 VALUES (:type, :dept, :name, :email, :phone, :message, :ip)'
            )->execute([
                ':type' => 'contact', ':dept' => $deptLabel ?: null,
                ':name' => $name, ':email' => $email, ':phone' => $phone,
                ':message' => $message, ':ip' => $ip,
            ]);
        } catch (Throwable) {
            // fall through; we still try to email
        }

        $lines = [
            'Mesaj din pagina de contact motociclete.com.ro',
            'Departament: ' . ($deptLabel !== '' ? $deptLabel : '—'),
            '',
            'Nume: ' . $name,
            'Email: ' . $email,
            'Telefon: ' . ($phone !== '' ? $phone : '—'),
            'Mesaj: ' . $message,
            '',
            'IP: ' . $ip,
            'Data: ' . date('Y-m-d H:i:s'),
        ];
        try {
            $this->mailer->send($to, 'Mesaj contact' . ($deptLabel !== '' ? ' — ' . $deptLabel : '') . ': ' . $name, implode("\n", $lines), 'contact', $email);
        } catch (Throwable) {
            // never let mail failure break the response
        }

        return $this->ok($request, $response);
    }

    private function isAjax(Request $request): bool
    {
        return strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
    }

    private function ok(Request $request, Response $response): Response
    {
        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true]);
        }
        return $response->withHeader('Location', $this->base . '/contact?trimis=1#formular')->withStatus(303);
    }

    private function err(Request $request, Response $response, string $msg): Response
    {
        if ($this->isAjax($request)) {
            return $this->json($response->withStatus(422), ['ok' => false, 'error' => $msg]);
        }
        return $response->withHeader('Location', $this->base . '/contact?eroare=' . rawurlencode($msg) . '#formular')->withStatus(303);
    }

    private function clientIp(Request $request): string
    {
        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ($xff !== '') {
            return trim(explode(',', $xff)[0]);
        }
        return (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
    }

    /** @param array<string,mixed> $payload */
    private function json(Response $response, array $payload): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
