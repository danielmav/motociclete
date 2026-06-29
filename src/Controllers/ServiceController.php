<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Service\Repository as Service;
use App\Support\Mailer;
use App\Support\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Throwable;

/**
 * Public Service page (/service): admin-managed description + price list, plus
 * the anonymous appointment form (POST /service/programare). The form persists
 * to `service_bookings` and emails the dealer (mirrors ContactController).
 */
final class ServiceController
{
    private Service $service;
    private Settings $settings;
    private Mailer $mailer;
    private string $dealer;
    private string $serviceMail;
    private string $base;

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->service  = $container['service'];
        $this->settings = $container['app_settings'];
        $this->mailer   = $container['mailer'];
        $this->dealer   = (string) ($container['settings']['mail']['dealer'] ?? 'info@motociclete.com.ro');
        // Programările de service merg la service@ (fallback la dealer).
        $this->serviceMail = (string) ($container['settings']['mail']['service'] ?? $this->dealer);
        $this->base = (string) ($container['settings']['app']['base_path'] ?? '');
    }

    /** GET /service */
    public function page(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        return $this->twig->render($response, 'service.twig', [
            'heading'        => $this->settings->get('service_heading', 'Service'),
            'desc_html'      => $this->settings->get('service_desc_html', ''),
            'note_html'      => $this->settings->get('service_note_html', ''),
            'price_groups'   => $this->service->groupedPrices(),
            'canonical_path' => '/service',
            // Fallback fără JS: după POST non-AJAX redirectăm aici cu flag-uri.
            'sent'           => isset($q['trimis']),
            'form_error'     => (string) ($q['eroare'] ?? ''),
        ]);
    }

    /** POST /service/programare — JSON pentru AJAX; redirect cu mesaj pentru POST normal. */
    public function book(Request $request, Response $response): Response
    {
        $d = (array) $request->getParsedBody();

        // Honeypot: silent success for bots.
        if (trim((string) ($d['website'] ?? '')) !== '') {
            return $this->bookOk($request, $response);
        }

        // GDPR: explicit consent is required to process the booking.
        if (trim((string) ($d['consent'] ?? '')) !== '1') {
            return $this->bookErr($request, $response, 'Bifează acordul privind prelucrarea datelor personale.');
        }

        $name  = trim((string) ($d['name'] ?? ''));
        $email = trim((string) ($d['email'] ?? ''));
        $phone = trim((string) ($d['phone'] ?? ''));

        if ($name === '' || $phone === '') {
            return $this->bookErr($request, $response, 'Completează numele și telefonul.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->bookErr($request, $response, 'Adresa de email nu este validă.');
        }

        $row = [
            'name'          => $name,
            'email'         => $email ?: null,
            'phone'         => $phone,
            'marca'         => trim((string) ($d['marca'] ?? '')) ?: null,
            'model'         => trim((string) ($d['model'] ?? '')) ?: null,
            'an_fabricatie' => trim((string) ($d['an_fabricatie'] ?? '')) ?: null,
            'sasiu'         => trim((string) ($d['sasiu'] ?? '')) ?: null,
            'kilometri'     => trim((string) ($d['kilometri'] ?? '')) ?: null,
            'lucrari'       => trim((string) ($d['lucrari'] ?? '')) ?: null,
            'ip'            => $this->clientIp($request),
        ];

        try {
            $this->service->createBooking($row);
        } catch (Throwable) {
            // fall through; we still try to email
        }
        $this->notify($row);

        return $this->bookOk($request, $response);
    }

    /** AJAX? (folosit ca să alegem între JSON și redirect cu mesaj). */
    private function isAjax(Request $request): bool
    {
        return strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
    }

    /** Succes: JSON pentru AJAX, altfel redirect la /service cu panoul „mulțumim". */
    private function bookOk(Request $request, Response $response): Response
    {
        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true]);
        }
        return $response->withHeader('Location', $this->base . '/service?trimis=1#programare')->withStatus(303);
    }

    /** Eroare: JSON 422 pentru AJAX, altfel redirect la /service cu mesajul. */
    private function bookErr(Request $request, Response $response, string $msg): Response
    {
        if ($this->isAjax($request)) {
            return $this->json($response->withStatus(422), ['ok' => false, 'error' => $msg]);
        }
        return $response->withHeader('Location', $this->base . '/service?eroare=' . rawurlencode($msg) . '#programare')->withStatus(303);
    }

    /** @param array<string,mixed> $f */
    private function notify(array $f): void
    {
        $moto = trim(($f['marca'] ?? '') . ' ' . ($f['model'] ?? ''));
        $lines = [
            'Programare service de pe motociclete.com.ro',
            '',
            'Nume: ' . $f['name'],
            'Email: ' . ($f['email'] ?: '—'),
            'Telefon: ' . $f['phone'],
            'Motocicletă: ' . ($moto !== '' ? $moto : '—'),
            'An fabricație: ' . ($f['an_fabricatie'] ?: '—'),
            'Serie șasiu: ' . ($f['sasiu'] ?: '—'),
            'Kilometri: ' . ($f['kilometri'] ?: '—'),
            'Lucrări solicitate: ' . ($f['lucrari'] ?: '—'),
            '',
            'IP: ' . $f['ip'],
            'Data: ' . date('Y-m-d H:i:s'),
        ];
        try {
            $this->mailer->send($this->serviceMail, 'Programare service' . ($moto !== '' ? ': ' . $moto : ''), implode("\n", $lines), 'service', (string) ($f['email'] ?? ''));
        } catch (Throwable) {
            // never let mail failure break the JSON response
        }
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
