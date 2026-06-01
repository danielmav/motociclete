<?php

declare(strict_types=1);

namespace App\Controllers;

use App\BikerShop\Client;
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

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->bikershop = $container['bikershop'];
    }

    public function index(Request $request, Response $response): Response
    {
        $accessories = $this->bikershop->featuredProducts(8);

        return $this->twig->render($response, 'home.twig', [
            'nav'             => $this->navigation(),
            'hero'            => $this->hero(),
            'brands'          => $this->brands(),
            'models'          => $this->featuredModels(),
            'makes'           => $this->bikershop->makes(),
            'accessories'     => $accessories,
            'accessoriesLive' => $this->bikershop->isAvailable(),
            'tour'            => $this->virtualTour(),
            'articles'        => $this->articles(),
        ]);
    }

    /**
     * Mega-menu navigation. Each entry is either a simple link or carries a
     * `mega` block (link columns + a featured card). Drives header + mobile nav.
     * @return array<int,array<string,mixed>>
     */
    private function navigation(): array
    {
        return [
            [
                'label' => 'Motociclete', 'href' => '/motociclete',
                'mega' => [
                    'columns' => [
                        ['title' => 'După categorie', 'links' => [
                            ['Naked', '/motociclete/naked'], ['Sport', '/motociclete/sport'],
                            ['Touring', '/motociclete/touring'], ['Adventure', '/motociclete/adventure'],
                            ['Cruiser', '/motociclete/cruiser'], ['Scuter', '/motociclete/scuter'],
                            ['ATV', '/motociclete/atv'],
                        ]],
                        ['title' => 'Branduri', 'links' => [
                            ['Yamaha', '/yamaha'], ['CFMOTO', '/cfmoto'],
                        ]],
                        ['title' => 'După permis', 'links' => [
                            ['Categoria A1', '/motociclete/a1'], ['Categoria A2', '/motociclete/a2'],
                            ['Categoria A', '/motociclete/a'], ['În stoc', '/motociclete/stoc'],
                        ]],
                    ],
                    'feature' => ['kicker' => 'Noutăți 2026', 'title' => 'Yamaha YZF-R9', 'href' => '/yamaha/yzf-r9', 'img' => '/assets/img/models/r9-card.webp'],
                ],
            ],
            [
                'label' => 'Echipament', 'href' => '/echipament',
                'mega' => [
                    'columns' => [
                        ['title' => 'Tip echipament', 'links' => [
                            ['Căști', '/echipament/casti'], ['Geci', '/echipament/geci'],
                            ['Mănuși', '/echipament/manusi'], ['Cizme', '/echipament/cizme'],
                            ['Pantaloni', '/echipament/pantaloni'], ['Protecții & airbag', '/echipament/protectii'],
                            ['Anvelope', '/echipament/anvelope'],
                        ]],
                        ['title' => 'Branduri importate', 'links' => [
                            ['Arai', '/arai'], ['AGV', '/agv'], ['Dainese', '/dainese'],
                            ['TCX', '/tcx'], ['MOMO', '/momo'], ['Putoline', '/putoline'], ['Twin Air', '/twin-air'],
                        ]],
                    ],
                    'feature' => ['kicker' => 'Importator oficial', 'title' => 'Căști Arai', 'href' => '/arai', 'img' => null],
                ],
            ],
            [
                'label' => 'Piese & accesorii', 'href' => '/piese',
                'mega' => [
                    'columns' => [
                        ['title' => 'Pe motocicleta ta', 'links' => [
                            ['Fit My Bike', '/piese#fit'], ['Întreținere & ulei', '/piese/intretinere'],
                            ['Frânare', '/piese/franare'], ['Transmisie', '/piese/transmisie'],
                            ['Anvelope', '/piese/anvelope'], ['Bagaje & suporturi', '/piese/bagaje'],
                        ]],
                        ['title' => 'Catalog', 'links' => [
                            ['Toate piesele', '/piese'], ['Accesorii', '/accesorii'],
                            ['Oferte', '/oferte'],
                        ]],
                    ],
                    'feature' => ['kicker' => 'Catalog BikerShop', 'title' => 'Sute de mii de piese, filtrate pe motocicleta ta', 'href' => '/piese#fit', 'img' => null],
                ],
            ],
            ['label' => 'Tur virtual', 'href' => '#tur'],
            ['label' => 'Service', 'href' => '/service'],
            ['label' => 'Blog', 'href' => '/blog'],
        ];
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

    /**
     * SEED data — replaced by db_local (Milestone 2). `img` null => placeholder.
     * @return array<int,array<string,mixed>>
     */
    private function featuredModels(): array
    {
        return [
            ['name' => 'Yamaha MT-09',      'cat' => 'Naked',     'cc' => 890, 'permis' => 'A', 'price' => 11990, 'tag' => 'Nou 2026',   'img' => '/assets/img/models/mt09-card.webp'],
            ['name' => 'Yamaha YZF-R9',     'cat' => 'Sport',     'cc' => 890, 'permis' => 'A', 'price' => 13490, 'tag' => 'Nou 2026',   'img' => '/assets/img/models/r9-card.webp'],
            ['name' => 'Yamaha Ténéré 700', 'cat' => 'Adventure', 'cc' => 689, 'permis' => 'A', 'price' => 12490, 'tag' => 'Best seller','img' => null],
            ['name' => 'CFMOTO 800NK',      'cat' => 'Naked',     'cc' => 799, 'permis' => 'A', 'price' => 8290,  'tag' => 'În stoc',    'img' => null],
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

    /** @return array<int,array<string,string>> */
    private function articles(): array
    {
        return [
            ['cat' => 'Ghid', 'title' => 'Permis A2 vs A: ce poți conduce și cum faci upgrade', 'read' => '6 min'],
            ['cat' => 'Review', 'title' => 'Am testat Yamaha Ténéré 700 pe Transalpina', 'read' => '9 min'],
            ['cat' => 'Echipament', 'title' => 'Cum alegi o cască Arai: forme, mărimi, ECE 22.06', 'read' => '7 min'],
        ];
    }
}
