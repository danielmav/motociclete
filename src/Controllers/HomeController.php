<?php

declare(strict_types=1);

namespace App\Controllers;

use App\BikerShop\Client;
use App\Catalog\Repository as Catalog;
use App\News\Repository as News;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Homepage. Milestone 1 = visual identity. Catalogue data is seed/placeholder
 * (clearly marked) until the local database + admin land in Milestone 2-3.
 * The "compatible accessories" strip pulls live from BikerShop when configured,
 * and falls back to a tasteful placeholder when it is not.
 */
final class HomeController
{
    private Client $bikershop;
    private Catalog $catalog;
    private News $news;

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->bikershop = $container['bikershop'];
        $this->catalog   = $container['catalog'];
        $this->news      = $container['news'];
    }

    public function index(Request $request, Response $response): Response
    {
        $accessories = $this->bikershop->featuredProducts(6);

        return $this->twig->render($response, 'home.twig', [
            'hero'            => $this->hero(),
            'brands'          => $this->brands(),
            'models'          => $this->catalog->randomModels(4),
            'makes'           => $this->bikershop->makes(),
            'accessories'     => $accessories,
            'accessoriesLive' => $this->bikershop->isAvailable(),
            'tour'            => $this->virtualTour(),
            'articles'        => $this->news->latest(3),
        ]);
    }

    /** @return array<string,mixed> */
    private function hero(): array
    {
        return [
            'kicker'   => '23 de ani pe două roți',
            'title'    => "Motocicleta ta\ncâștigă teren.",
            'subtitle' => 'Dealer autorizat Yamaha și CFMOTO. Showroom Pipera, București — plus tot echipamentul și piesele potrivite pentru ea, din BikerShop.',
            'image'    => '/assets/img/models/mt09-hero.webp',
            'imageAlt' => 'Yamaha MT-09 în mișcare',
            'ctaPrimary'   => ['label' => 'Vezi modelele 2026', 'href' => '/motociclete'],
            'ctaSecondary' => ['label' => 'Programează test ride', 'href' => 'http://drivetest.test'],
        ];
    }

    /**
     * Brand partners, split by relationship as specified by Dual Motors.
     * @return array<string,array<int,string>>
     */
    private function brands(): array
    {
        return [
            'Dealer autorizat'   => ['Yamaha', 'CFMOTO'],
            'Importator oficial' => ['Arai', 'Putoline', 'Dainese', 'AGV', 'TCX', 'MOMO', 'Twin Air'],
        ];
    }

    /** @return array<string,string> */
    private function virtualTour(): array
    {
        return [
            'url'   => 'https://www.3dpano.ro/tur-virtual/dualmotors/',
            'image' => '/assets/img/showroom/showroom-1.webp',
        ];
    }

}
