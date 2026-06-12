<?php

declare(strict_types=1);

namespace App\Controllers;

use App\BikerShop\Client;
use App\Catalog\Repository as CatalogRepository;
use App\News\Repository as NewsRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Site-wide search (/cauta?q=…). Fans the query out to the three content
 * sources — local catalog (motociclete), blog (news) and BikerShop equipment —
 * and renders the grouped results. Each source degrades gracefully on its own.
 */
final class SearchController
{
    private CatalogRepository $catalog;
    private NewsRepository $news;
    private Client $bikershop;

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->catalog   = $container['catalog'];
        $this->news      = $container['news'];
        $this->bikershop = $container['bikershop'];
    }

    /** /cauta?q=… */
    public function index(Request $request, Response $response): Response
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));

        $models = $accessories = $articles = [];
        if (mb_strlen($q) >= 2) {
            $models      = $this->catalog->search($q, 24);
            $articles    = $this->news->search($q, 12);
            $accessories = $this->bikershop->searchProducts($q, 24);
        }

        $total = count($models) + count($accessories) + count($articles);

        return $this->twig->render($response, 'search.twig', [
            'q'           => $q,
            'models'      => $models,
            'accessories' => $accessories,
            'articles'    => $articles,
            'total'       => $total,
        ]);
    }
}
