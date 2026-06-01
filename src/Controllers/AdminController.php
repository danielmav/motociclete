<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Minimal admin. Currently just the price settings (EUR->RON rate, VAT).
 * Protected with HTTP Basic auth (credentials from config 'admin'); if no
 * password is configured the guard is a no-op (handy in local dev).
 */
final class AdminController
{
    private Settings $settings;
    /** @var array{user:string,pass:string} */
    private array $auth;
    private string $base;

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->settings = $container['app_settings'];
        $this->auth = $container['settings']['admin'];
        $this->base = (string) ($container['settings']['app']['base_path'] ?? '');
    }

    /** GET /admin — price settings form. */
    public function settings(Request $request, Response $response): Response
    {
        if ($denied = $this->guard($request, $response)) {
            return $denied;
        }
        return $this->render($response, $request->getQueryParams()['saved'] ?? null);
    }

    /** POST /admin/setari — persist price settings. */
    public function save(Request $request, Response $response): Response
    {
        if ($denied = $this->guard($request, $response)) {
            return $denied;
        }
        $body = (array) $request->getParsedBody();

        $rate = (float) str_replace(',', '.', (string) ($body['eur_ron_rate'] ?? ''));
        $vat  = (int) ($body['vat_pct'] ?? 0);
        $incl = !empty($body['price_includes_vat']) ? '1' : '0';

        if ($rate > 0) {
            $this->settings->set('eur_ron_rate', rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.'));
        }
        if ($vat >= 0 && $vat <= 100) {
            $this->settings->set('vat_pct', (string) $vat);
        }
        $this->settings->set('price_includes_vat', $incl);

        return $response
            ->withHeader('Location', $this->base . '/admin?saved=1')
            ->withStatus(303);
    }

    private function render(Response $response, ?string $saved): Response
    {
        return $this->twig->render($response, 'admin/settings.twig', [
            'cur'   => $this->settings->currency(),
            'saved' => $saved !== null,
        ]);
    }

    /** Returns a 401 response when auth fails, or null when allowed. */
    private function guard(Request $request, Response $response): ?Response
    {
        if ($this->auth['pass'] === '') {
            return null; // protection disabled
        }
        $server = $request->getServerParams();
        $user = $server['PHP_AUTH_USER'] ?? '';
        $pass = $server['PHP_AUTH_PW'] ?? '';

        if ($user === '' && ($hdr = $request->getHeaderLine('Authorization')) && stripos($hdr, 'basic ') === 0) {
            [$user, $pass] = array_pad(explode(':', (string) base64_decode(substr($hdr, 6)), 2), 2, '');
        }

        if (hash_equals($this->auth['user'], $user) && hash_equals($this->auth['pass'], $pass)) {
            return null;
        }
        $response->getBody()->write('Autentificare necesară');
        return $response->withHeader('WWW-Authenticate', 'Basic realm="Admin Dual Motors"')->withStatus(401);
    }
}
