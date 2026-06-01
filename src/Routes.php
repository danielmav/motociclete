<?php

declare(strict_types=1);

use App\Controllers\ApiController;
use App\Controllers\HomeController;
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
        ], JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    });
};
