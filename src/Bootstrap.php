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
        $twig->getEnvironment()->addGlobal('nav', Support\Navigation::menu());
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
            'news'      => new News\Repository($db),
        ];

        // --- Error handling ---
        $debug = (bool) $settings['app']['debug'];
        $errorMw = $app->addErrorMiddleware($debug, true, true);

        // --- Routes ---
        (require $root . '/src/Routes.php')($app, $twig, $container);

        return $app;
    }
}
