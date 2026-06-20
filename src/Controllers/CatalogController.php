<?php

declare(strict_types=1);

namespace App\Controllers;

use App\BikerShop\Client as BikerShopClient;
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
    private \App\Accessories\Repository $accessories;
    private BikerShopClient $bikershop;
    private string $base;
    private \App\Finance\Repository $finance;
    private array $brandLabels = ['yamaha' => 'Yamaha', 'cfmoto' => 'CFMOTO'];

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->repo      = $container['catalog'];
        $this->accessories = $container['accessories'];
        $this->bikershop = $container['bikershop'];
        $this->base      = (string) ($container['settings']['app']['base_path'] ?? '');
        $this->finance = $container['finance'];
    }

    /** /{brand} — brand landing: list of top categories. */
    public function brand(Request $request, Response $response, array $args): Response
    {
        $brand = $args['brand'];
        $cats = $this->repo->topCategories($brand);
        if (!$cats) {
            throw new HttpNotFoundException($request);
        }
        // Doar categoriile cu produse; fiecare primește un produs aleator (teaser).
        $cats = array_values(array_filter($cats, fn ($c) => (int) $c['product_count'] > 0));
        foreach ($cats as &$c) {
            $c['sample'] = $this->repo->sampleTopCategoryProduct((int) $c['id']);
        }
        unset($c);

        return $this->twig->render($response, 'catalog/brand.twig', [
            'brand'          => $brand,
            'brandLabel'     => $this->brandLabels[$brand] ?? ucfirst($brand),
            'categories'     => $cats,
            'canonical_path' => '/' . $brand,
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
        return $this->renderCategory($request, $response, $brand, $top, $top, null);
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
            return $this->renderCategory($request, $response, $brand, $top, $sub, $top);
        }
        // Otherwise treat {seg} as a product slug under this brand.
        return $this->renderProduct($request, $response, $brand, $args['seg']);
    }

    /** /{brand}/{cat}/{sub}/{slug} — product on a subcategory (Yamaha). */
    public function product(Request $request, Response $response, array $args): Response
    {
        return $this->renderProduct($request, $response, $args['brand'], $args['slug']);
    }

    /** Old top-category URL segment (legacy site) -> new brand/category path. */
    private const LEGACY_CATEGORY_MAP = [
        'motociclete-yamaha'  => '/yamaha/motociclete',
        'scutere-yamaha'      => '/yamaha/scutere',
        'atvuri-yamaha'       => '/yamaha/atvuri',
        'marine-yamaha'       => '/yamaha/marine',
        'waverunners-yamaha'  => '/yamaha/waverunners',
        'snowmobile-yamaha'   => '/yamaha/snowmobile',
        'cfmoto'              => '/cfmoto',
    ];

    /**
     * Legacy {anything}.html — 301 to the new canonical URL. Discontinued models
     * (no product match) fall back to their category page so link equity is kept
     * instead of returning a hard 404.
     */
    public function legacyRedirect(Request $request, Response $response, array $args): Response
    {
        $legacy = ltrim($args['legacy'], '/');
        $canonical = $this->repo->canonicalForLegacyLoose($legacy);
        if (!$canonical) {
            $canonical = $this->legacyCategoryFallback($legacy);
        }
        if (!$canonical) {
            throw new HttpNotFoundException($request);
        }
        return $response->withHeader('Location', $this->base . $canonical)->withStatus(301);
    }

    /** Map the first path segment of a legacy URL to a current category, or null. */
    private function legacyCategoryFallback(string $legacy): ?string
    {
        $first = explode('/', $legacy)[0] ?? '';
        return self::LEGACY_CATEGORY_MAP[$first] ?? null;
    }

    /**
     * Build a 301 from the current request's full path treated as a legacy URL
     * (used when a brand-first route swallowed a `.html` URL). Null if unmapped.
     */
    private function legacyRedirectFromPath(Request $request, Response $response): ?Response
    {
        $path = $request->getUri()->getPath();
        if ($this->base !== '' && str_starts_with($path, $this->base)) {
            $path = substr($path, strlen($this->base));
        }
        $legacy = ltrim($path, '/');
        $canonical = $this->repo->canonicalForLegacyLoose($legacy) ?? $this->legacyCategoryFallback($legacy);
        if (!$canonical) {
            return null;
        }
        return $response->withHeader('Location', $this->base . $canonical)->withStatus(301);
    }

    // -- shared renderers -----------------------------------------------------

    /**
     * @param array<string,mixed> $top     top-level category row
     * @param array<string,mixed> $current category whose products we list
     * @param array<string,mixed>|null $parentForSub the top category when listing a sub
     */
    private function renderCategory(Request $request, Response $response, string $brand, array $top, array $current, ?array $parentForSub): Response
    {
        $isTop = $parentForSub === null;
        $all = $this->repo->productsInCategory($current);
        $subs = $isTop ? $this->repo->subcategories((int) $top['id']) : [];

        // Facets from the full set; filters applied in PHP (categories are small).
        $licences = [];
        $years = [];
        foreach ($all as $c) {
            if ($c['licence'] !== '' && $c['licence'] !== null) {
                $licences[$c['licence']] = true;
            }
            if ($c['year']) {
                $years[$c['year']] = true;
            }
        }
        $licences = array_keys($licences);
        sort($licences);
        $years = array_keys($years);
        rsort($years);

        $q = $request->getQueryParams();
        $selPermis = trim((string) ($q['permis'] ?? ''));
        $selAn = (int) ($q['an'] ?? 0);
        if (!in_array($selPermis, $licences, true)) {
            $selPermis = '';
        }
        if (!in_array($selAn, $years, true)) {
            $selAn = 0;
        }

        $products = array_values(array_filter($all, fn ($c) =>
            ($selPermis === '' || $c['licence'] === $selPermis)
            && ($selAn === 0 || $c['year'] === $selAn)));

        $crumbs = [['label' => $this->brandLabels[$brand] ?? ucfirst($brand), 'url' => '/' . $brand]];
        $crumbs[] = ['label' => $top['name'], 'url' => '/' . $brand . '/' . $top['slug']];
        if (!$isTop) {
            $crumbs[] = ['label' => $current['name'], 'url' => null];
        }

        // Canonical = the category's clean path (drops the ?permis / ?an facets).
        $canonicalPath = $isTop
            ? '/' . $brand . '/' . $top['slug']
            : '/' . $brand . '/' . $top['slug'] . '/' . $current['slug'];

        return $this->twig->render($response, 'catalog/category.twig', [
            'brand'         => $brand,
            'brandLabel'    => $this->brandLabels[$brand] ?? ucfirst($brand),
            'category'      => $current,
            'isTop'         => $isTop,
            'subcategories' => $subs,
            'products'      => $products,
            'total'         => count($all),
            'facets'        => ['licences' => $licences, 'years' => $years],
            'filters'       => ['permis' => $selPermis, 'an' => $selAn],
            'crumbs'        => $crumbs,
            'canonical_path' => $canonicalPath,
        ]);
    }

    private function renderProduct(Request $request, Response $response, string $brand, string $slug): Response
    {
        $product = $this->repo->product($brand, $slug);
        if (!$product) {
            // Old CFMOTO URLs are brand-first with a `.html` suffix, so they match
            // this product route before the legacy `.html` route — redirect here.
            if (str_ends_with($slug, '.html')) {
                $redirect = $this->legacyRedirectFromPath($request, $response);
                if ($redirect) {
                    return $redirect;
                }
            }
            throw new HttpNotFoundException($request);
        }
        $id = (int) $product['id'];

        $crumbs = [['label' => $this->brandLabels[$brand] ?? ucfirst($brand), 'url' => '/' . $brand]];
        $crumbs[] = ['label' => $product['top_name'], 'url' => '/' . $brand . '/' . $product['top_slug']];
        if ($product['sub_slug']) {
            $crumbs[] = ['label' => $product['cat_name'], 'url' => '/' . $brand . '/' . $product['top_slug'] . '/' . $product['sub_slug']];
        }
        $crumbs[] = ['label' => $product['name'], 'url' => null];

        // Same related products BikerShop curates for this motorcycle (advrider_related
        // module: manual + partseurope caches), split by manufacturer into:
        //  - OEM (piese originale): manufacturer = the bike's brand (Yamaha/CFMOTO);
        //  - Aftermarket (accesorii & echipament): every other manufacturer.
        $bsId = isset($product['bs_product_id']) ? (int) $product['bs_product_id'] : 0;
        $rel = $this->bikershop->relatedForBike($bsId, $brand, 15);
        $accessories = $rel['aftermarket'];
        $bsUrl = $rel['url'];

        // OEM Yamaha: relația accesoriu↔model vine din DB-ul portalului (alimentat din
        // feedul Yamaha de database/import_yamaha_accessories.php), NU din adv_related.
        // Cumpărarea rămâne pe BikerShop → preț/imagine/URL live prin bs_product_id.
        // Fallback la adv_related dacă încă nu există mapare în portal (ex. yamaha_pid neimportat).
        $oemParts = $rel['oem'];
        if ($brand === 'yamaha') {
            // Toate accesoriile originale pentru acest model (cap generos — e catalogul
            // OEM complet al modelului). Cele neafișate față de feedul Yamaha = accesorii
            // încă neimportate pe bikershop (productsByIds păstrează doar active/vizibile).
            $oemIds = $this->accessories->bsProductIdsForModel($id, 120);
            if ($oemIds) {
                $oemParts = $this->bikershop->productsByIds($oemIds, count($oemIds));
            }
        }

        // UniCredit rate calculator: RON (VAT-inclusive) price + per-term instalments.
        $cur = $this->twig->getEnvironment()->getGlobals()['currency'];
        $priceEur = (float) ($product['price'] ?? 0);
        $priceRon = $priceEur > 0 ? price_dual($priceEur, $cur)['ron_raw'] : 0;
        $financeRates = $priceRon > 0 ? $this->finance->ratesFor((float) $priceRon) : [];
        $financeCfg = $this->finance->config();

        return $this->twig->render($response, 'catalog/product.twig', [
            'brand'        => $brand,
            'brandLabel'   => $this->brandLabels[$brand] ?? ucfirst($brand),
            'p'            => $product,
            'og_image'     => $product['cover'],
            'canonical_path' => $product['url'],
            'colors'       => $this->repo->images($brand, $id, 'color'),
            'gallery'      => $this->repo->images($brand, $id, 'gallery'),
            'details'      => $this->repo->images($brand, $id, 'detail'),
            'related'      => $this->repo->related($product, 4),
            'oemParts'     => $oemParts,
            'accessories'  => $accessories,
            'bsUrl'        => $bsUrl,
            'crumbs'        => $crumbs,
            'financePriceRon' => $priceRon,
            'financeRates'    => $financeRates,
            'financeCfg'      => $financeCfg,
        ]);
    }
}
