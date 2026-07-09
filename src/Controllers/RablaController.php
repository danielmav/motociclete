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

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->catalog = $container['catalog'];
    }

    public function page(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'catalog/rabla.twig', [
            'groups'         => $this->catalog->rablaEligibleGrouped(),
            'year'           => (int) date('Y'),
            'canonical_path' => '/programul-rabla',
        ]);
    }
}
