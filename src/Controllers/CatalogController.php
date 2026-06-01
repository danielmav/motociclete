<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Catalog\Repository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Catalog pages (category listing + product detail) backed by the local
 * `motociclete` DB via Catalog\Repository. Also handles 301 redirects from the
 * legacy *.html URLs to their new canonical paths.
 */
final class CatalogController
{
    private Repository $repo;
    private string $base;
    private array $brandLabels = ['yamaha' => 'Yamaha', 'cfmoto' => 'CFMOTO'];

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->repo = $container['catalog'];
        $this->base = (string) ($container['settings']['app']['base_path'] ?? '');
    }

    /** /{brand} — brand landing: list of top categories. */
    public function brand(Request $request, Response $response, array $args): Response
    {
        $brand = $args['brand'];
        $cats = $this->repo->topCategories($brand);
        if (!$cats) {
            throw new HttpNotFoundException($request);
        }
        return $this->twig->render($response, 'catalog/brand.twig', [
            'brand'      => $brand,
            'brandLabel' => $this->brandLabels[$brand] ?? ucfirst($brand),
            'categories' => $cats,
        ]);
    }

    /** /{brand}/{cat} — top-level category page. */
    public function category(Request $request, Response $response, array $args): Response
    {
        $brand = $args['brand'];
        $top = $this->repo->topCategory($brand, $args['cat']);
        if (!$top) {
            throw new HttpNotFoundException($request);
        }
        return $this->renderCategory($response, $brand, $top, $top, null);
    }

    /**
     * /{brand}/{cat}/{seg} — ambiguous: either a subcategory listing or a
     * product attached directly to a top category (CFMOTO flat categories).
     */
    public function categoryOrProduct(Request $request, Response $response, array $args): Response
    {
        $brand = $args['brand'];
        $top = $this->repo->topCategory($brand, $args['cat']);
        if (!$top) {
            throw new HttpNotFoundException($request);
        }
        // Subcategory listing wins if {seg} is a child of {cat}.
        $sub = $this->repo->subCategory((int) $top['id'], $args['seg']);
        if ($sub) {
            return $this->renderCategory($response, $brand, $top, $sub, $top);
        }
        // Otherwise treat {seg} as a product slug under this brand.
        return $this->renderProduct($request, $response, $brand, $args['seg']);
    }

    /** /{brand}/{cat}/{sub}/{slug} — product on a subcategory (Yamaha). */
    public function product(Request $request, Response $response, array $args): Response
    {
        return $this->renderProduct($request, $response, $args['brand'], $args['slug']);
    }

    /** Legacy {anything}.html — 301 to the new canonical URL, or 404. */
    public function legacyRedirect(Request $request, Response $response, array $args): Response
    {
        $legacy = ltrim($args['legacy'], '/');
        $canonical = $this->repo->canonicalForLegacy($legacy);
        if (!$canonical) {
            throw new HttpNotFoundException($request);
        }
        return $response->withHeader('Location', $this->base . $canonical)->withStatus(301);
    }

    // -- shared renderers -----------------------------------------------------

    /**
     * @param array<string,mixed> $top     top-level category row
     * @param array<string,mixed> $current category whose products we list
     * @param array<string,mixed>|null $parentForSub the top category when listing a sub
     */
    private function renderCategory(Response $response, string $brand, array $top, array $current, ?array $parentForSub): Response
    {
        $isTop = $parentForSub === null;
        $products = $this->repo->productsInCategory($current);
        $subs = $isTop ? $this->repo->subcategories((int) $top['id']) : [];

        $crumbs = [['label' => $this->brandLabels[$brand] ?? ucfirst($brand), 'url' => '/' . $brand]];
        $crumbs[] = ['label' => $top['name'], 'url' => '/' . $brand . '/' . $top['slug']];
        if (!$isTop) {
            $crumbs[] = ['label' => $current['name'], 'url' => null];
        }

        return $this->twig->render($response, 'catalog/category.twig', [
            'brand'         => $brand,
            'brandLabel'    => $this->brandLabels[$brand] ?? ucfirst($brand),
            'category'      => $current,
            'isTop'         => $isTop,
            'subcategories' => $subs,
            'products'      => $products,
            'crumbs'        => $crumbs,
        ]);
    }

    private function renderProduct(Request $request, Response $response, string $brand, string $slug): Response
    {
        $product = $this->repo->product($brand, $slug);
        if (!$product) {
            throw new HttpNotFoundException($request);
        }
        $id = (int) $product['id'];

        $crumbs = [['label' => $this->brandLabels[$brand] ?? ucfirst($brand), 'url' => '/' . $brand]];
        $crumbs[] = ['label' => $product['top_name'], 'url' => '/' . $brand . '/' . $product['top_slug']];
        if ($product['sub_slug']) {
            $crumbs[] = ['label' => $product['cat_name'], 'url' => '/' . $brand . '/' . $product['top_slug'] . '/' . $product['sub_slug']];
        }
        $crumbs[] = ['label' => $product['name'], 'url' => null];

        return $this->twig->render($response, 'catalog/product.twig', [
            'brand'      => $brand,
            'brandLabel' => $this->brandLabels[$brand] ?? ucfirst($brand),
            'p'          => $product,
            'og_image'   => $product['cover'],
            'colors'     => $this->repo->images($brand, $id, 'color'),
            'gallery'    => $this->repo->images($brand, $id, 'gallery'),
            'details'    => $this->repo->images($brand, $id, 'detail'),
            'related'    => $this->repo->related($product, 4),
            'crumbs'     => $crumbs,
        ]);
    }
}
