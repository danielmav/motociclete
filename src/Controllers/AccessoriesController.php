<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Accessories\Repository as Accessories;
use App\BikerShop\Client as BikerShop;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Public Accessories page (/accesorii): original Yamaha accessories owned by the
 * portal (relationship accessory↔model), shown as a shop with a "Ce motocicletă
 * ai?" model selector + category (accessory_type) filters and pagination.
 *
 * The relationship lives locally (Accessories\Repository); price/image/buy-URL are
 * fetched LIVE from BikerShop by id. Degrades gracefully if either DB is down.
 */
final class AccessoriesController
{
    private const PER_PAGE = 24;

    private Accessories $accessories;
    private BikerShop $bikershop;

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->accessories = $container['accessories'];
        $this->bikershop   = $container['bikershop'];
    }

    /** GET /accesorii?model=&tip=&page= */
    public function index(Request $request, Response $response): Response
    {
        $q     = $request->getQueryParams();
        $model = (int) ($q['model'] ?? 0) ?: null;
        $tip   = trim((string) ($q['tip'] ?? '')) ?: null;
        $page  = max(1, (int) ($q['page'] ?? 1));

        $models = $this->accessories->modelsWithAccessories();
        $types  = $this->accessories->types($model);

        $pageData = $this->accessories->page($model, $tip, $page, self::PER_PAGE);
        $items    = $this->bikershop->productsByIds($pageData['bs_ids'], self::PER_PAGE);

        $total      = $pageData['total'];
        $totalPages = (int) max(1, ceil($total / self::PER_PAGE));

        // Active model name for the heading.
        $activeModel = null;
        if ($model) {
            foreach ($models as $m) {
                if ($m['id'] === $model) {
                    $activeModel = $m;
                    break;
                }
            }
        }

        return $this->twig->render($response, 'accessories.twig', [
            'models'         => $models,
            'types'          => $types,
            'items'          => $items,
            'total'          => $total,
            'page'           => $page,
            'total_pages'    => $totalPages,
            'active_model'   => $activeModel,
            'active_model_id' => $model,
            'active_type'    => $tip,
            'canonical_path' => '/accesorii',
        ]);
    }
}
