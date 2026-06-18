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
}
