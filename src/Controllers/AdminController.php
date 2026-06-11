<?php

declare(strict_types=1);

namespace App\Controllers;

use App\BikerShop\Client as BikerShopClient;
use App\Catalog\Repository;
use App\Client\Repository as ClientRepo;
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
    private Repository $repo;
    private BikerShopClient $bikershop;
    private ClientRepo $client;
    /** @var array{user:string,pass:string} */
    private array $auth;
    private string $base;

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->settings  = $container['app_settings'];
        $this->repo      = $container['catalog'];
        $this->bikershop = $container['bikershop'];
        $this->client    = $container['client'];
        $this->auth      = $container['settings']['admin'];
        $this->base      = (string) ($container['settings']['app']['base_path'] ?? '');
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

    /** GET /admin/fitment — fitment mapping table. */
    public function fitment(Request $request, Response $response): Response
    {
        if ($denied = $this->guard($request, $response)) {
            return $denied;
        }
        return $this->twig->render($response, 'admin/fitment.twig', [
            'products' => $this->repo->allProductsForFitmentAdmin(),
            'makes'    => $this->bikershop->makes(),
            'saved'    => ($request->getQueryParams()['saved'] ?? null) !== null,
        ]);
    }

    /** POST /admin/fitment/save — persist fitment IDs for one product. */
    public function saveFitment(Request $request, Response $response): Response
    {
        if ($denied = $this->guard($request, $response)) {
            return $denied;
        }
        $body      = (array) $request->getParsedBody();
        $productId = (int) ($body['product_id'] ?? 0);
        $makeId    = ($body['make_id'] ?? '') !== '' ? (int) $body['make_id'] : null;
        $modelId   = ($body['model_id'] ?? '') !== '' ? (int) $body['model_id'] : null;
        $yearId    = ($body['year_id'] ?? '') !== '' ? (int) $body['year_id'] : null;

        if ($productId > 0) {
            $this->repo->updateFitment($productId, $makeId, $modelId, $yearId);
        }
        return $response
            ->withHeader('Location', $this->base . '/admin/fitment?saved=1')
            ->withStatus(303);
    }

    // -- My Garage back-office ------------------------------------------------

    /** GET /admin/garage — search clients & their bikes. */
    public function garage(Request $request, Response $response): Response
    {
        if ($denied = $this->guard($request, $response)) {
            return $denied;
        }
        $q = (string) ($request->getQueryParams()['q'] ?? '');
        return $this->twig->render($response, 'admin/garage.twig', [
            'q'     => $q,
            'bikes' => $this->client->adminBikes($q !== '' ? $q : null),
        ]);
    }

    /** GET /admin/garage/moto/{id} — edit a bike + add service/incident records. */
    public function garageBike(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->guard($request, $response)) {
            return $denied;
        }
        $bike = $this->client->adminBike((int) ($args['id'] ?? 0));
        if (!$bike) {
            $response->getBody()->write('Motocicletă inexistentă');
            return $response->withStatus(404);
        }
        $bikeId = (int) $bike['id'];
        return $this->twig->render($response, 'admin/garage_bike.twig', [
            'bike'      => $bike,
            'products'  => $this->repo->allProductsForFitmentAdmin(),
            'service'   => $this->client->serviceRecords($bikeId),
            'incidents' => $this->client->incidents($bikeId),
            'saved'     => ($request->getQueryParams()['saved'] ?? null) !== null,
        ]);
    }

    /** POST /admin/garage/moto/{id} — save bike fields / add a service / incident. */
    public function garageBikeSave(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->guard($request, $response)) {
            return $denied;
        }
        $id   = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();
        $action = (string) ($body['action'] ?? 'profile');

        if ($id > 0) {
            if ($action === 'service') {
                $this->client->addServiceRecord($id, $body);
            } elseif ($action === 'incident') {
                $this->client->addIncident($id, $body);
            } else {
                $this->client->updateBike($id, $body);
            }
        }
        return $response
            ->withHeader('Location', $this->base . '/admin/garage/moto/' . $id . '?saved=1')
            ->withStatus(303);
    }

    /** GET /admin/service-requests — inbox of client requests. */
    public function serviceRequests(Request $request, Response $response): Response
    {
        if ($denied = $this->guard($request, $response)) {
            return $denied;
        }
        $status = (string) ($request->getQueryParams()['status'] ?? '');
        return $this->twig->render($response, 'admin/service_requests.twig', [
            'status'   => $status,
            'requests' => $this->client->serviceRequests($status !== '' ? $status : null),
        ]);
    }

    /** POST /admin/service-requests — change a request's status. */
    public function serviceRequestSave(Request $request, Response $response): Response
    {
        if ($denied = $this->guard($request, $response)) {
            return $denied;
        }
        $body = (array) $request->getParsedBody();
        $this->client->setRequestStatus((int) ($body['id'] ?? 0), (string) ($body['status'] ?? ''));
        return $response
            ->withHeader('Location', $this->base . '/admin/service-requests')
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
