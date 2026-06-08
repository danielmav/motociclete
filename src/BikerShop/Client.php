<?php

declare(strict_types=1);

namespace App\BikerShop;

use App\Database;
use PDO;
use Throwable;

/**
 * The ONLY place that touches the BikerShop (PrestaShop 9) database.
 * Read-only. Isolates the PrestaShop schema from the rest of the app — if the
 * schema differs from the assumptions below, this is the single file to adjust.
 *
 * Every public method degrades gracefully (returns [] / null) when BikerShop is
 * not configured or unreachable, so the portal never breaks because of it.
 */
final class Client
{
    private ?PDO $pdo;
    private string $prefix;
    private int $langId;
    private int $shopId;
    private string $baseUrl;

    /** @param array<string,mixed> $cfg the 'db.bikershop' settings array */
    public function __construct(Database $db, array $cfg)
    {
        $this->pdo     = $db->bikershop();
        $this->prefix  = $cfg['prefix'] ?? 'ps_';
        $this->langId  = (int) ($cfg['lang_id'] ?? 1);
        $this->shopId  = (int) ($cfg['shop_id'] ?? 1);
        $this->baseUrl = rtrim($cfg['base_url'] ?? 'https://bikershop.ro', '/');
    }

    public function isAvailable(): bool
    {
        return $this->pdo instanceof PDO;
    }

    /**
     * A handful of active products for the homepage teaser.
     * When compatibility mapping lands (Milestone 3) we'll filter by the
     * selected motorcycle; for now this previews live equipment.
     *
     * @return array<int,array<string,mixed>>
     */
    public function featuredProducts(int $limit = 8): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $p = $this->prefix;
        $shop = $this->shopId; // trusted config int, inlined (named params can't repeat)
        $lang = $this->langId;
        $sql = "
            SELECT  pr.id_product,
                    pl.name,
                    pl.link_rewrite,
                    COALESCE(ps.price, pr.price) AS price,
                    m.name AS manufacturer,
                    img.id_image
            FROM        {$p}product       pr
            JOIN        {$p}product_shop  ps  ON ps.id_product = pr.id_product AND ps.id_shop = {$shop}
            JOIN        {$p}product_lang  pl  ON pl.id_product = pr.id_product AND pl.id_lang = {$lang} AND pl.id_shop = {$shop}
            LEFT JOIN   {$p}manufacturer  m   ON m.id_manufacturer = pr.id_manufacturer
            LEFT JOIN   {$p}image         img ON img.id_product = pr.id_product AND img.cover = 1
            WHERE   ps.active = 1
            ORDER BY pr.date_add DESC
            LIMIT   :lim
        ";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }

        return array_map(fn (array $r) => $this->shapeProduct($r), $rows);
    }

    /**
     * Cascading "Fit My Bike" selectors, powered by the LeoPartsFilter module.
     * make -> model -> year. Names come from the *_lang tables.
     * @return array<int,array{id:int,name:string}>
     */
    public function makes(): array
    {
        return $this->options(
            "SELECT m.id_leopartsfilter_make AS id, ml.name
             FROM {$this->prefix}leopartsfilter_make m
             JOIN {$this->prefix}leopartsfilter_make_lang ml
               ON ml.id_leopartsfilter_make = m.id_leopartsfilter_make AND ml.id_lang = :lang
             WHERE m.active = 1
             ORDER BY ml.name",
            []
        );
    }

    /** @return array<int,array{id:int,name:string}> */
    public function models(int $makeId): array
    {
        return $this->options(
            "SELECT DISTINCT mo.id_leopartsfilter_model AS id, mol.name
             FROM {$this->prefix}leopartsfilter_model mo
             JOIN {$this->prefix}leopartsfilter_model_lang mol
               ON mol.id_leopartsfilter_model = mo.id_leopartsfilter_model AND mol.id_lang = :lang
             WHERE mo.active = 1 AND mo.id_leopartsfilter_make = :make
             ORDER BY mol.name",
            [':make' => $makeId]
        );
    }

    /** @return array<int,array{id:int,name:string}> */
    public function years(int $makeId, int $modelId): array
    {
        return $this->options(
            "SELECT DISTINCT y.id_leopartsfilter_year AS id, yl.name
             FROM {$this->prefix}leopartsfilter_year y
             JOIN {$this->prefix}leopartsfilter_year_lang yl
               ON yl.id_leopartsfilter_year = y.id_leopartsfilter_year AND yl.id_lang = :lang
             WHERE y.active = 1 AND y.id_leopartsfilter_make = :make AND y.id_leopartsfilter_model = :model
             ORDER BY yl.name DESC",
            [':make' => $makeId, ':model' => $modelId]
        );
    }

    /**
     * Active products compatible with a given motorcycle (model, optional year).
     * Joins the LeoPartsFilter fitment link table to the catalogue.
     * @return array<int,array<string,mixed>>
     */
    public function compatibleProducts(int $modelId, ?int $yearId, int $limit = 12): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $p = $this->prefix;
        $shop = $this->shopId; // trusted config ints, inlined (named params can't repeat)
        $lang = $this->langId;
        $yearCond = $yearId ? "AND lp.id_leopartsfilter_year = :year" : "";
        $sql = "
            SELECT  pr.id_product, pl.name, pl.link_rewrite,
                    COALESCE(ps.price, pr.price) AS price,
                    m.name AS manufacturer, img.id_image
            FROM        {$p}leopartsfilter_product lp
            JOIN        {$p}product       pr  ON pr.id_product = lp.id_product
            JOIN        {$p}product_shop  ps  ON ps.id_product = pr.id_product AND ps.id_shop = {$shop} AND ps.active = 1
            JOIN        {$p}product_lang  pl  ON pl.id_product = pr.id_product AND pl.id_lang = {$lang} AND pl.id_shop = {$shop}
            LEFT JOIN   {$p}manufacturer  m   ON m.id_manufacturer = pr.id_manufacturer
            LEFT JOIN   {$p}image         img ON img.id_product = pr.id_product AND img.cover = 1
            WHERE   lp.id_leopartsfilter_model = :model {$yearCond}
            GROUP BY pr.id_product
            ORDER BY pr.id_product DESC
            LIMIT :lim
        ";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':model', $modelId, PDO::PARAM_INT);
            if ($yearId) {
                $stmt->bindValue(':year', $yearId, PDO::PARAM_INT);
            }
            $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
            $stmt->execute();
            return array_map(fn (array $r) => $this->shapeProduct($r), $stmt->fetchAll());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Look up BikerShop LeoPartsFilter IDs for a local product.
     * Used by database/migrate_fitment.php to populate lp_* columns.
     *
     * Returns ['make_id', 'model_id', 'year_id', 'ambiguous', 'candidates']
     * or null if BikerShop is unavailable or nothing matches.
     *
     * @return array{make_id:int,model_id:int,year_id:int|null,ambiguous:bool,candidates:list<string>}|null
     */
    public function lookupFitment(string $brand, string $modelName, int $year): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $p = $this->prefix;
        // Normalize brand to expected display name
        $brandDisplay = self::BRAND_DISPLAY[$brand] ?? ucfirst($brand);
        try {
            // 1. Find make_id by brand display name
            $stmt = $this->pdo->prepare(
                "SELECT m.id_leopartsfilter_make AS id
                 FROM {$p}leopartsfilter_make m
                 JOIN {$p}leopartsfilter_make_lang ml
                   ON ml.id_leopartsfilter_make = m.id_leopartsfilter_make AND ml.id_lang = :lang
                 WHERE m.active = 1 AND ml.name LIKE :brand
                 LIMIT 1"
            );
            $stmt->bindValue(':lang', $this->langId, PDO::PARAM_INT);
            $stmt->bindValue(':brand', '%' . $brandDisplay . '%');
            $stmt->execute();
            $makeRow = $stmt->fetch();
            if (!$makeRow) {
                return null;
            }
            $makeId = (int) $makeRow['id'];

            // 2. Find model_id — try full name, then strip trailing year/variant suffix
            $modelId   = null;
            $ambiguous = false;
            $candidates = [];
            foreach ($this->modelCandidates($modelName) as $pattern) {
                $stmt = $this->pdo->prepare(
                    "SELECT mo.id_leopartsfilter_model AS id, mol.name
                     FROM {$p}leopartsfilter_model mo
                     JOIN {$p}leopartsfilter_model_lang mol
                       ON mol.id_leopartsfilter_model = mo.id_leopartsfilter_model AND mol.id_lang = :lang
                     WHERE mo.active = 1 AND mo.id_leopartsfilter_make = :make
                       AND mol.name LIKE :model
                     ORDER BY mol.name
                     LIMIT 5"
                );
                $stmt->bindValue(':lang', $this->langId, PDO::PARAM_INT);
                $stmt->bindValue(':make', $makeId, PDO::PARAM_INT);
                $stmt->bindValue(':model', '%' . $pattern . '%');
                $stmt->execute();
                $rows = $stmt->fetchAll();
                if ($rows) {
                    $modelId    = (int) $rows[0]['id'];
                    $ambiguous  = count($rows) > 1;
                    $candidates = array_column($rows, 'name');
                    break;
                }
            }
            if ($modelId === null) {
                return null;
            }

            // 3. Find year_id by year string within this model
            $stmt = $this->pdo->prepare(
                "SELECT y.id_leopartsfilter_year AS id
                 FROM {$p}leopartsfilter_year y
                 JOIN {$p}leopartsfilter_year_lang yl
                   ON yl.id_leopartsfilter_year = y.id_leopartsfilter_year AND yl.id_lang = :lang
                 WHERE y.active = 1 AND y.id_leopartsfilter_make = :make AND y.id_leopartsfilter_model = :model
                   AND yl.name = :year
                 LIMIT 1"
            );
            $stmt->bindValue(':lang', $this->langId, PDO::PARAM_INT);
            $stmt->bindValue(':make', $makeId, PDO::PARAM_INT);
            $stmt->bindValue(':model', $modelId, PDO::PARAM_INT);
            $stmt->bindValue(':year', (string) $year);
            $stmt->execute();
            $yearRow = $stmt->fetch();

            return [
                'make_id'    => $makeId,
                'model_id'   => $modelId,
                'year_id'    => $yearRow ? (int) $yearRow['id'] : null,
                'ambiguous'  => $ambiguous,
                'candidates' => $candidates,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** Brand display names for lookupFitment(). BikerShop uses "CF MOTO" (with space). */
    private const BRAND_DISPLAY = ['yamaha' => 'Yamaha', 'cfmoto' => 'CF MOTO'];

    /**
     * Progressively shorter LIKE patterns for model name matching.
     * "MT-09 Y AMT 2025" -> ["MT-09 Y AMT 2025", "MT-09 Y AMT", "MT-09 Y", "MT-09"]
     * @return list<string>
     */
    private function modelCandidates(string $name): array
    {
        // Strip trailing 4-digit year first
        $stripped = trim(preg_replace('/\s+\d{4}$/', '', $name) ?? $name);
        $parts = preg_split('/\s+/', $stripped) ?: [$stripped];
        $candidates = [];
        // From most specific (full) to least specific (first word only)
        for ($i = count($parts); $i >= 1; $i--) {
            $candidates[] = implode(' ', array_slice($parts, 0, $i));
        }
        return array_unique($candidates);
    }

    /**
     * Run a *_lang options query (binds :lang). Returns id/name option rows.
     * @param array<string,int> $params
     * @return array<int,array{id:int,name:string}>
     */
    private function options(string $sql, array $params): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':lang', $this->langId, PDO::PARAM_INT);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v, PDO::PARAM_INT);
            }
            $stmt->execute();
            return array_map(
                fn (array $r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
                $stmt->fetchAll()
            );
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function shapeProduct(array $r): array
    {
        return [
            'id'           => (int) $r['id_product'],
            'name'         => (string) $r['name'],
            'manufacturer' => $r['manufacturer'] ?? '',
            'price'        => round(((float) $r['price']) * 1.19), // approx. gross (VAT)
            'image'        => $this->imageUrl($r['id_image'] ?? null, $r['link_rewrite']),
            'url'          => $this->productUrl((int) $r['id_product'], (string) $r['link_rewrite']),
        ];
    }

    /** PrestaShop friendly image URL: /{id_image}-large_default/{slug}.jpg */
    public function imageUrl(?int $idImage, string $slug, string $type = 'large_default'): ?string
    {
        if (!$idImage) {
            return null;
        }
        return sprintf('%s/%d-%s/%s.jpg', $this->baseUrl, $idImage, $type, $slug);
    }

    /** PrestaShop friendly product URL: /{id_product}-{slug}.html */
    public function productUrl(int $idProduct, string $slug): string
    {
        return sprintf('%s/%d-%s.html', $this->baseUrl, $idProduct, $slug);
    }
}
