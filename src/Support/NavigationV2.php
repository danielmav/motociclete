<?php

declare(strict_types=1);

namespace App\Support;

use App\Catalog\Repository as Catalog;

/**
 * Live, type-first site-wide mega-menu (redesign).
 *
 * Unlike the brand-first {@see Navigation} used site-wide, this menu groups the
 * full Yamaha + CFMOTO range by *product type* (Motociclete / Scutere / ATV /
 * Marine), with a brand filter inside each panel. Sidebar subcategories and the
 * model cards are pulled live from the local catalog ({@see Catalog}); the menu
 * degrades gracefully (empty groups are dropped) if the DB is unreachable.
 *
 * Only used by the v2 page — built per-request in HomeController and passed as a
 * template variable, so non-v2 pages never pay for these queries.
 */
final class NavigationV2
{
    /** Max model cards rendered eagerly per subcategory pane. */
    private const CARDS_PER_PANE = 16;

    /**
     * Product-type panels. Each source describes where a sidebar group comes from:
     *   mode 'subcats' → use the subcategories of {top} as sidebar items;
     *   mode 'self'    → use each of {tops} (flat top categories, e.g. CFMOTO) as a sidebar item.
     */
    private const PANELS = [
        'motociclete' => [
            'label' => 'Motociclete',
            'sources' => [
                ['brand' => 'yamaha', 'top' => 'motociclete', 'group' => 'Yamaha', 'mode' => 'subcats'],
                ['brand' => 'cfmoto', 'tops' => ['naked', 'sport', 'touring-travel', 'heritage'], 'group' => 'CFMOTO', 'mode' => 'self'],
            ],
        ],
        'scutere' => [
            'label' => 'Scutere',
            'sources' => [
                ['brand' => 'yamaha', 'top' => 'scutere', 'group' => 'Yamaha', 'mode' => 'subcats'],
            ],
        ],
        'atv' => [
            'label' => 'ATV / SSV',
            'sources' => [
                ['brand' => 'yamaha', 'top' => 'atvuri', 'group' => 'Yamaha', 'mode' => 'subcats'],
            ],
        ],
        'marine' => [
            'label' => 'Marine',
            'sources' => [
                ['brand' => 'yamaha', 'top' => 'waverunners', 'group' => 'WaveRunners', 'mode' => 'subcats'],
                ['brand' => 'yamaha', 'top' => 'marine', 'group' => 'Marine', 'mode' => 'subcats'],
            ],
        ],
    ];

    /** Slug → emoji icon for sidebar polish (fallback: empty). */
    private const ICONS = [
        'supersport' => '🏁', 'hyper-naked' => '⚡', 'sport-heritage' => '🕰', 'sport-touring' => '🗺',
        'adventure' => '🏔', 'competitie' => '🏆', 'naked' => '⚡', 'sport' => '🏁',
        'touring-travel' => '🗺', 'heritage' => '🕰', 'urban-mobility' => '🛵', 'masini-de-golf' => '⛳',
        'utilitare' => '🚜', 'recreational' => '🏞', 'cruising' => '🌊', 'recreation' => '🏖',
        'barci' => '⛵', 'motoare-barca-high-power' => '⚙', 'motoare-barca-mid-power' => '⚙',
        'motoare-barca-portabile' => '⚙',
    ];

    /**
     * Build once and reuse from a file cache. The menu is on every page now, so
     * we avoid the ~20 catalog queries per request. Cached prices are raw EUR;
     * the `prices()` Twig function applies the live currency at render time, so a
     * currency change is reflected immediately. Catalog edits show after `$ttl`
     * (or delete the cache file to bust it).
     *
     * @return array<string,mixed>
     */
    public static function cached(Catalog $catalog, string $cacheDir, int $ttl = 600): array
    {
        $file = rtrim($cacheDir, '/\\') . '/navv2.cache';
        if (is_file($file) && (time() - filemtime($file)) < $ttl) {
            $data = @unserialize((string) file_get_contents($file));
            if (is_array($data) && $data !== []) {
                return $data;
            }
        }
        $data = self::build($catalog);
        if (is_dir($cacheDir) || @mkdir($cacheDir, 0775, true)) {
            @file_put_contents($file, serialize($data), LOCK_EX);
        }
        return $data;
    }

    /**
     * Build the full v2 navigation: live product panels + accessories mega + links.
     * @return array<string,mixed>
     */
    public static function build(Catalog $catalog): array
    {
        $items = [];

        foreach (self::PANELS as $key => $def) {
            $panel = self::buildPanel($catalog, $key, $def);
            if ($panel !== null) {
                $items[] = $panel;
            }
        }

        $items[] = self::accessories();
        $items[] = ['type' => 'link', 'label' => 'Service', 'href' => '/service'];
        $items[] = ['type' => 'link', 'label' => 'Blog', 'href' => '/blog'];
        $items[] = ['type' => 'link', 'label' => 'Despre noi', 'href' => '/despre_dual_motors'];

        return ['items' => $items];
    }

    /**
     * One product-type panel: brand pills + sidebar groups (with live cards).
     * Returns null if no group has any subcategory (DB down / empty range).
     * @param array<string,mixed> $def
     * @return array<string,mixed>|null
     */
    private static function buildPanel(Catalog $catalog, string $key, array $def): ?array
    {
        $groups = [];
        $brands = [];

        foreach ($def['sources'] as $src) {
            $subcats = $src['mode'] === 'self'
                ? self::selfGroups($catalog, $src)
                : self::subcatGroup($catalog, $src);

            if ($subcats === []) {
                continue;
            }
            $groups[] = ['brand' => $src['brand'], 'label' => $src['group'], 'subcats' => $subcats];
            $brands[$src['brand']] = true;
        }

        if ($groups === []) {
            return null;
        }

        // Brand pills only make sense when the panel spans more than one brand.
        $pills = count($brands) > 1 ? array_merge(['all'], array_keys($brands)) : [];

        // "Toată gama …" points to the top-category landing page of the first source
        // (e.g. /yamaha/motociclete), NOT its first subcategory.
        $src0 = $def['sources'][0];
        $href = '/' . $src0['brand'] . '/' . ($src0['top'] ?? $src0['tops'][0]);

        return [
            'type'   => 'products',
            'key'    => $key,
            'label'  => $def['label'],
            'href'   => $href,
            'pills'  => $pills,
            'groups' => $groups,
        ];
    }

    /**
     * Sidebar items from the subcategories of a top category (Yamaha style).
     * @param array<string,mixed> $src
     * @return array<int,array<string,mixed>>
     */
    private static function subcatGroup(Catalog $catalog, array $src): array
    {
        $top = $catalog->topCategory($src['brand'], $src['top']);
        if (!$top) {
            return [];
        }
        $out = [];
        foreach ($catalog->subcategories((int) $top['id']) as $sub) {
            if ((int) $sub['product_count'] === 0) {
                continue;
            }
            $cat = ['id' => (int) $sub['id'], 'parent_id' => (int) $top['id']];
            $out[] = self::sidebarItem(
                $catalog,
                $src['brand'],
                $sub['name'],
                $sub['slug'],
                '/' . $src['brand'] . '/' . $top['slug'] . '/' . $sub['slug'],
                (int) $sub['product_count'],
                $cat
            );
        }
        return $out;
    }

    /**
     * Sidebar items where each flat top category is itself an item (CFMOTO style).
     * @param array<string,mixed> $src
     * @return array<int,array<string,mixed>>
     */
    private static function selfGroups(Catalog $catalog, array $src): array
    {
        $out = [];
        foreach ($src['tops'] as $slug) {
            $top = $catalog->topCategory($src['brand'], $slug);
            if (!$top) {
                continue;
            }
            // parent_id is null → productsInCategory treats it as a top category.
            $cat = ['id' => (int) $top['id'], 'parent_id' => $top['parent_id']];
            $count = count($catalog->productsInCategory($cat));
            if ($count === 0) {
                continue;
            }
            $out[] = self::sidebarItem(
                $catalog,
                $src['brand'],
                $top['name'],
                $top['slug'],
                '/' . $src['brand'] . '/' . $top['slug'],
                $count,
                $cat
            );
        }
        return $out;
    }

    /**
     * Shape one sidebar entry with its live model cards (capped).
     * @param array<string,mixed> $cat category row for productsInCategory()
     * @return array<string,mixed>
     */
    private static function sidebarItem(
        Catalog $catalog,
        string $brand,
        string $name,
        string $slug,
        string $url,
        int $count,
        array $cat
    ): array {
        $cards = array_slice($catalog->productsInCategory($cat), 0, self::CARDS_PER_PANE);
        return [
            'id'       => 'v2-' . $brand . '-' . $slug,
            'brand'    => $brand,
            'name'     => $name,
            'slug'     => $slug,
            'url'      => $url,
            'count'    => $count,
            'icon'     => self::ICONS[$slug] ?? '',
            'products' => $cards,
        ];
    }

    /**
     * Accessories mega: original (OEM) vs aftermarket, plus Fit My Bike + catalog.
     * @return array<string,mixed>
     */
    private static function accessories(): array
    {
        return [
            'type'  => 'mega',
            'key'   => 'accesorii',
            'label' => 'Accesorii',
            'href'  => '/accesorii',
            'columns' => [
                ['title' => 'Accesorii originale', 'links' => [
                    ['Toate accesoriile', '/accesorii'],
                    ['Accesorii Yamaha', '/accesorii'],
                    ['Caută după model', '/accesorii'],
                ]],
            ],
            'feature' => [
                'kicker' => 'Fit My Bike',
                'title'  => 'Spune-ne ce motocicletă ai — îți arătăm doar ce se potrivește.',
                'href'   => '/#fit',
            ],
        ];
    }
}
