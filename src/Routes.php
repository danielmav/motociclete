<?php

declare(strict_types=1);

use App\Controllers\ApiController;
use App\Controllers\CatalogController;
use App\Controllers\CompareController;
use App\Controllers\HomeController;
use App\Controllers\NewsController;
use Slim\App;
use Slim\Views\Twig;

/**
 * Route table. Returns a closure so Bootstrap can inject the app, the Twig
 * view and a tiny service container. Keep this flat and readable.
 *
 * @return callable(App, Twig, array<string,mixed>): void
 */
return function (App $app, Twig $twig, array $container): void {
    $app->get('/', function ($request, $response) use ($twig, $container) {
        return (new HomeController($twig, $container))->index($request, $response);
    })->setName('home');

    // --- Fit My Bike (live JSON, backed by BikerShop) ---
    $api = function (string $method) use ($container) {
        return function ($request, $response) use ($container, $method) {
            return (new ApiController($container))->{$method}($request, $response);
        };
    };
    $app->get('/api/fit/models',   $api('models'));
    $app->get('/api/fit/years',    $api('years'));
    $app->get('/api/fit/products', $api('products'));

    // --- Product-page lead forms (Cere ofertă / Test ride) ---
    $lead = function (string $method) use ($container) {
        return function ($request, $response) use ($container, $method) {
            return (new \App\Controllers\ContactController($container))->{$method}($request, $response);
        };
    };
    $app->post('/api/lead/oferta',    $lead('oferta'));
    $app->post('/api/lead/test-ride', $lead('testRide'));

    // Health check (handy while wiring things up).
    $app->get('/health', function ($request, $response) use ($container) {
        $response->getBody()->write(json_encode([
            'ok'        => true,
            'bikershop' => $container['bikershop']->isAvailable(),
            'catalog'   => $container['catalog']->isAvailable(),
            'news'      => $container['news']->isAvailable(),
        ], JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // --- Admin (HTTP Basic protected) ---
    $admin = function (string $method) use ($twig, $container) {
        return function ($request, $response, $args) use ($twig, $container, $method) {
            return (new \App\Controllers\AdminController($twig, $container))->{$method}($request, $response, $args);
        };
    };
    $app->get('/admin',              $admin('settings'));
    $app->post('/admin/setari',      $admin('save'));
    $app->get('/admin/fitment',      $admin('fitment'));
    $app->post('/admin/fitment/save', $admin('saveFitment'));
    // My Garage back-office
    $app->get('/admin/garage',                    $admin('garage'));
    $app->get('/admin/garage/moto/{id:[0-9]+}',   $admin('garageBike'));
    $app->post('/admin/garage/moto/{id:[0-9]+}',  $admin('garageBikeSave'));
    $app->get('/admin/service-requests',          $admin('serviceRequests'));
    $app->post('/admin/service-requests',         $admin('serviceRequestSave'));

    // --- Blog (Pe Două Roți), backed by the legacy `noutati` table ---
    $blog = function (string $method) use ($twig, $container) {
        return function ($request, $response, $args) use ($twig, $container, $method) {
            return (new NewsController($twig, $container))->{$method}($request, $response, $args);
        };
    };
    $app->get('/blog', $blog('index'));
    $app->get('/blog/{slug}', $blog('article'));

    // --- Compare models (same brand + same main category) ---
    $app->get('/compara', function ($request, $response, $args) use ($twig, $container) {
        return (new CompareController($twig, $container))->index($request, $response, $args);
    });

    // --- Financing conditions page (UniCredit), backed by the `finance` table ---
    $app->get('/finantare', function ($request, $response) use ($twig, $container) {
        return (new \App\Controllers\FinanceController($twig, $container))->page($request, $response);
    });

    // --- My Garage (private client area, passwordless OTP login) ---
    $garage = function (string $method) use ($twig, $container) {
        return function ($request, $response, $args) use ($twig, $container, $method) {
            return (new \App\Controllers\ClientController($twig, $container))->{$method}($request, $response, $args);
        };
    };
    $app->get('/garage',          $garage('dashboard'));
    $app->get('/garage/login',    $garage('loginForm'));
    $app->post('/garage/login',   $garage('sendCode'));
    $app->get('/garage/verify',   $garage('verifyForm'));
    $app->post('/garage/verify',  $garage('verify'));
    $app->get('/garage/logout',   $garage('logout'));
    $app->get('/garage/service',  $garage('serviceForm'));
    $app->post('/garage/service', $garage('serviceSubmit'));
    $app->get('/garage/moto/{id:[0-9]+}', $garage('bike'));
    // /cont alias -> garage
    $app->get('/cont', function ($request, $response) use ($container) {
        return $response->withHeader('Location', ($container['settings']['app']['base_path'] ?? '') . '/garage')->withStatus(302);
    });

    // --- Catalog (Yamaha + CFMOTO), backed by the local DB ---
    // Static routes above (/, /api/*, /health) take priority in FastRoute.
    $catalog = function (string $method) use ($twig, $container) {
        return function ($request, $response, $args) use ($twig, $container, $method) {
            return (new CatalogController($twig, $container))->{$method}($request, $response, $args);
        };
    };
    $app->get('/{brand:yamaha|cfmoto}',                    $catalog('brand'));
    $app->get('/{brand:yamaha|cfmoto}/{cat}',              $catalog('category'));
    $app->get('/{brand:yamaha|cfmoto}/{cat}/{seg}',        $catalog('categoryOrProduct'));
    $app->get('/{brand:yamaha|cfmoto}/{cat}/{sub}/{slug}', $catalog('product'));

    // Legacy SEO URLs (e.g. /scutere-yamaha/sport/...-2026.html) -> 301 canonical.
    $app->get('/{legacy:.+\.html}', $catalog('legacyRedirect'));
};
