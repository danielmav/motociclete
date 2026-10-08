<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Used\Repository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Secțiunea publică „Rulate": liste (toate / categorie / marcă) + pagina anunțului.
 * Un anunț expirat sau dezactivat răspunde 410 cu alternative.
 */
final class UsedController
{
    private const PER_PAGE = 12;
    private const SALES_SLUG = 'vanzari-moto';

    private Repository $repo;
    private string $base;
    private string $appUrl;
    /** @var array<string,mixed> */
    private array $container;

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->container = $container;
        $this->repo   = $container['used'];
        $this->base   = (string) ($container['settings']['app']['base_path'] ?? '');
        $this->appUrl = rtrim((string) ($container['settings']['app']['url'] ?? ''), '/');
    }

    /** GET /rulate */
    public function index(Request $request, Response $response): Response
    {
        return $this->renderList($request, $response, null, null);
    }

    /** GET /rulate/{cat} */
    public function category(Request $request, Response $response, array $args): Response
    {
        $cat = $this->repo->categoryBySlug((string) ($args['cat'] ?? ''));
        if ($cat === null) {
            throw new HttpNotFoundException($request);
        }
        return $this->renderList($request, $response, $cat, null);
    }

    /** GET /rulate/marca/{marca} */
    public function brand(Request $request, Response $response, array $args): Response
    {
        $brand = $this->repo->brandBySlug((string) ($args['marca'] ?? ''));
        if ($brand === null) {
            throw new HttpNotFoundException($request);
        }
        return $this->renderList($request, $response, null, $brand);
    }

    /** GET /rulate/{id}-{slug} */
    public function show(Request $request, Response $response, array $args): Response
    {
        $v = $this->repo->find((int) ($args['id'] ?? 0));
        if ($v === null) {
            throw new HttpNotFoundException($request);
        }
        if (!$this->repo->isPublic($v)) {
            return $this->twig->render($response->withStatus(410), 'used/gone.twig', $this->sidebar() + [
                'v'              => $v,
                'others'         => $this->repo->latest(6, $v['id']),
                'canonical_path' => $v['url'],
            ]);
        }
        if ((string) ($args['slug'] ?? '') !== $v['slug']) {
            return $response->withHeader('Location', $this->base . $v['url'])->withStatus(301);
        }

        $price = $v['price_eur'] !== null ? (int) round($v['price_eur']) : null;
        $title = $v['title'] . ($v['year'] ? ' (' . $v['year'] . ')' : '') . ' — rulat'
            . ($price !== null ? ', ' . number_format($price, 0, ',', '.') . ' EUR' : '');
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($v['description_html']), ENT_QUOTES, 'UTF-8')));
        $desc = trim(($v['facts'] !== '' ? $v['facts'] . '. ' : '') . mb_substr($text, 0, 140));
        if ($desc === '') {
            $desc = $v['brand_name'] . ' rulat, de vânzare la Dual Motors, showroom Pipera, București.';
        }

        return $this->twig->render($response, 'used/show.twig', $this->sidebar() + [
            'v'              => $v,
            'others'         => $this->repo->latest(6, $v['id']),
            'seo'            => ['title' => $title, 'description' => $desc],
            'canonical_path' => $v['url'],
            'og_image'       => $v['image'],
            'ld_json'        => $this->json([$this->vehicleLd($v, $price, $desc), $this->crumbsLd([
                ['Rulate', '/rulate'],
                [$v['category_name'], '/rulate/' . $v['category_slug']],
                [$v['title'], null],
            ])]),
        ]);
    }

    /** @param array<string,mixed>|null $cat @param array<string,mixed>|null $brand */
    private function renderList(Request $request, Response $response, ?array $cat, ?array $brand): Response
    {
        $page = max(1, (int) ($request->getQueryParams()['p'] ?? 1));
        $res = $this->repo->page($cat['id'] ?? null, $brand['id'] ?? null, $page, self::PER_PAGE);
        $pages = max(1, (int) ceil($res['total'] / self::PER_PAGE));
        if ($page > $pages) {
            throw new HttpNotFoundException($request);
        }

        $path = '/rulate';
        $heading = 'Vehicule rulate';
        $seo = [
            'title'       => 'Motociclete rulate și second hand — Dual Motors',
            'description' => 'Motociclete, scutere și ATV-uri rulate, verificate de Dual Motors. Vezi anunțurile active și scrie-ne direct din pagina anunțului — showroom Pipera, București.',
        ];
        $crumbs = [['Rulate', null]];
        if ($cat) {
            $path = '/rulate/' . $cat['slug'];
            $heading = $cat['name'] . ' rulate';
            $seo = [
                'title'       => $cat['name'] . ' rulate — Dual Motors',
                'description' => $cat['name'] . ' rulate de vânzare la Dual Motors: anunțuri active cu an, kilometraj și preț. Showroom Pipera, București.',
            ];
            $crumbs = [['Rulate', '/rulate'], [$cat['name'], null]];
        } elseif ($brand) {
            $path = '/rulate/marca/' . $brand['slug'];
            $heading = $brand['name'] . ' rulate';
            $seo = [
                'title'       => $brand['name'] . ' rulate, second hand — Dual Motors',
                'description' => 'Vehicule ' . $brand['name'] . ' rulate de vânzare la Dual Motors: anunțuri active cu an, kilometraj și preț. Showroom Pipera, București.',
            ];
            $crumbs = [['Rulate', '/rulate'], [$brand['name'], null]];
        }
        if ($page > 1) {
            $seo['title'] .= ' — pagina ' . $page;
        }

        $items = [];
        foreach ($res['items'] as $i => $v) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => $this->appUrl . $this->base . $v['url'], 'name' => $v['title']];
        }

        return $this->twig->render($response, 'used/index.twig', $this->sidebar() + [
            'vehicles'         => $res['items'],
            'total'            => $res['total'],
            'page'             => $page,
            'pages'            => $pages,
            'list_path'        => $path,
            'heading'          => $heading,
            'seo'              => $seo,
            'current_category' => $cat['slug'] ?? '',
            'current_brand'    => $brand['slug'] ?? '',
            // O categorie/marcă fără anunțuri publice nu merită indexată.
            'noindex'          => ($cat || $brand) && $res['total'] === 0,
            'canonical_path'   => $path . ($page > 1 ? '?p=' . $page : ''),
            'ld_json'          => $this->json([
                ['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => $heading, 'itemListElement' => $items],
                $this->crumbsLd($crumbs),
            ]),
        ]);
    }

    /** Datele coloanei din dreapta, comune tuturor paginilor. */
    private function sidebar(): array
    {
        $dept = $this->container['content']->departmentBySlug(self::SALES_SLUG);
        return [
            'categories' => $this->repo->categoriesWithCounts(),
            'brands'     => $this->repo->brandsWithCounts(),
            'sales'      => [
                'phone' => (string) ($dept['phone'] ?? '') ?: '0722 354 437',
                'email' => (string) ($dept['email'] ?? '') ?: 'showroom@motociclete.com.ro',
            ],
        ];
    }

    /** @param array<string,mixed> $v */
    private function vehicleLd(array $v, ?int $price, string $desc): array
    {
        $url = $this->appUrl . $this->base . $v['url'];
        $ld = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Vehicle',
            'name'        => $v['title'],
            'description' => $desc,
            'url'         => $url,
            'brand'       => ['@type' => 'Brand', 'name' => $v['brand_name']],
            'itemCondition' => 'https://schema.org/UsedCondition',
        ];
        if (!empty($v['images'])) {
            $ld['image'] = array_map(fn (array $i): string => $this->appUrl . $this->base . $i['url'], $v['images']);
        }
        if ($v['year']) {
            $ld['vehicleModelDate'] = (string) $v['year'];
        }
        if ($v['km'] !== null) {
            $ld['mileageFromOdometer'] = ['@type' => 'QuantitativeValue', 'value' => $v['km'], 'unitCode' => 'KMT'];
        }
        if ($v['cc']) {
            $ld['vehicleEngine'] = ['@type' => 'EngineSpecification', 'engineDisplacement' => [
                '@type' => 'QuantitativeValue', 'value' => $v['cc'], 'unitCode' => 'CMQ',
            ]];
        }
        if ($price !== null) {
            $ld['offers'] = [
                '@type'         => 'Offer',
                'url'           => $url,
                'price'         => $price,
                'priceCurrency' => 'EUR',
                'itemCondition' => 'https://schema.org/UsedCondition',
                'availability'  => 'https://schema.org/InStock',
                'seller'        => ['@type' => 'MotorcycleDealer', 'name' => 'Dual Motors'],
            ];
        }
        return $ld;
    }

    /** @param list<array{0:string,1:?string}> $crumbs */
    private function crumbsLd(array $crumbs): array
    {
        $items = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Acasă', 'item' => $this->appUrl . $this->base . '/']];
        foreach ($crumbs as $i => [$name, $path]) {
            $item = ['@type' => 'ListItem', 'position' => $i + 2, 'name' => $name];
            if ($path !== null) {
                $item['item'] = $this->appUrl . $this->base . $path;
            }
            $items[] = $item;
        }
        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /** JSON pentru <script type="application/ld+json">: „/" rămâne escapat, deci „</script>" nu poate apărea. */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
