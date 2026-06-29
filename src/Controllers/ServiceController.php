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

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->service  = $container['service'];
        $this->settings = $container['app_settings'];
        $this->mailer   = $container['mailer'];
        $this->dealer   = (string) ($container['settings']['mail']['dealer'] ?? 'info@motociclete.com.ro');
        // Programările de service merg la service@ (fallback la dealer).
        $this->serviceMail = (string) ($container['settings']['mail']['service'] ?? $this->dealer);
    }

    /** GET /service */
    public function page(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'service.twig', [
            'heading'        => $this->settings->get('service_heading', 'Service'),
            'desc_html'      => $this->settings->get('service_desc_html', ''),
            'note_html'      => $this->settings->get('service_note_html', ''),
            'price_groups'   => $this->service->groupedPrices(),
            'canonical_path' => '/service',
        ]);
    }

    /** POST /service/programare — JSON. */
    public function book(Request $request, Response $response): Response
    {
        $d = (array) $request->getParsedBody();

        // Honeypot: silent success for bots.
        if (trim((string) ($d['website'] ?? '')) !== '') {
            return $this->json($response, ['ok' => true]);
        }

        // GDPR: explicit consent is required to process the booking.
        if (trim((string) ($d['consent'] ?? '')) !== '1') {
            return $this->json($response->withStatus(422), ['ok' => false, 'error' => 'Bifează acordul privind prelucrarea datelor personale.']);
        }

        $name  = trim((string) ($d['name'] ?? ''));
        $email = trim((string) ($d['email'] ?? ''));
        $phone = trim((string) ($d['phone'] ?? ''));

        if ($name === '' || $phone === '') {
            return $this->json($response->withStatus(422), ['ok' => false, 'error' => 'Completează numele și telefonul.']);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json($response->withStatus(422), ['ok' => false, 'error' => 'Adresa de email nu este validă.']);
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

        return $this->json($response, ['ok' => true]);
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
            $this->mailer->send($this->serviceMail, 'Programare service' . ($moto !== '' ? ': ' . $moto : ''), implode("\n", $lines), 'service');
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
