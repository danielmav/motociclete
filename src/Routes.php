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

    // --- Admin back-office (hidden path from settings, session auth) ---
    $adminBase = (string) ($container['settings']['admin']['path'] ?? '/dm-control');
    $adminCtl = function (string $class, string $method) use ($twig, $container) {
        return function ($request, $response, $args) use ($twig, $container, $class, $method) {
            $fq = 'App\\Admin\\' . $class;
            return (new $fq($twig, $container))->{$method}($request, $response, $args ?? []);
        };
    };
    $app->get($adminBase,             $adminCtl('DashboardController', 'index'));
    $app->get($adminBase . '/login',  $adminCtl('AuthController', 'loginForm'));
    $app->post($adminBase . '/login', $adminCtl('AuthController', 'login'));
    $app->get($adminBase . '/logout', $adminCtl('AuthController', 'logout'));
    $app->post($adminBase . '/upload', $adminCtl('UploadController', 'upload'));
    // Hero slides
    $app->get($adminBase . '/hero',                       $adminCtl('HeroController', 'index'));
    $app->get($adminBase . '/hero/{id:[0-9]+}',           $adminCtl('HeroController', 'form'));
    $app->post($adminBase . '/hero/{id:[0-9]+}',          $adminCtl('HeroController', 'save'));
    $app->post($adminBase . '/hero/{id:[0-9]+}/delete',   $adminCtl('HeroController', 'delete'));
    // Categories
    $app->get($adminBase . '/categorii',                     $adminCtl('CategoryController', 'index'));
    $app->get($adminBase . '/categorii/{id:[0-9]+}',         $adminCtl('CategoryController', 'form'));
    $app->post($adminBase . '/categorii/{id:[0-9]+}',        $adminCtl('CategoryController', 'save'));
    $app->post($adminBase . '/categorii/{id:[0-9]+}/delete', $adminCtl('CategoryController', 'delete'));
    // Products
    $app->get($adminBase . '/produse',                     $adminCtl('ProductController', 'index'));
    $app->post($adminBase . '/produse/import-yamaha',      $adminCtl('ProductController', 'importYamaha'));
    $app->get($adminBase . '/produse/{id:[0-9]+}',         $adminCtl('ProductController', 'form'));
    $app->post($adminBase . '/produse/{id:[0-9]+}',        $adminCtl('ProductController', 'save'));
    $app->post($adminBase . '/produse/{id:[0-9]+}/delete', $adminCtl('ProductController', 'delete'));
    $app->post($adminBase . '/produse/{id:[0-9]+}/sync-accesorii', $adminCtl('ProductController', 'syncAccessories'));
    // Blog
    $app->get($adminBase . '/blog',                          $adminCtl('NewsController', 'index'));
    $app->post($adminBase . '/blog/categorie',               $adminCtl('NewsController', 'saveCategory'));
    $app->post($adminBase . '/blog/categorie/{id:[0-9]+}/delete', $adminCtl('NewsController', 'deleteCategory'));
    $app->get($adminBase . '/blog/{id:[0-9]+}',              $adminCtl('NewsController', 'form'));
    $app->post($adminBase . '/blog/{id:[0-9]+}',             $adminCtl('NewsController', 'save'));
    $app->post($adminBase . '/blog/{id:[0-9]+}/delete',      $adminCtl('NewsController', 'delete'));
    // Events
    $app->get($adminBase . '/evenimente',                     $adminCtl('EventController', 'index'));
    $app->get($adminBase . '/evenimente/{id:[0-9]+}',         $adminCtl('EventController', 'form'));
    $app->post($adminBase . '/evenimente/{id:[0-9]+}',        $adminCtl('EventController', 'save'));
    $app->post($adminBase . '/evenimente/{id:[0-9]+}/delete', $adminCtl('EventController', 'delete'));
    // Announcements (site-wide pop-up)
    $app->get($adminBase . '/anunturi',                     $adminCtl('AnnouncementController', 'index'));
    $app->get($adminBase . '/anunturi/{id:[0-9]+}',         $adminCtl('AnnouncementController', 'form'));
    $app->post($adminBase . '/anunturi/{id:[0-9]+}',        $adminCtl('AnnouncementController', 'save'));
    $app->post($adminBase . '/anunturi/{id:[0-9]+}/delete', $adminCtl('AnnouncementController', 'delete'));
    // Despre — intro + gallery
    $app->get($adminBase . '/despre',                       $adminCtl('AboutController', 'index'));
    $app->post($adminBase . '/despre',                      $adminCtl('AboutController', 'save'));
    // Despre — team members
    $app->get($adminBase . '/echipa/{id:[0-9]+}',           $adminCtl('AboutController', 'memberForm'));
    $app->post($adminBase . '/echipa/{id:[0-9]+}',          $adminCtl('AboutController', 'memberSave'));
    $app->post($adminBase . '/echipa/{id:[0-9]+}/delete',   $adminCtl('AboutController', 'memberDelete'));
    // Despre — history timeline
    $app->get($adminBase . '/istoric',                       $adminCtl('HistoryController', 'index'));
    $app->get($adminBase . '/istoric/{id:[0-9]+}',           $adminCtl('HistoryController', 'form'));
    $app->post($adminBase . '/istoric/{id:[0-9]+}',          $adminCtl('HistoryController', 'save'));
    $app->post($adminBase . '/istoric/{id:[0-9]+}/delete',   $adminCtl('HistoryController', 'delete'));
    // Service — description + note + price list
    $app->get($adminBase . '/service',                       $adminCtl('ServiceController', 'index'));
    $app->post($adminBase . '/service',                      $adminCtl('ServiceController', 'save'));
    // Settings
    $app->get($adminBase . '/setari',                              $adminCtl('SettingsController', 'index'));
    $app->post($adminBase . '/setari',                             $adminCtl('SettingsController', 'save'));
    $app->post($adminBase . '/setari/departament',                $adminCtl('SettingsController', 'saveDepartment'));
    $app->post($adminBase . '/setari/departament/{id:[0-9]+}/delete', $adminCtl('SettingsController', 'deleteDepartment'));
    $app->get($adminBase . '/setari/pagini',                       $adminCtl('SettingsController', 'pages'));
    $app->get($adminBase . '/setari/pagini/{id:[0-9]+}',           $adminCtl('SettingsController', 'pageForm'));
    $app->post($adminBase . '/setari/pagini/{id:[0-9]+}',          $adminCtl('SettingsController', 'savePage'));
    $app->post($adminBase . '/setari/pagini/{id:[0-9]+}/delete',   $adminCtl('SettingsController', 'deletePage'));
    // Messages
    $app->get($adminBase . '/mesaje',                  $adminCtl('MessageController', 'index'));
    $app->post($adminBase . '/mesaje/citit',           $adminCtl('MessageController', 'markRead'));
    $app->post($adminBase . '/mesaje/service-status',  $adminCtl('MessageController', 'serviceStatus'));
    $app->post($adminBase . '/mesaje/booking-status',  $adminCtl('MessageController', 'bookingStatus'));
    // Garage
    $app->get($adminBase . '/garage',                       $adminCtl('GarageController', 'index'));
    $app->get($adminBase . '/garage/calendar',              $adminCtl('GarageController', 'calendar'));
    $app->get($adminBase . '/garage/moto/{id:[0-9]+}',      $adminCtl('GarageController', 'bike'));
    $app->post($adminBase . '/garage/moto/{id:[0-9]+}',     $adminCtl('GarageController', 'bikeSave'));

    // --- Blog (Pe Două Roți), backed by the legacy `noutati` table ---
    $blog = function (string $method) use ($twig, $container) {
        return function ($request, $response, $args) use ($twig, $container, $method) {
            return (new NewsController($twig, $container))->{$method}($request, $response, $args);
        };
    };
    $app->get('/blog', $blog('index'));
    $app->get('/blog/{slug}', $blog('article'));

    // --- Events (public section) ---
    $ev = function (string $method) use ($twig, $container) {
        return function ($request, $response, $args) use ($twig, $container, $method) {
            return (new \App\Controllers\EventController($twig, $container))->{$method}($request, $response, $args);
        };
    };
    $app->get('/evenimente', $ev('index'));
    $app->get('/evenimente/{slug}', $ev('show'));

    // --- Compare models (same brand + same main category) ---
    $app->get('/compara', function ($request, $response, $args) use ($twig, $container) {
        return (new CompareController($twig, $container))->index($request, $response, $args);
    });

    // --- Site-wide search (catalog + blog + BikerShop equipment) ---
    $app->get('/cauta', function ($request, $response) use ($twig, $container) {
        return (new \App\Controllers\SearchController($twig, $container))->index($request, $response);
    });

    // --- Financing conditions page (UniCredit), backed by the `finance` table ---
    $app->get('/finantare', function ($request, $response) use ($twig, $container) {
        return (new \App\Controllers\FinanceController($twig, $container))->page($request, $response);
    });

    // --- Despre noi (admin-managed: intro + team + history timeline) ---
    // Canonical SEO URL mirrors the legacy filename (despre_dual_motors).
    $app->get('/despre_dual_motors', function ($request, $response) use ($twig, $container) {
        return (new \App\Controllers\AboutController($twig, $container))->index($request, $response);
    });
    // 301 the old short slug + the legacy .php URL to the canonical one.
    $despreRedirect = function ($request, $response) use ($container) {
        return $response
            ->withHeader('Location', ($container['settings']['app']['base_path'] ?? '') . '/despre_dual_motors')
            ->withStatus(301);
    };
    $app->get('/despre', $despreRedirect);
    $app->get('/despre_dual_motors.php', $despreRedirect);

    // --- Service (admin-managed description + price list; anonymous booking form) ---
    $app->get('/service', function ($request, $response) use ($twig, $container) {
        return (new \App\Controllers\ServiceController($twig, $container))->page($request, $response);
    });
    $app->post('/service/programare', function ($request, $response) use ($twig, $container) {
        return (new \App\Controllers\ServiceController($twig, $container))->book($request, $response);
    });

    // --- Accesorii originale (portal-owned relation; live price/image from BikerShop) ---
    $app->get('/accesorii', function ($request, $response) use ($twig, $container) {
        return (new \App\Controllers\AccessoriesController($twig, $container))->index($request, $response);
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

    // Static pages (terms/privacy/about…) at /{slug}. Registered LAST so static
    // routes + brand + legacy take priority; unknown slugs 404 in the controller.
    $app->get('/{slug:[a-z0-9-]+}', function ($request, $response, $args) use ($twig, $container) {
        return (new \App\Controllers\PageController($twig, $container))->show($request, $response, $args);
    });
};
