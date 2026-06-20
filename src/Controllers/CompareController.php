<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Catalog\Repository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Side-by-side model comparison. Only products of the SAME brand and SAME main
 * (top) category can be compared — enforced here regardless of the query string.
 */
final class CompareController
{
    private const MAX = 4;

    private Repository $repo;
    private array $brandLabels = ['yamaha' => 'Yamaha', 'cfmoto' => 'CFMOTO'];

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->repo = $container['catalog'];
    }

    /** /compara?brand=yamaha&models=slug1,slug2 */
    public function index(Request $request, Response $response, array $args): Response
    {
        $q = $request->getQueryParams();
        $brand = (string) ($q['brand'] ?? '');
        if (!isset($this->brandLabels[$brand])) {
            throw new HttpNotFoundException($request);
        }

        $slugs = array_slice(array_filter(array_map('trim', explode(',', (string) ($q['models'] ?? '')))), 0, self::MAX);
        $products = $this->repo->productsBySlugs($brand, $slugs);

        // Enforce same main category: keep only those matching the first one's top.
        if ($products) {
            $top = $products[0]['top_slug'];
            $products = array_values(array_filter($products, fn ($p) => $p['top_slug'] === $top));
        }

        return $this->twig->render($response, 'catalog/compare.twig', [
            'brand'          => $brand,
            'brandLabel'     => $this->brandLabels[$brand],
            'products'       => $products,
            'canonical_path' => '/compara',
        ]);
    }
}
