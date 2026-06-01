<?php

declare(strict_types=1);

namespace App\Controllers;

use App\BikerShop\Client;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Lightweight JSON endpoints for the live "Fit My Bike" selector.
 * All read-only, all backed by BikerShop\Client.
 */
final class ApiController
{
    /** @param array<string,mixed> $container */
    public function __construct(array $container)
    {
        $this->bikershop = $container['bikershop'];
    }

    private Client $bikershop;

    public function models(Request $request, Response $response): Response
    {
        $make = (int) ($request->getQueryParams()['make'] ?? 0);
        return $this->json($response, ['options' => $make ? $this->bikershop->models($make) : []]);
    }

    public function years(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $make = (int) ($q['make'] ?? 0);
        $model = (int) ($q['model'] ?? 0);
        return $this->json($response, ['options' => ($make && $model) ? $this->bikershop->years($make, $model) : []]);
    }

    public function products(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $model = (int) ($q['model'] ?? 0);
        $year  = (int) ($q['year'] ?? 0) ?: null;
        $items = $model ? $this->bikershop->compatibleProducts($model, $year, 12) : [];
        return $this->json($response, ['count' => count($items), 'items' => $items]);
    }

    /** @param array<string,mixed> $data */
    private function json(Response $response, array $data): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }
}
