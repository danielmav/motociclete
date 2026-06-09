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
