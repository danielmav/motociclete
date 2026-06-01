<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Site-wide mega-menu definition. Registered as the Twig global `nav` in
 * Bootstrap so the header renders on every page. Links point at the real
 * catalog routes (/{brand}/{cat}); equipment/parts entries remain placeholders
 * for upcoming milestones.
 */
final class Navigation
{
    /** @return array<int,array<string,mixed>> */
    public static function menu(): array
    {
        return [
            [
                'label' => 'Motociclete', 'href' => '/yamaha',
                'mega' => [
                    'columns' => [
                        ['title' => 'Yamaha', 'links' => [
                            ['Motociclete', '/yamaha/motociclete'], ['Scutere', '/yamaha/scutere'],
                            ['ATV', '/yamaha/atvuri'], ['WaveRunners', '/yamaha/waverunners'],
                            ['Marine', '/yamaha/marine'], ['Snowmobile', '/yamaha/snowmobile'],
                        ]],
                        ['title' => 'CFMOTO', 'links' => [
                            ['Naked', '/cfmoto/naked'], ['Sport', '/cfmoto/sport'],
                            ['Touring & Travel', '/cfmoto/touring-travel'], ['Heritage', '/cfmoto/heritage'],
                        ]],
                        ['title' => 'Branduri', 'links' => [
                            ['Toate Yamaha', '/yamaha'], ['Toate CFMOTO', '/cfmoto'],
                        ]],
                    ],
                    'feature' => ['kicker' => 'Dealer autorizat', 'title' => 'Gama Yamaha 2026', 'href' => '/yamaha', 'img' => null],
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
                            ['Fit My Bike', '/#fit'], ['Întreținere & ulei', '/piese/intretinere'],
                            ['Frânare', '/piese/franare'], ['Transmisie', '/piese/transmisie'],
                            ['Anvelope', '/piese/anvelope'], ['Bagaje & suporturi', '/piese/bagaje'],
                        ]],
                        ['title' => 'Catalog', 'links' => [
                            ['Toate piesele', '/piese'], ['Accesorii', '/accesorii'],
                            ['Oferte', '/oferte'],
                        ]],
                    ],
                    'feature' => ['kicker' => 'Catalog BikerShop', 'title' => 'Sute de mii de piese, filtrate pe motocicleta ta', 'href' => '/#fit', 'img' => null],
                ],
            ],
            ['label' => 'Tur virtual', 'href' => '/#tur'],
            ['label' => 'Service', 'href' => '/service'],
            ['label' => 'Blog', 'href' => '/blog'],
        ];
    }
}
