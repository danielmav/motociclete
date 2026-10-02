<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Catalog\Repository as Catalog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Pagina publică indexabilă „Modele eligibile programul RABLA {an}" — listează
 * toate produsele marcate eligibile, grupate pe tip (Motociclete / Scutere / …).
 */
final class RablaController
{
    private Catalog $catalog;
    private \App\Support\Settings $settings;
    private string $base;

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->catalog  = $container['catalog'];
        $this->settings = $container['app_settings'];
        $this->base     = (string) ($container['settings']['app']['base_path'] ?? '');
    }

    public function page(Request $request, Response $response): Response
    {
        // Programul oprit din Setări → pagina nu mai e disponibilă (302: revine la reactivare).
        if (!$this->settings->bool('rabla_home_section', false)) {
            return $response->withHeader('Location', $this->base . '/')->withStatus(302);
        }

        return $this->twig->render($response, 'catalog/rabla.twig', [
            'groups'         => $this->catalog->rablaEligibleGrouped(),
            'year'           => (int) date('Y'),
            'canonical_path' => '/programul-rabla',
        ]);
    }
}
