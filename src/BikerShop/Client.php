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
    public function featuredProducts(int $limit = 6): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $p = $this->prefix;
        $shop = $this->shopId; // trusted config int, inlined (named params can't repeat)
        $lang = $this->langId;
        $limit = max(1, $limit);
        // Random teaser: pick distinct products at several random id anchors and
        // scan forward to the first match. Avoids ORDER BY RAND() over 300k+ rows
        // (≈1s) and the clustering of a single-window scan, while staying fast
        // (PK range seek + LIMIT 1). INNER JOIN on the cover image so every card
        // shows a photo.
        $sql = "
            SELECT  pr.id_product,
                    pl.name,
                    pl.link_rewrite,
                    COALESCE(ps.price, pr.price) AS price,
                    (SELECT t.rate FROM {$p}tax_rule trl JOIN {$p}tax t ON t.id_tax = trl.id_tax
                      WHERE trl.id_tax_rules_group = pr.id_tax_rules_group AND t.active = 1 LIMIT 1) AS tax_rate,
                    m.name AS manufacturer,
                    img.id_image
            FROM        {$p}product       pr
            JOIN        {$p}product_shop  ps  ON ps.id_product = pr.id_product AND ps.id_shop = {$shop}
            JOIN        {$p}product_lang  pl  ON pl.id_product = pr.id_product AND pl.id_lang = {$lang} AND pl.id_shop = {$shop}
            JOIN        {$p}image         img ON img.id_product = pr.id_product AND img.cover = 1
            LEFT JOIN   {$p}manufacturer  m   ON m.id_manufacturer = pr.id_manufacturer
            WHERE   ps.active = 1 AND pr.id_product >= :anchor
            ORDER BY pr.id_product
            LIMIT   1
        ";

        try {
            $max = (int) $this->pdo->query("SELECT MAX(id_product) FROM {$p}product")->fetchColumn();
            $stmt = $this->pdo->prepare($sql);
            $rows = [];
            $seen = [];
            // A few extra tries to cover the (rare) anchor that lands past the
            // last matching product or hits an already-picked id.
            for ($i = 0, $tries = 0; count($rows) < $limit && $tries < $limit * 5; $tries++) {
                $stmt->execute([':anchor' => random_int(1, max(1, $max - 50))]);
                $r = $stmt->fetch();
                if ($r && empty($seen[$r['id_product']])) {
                    $seen[$r['id_product']] = true;
                    $rows[] = $r;
                    $i++;
                }
            }
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
        // Toate mărcile active; Yamaha & CF MOTO (mărcile reprezentate) sus.
        return $this->options(
            "SELECT m.id_leopartsfilter_make AS id, ml.name
             FROM {$this->prefix}leopartsfilter_make m
             JOIN {$this->prefix}leopartsfilter_make_lang ml
               ON ml.id_leopartsfilter_make = m.id_leopartsfilter_make AND ml.id_lang = :lang
             WHERE m.active = 1
             ORDER BY (ml.name IN ('YAMAHA', 'CF MOTO')) DESC,
                      FIELD(ml.name, 'YAMAHA', 'CF MOTO'),
                      ml.name",
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
                    (SELECT t.rate FROM {$p}tax_rule trl JOIN {$p}tax t ON t.id_tax = trl.id_tax
                      WHERE trl.id_tax_rules_group = pr.id_tax_rules_group AND t.active = 1 LIMIT 1) AS tax_rate,
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
     * Search active, search-visible products by name. Every word must match (AND),
     * so "ulei Putoline" finds "Ulei de furca Putoline …". LIKE scan over
     * product_lang (~334k rows, ≈1.2s) — fine for a page submit; the storefront
     * search index (ps_search_*) is not populated. @return array<int,array<string,mixed>>
     */
    public function searchProducts(string $query, int $limit = 24): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $parts = preg_split('/\s+/', trim($query)) ?: [];
        $words = array_values(array_filter($parts, static fn ($w) => mb_strlen($w) >= 2));
        if (!$words) {
            return [];
        }
        $p = $this->prefix;
        $shop = $this->shopId; // trusted config ints, inlined (named params can't repeat)
        $lang = $this->langId;
        $conds = [];
        $params = [];
        foreach ($words as $w) {
            $conds[] = "pl.name LIKE ?";
            $params[] = '%' . $w . '%';
        }
        $params[] = $query . '%';              // relevance: whole query as a name prefix
        $params[] = max(1, $limit);            // LIMIT
        $sql = "
            SELECT  pr.id_product, pl.name, pl.link_rewrite,
                    COALESCE(ps.price, pr.price) AS price,
                    (SELECT t.rate FROM {$p}tax_rule trl JOIN {$p}tax t ON t.id_tax = trl.id_tax
                      WHERE trl.id_tax_rules_group = pr.id_tax_rules_group AND t.active = 1 LIMIT 1) AS tax_rate,
                    m.name AS manufacturer, img.id_image
            FROM        {$p}product_lang  pl
            JOIN        {$p}product_shop  ps  ON ps.id_product = pl.id_product AND ps.id_shop = {$shop}
                         AND ps.active = 1 AND ps.visibility IN ('both','search')
            JOIN        {$p}product       pr  ON pr.id_product = pl.id_product
            LEFT JOIN   {$p}manufacturer  m   ON m.id_manufacturer = pr.id_manufacturer
            LEFT JOIN   {$p}image         img ON img.id_product = pr.id_product AND img.cover = 1
            WHERE   pl.id_lang = {$lang} AND pl.id_shop = {$shop} AND " . implode(' AND ', $conds) . "
            ORDER BY (pl.name LIKE ?) DESC, pl.name
            LIMIT ?
        ";
        try {
            $stmt = $this->pdo->prepare($sql);
            $i = 1;
            foreach ($params as $v) {
                $stmt->bindValue($i++, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
            return array_map(fn (array $r) => $this->shapeProduct($r), $stmt->fetchAll());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Active products by their BikerShop ids, in the given order. Used to render
     * the OEM parts strip: ids come precomputed from the local `oem_product_map`
     * (see database/migrate_oem_fitment.php), details are fetched live here on
     * the primary key (fast). @return array<int,array<string,mixed>>
     */
    public function productsByIds(array $ids, int $limit = 12): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !$this->isAvailable()) {
            return [];
        }
        $ids = array_slice($ids, 0, $limit);
        $p = $this->prefix;
        $shop = $this->shopId; // trusted config ints, inlined (named params can't repeat)
        $lang = $this->langId;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "
            SELECT  pr.id_product, pl.name, pl.link_rewrite,
                    COALESCE(ps.price, pr.price) AS price,
                    (SELECT t.rate FROM {$p}tax_rule trl JOIN {$p}tax t ON t.id_tax = trl.id_tax
                      WHERE trl.id_tax_rules_group = pr.id_tax_rules_group AND t.active = 1 LIMIT 1) AS tax_rate,
                    m.name AS manufacturer, img.id_image
            FROM        {$p}product       pr
            JOIN        {$p}product_shop  ps  ON ps.id_product = pr.id_product AND ps.id_shop = {$shop} AND ps.active = 1
            JOIN        {$p}product_lang  pl  ON pl.id_product = pr.id_product AND pl.id_lang = {$lang} AND pl.id_shop = {$shop}
            LEFT JOIN   {$p}manufacturer  m   ON m.id_manufacturer = pr.id_manufacturer
            LEFT JOIN   {$p}image         img ON img.id_product = pr.id_product AND img.cover = 1
            WHERE   pr.id_product IN ({$in})
        ";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($ids);
            $shaped = array_map(fn (array $r) => $this->shapeProduct($r), $stmt->fetchAll());
        } catch (Throwable) {
            return [];
        }
        // Preserve the precomputed order (position) the ids were passed in.
        $order = array_flip($ids);
        usort($shaped, fn ($a, $b) => ($order[$a['id']] ?? PHP_INT_MAX) <=> ($order[$b['id']] ?? PHP_INT_MAX));
        return $shaped;
    }

    /**
     * Related product ids for a BikerShop motorcycle product, exactly as the
     * advrider_related module shows them on the storefront: merged from the
     * partseurope + manual + rvx caches (source order), deduped, only active &
     * visible products. Split into OEM/aftermarket by manufacturer downstream.
     * @return array<int,int>
     */
    public function relatedBikeProductIds(int $bsProductId, int $cap = 200): array
    {
        if ($bsProductId < 1 || !$this->isAvailable()) {
            return [];
        }
        $p = $this->prefix;
        $shop = $this->shopId; // trusted config int, inlined
        // Same order as the module's default ADVRIDER_RELATED_SOURCE_ORDER.
        $tables = [
            'advrider_related_partseurope_cache',
            'advrider_related_manual_cache',
            'advrider_related_rvx_cache',
        ];
        $seen = [];
        $out = [];
        foreach ($tables as $t) {
            $sql = "SELECT c.id_related_product AS rid
                    FROM {$p}{$t} c
                    JOIN {$p}product_shop ps ON ps.id_product = c.id_related_product
                         AND ps.id_shop = {$shop} AND ps.active = 1
                         AND ps.visibility IN ('both','catalog','search')
                    WHERE c.id_product = :id
                    ORDER BY c.position, c.id_related_product";
            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([':id' => $bsProductId]);
                foreach ($stmt->fetchAll() as $r) {
                    $rid = (int) $r['rid'];
                    if ($rid > 0 && empty($seen[$rid])) {
                        $seen[$rid] = true;
                        $out[] = $rid;
                        if (count($out) >= $cap) {
                            return $out;
                        }
                    }
                }
            } catch (Throwable) {
                // table missing / unreachable — skip this source
            }
        }
        return $out;
    }

    /**
     * Related products for a bike, fetched + split by manufacturer into OEM
     * (manufacturer = the bike's brand) and aftermarket (everything else).
     * @return array{oem:array<int,array<string,mixed>>,aftermarket:array<int,array<string,mixed>>,url:?string}
     */
    public function relatedForBike(int $bsProductId, string $brand, int $perGroup = 15): array
    {
        $empty = ['oem' => [], 'aftermarket' => [], 'url' => null];
        if ($bsProductId < 1 || !$this->isAvailable()) {
            return $empty;
        }
        $ids = $this->relatedBikeProductIds($bsProductId);
        if (!$ids) {
            return $empty;
        }
        $products = $this->productsByIds($ids, count($ids));
        $needle = $brand === 'cfmoto' ? 'cfmoto' : 'yamaha';
        $oem = $after = [];
        foreach ($products as $pr) {
            if (str_contains(mb_strtolower((string) $pr['manufacturer']), $needle)) {
                $oem[] = $pr;
            } else {
                $after[] = $pr;
            }
        }
        return [
            'oem'         => array_slice($oem, 0, $perGroup),
            'aftermarket' => array_slice($after, 0, $perGroup),
            'url'         => $this->productUrl($bsProductId, ''),
        ];
    }

    /**
     * Map product references -> BikerShop id_product (read-only). Used by
     * database/import_yamaha_accessories.php to resolve a Yamaha accessory
     * (matched by `reference`) to its purchasable BikerShop product. When several
     * products share a reference, the active one wins. References with no match
     * are simply absent from the result.
     *
     * @param array<int,string> $refs
     * @return array<string,int> reference => id_product
     */
    public function productIdsByReferences(array $refs): array
    {
        $refs = array_values(array_unique(array_filter(array_map('trim', $refs), static fn ($r) => $r !== '')));
        if (!$refs || !$this->isAvailable()) {
            return [];
        }
        $p = $this->prefix;
        $shop = $this->shopId; // trusted config int, inlined
        $in = implode(',', array_fill(0, count($refs), '?'));
        $sql = "
            SELECT pr.reference, pr.id_product, COALESCE(ps.active, 0) AS active
            FROM        {$p}product       pr
            LEFT JOIN   {$p}product_shop  ps ON ps.id_product = pr.id_product AND ps.id_shop = {$shop}
            WHERE pr.reference IN ({$in})
            ORDER BY active DESC, pr.id_product ASC
        ";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($refs);
            $out = [];
            foreach ($stmt->fetchAll() as $r) {
                $ref = (string) $r['reference'];
                if (!isset($out[$ref])) { // first wins (active ordered first)
                    $out[$ref] = (int) $r['id_product'];
                }
            }
            return $out;
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

            // 2. Gather candidate models (LIKE, progressively shorter). When the
            //    product has a displacement, surface candidates that share it so
            //    the right one survives the LIMIT before ranking.
            $disp  = FitmentMatcher::displacementOf($modelName);
            $order = $disp !== null
                ? "ORDER BY (mol.name LIKE :disp) DESC, mol.name"
                : "ORDER BY mol.name";
            $rows = [];
            foreach ($this->modelCandidates($modelName) as $pattern) {
                $stmt = $this->pdo->prepare(
                    "SELECT mo.id_leopartsfilter_model AS id, mol.name
                     FROM {$p}leopartsfilter_model mo
                     JOIN {$p}leopartsfilter_model_lang mol
                       ON mol.id_leopartsfilter_model = mo.id_leopartsfilter_model AND mol.id_lang = :lang
                     WHERE mo.active = 1 AND mo.id_leopartsfilter_make = :make
                       AND mol.name LIKE :model
                     {$order}
                     LIMIT 50"
                );
                $stmt->bindValue(':lang', $this->langId, PDO::PARAM_INT);
                $stmt->bindValue(':make', $makeId, PDO::PARAM_INT);
                $stmt->bindValue(':model', '%' . $pattern . '%');
                if ($disp !== null) {
                    $stmt->bindValue(':disp', '%' . $disp . '%');
                }
                $stmt->execute();
                $rows = $stmt->fetchAll();
                if ($rows) {
                    break;
                }
            }
            if (!$rows) {
                return null;
            }

            // 3. Attach the available years per candidate model, then rank.
            $modelIds = array_map(static fn ($r) => (int) $r['id'], $rows);
            $years    = $this->yearsForModels($makeId, $modelIds);
            $candidates = array_map(
                static fn ($r) => [
                    'id'    => (int) $r['id'],
                    'name'  => (string) $r['name'],
                    'years' => $years[(int) $r['id']] ?? [],
                ],
                $rows
            );

            $best = FitmentMatcher::pickBest($modelName, $year, $candidates);
            if ($best['model_id'] === null) {
                return null;
            }

            return [
                'make_id'    => $makeId,
                'model_id'   => $best['model_id'],
                'year_id'    => $best['year_id'],
                'ambiguous'  => $best['ambiguous'],
                'confident'  => $best['confident'],
                'candidates' => $best['candidates'],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Available years (year string => year_id) for a set of candidate models.
     *
     * @param list<int> $modelIds
     * @return array<int,array<string,int>>
     */
    private function yearsForModels(int $makeId, array $modelIds): array
    {
        if ($modelIds === []) {
            return [];
        }
        $p   = $this->prefix;
        $ids = implode(',', array_map('intval', $modelIds)); // trusted ints, inlined
        $stmt = $this->pdo->prepare(
            "SELECT y.id_leopartsfilter_model AS model, y.id_leopartsfilter_year AS id, yl.name
             FROM {$p}leopartsfilter_year y
             JOIN {$p}leopartsfilter_year_lang yl
               ON yl.id_leopartsfilter_year = y.id_leopartsfilter_year AND yl.id_lang = :lang
             WHERE y.active = 1 AND y.id_leopartsfilter_make = :make
               AND y.id_leopartsfilter_model IN ({$ids})"
        );
        $stmt->bindValue(':lang', $this->langId, PDO::PARAM_INT);
        $stmt->bindValue(':make', $makeId, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['model']][(string) $r['name']] = (int) $r['id'];
        }
        return $out;
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
        // Prețurile BikerShop sunt în RON (Lei), stocate FĂRĂ TVA în product_shop.price.
        // Aplicăm cota reală de TVA a produsului (de regulă 21%) pentru prețul brut.
        $excl = (float) $r['price'];
        $rate = isset($r['tax_rate']) ? (float) $r['tax_rate'] : 0.0;
        return [
            'id'           => (int) $r['id_product'],
            'name'         => (string) $r['name'],
            'manufacturer' => $r['manufacturer'] ?? '',
            'price'        => round($excl * (1 + $rate / 100), 2), // brut RON, cu TVA
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
