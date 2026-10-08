<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Support\Mailer;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Handles the product-page lead forms (Cere ofertă / Programează test ride):
 * validate -> persist to `site_messages` -> email the dealer -> JSON response.
 */
final class ContactController
{
    private Database $db;
    private Mailer $mailer;
    private string $dealer;
    private \App\Used\Repository $used;
    private \App\Content\Repository $content;
    /** @var array<string,mixed> */
    private array $settings;

    /** @param array<string,mixed> $container */
    public function __construct(array $container)
    {
        $this->used     = $container['used'];
        $this->content  = $container['content'];
        $this->settings = $container['settings'];
        $this->db     = $container['db'];
        $this->mailer = $container['mailer'];
        $this->dealer = (string) ($container['settings']['mail']['dealer'] ?? 'info@motociclete.com.ro');
    }

    public function oferta(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, 'oferta');
    }

    public function testRide(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, 'test_ride');
    }

    /**
     * POST /api/lead/rulate — formularul din coloana secțiunii Rulate. Merge la
     * Vânzări moto (departament din `contact_departments`, rezervă: dealer).
     * E de ajuns un mijloc de contact: telefon SAU email.
     */
    public function rulate(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();

        if (trim((string) ($data['website'] ?? '')) !== '') {
            return $this->json($response, ['ok' => true]);
        }
        if (trim((string) ($data['consent'] ?? '')) !== '1') {
            return $this->json($response->withStatus(422), ['ok' => false, 'error' => 'Bifează acordul privind prelucrarea datelor personale.']);
        }

        $name    = mb_substr(trim((string) ($data['name'] ?? '')), 0, 120);
        $email   = trim((string) ($data['email'] ?? ''));
        $phone   = mb_substr(trim((string) ($data['phone'] ?? '')), 0, 40);
        $message = mb_substr(trim((string) ($data['message'] ?? '')), 0, 4000);

        if ($name === '') {
            return $this->json($response->withStatus(422), ['ok' => false, 'error' => 'Completează numele.']);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json($response->withStatus(422), ['ok' => false, 'error' => 'Adresa de email nu e validă.']);
        }
        if ($email === '' && $phone === '') {
            return $this->json($response->withStatus(422), ['ok' => false, 'error' => 'Lasă-ne un telefon sau un email ca să te putem contacta.']);
        }

        // Anunțul e opțional; unul șters sau expirat între timp nu blochează mesajul.
        $vehicle = null;
        $vid = (int) ($data['vehicle_id'] ?? 0);
        if ($vid > 0) {
            $found = $this->used->find($vid);
            if ($found !== null && $this->used->isPublic($found)) {
                $vehicle = $found;
            }
        }
        $title = $vehicle['title'] ?? '';
        $path  = $vehicle['url'] ?? '';
        $ip    = $this->clientIp($request);

        try {
            $this->db->local()->prepare(
                'INSERT INTO site_messages (type, brand, product_slug, product_name, name, email, phone, message, ip)
                 VALUES (:type, :brand, :slug, :pname, :name, :email, :phone, :message, :ip)'
            )->execute([
                ':type' => 'rulate',
                ':brand' => $vehicle ? mb_substr((string) $vehicle['brand_name'], 0, 32) : null,
                ':slug' => $path !== '' ? mb_substr($path, 0, 191) : null,
                ':pname' => $title !== '' ? mb_substr($title, 0, 191) : null,
                ':name' => $name, ':email' => $email, ':phone' => $phone,
                ':message' => $message ?: null, ':ip' => $ip,
            ]);
        } catch (Throwable) {
            // mergem mai departe: încercăm măcar emailul
        }

        $dept = $this->content->departmentBySlug('vanzari-moto');
        $to = $dept && filter_var((string) $dept['email'], FILTER_VALIDATE_EMAIL) ? (string) $dept['email'] : $this->dealer;
        $site = rtrim((string) ($this->settings['app']['url'] ?? ''), '/') . (string) ($this->settings['app']['base_path'] ?? '');
        $lines = [
            'Mesaj din secțiunea Rulate — motociclete.com.ro',
            'Anunț: ' . ($title !== '' ? $title : '— (mesaj general)'),
        ];
        if ($path !== '') {
            $lines[] = '';
            $lines[] = $site . $path;
        }
        array_push(
            $lines,
            '',
            'Nume: ' . $name,
            'Telefon: ' . ($phone !== '' ? $phone : '—'),
            'Email: ' . ($email !== '' ? $email : '—'),
            'Mesaj: ' . ($message !== '' ? $message : '—'),
            '',
            'IP: ' . $ip,
            'Data: ' . date('Y-m-d H:i:s')
        );
        try {
            $this->mailer->send($to, 'Rulate: ' . ($title !== '' ? $title : 'mesaj de la ' . $name), implode("\n", $lines), 'rulate', $email);
        } catch (Throwable) {
            // eșecul emailului nu strică răspunsul; mesajul e în site_messages
        }

        return $this->json($response, ['ok' => true]);
    }

    private function handle(Request $request, Response $response, string $type): Response
    {
        $data = (array) $request->getParsedBody();

        // Honeypot: silent success for bots that fill the hidden field.
        if (trim((string) ($data['website'] ?? '')) !== '') {
            return $this->json($response, ['ok' => true]);
        }

        // GDPR: explicit consent is required to process the lead.
        if (trim((string) ($data['consent'] ?? '')) !== '1') {
            return $this->json($response->withStatus(422), ['ok' => false, 'error' => 'Bifează acordul privind prelucrarea datelor personale.']);
        }

        $name  = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));
        $licence = trim((string) ($data['licence'] ?? ''));
        $brand   = trim((string) ($data['brand'] ?? ''));
        $pslug   = trim((string) ($data['product_slug'] ?? ''));
        $pname   = trim((string) ($data['product_name'] ?? ''));

        if ($name === '' || $phone === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json($response->withStatus(422), ['ok' => false, 'error' => 'Completează nume, email valid și telefon.']);
        }
        if ($type === 'test_ride' && $licence === '') {
            return $this->json($response->withStatus(422), ['ok' => false, 'error' => 'Selectează categoria de permis.']);
        }

        $ip = $this->clientIp($request);

        $stored = false;
        try {
            $pdo = $this->db->local();
            $stmt = $pdo->prepare(
                'INSERT INTO site_messages
                   (type, brand, product_slug, product_name, name, email, phone, message, licence, ip)
                 VALUES (:type, :brand, :slug, :pname, :name, :email, :phone, :message, :licence, :ip)'
            );
            $stmt->execute([
                ':type' => $type, ':brand' => $brand ?: null,
                ':slug' => $pslug ?: null, ':pname' => $pname ?: null,
                ':name' => $name, ':email' => $email, ':phone' => $phone,
                ':message' => $message ?: null, ':licence' => $licence ?: null, ':ip' => $ip,
            ]);
            $stored = true;
        } catch (Throwable) {
            $stored = false;
        }

        $this->notify($type, compact('name', 'email', 'phone', 'message', 'licence', 'brand', 'pname', 'pslug', 'ip'));

        // As long as we persisted the lead OR emailed it, the user sees success.
        return $this->json($response, ['ok' => true]);
    }

    /** @param array<string,string> $f */
    private function notify(string $type, array $f): void
    {
        $label = $type === 'test_ride' ? 'Programare drive test' : 'Cerere ofertă';
        $model = trim(($f['brand'] ? ucfirst($f['brand']) . ' ' : '') . $f['pname']);
        $subject = $label . ($model !== '' ? ': ' . $model : '');
        $lines = [
            $label . ' de pe motociclete.com.ro',
            'Model: ' . ($model !== '' ? $model : '—'),
            $f['pslug'] !== '' ? 'Pagina: ' . $f['pslug'] : '',
            '',
            'Nume: ' . $f['name'],
            'Email: ' . $f['email'],
            'Telefon: ' . $f['phone'],
        ];
        if ($type === 'test_ride') {
            $lines[] = 'Categorie permis: ' . $f['licence'];
        } else {
            $lines[] = 'Mesaj: ' . ($f['message'] !== '' ? $f['message'] : '—');
        }
        $lines[] = '';
        $lines[] = 'IP: ' . $f['ip'];
        $lines[] = 'Data: ' . date('Y-m-d H:i:s');

        try {
            $this->mailer->send($this->dealer, $subject, implode("\n", $lines), $type === 'test_ride' ? 'test_ride' : 'lead', $f['email']);
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
        $server = $request->getServerParams();
        return (string) ($server['REMOTE_ADDR'] ?? '');
    }

    /** @param array<string,mixed> $payload */
    private function json(Response $response, array $payload): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
