<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Database;
use PDO;
use Throwable;

/**
 * The single place that reads the local catalog DB (`motociclete`).
 * Mirrors the discipline of BikerShop\Client: prepared statements, graceful
 * degradation (returns []/null) if the DB is unreachable.
 *
 * URL scheme (brand-first, clean):
 *   category (top) : /{brand}/{cat}
 *   subcategory    : /{brand}/{cat}/{sub}
 *   product (sub)  : /{brand}/{cat}/{sub}/{slug}     (Yamaha — 2-level cats)
 *   product (top)  : /{brand}/{cat}/{slug}           (CFMOTO — flat cats)
 */
final class Repository
{
    private ?PDO $pdo;

    /** image type -> media subfolder (mirrors server layout; 'cover' is its own folder) */
    private const FOLDER = ['cover' => 'cover', 'color' => 'culori', 'gallery' => 'motociclete', 'detail' => 'detalii'];

    /** SELECT list that resolves a product's category breadcrumb in one go. */
    private const PROD_JOIN = "
        FROM products p
        JOIN categories c ON c.id = p.category_id
        LEFT JOIN categories t ON t.id = c.parent_id";

    public function __construct(Database $db)
    {
        try {
            $this->pdo = $db->local();
        } catch (Throwable) {
            $this->pdo = null;
        }
    }

    public function isAvailable(): bool
    {
        return $this->pdo instanceof PDO;
    }

    // -- URL / image helpers --------------------------------------------------

    /** Web-relative image path (templates prepend {{ base }}). Null if no file. */
    public static function imagePath(string $brand, string $type, ?string $filename): ?string
    {
        if (!$filename) {
            return null;
        }
        $folder = self::FOLDER[$type] ?? $type;
        return '/media/' . $brand . '/' . $folder . '/' . rawurlencode($filename);
    }

    /** Canonical product URL from a shaped row (has brand/top_slug/sub_slug/slug). */
    public static function productUrl(array $p): string
    {
        $segs = $p['sub_slug']
            ? [$p['brand'], $p['top_slug'], $p['sub_slug'], $p['slug']]
            : [$p['brand'], $p['top_slug'], $p['slug']];
        return '/' . implode('/', $segs);
    }

    // -- Categories -----------------------------------------------------------

    /** @return array<int,array<string,mixed>> top-level categories + product counts */
    public function topCategories(string $brand): array
    {
        return $this->all(
            "SELECT c.id, c.name, c.slug, c.description,
                    (SELECT COUNT(*) FROM products p
                       JOIN categories cc ON cc.id = p.category_id
                      WHERE cc.id = c.id OR cc.parent_id = c.id) AS product_count
             FROM categories c
             WHERE c.brand = :brand AND c.parent_id IS NULL
             ORDER BY c.position, c.name",
            [':brand' => $brand]
        );
    }

    /** @return array<int,array<string,mixed>> children of a top category */
    public function subcategories(int $parentId): array
    {
        return $this->all(
            "SELECT c.id, c.name, c.slug, c.description,
                    (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
             FROM categories c
             WHERE c.parent_id = :pid
             ORDER BY c.position, c.name",
            [':pid' => $parentId]
        );
    }

    /** A top-level category row by slug, or null. */
    public function topCategory(string $brand, string $slug): ?array
    {
        return $this->one(
            "SELECT * FROM categories WHERE brand = :brand AND parent_id IS NULL AND slug = :slug",
            [':brand' => $brand, ':slug' => $slug]
        );
    }

    /** A subcategory row by slug within a parent, or null. */
    public function subCategory(int $parentId, string $slug): ?array
    {
        return $this->one(
            "SELECT * FROM categories WHERE parent_id = :pid AND slug = :slug",
            [':pid' => $parentId, ':slug' => $slug]
        );
    }

    // -- Products -------------------------------------------------------------

    /**
     * Shaped product cards for a category. For a top category, includes products
     * of all its subcategories; for a subcategory, just that one.
     * @return array<int,array<string,mixed>>
     */
    public function productsInCategory(array $category): array
    {
        $isTop = $category['parent_id'] === null;
        $where = $isTop
            ? "(c.id = :cid OR c.parent_id = :cid2)"
            : "c.id = :cid";
        $params = $isTop ? [':cid' => $category['id'], ':cid2' => $category['id']] : [':cid' => $category['id']];

        $rows = $this->all(
            "SELECT p.id, p.brand, p.name, p.subtitle, p.slug, p.year, p.price, p.discount_pct,
                    p.licence, p.cover_image, c.slug AS cat_slug, c.parent_id AS cat_parent, t.slug AS top_slug
             " . self::PROD_JOIN . "
             WHERE {$where}
             ORDER BY p.position, p.year DESC, p.name",
            $params
        );
        return array_map([$this, 'shapeCard'], $rows);
    }

    /** Full product detail by (brand, slug), shaped with breadcrumb + url. Null if missing. */
    public function product(string $brand, string $slug): ?array
    {
        $row = $this->one(
            "SELECT p.*, c.name AS cat_name, c.slug AS cat_slug, c.parent_id AS cat_parent,
                    t.name AS top_name, t.slug AS top_slug
             " . self::PROD_JOIN . "
             WHERE p.brand = :brand AND p.slug = :slug",
            [':brand' => $brand, ':slug' => $slug]
        );
        if (!$row) {
            return null;
        }
        return $this->shapeProduct($row);
    }

    /** Related products (same category, excluding self). @return array<int,array<string,mixed>> */
    public function related(array $product, int $limit = 4): array
    {
        if (!$product['category_id']) {
            return [];
        }
        $rows = $this->all(
            "SELECT p.id, p.brand, p.name, p.subtitle, p.slug, p.year, p.price, p.discount_pct,
                    p.licence, p.cover_image, c.slug AS cat_slug, c.parent_id AS cat_parent, t.slug AS top_slug
             " . self::PROD_JOIN . "
             WHERE p.category_id = :cid AND p.id <> :id
             ORDER BY p.position, p.year DESC
             LIMIT {$limit}",
            [':cid' => $product['category_id'], ':id' => $product['id']]
        );
        return array_map([$this, 'shapeCard'], $rows);
    }

    /** Product images of a type, as web-relative URLs + alt. @return array<int,array<string,string>> */
    public function images(string $brand, int $productId, string $type): array
    {
        $rows = $this->all(
            "SELECT filename FROM product_images WHERE product_id = :pid AND type = :type ORDER BY position, id",
            [':pid' => $productId, ':type' => $type]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['src' => self::imagePath($brand, $type, $r['filename'])];
        }
        return $out;
    }

    /** For legacy 301 redirects: canonical URL for an old `*.html` path, or null. */
    public function canonicalForLegacy(string $legacyUrl): ?string
    {
        $row = $this->one(
            "SELECT p.brand, p.slug, c.slug AS cat_slug, c.parent_id AS cat_parent, t.slug AS top_slug
             " . self::PROD_JOIN . "
             WHERE p.legacy_url = :u",
            [':u' => $legacyUrl]
        );
        if (!$row) {
            return null;
        }
        return self::productUrl($this->breadcrumbSlugs($row));
    }

    // -- Shaping --------------------------------------------------------------

    /** Normalize top/sub slug + url onto a row that has cat_slug/cat_parent/top_slug. */
    private function breadcrumbSlugs(array $r): array
    {
        // Product on a sub-category: top = parent, sub = own category.
        // Product on a top category (CFMOTO flat): top = own category, no sub.
        if ($r['cat_parent'] !== null) {
            $r['top_slug'] = $r['top_slug'];
            $r['sub_slug'] = $r['cat_slug'];
        } else {
            $r['top_slug'] = $r['cat_slug'];
            $r['sub_slug'] = null;
        }
        return $r;
    }

    /** @return array<string,mixed> compact card for grids */
    private function shapeCard(array $r): array
    {
        $r = $this->breadcrumbSlugs($r);
        return [
            'name'         => $r['name'],
            'subtitle'     => $r['subtitle'],
            'year'         => (int) $r['year'],
            'price'        => (int) $r['price'],
            'old_price'    => $this->oldPrice($r),
            'discount'     => (int) round((float) $r['discount_pct']),
            'licence'      => $r['licence'],
            'cat'          => ucfirst((string) ($r['sub_slug'] ?: $r['top_slug'])),
            'image'        => self::imagePath($r['brand'], 'cover', $r['cover_image']),
            'url'          => self::productUrl($r),
        ];
    }

    /** @return array<string,mixed> full product for the detail page */
    private function shapeProduct(array $r): array
    {
        $r = $this->breadcrumbSlugs($r);
        $r['url']       = self::productUrl($r);
        $r['cover']     = self::imagePath($r['brand'], 'cover', $r['cover_image']);
        $r['old_price'] = $this->oldPrice($r);
        $r['price']     = (int) $r['price'];
        $r['year']      = (int) $r['year'];
        return $r;
    }

    /** Pre-discount price if a discount exists, else null. */
    private function oldPrice(array $r): ?int
    {
        $pct = (float) $r['discount_pct'];
        return $pct > 0 ? (int) round((int) $r['price'] / (1 - $pct / 100)) : null;
    }

    // -- Tiny query helpers ---------------------------------------------------

    /** @param array<string,mixed> $params @return array<int,array<string,mixed>> */
    private function all(string $sql, array $params = []): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $params @return array<string,mixed>|null */
    private function one(string $sql, array $params = []): ?array
    {
        $rows = $this->all($sql, $params);
        return $rows[0] ?? null;
    }
}
