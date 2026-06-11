<?php

declare(strict_types=1);

namespace App\Client;

use App\Catalog\Repository as Catalog;
use App\Database;
use PDO;
use Throwable;

/**
 * Reads/writes the My Garage data (local DB): client identity (from `clienti`),
 * their motorcycles (`client_bikes` + catalog), service history, incidents,
 * service requests and passwordless OTP login codes.
 *
 * A "person" is identified by `email_norm`; one person can own several bikes
 * (several `clienti` rows sharing that email). Prepared statements + graceful
 * degradation, mirroring App\Catalog\Repository.
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

    // -- Identity -------------------------------------------------------------

    /**
     * Resolve a login identifier (email or phone) to the person's account.
     * @return array{email_norm:string,name:string,has_email:bool}|null
     */
    public function findByLogin(string $identifier): ?array
    {
        $email = normalize_email($identifier);
        $phone = normalize_phone($identifier);
        if (!$email && !$phone) {
            return null;
        }
        $row = $email
            ? $this->one("SELECT email_norm, client FROM clienti WHERE email_norm = :v LIMIT 1", [':v' => $email])
            : $this->one("SELECT email_norm, client FROM clienti WHERE telefon_norm = :v LIMIT 1", [':v' => $phone]);
        if (!$row) {
            return null;
        }
        return [
            'email_norm' => (string) ($row['email_norm'] ?? ''),
            'name'       => (string) ($row['client'] ?? ''),
            'has_email'  => !empty($row['email_norm']),
        ];
    }

    // -- Bikes ----------------------------------------------------------------

    /** All bikes owned by a person (by email_norm), with catalog data. @return array<int,array<string,mixed>> */
    public function bikesForEmail(string $emailNorm): array
    {
        $rows = $this->all(
            "SELECT b.id, b.model_label, b.year, b.vin, b.color, b.plate, b.mileage_km,
                    b.nickname, b.product_id, b.clienti_id,
                    p.brand, p.name AS product_name, p.slug, p.cover_image,
                    c.slug AS cat_slug, c.parent_id AS cat_parent, t.slug AS top_slug
             FROM client_bikes b
             JOIN clienti cl ON cl.id = b.clienti_id
             LEFT JOIN products p ON p.id = b.product_id
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN categories t ON t.id = c.parent_id
             WHERE cl.email_norm = :email
             ORDER BY b.year DESC, b.id",
            [':email' => $emailNorm]
        );
        return array_map([$this, 'shapeBike'], $rows);
    }

    /** A single bike owned by this person (authorization enforced). @return array<string,mixed>|null */
    public function bikeForEmail(string $emailNorm, int $bikeId): ?array
    {
        $row = $this->one(
            "SELECT b.id, b.model_label, b.year, b.vin, b.color, b.plate, b.mileage_km,
                    b.nickname, b.notes, b.purchase_date, b.product_id, b.clienti_id,
                    p.brand, p.name AS product_name, p.slug, p.cover_image, p.lp_model_id, p.lp_year_id,
                    c.slug AS cat_slug, c.parent_id AS cat_parent, t.slug AS top_slug
             FROM client_bikes b
             JOIN clienti cl ON cl.id = b.clienti_id
             LEFT JOIN products p ON p.id = b.product_id
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN categories t ON t.id = c.parent_id
             WHERE b.id = :id AND cl.email_norm = :email
             LIMIT 1",
            [':id' => $bikeId, ':email' => $emailNorm]
        );
        return $row ? $this->shapeBike($row) : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function serviceRecords(int $bikeId): array
    {
        return $this->all(
            "SELECT service_date, mileage_km, type, description, cost_ron, performed_by
             FROM service_records WHERE bike_id = :id ORDER BY service_date DESC, id DESC",
            [':id' => $bikeId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function incidents(int $bikeId): array
    {
        return $this->all(
            "SELECT incident_date, severity, description
             FROM incidents WHERE bike_id = :id ORDER BY incident_date DESC, id DESC",
            [':id' => $bikeId]
        );
    }

    public function createServiceRequest(int $clientiId, ?int $bikeId, ?string $preferredDate, string $problem): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO service_requests (clienti_id, bike_id, preferred_date, problem)
                 VALUES (:cid, :bid, :date, :problem)"
            );
            return $stmt->execute([
                ':cid' => $clientiId,
                ':bid' => $bikeId,
                ':date' => $preferredDate ?: null,
                ':problem' => $problem,
            ]);
        } catch (Throwable) {
            return false;
        }
    }

    // -- OTP ------------------------------------------------------------------

    /** Store a hashed OTP for an email. Returns false if DB unavailable. */
    public function createOtp(string $emailNorm, string $code, int $ttlMinutes, ?string $ip): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO client_otp (identifier, code_hash, expires_at, ip)
                 VALUES (:id, :hash, DATE_ADD(NOW(), INTERVAL :ttl MINUTE), :ip)"
            );
            return $stmt->execute([
                ':id' => $emailNorm,
                ':hash' => hash('sha256', $code),
                ':ttl' => $ttlMinutes,
                ':ip' => $ip,
            ]);
        } catch (Throwable) {
            return false;
        }
    }

    /** How many OTPs were requested for this email in the last N minutes (rate-limit). */
    public function recentOtpCount(string $emailNorm, int $minutes): int
    {
        $row = $this->one(
            "SELECT COUNT(*) n FROM client_otp
             WHERE identifier = :id AND created_at > DATE_SUB(NOW(), INTERVAL :m MINUTE)",
            [':id' => $emailNorm, ':m' => $minutes]
        );
        return (int) ($row['n'] ?? 0);
    }

    /**
     * Verify a code for an email against the latest unused, unexpired OTP.
     * Single-use; increments attempts on failure; max 5 attempts.
     */
    public function verifyOtp(string $emailNorm, string $code): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        $row = $this->one(
            "SELECT id, code_hash, attempts FROM client_otp
             WHERE identifier = :id AND used_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1",
            [':id' => $emailNorm]
        );
        if (!$row || (int) $row['attempts'] >= 5) {
            return false;
        }
        if (!hash_equals((string) $row['code_hash'], hash('sha256', $code))) {
            $this->pdo->prepare("UPDATE client_otp SET attempts = attempts + 1 WHERE id = :id")
                ->execute([':id' => $row['id']]);
            return false;
        }
        $this->pdo->prepare("UPDATE client_otp SET used_at = NOW() WHERE id = :id")
            ->execute([':id' => $row['id']]);
        return true;
    }

    // -- Admin (back-office, dealer-maintained) -------------------------------

    /** Bikes + owner, optionally filtered by a search term. @return array<int,array<string,mixed>> */
    public function adminBikes(?string $q = null, int $limit = 200): array
    {
        $where = '';
        $params = [];
        if ($q !== null && trim($q) !== '') {
            $where = "WHERE cl.client LIKE :q OR cl.email_norm LIKE :q OR cl.telefon_norm LIKE :q
                      OR b.model_label LIKE :q OR b.plate LIKE :q OR b.vin LIKE :q OR p.name LIKE :q";
            $params[':q'] = '%' . trim($q) . '%';
        }
        return $this->all(
            "SELECT b.id, b.model_label, b.year, b.plate, b.mileage_km, b.product_id,
                    p.name AS product_name, cl.client AS owner, cl.email_norm, cl.telefon_norm
             FROM client_bikes b
             JOIN clienti cl ON cl.id = b.clienti_id
             LEFT JOIN products p ON p.id = b.product_id
             {$where}
             ORDER BY cl.client, b.id
             LIMIT " . (int) $limit,
            $params
        );
    }

    /** Bike row + owner for the admin edit page. @return array<string,mixed>|null */
    public function adminBike(int $id): ?array
    {
        return $this->one(
            "SELECT b.*, cl.client AS owner, cl.email_norm, cl.telefon_norm, p.name AS product_name
             FROM client_bikes b
             JOIN clienti cl ON cl.id = b.clienti_id
             LEFT JOIN products p ON p.id = b.product_id
             WHERE b.id = :id",
            [':id' => $id]
        );
    }

    /** @param array<string,mixed> $f */
    public function updateBike(int $id, array $f): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE client_bikes SET
                    product_id = :pid, nickname = :nick, color = :color, plate = :plate,
                    mileage_km = :km, purchase_date = :pdate, year = :year, vin = :vin, notes = :notes
                 WHERE id = :id"
            );
            return $stmt->execute([
                ':pid'   => $f['product_id'] ?: null,
                ':nick'  => $f['nickname'] ?: null,
                ':color' => $f['color'] ?: null,
                ':plate' => $f['plate'] ?: null,
                ':km'    => $f['mileage_km'] !== '' ? (int) $f['mileage_km'] : null,
                ':pdate' => $f['purchase_date'] ?: null,
                ':year'  => $f['year'] ?: null,
                ':vin'   => $f['vin'] ?: null,
                ':notes' => $f['notes'] ?: null,
                ':id'    => $id,
            ]);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $f */
    public function addServiceRecord(int $bikeId, array $f): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        try {
            return $this->pdo->prepare(
                "INSERT INTO service_records (bike_id, service_date, mileage_km, type, description, cost_ron, performed_by)
                 VALUES (:bid, :date, :km, :type, :descr, :cost, :by)"
            )->execute([
                ':bid' => $bikeId,
                ':date' => $f['service_date'] ?: null,
                ':km' => $f['mileage_km'] !== '' ? (int) $f['mileage_km'] : null,
                ':type' => $f['type'] ?: null,
                ':descr' => $f['description'] ?: null,
                ':cost' => $f['cost_ron'] !== '' ? (float) str_replace(',', '.', (string) $f['cost_ron']) : null,
                ':by' => $f['performed_by'] ?: null,
            ]);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $f */
    public function addIncident(int $bikeId, array $f): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        try {
            return $this->pdo->prepare(
                "INSERT INTO incidents (bike_id, incident_date, severity, description)
                 VALUES (:bid, :date, :sev, :descr)"
            )->execute([
                ':bid' => $bikeId,
                ':date' => $f['incident_date'] ?: null,
                ':sev' => $f['severity'] ?: null,
                ':descr' => $f['description'] ?: null,
            ]);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function serviceRequests(?string $status = null): array
    {
        $where = '';
        $params = [];
        if ($status !== null && $status !== '') {
            $where = "WHERE r.status = :s";
            $params[':s'] = $status;
        }
        return $this->all(
            "SELECT r.id, r.preferred_date, r.problem, r.status, r.created_at,
                    b.model_label, b.plate, cl.client AS owner, cl.email_norm, cl.telefon_norm, b.id AS bike_id
             FROM service_requests r
             JOIN clienti cl ON cl.id = r.clienti_id
             LEFT JOIN client_bikes b ON b.id = r.bike_id
             {$where}
             ORDER BY FIELD(r.status,'nou','confirmat','inchis'), r.created_at DESC",
            $params
        );
    }

    public function setRequestStatus(int $id, string $status): bool
    {
        if (!$this->isAvailable() || !in_array($status, ['nou', 'confirmat', 'inchis'], true)) {
            return false;
        }
        try {
            return $this->pdo->prepare("UPDATE service_requests SET status = :s WHERE id = :id")
                ->execute([':s' => $status, ':id' => $id]);
        } catch (Throwable) {
            return false;
        }
    }

    // -- Shaping --------------------------------------------------------------

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function shapeBike(array $r): array
    {
        $hasProduct = !empty($r['product_id']);
        $url = null;
        $cover = null;
        if ($hasProduct) {
            $top = $r['cat_parent'] !== null ? $r['top_slug'] : $r['cat_slug'];
            $sub = $r['cat_parent'] !== null ? $r['cat_slug'] : null;
            $segs = $sub
                ? [$r['brand'], $top, $sub, $r['slug']]
                : [$r['brand'], $top, $r['slug']];
            $url = '/' . implode('/', array_filter($segs));
            $cover = Catalog::imagePath((string) $r['brand'], 'cover', $r['cover_image'] ?? null);
        }
        return [
            'id'         => (int) $r['id'],
            'clienti_id' => (int) $r['clienti_id'],
            'product_id' => $hasProduct ? (int) $r['product_id'] : null,
            'title'      => $r['nickname'] ?: ($r['product_name'] ?: $r['model_label']),
            'model'      => $r['product_name'] ?: $r['model_label'],
            'brand'      => $r['brand'] ?? null,
            'year'       => $r['year'] ? (int) $r['year'] : null,
            'vin'        => $r['vin'] ?? null,
            'color'      => $r['color'] ?? null,
            'plate'      => $r['plate'] ?? null,
            'mileage_km' => $r['mileage_km'] !== null ? (int) $r['mileage_km'] : null,
            'notes'      => $r['notes'] ?? null,
            'purchase_date' => $r['purchase_date'] ?? null,
            'lp_model_id'   => isset($r['lp_model_id']) ? (int) $r['lp_model_id'] : null,
            'lp_year_id'    => isset($r['lp_year_id']) ? (int) $r['lp_year_id'] : null,
            'cover'      => $cover,
            'url'        => $url,
        ];
    }

    // -- Query helpers --------------------------------------------------------

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
