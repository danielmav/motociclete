<?php

declare(strict_types=1);

namespace App\Accessories;

use App\Database;
use PDO;
use Throwable;

/**
 * Reads the portal-owned Yamaha accessory↔model relationship (`yamaha_accessories`
 * + `yamaha_accessory_models`), populated from the Yamaha hyperdrive feed by
 * database/import_yamaha_accessories.php.
 *
 * The relationship (which OEM accessory fits which model) lives here — NOT in
 * BikerShop's advrider_related. Purchase still happens on BikerShop, so the actual
 * price/image/buy-URL are fetched live by id (BikerShop\Client::productsByIds),
 * keyed by the `bs_product_id` resolved here via the product reference.
 *
 * Read-only, graceful degradation (returns [] if the DB is unreachable).
 */
final class Repository
{
    private ?PDO $pdo;

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

    /**
     * BikerShop product ids that are OEM Yamaha accessories for a local model,
     * in feed order (popularity). Only accessories already on BikerShop
     * (bs_product_id set) are returned — those are the ones that are purchasable.
     * @return array<int,int>
     */
    public function bsProductIdsForModel(int $productId, int $limit = 15): array
    {
        if ($productId < 1 || !$this->isAvailable()) {
            return [];
        }
        try {
            $stmt = $this->pdo->prepare(
                "SELECT a.bs_product_id
                 FROM yamaha_accessory_models m
                 JOIN yamaha_accessories a ON a.id = m.accessory_id
                 WHERE m.product_id = :pid AND a.bs_product_id IS NOT NULL
                 ORDER BY m.position ASC
                 LIMIT " . (int) max(1, $limit)
            );
            $stmt->execute([':pid' => $productId]);
            return array_values(array_map('intval', array_column($stmt->fetchAll(), 'bs_product_id')));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Local models that have at least one purchasable accessory (bs_product_id set),
     * for the "Ce motocicletă ai?" selector. Ordered by brand + name.
     * @return array<int,array{id:int,brand:string,name:string,slug:string}>
     */
    public function modelsWithAccessories(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        try {
            $stmt = $this->pdo->query(
                "SELECT DISTINCT p.id, p.brand, p.name, p.slug
                 FROM yamaha_accessory_models m
                 JOIN yamaha_accessories a ON a.id = m.accessory_id AND a.bs_product_id IS NOT NULL
                 JOIN products p ON p.id = m.product_id
                 ORDER BY p.brand ASC, p.name ASC"
            );
            return array_map(fn (array $r) => [
                'id'    => (int) $r['id'],
                'brand' => (string) $r['brand'],
                'name'  => (string) $r['name'],
                'slug'  => (string) $r['slug'],
            ], $stmt->fetchAll());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Distinct accessory categories (accessory_type) with a count of purchasable
     * accessories, optionally restricted to the accessories that fit one model.
     * @return array<int,array{type:string,count:int}>
     */
    public function types(?int $productId = null): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        try {
            if ($productId) {
                $sql = "SELECT a.accessory_type AS type, COUNT(DISTINCT a.bs_product_id) AS c
                        FROM yamaha_accessory_models m
                        JOIN yamaha_accessories a ON a.id = m.accessory_id
                        WHERE m.product_id = :pid AND a.bs_product_id IS NOT NULL AND a.accessory_type <> ''
                        GROUP BY a.accessory_type ORDER BY a.accessory_type ASC";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([':pid' => $productId]);
            } else {
                $sql = "SELECT a.accessory_type AS type, COUNT(DISTINCT a.bs_product_id) AS c
                        FROM yamaha_accessories a
                        WHERE a.bs_product_id IS NOT NULL AND a.accessory_type <> ''
                        GROUP BY a.accessory_type ORDER BY a.accessory_type ASC";
                $stmt = $this->pdo->query($sql);
            }
            return array_map(fn (array $r) => [
                'type'  => (string) $r['type'],
                'count' => (int) $r['c'],
            ], $stmt->fetchAll());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * One page of accessory BikerShop ids, filtered by model and/or category, with
     * the total (distinct, purchasable) for pagination. When a model is given the
     * order is feed popularity (position); otherwise by name.
     * @return array{bs_ids:array<int,int>,total:int}
     */
    public function page(?int $productId, ?string $type, int $page, int $perPage): array
    {
        $empty = ['bs_ids' => [], 'total' => 0];
        if (!$this->isAvailable()) {
            return $empty;
        }
        $page    = max(1, $page);
        $perPage = max(1, $perPage);
        $offset  = ($page - 1) * $perPage;
        $type    = ($type !== null && $type !== '') ? $type : null;

        $where  = ['a.bs_product_id IS NOT NULL'];
        $params = [];
        if ($type !== null) {
            $where[] = 'a.accessory_type = :type';
            $params[':type'] = $type;
        }
        try {
            if ($productId) {
                $params[':pid'] = $productId;
                $from = "FROM yamaha_accessory_models m
                         JOIN yamaha_accessories a ON a.id = m.accessory_id";
                $where[] = 'm.product_id = :pid';
                $order = 'MIN(m.position) ASC';
            } else {
                $from = 'FROM yamaha_accessories a';
                $order = 'MIN(a.name) ASC';
            }
            $whereSql = 'WHERE ' . implode(' AND ', $where);

            $count = $this->pdo->prepare(
                "SELECT COUNT(*) FROM (SELECT a.bs_product_id {$from} {$whereSql} GROUP BY a.bs_product_id) t"
            );
            $count->execute($params);
            $total = (int) $count->fetchColumn();

            $list = $this->pdo->prepare(
                "SELECT a.bs_product_id {$from} {$whereSql}
                 GROUP BY a.bs_product_id
                 ORDER BY {$order}
                 LIMIT {$perPage} OFFSET {$offset}"
            );
            $list->execute($params);
            $ids = array_values(array_map('intval', array_column($list->fetchAll(), 'bs_product_id')));

            return ['bs_ids' => $ids, 'total' => $total];
        } catch (Throwable) {
            return $empty;
        }
    }
}
