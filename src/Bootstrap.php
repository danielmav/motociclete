<?php

declare(strict_types=1);

namespace App;

use Dotenv\Dotenv;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

/**
 * Wires up the application: env, settings, Twig, error handling, routes.
 */
final class Bootstrap
{
    public static function create(): App
    {
        $root = dirname(__DIR__);

        // --- Environment ---
        if (is_file($root . '/.env')) {
            Dotenv::createImmutable($root)->safeLoad();
        }

        /** @var array<string,mixed> $settings */
        $settings = require $root . '/config/settings.php';

        // --- Session (My Garage client login) ---
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
            $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
                || ($_SERVER['SERVER_PORT'] ?? '') === '443';
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => ($settings['app']['base_path'] ?: '/'),
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => $https,
            ]);
            session_name('dm_garage');
            session_start();
            // CSRF token (per session) used by admin forms.
            if (empty($_SESSION['csrf'])) {
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
            }
        }

        // --- Slim app ---
        $app = AppFactory::create();
        if ($settings['app']['base_path'] !== '') {
            $app->setBasePath($settings['app']['base_path']);
        }
        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();

        // --- Twig view ---
        $twig = Twig::create($settings['twig']['templates'], [
            'cache'       => $settings['twig']['cache'],
            'auto_reload' => true,
        ]);
        // --- Shared services available to controllers ---
        $db = new Database($settings['db']);
        $appSettings = new Support\Settings($db);
        $currency = $appSettings->currency();

        $twig->getEnvironment()->addGlobal('app', $settings['app']);
        $twig->getEnvironment()->addGlobal('base', $settings['app']['base_path']);
        $twig->getEnvironment()->addGlobal('testride_url', $settings['app']['testride_url']);
        $twig->getEnvironment()->addGlobal('currency', $currency);
        $twig->getEnvironment()->addFilter(
            new \Twig\TwigFilter('money', fn ($v) => money_ron((float) $v))
        );
        // EUR amount -> {eur, ron} VAT-inclusive display strings.
        $twig->getEnvironment()->addFunction(
            new \Twig\TwigFunction('prices', fn ($eur) => price_dual((float) $eur, $currency))
        );
        $app->add(TwigMiddleware::create($app, $twig));

        $container = [
            'settings'  => $settings,
            'db'        => $db,
            'app_settings' => $appSettings,
            'bikershop' => new BikerShop\Client($db, $settings['db']['bikershop']),
            'catalog'   => new Catalog\Repository($db),
            'accessories' => new Accessories\Repository($db),
            'accessories_importer' => new Accessories\Importer($db, new BikerShop\Client($db, $settings['db']['bikershop'])),
            'hero'      => new Hero\Repository($db),
            'news'      => new News\Repository($db),
            'events'    => new Event\Repository($db),
            'about'     => new About\Repository($db),
            'history'   => new History\Repository($db),
            'service'   => new Service\Repository($db),
            'content'   => new Content\Repository($db),
            'finance'   => new Finance\Repository($db),
            'client'    => new Client\Repository($db),
            'mailer'    => new Support\Mailer(
                $settings['mail'],
                $root . '/storage/logs',
                ($settings['app']['env'] ?? 'prod') === 'dev',
                $db->local()
            ),
        ];

        // Site-wide mega menu (live from the catalog, file-cached). Registered
        // after the container so it can use the catalog repository.
        $twig->getEnvironment()->addGlobal(
            'navV2',
            Support\NavigationV2::cached($container['catalog'], $root . '/storage/cache')
        );

        // Footer contact data (admin-managed): socials, address, departments, legal pages.
        $twig->getEnvironment()->addGlobal('site', [
            'social' => [
                'facebook'  => $appSettings->get('social_facebook', ''),
                'instagram' => $appSettings->get('social_instagram', ''),
                'youtube'   => $appSettings->get('social_youtube', ''),
                'tiktok'    => $appSettings->get('social_tiktok', ''),
            ],
            'address'     => $appSettings->get('address', ''),
            'schedule'    => $appSettings->get('schedule', ''),
            'phone'       => $appSettings->get('phone_general', ''),
            'departments' => $container['content']->departments(),
            'legal_pages' => $container['content']->activePages(),
        ]);

        // --- Error handling ---
        $debug = (bool) $settings['app']['debug'];
        $errorMw = $app->addErrorMiddleware($debug, true, true);

        // --- Routes ---
        (require $root . '/src/Routes.php')($app, $twig, $container);

        return $app;
    }
}
