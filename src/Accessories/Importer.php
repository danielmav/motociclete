<?php

declare(strict_types=1);

namespace App\Accessories;

use App\BikerShop\Client;
use App\Database;
use PDO;
use Throwable;

/**
 * Preia accesoriile originale Yamaha din endpointul hyperdrive și le sincronizează
 * în baza portalului (`yamaha_accessories` + `yamaha_accessory_models`).
 *
 * Folosit din DOUĂ locuri:
 *   - CLI: database/import_yamaha_accessories.php (toate modelele / cron)
 *   - Admin: la salvarea unui produs Yamaha cu PID nou/schimbat + buton manual.
 *
 * Reguli:
 *   - NEDISTRUCTIV pentru accesorii: doar upsert pe `yamaha_id`, nu se șterge nimic
 *     din `yamaha_accessories` (accesoriile rămân chiar dacă modelul dispare).
 *   - La un model se reconstruiesc DOAR legăturile lui (`yamaha_accessory_models`).
 *   - Degradare grațioasă: dacă Yamaha/DB/bikershop pică, întoarce ok=false cu eroare,
 *     fără să arunce excepții (ca să nu strice salvarea din admin).
 */
final class Importer
{
    // URL hyperdrive: GUID categorie CONSTANT; doar %PID% variază. limit/offset la paginare.
    private const URL_TEMPLATE =
        'https://hyperdrive.yamaha-motor.eu/products/yme-prod-ro'
        . '?projectKey=yme-prod-ro&locale=ro-RO'
        . '&query=categories.id:subtree(%221a517708-545a-4094-89e3-ca507def0af3%22)'
        . '%7Cvariants.attributes.embargoExternalReleased:true'
        . '%7Cvariants.attributes.products:%PID%'
        . '&allFacets=categories.id%7Cvariants.attributes.collection%7Cvariants.attributes.gender%7Cvariants.attributes.accessoryType'
        . '&selectedFacets='
        . '&sort=variants.attributes.popularityIndex.desc%7Cvariants.sku.desc'
        . '&text=&productType=Accessory&version=caas';

    private ?PDO $pdo;
    private Client $bs;

    public function __construct(Database $db, Client $bs)
    {
        try {
            $this->pdo = $db->local();
        } catch (Throwable) {
            $this->pdo = null;
        }
        $this->bs = $bs;
    }

    public function isAvailable(): bool
    {
        return $this->pdo instanceof PDO;
    }

    /** Modelele Yamaha active cu PID setat. @return array<int,array<string,mixed>> */
    public function models(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        try {
            return $this->pdo->query(
                "SELECT id, name, yamaha_pid FROM products
                 WHERE brand = 'yamaha' AND yamaha_pid IS NOT NULL AND yamaha_pid <> '' AND is_active = 1
                 ORDER BY name"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Sincronizează accesoriile unui model. Întoarce statistici; nu aruncă excepții.
     * @return array{model:string,fetched:int,new:int,price_changed:int,unmatched:int,links:int,ok:bool,error:?string}
     */
    public function importForModel(int $productId, bool $apply = true): array
    {
        $stat = ['model' => '', 'fetched' => 0, 'new' => 0, 'price_changed' => 0, 'unmatched' => 0, 'links' => 0, 'ok' => false, 'error' => null];
        if (!$this->isAvailable()) {
            $stat['error'] = 'Baza de date indisponibilă';
            return $stat;
        }
        try {
            $m = $this->pdo->prepare("SELECT id, name, yamaha_pid FROM products WHERE id = :id AND brand = 'yamaha'");
            $m->execute([':id' => $productId]);
            $row = $m->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['yamaha_pid'])) {
                $stat['error'] = 'Modelul nu are PID Yamaha setat';
                return $stat;
            }
            $stat['model'] = (string) $row['name'];

            $products = $this->fetchAll((string) $row['yamaha_pid']);
            if (!$products) {
                $stat['error'] = 'Niciun accesoriu primit (Yamaha indisponibil sau PID greșit)';
                return $stat;
            }

            // Shape + dedup pe yamaha_id, păstrând ordinea (popularitate).
            $shaped = [];
            foreach ($products as $p) {
                $s = $this->shape($p);
                if ($s['yamaha_id'] === '' || isset($shaped[$s['yamaha_id']])) {
                    continue;
                }
                $shaped[$s['yamaha_id']] = $s;
            }

            // Match referințe -> bs_product_id (un singur query).
            $refs = array_values(array_filter(array_map(static fn ($s) => $s['reference'], $shaped)));
            $bsMap = $this->bs->productIdsByReferences($refs);

            // Starea curentă pentru diff (preț schimbat / nou).
            $existing = [];
            foreach ($this->pdo->query("SELECT yamaha_id, price_eur FROM yamaha_accessories")->fetchAll(PDO::FETCH_ASSOC) as $e) {
                $existing[$e['yamaha_id']] = (float) $e['price_eur'];
            }

            $upsert = $this->pdo->prepare(
                "INSERT INTO yamaha_accessories (yamaha_id, reference, name, price_eur, accessory_type, bs_product_id)
                 VALUES (:yid, :ref, :name, :price, :type, :bs)
                 ON DUPLICATE KEY UPDATE reference=VALUES(reference), name=VALUES(name),
                    price_eur=VALUES(price_eur), accessory_type=VALUES(accessory_type), bs_product_id=VALUES(bs_product_id)"
            );
            $selId   = $this->pdo->prepare("SELECT id FROM yamaha_accessories WHERE yamaha_id = :yid");
            $insLink = $this->pdo->prepare(
                "INSERT INTO yamaha_accessory_models (accessory_id, product_id, position)
                 VALUES (:aid, :pid, :pos) ON DUPLICATE KEY UPDATE position = VALUES(position)"
            );

            if ($apply) {
                $this->pdo->prepare("DELETE FROM yamaha_accessory_models WHERE product_id = :pid")->execute([':pid' => $productId]);
            }

            $pos = 0;
            foreach ($shaped as $yid => $s) {
                $stat['fetched']++;
                $bsId = $s['reference'] !== '' ? ($bsMap[$s['reference']] ?? null) : null;
                if ($bsId === null) {
                    $stat['unmatched']++;
                }
                if (!isset($existing[$yid])) {
                    $stat['new']++;
                } elseif (abs($existing[$yid] - $s['price_eur']) > 0.001) {
                    $stat['price_changed']++;
                }
                if ($apply) {
                    $upsert->execute([
                        ':yid' => $yid, ':ref' => $s['reference'], ':name' => $s['name'],
                        ':price' => $s['price_eur'], ':type' => $s['type'], ':bs' => $bsId,
                    ]);
                    $selId->execute([':yid' => $yid]);
                    $accId = (int) $selId->fetchColumn();
                    if ($accId > 0) {
                        $insLink->execute([':aid' => $accId, ':pid' => $productId, ':pos' => $pos++]);
                        $stat['links']++;
                    }
                } else {
                    $stat['links']++;
                }
            }
            $stat['ok'] = true;
            return $stat;
        } catch (Throwable $e) {
            $stat['error'] = $e->getMessage();
            return $stat;
        }
    }

    /** Aduce toate accesoriile (paginat) pentru un PID. @return array<int,array<string,mixed>> */
    private function fetchAll(string $pid): array
    {
        $base = str_replace('%PID%', $pid, self::URL_TEMPLATE);
        $limit = 96;
        $offset = 0;
        $out = [];
        do {
            $data = $this->getJson($base . "&limit={$limit}&offset={$offset}");
            if (!is_array($data)) {
                break;
            }
            $total = (int) ($data['total'] ?? 0);
            $batch = $data['results'] ?? [];
            if (!$batch) {
                break;
            }
            foreach ($batch as $p) {
                $out[] = $p;
            }
            $offset += $limit;
            if ($offset < $total) {
                usleep(500000); // politețe / anti-blocare
            }
        } while ($offset < $total);
        return $out;
    }

    /** @return array<string,mixed>|null */
    private function getJson(string $url): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0',
                'Accept: application/json',
                'Referer: https://www.yamaha-motor.eu/',
                'Origin: https://www.yamaha-motor.eu',
            ],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return $body === false ? null : json_decode((string) $body, true);
    }

    /** Extrage {yamaha_id, reference, name, price_eur, type} din variantele unui produs. */
    private function shape(array $product): array
    {
        $priceEur = 0.0;
        $reference = '';
        foreach (($product['variants'] ?? []) as $v) {
            if ($priceEur === 0.0 && !empty($v['prices'][0]['amount']) && $v['prices'][0]['amount'] > 0) {
                $priceEur = (float) $v['prices'][0]['amount'];
            }
            if ($reference === '' && !empty($v['sku'])) {
                $reference = substr(str_replace('-', '', (string) $v['sku']), 0, -2); // referința PrestaShop
            }
        }
        $type = '';
        foreach (($product['variants'][0]['attributes'] ?? []) as $a) {
            if (($a['name'] ?? '') === 'accessoryType') {
                $type = is_array($a['value'] ?? null) ? (string) reset($a['value']) : (string) ($a['value'] ?? '');
                break;
            }
        }
        return [
            'yamaha_id' => (string) ($product['id'] ?? ''),
            'reference' => $reference,
            'name'      => trim((string) preg_replace('/\s+/u', ' ', (string) ($product['name'] ?? ''))),
            'price_eur' => $priceEur,
            'type'      => $type,
        ];
    }
}
