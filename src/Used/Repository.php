<?php

declare(strict_types=1);

namespace App\Used;

use App\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Vehicule rulate (`used_*`). Singurul loc care atinge aceste tabele și singurul
 * care știe când un anunț e public: is_active = 1 ȘI expires_at > acum.
 * „Acum" vine din PHP (Europe/Bucharest), nu din NOW() — serverul e pe UTC.
 * Citirile degradează grațios; scrierile lasă excepția să urce.
 */
final class Repository
{
    public const DAYS = 30;

    private const IMG_BASE = '/media/rulate/';
    private const COLS = ['title', 'brand_id', 'category_id', 'price_eur', 'year', 'km', 'cc', 'description_html', 'video'];
    private const SELECT =
        "SELECT v.*, b.name AS brand_name, b.slug AS brand_slug, c.name AS category_name, c.slug AS category_slug,
                (SELECT i.filename FROM used_images i WHERE i.vehicle_id = v.id
                  ORDER BY i.is_cover DESC, i.position, i.id LIMIT 1) AS cover
         FROM used_vehicles v
         JOIN used_brands b ON b.id = v.brand_id
         JOIN used_categories c ON c.id = v.category_id";
    private const IS_PUBLIC = 'v.is_active = 1 AND v.expires_at > :now';

    private ?PDO $pdo;
    /** @var callable|null */
    private $clock;

    public function __construct(Database $db, private string $mediaDir, ?callable $clock = null)
    {
        $this->clock = $clock;
        try {
            $this->pdo = $db->local();
        } catch (Throwable) {
            $this->pdo = null;
        }
    }

    /** ID-ul YouTube (11 caractere) dintr-un URL sau dintr-un ID lipit direct. */
    public static function youtubeId(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('~(?:youtu\.be/|[?&]v=|/embed/|/shorts/)([A-Za-z0-9_-]{11})~', $value, $m)) {
            return $m[1];
        }
        return preg_match('~^[A-Za-z0-9_-]{11}$~', $value) ? $value : null;
    }

    // -- Public --------------------------------------------------------------

    /** @return array{items: list<array<string,mixed>>, total: int} */
    public function page(?int $categoryId, ?int $brandId, int $page, int $perPage): array
    {
        $where = [self::IS_PUBLIC];
        $params = [':now' => $this->now()];
        if ($categoryId) {
            $where[] = 'v.category_id = :cat';
            $params[':cat'] = $categoryId;
        }
        if ($brandId) {
            $where[] = 'v.brand_id = :brand';
            $params[':brand'] = $brandId;
        }
        $w = implode(' AND ', $where);
        $total = (int) ($this->one("SELECT COUNT(*) AS n FROM used_vehicles v WHERE $w", $params)['n'] ?? 0);
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->all(
            self::SELECT . " WHERE $w ORDER BY v.created_at DESC, v.id DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );
        return ['items' => $this->shapeAll($rows), 'total' => $total];
    }

    /** Un anunț în orice stare (cu imagini), sau null. */
    public function find(int $id): ?array
    {
        $row = $this->one(self::SELECT . ' WHERE v.id = :id', [':id' => $id]);
        if (!$row) {
            return null;
        }
        $v = $this->shape($row, $this->now());
        $v['images'] = array_map(
            static fn (array $r): array => [
                'filename' => (string) $r['filename'],
                'url'      => self::IMG_BASE . rawurlencode((string) $r['filename']),
            ],
            $this->all('SELECT filename FROM used_images WHERE vehicle_id = :id ORDER BY position, id', [':id' => $id])
        );
        return $v;
    }

    /** @param array<string,mixed> $vehicle */
    public function isPublic(array $vehicle): bool
    {
        return ($vehicle['state'] ?? '') === 'active';
    }

    public function latest(int $limit, ?int $excludeId = null): array
    {
        $rows = $this->all(
            self::SELECT . ' WHERE ' . self::IS_PUBLIC . ' AND v.id <> :ex ORDER BY v.created_at DESC, v.id DESC LIMIT ' . (int) $limit,
            [':now' => $this->now(), ':ex' => (int) $excludeId]
        );
        return $this->shapeAll($rows);
    }

    public function categoriesWithCounts(): array
    {
        return $this->taxaWithCounts('used_categories', 'category_id');
    }

    public function brandsWithCounts(): array
    {
        return $this->taxaWithCounts('used_brands', 'brand_id');
    }

    public function categoryBySlug(string $slug): ?array
    {
        return $this->taxonBySlug('used_categories', $slug);
    }

    public function brandBySlug(string $slug): ?array
    {
        return $this->taxonBySlug('used_brands', $slug);
    }

    /** @return list<array{path:string,lastmod:?string}> */
    public function sitemapEntries(): array
    {
        $out = [];
        foreach ($this->categoriesWithCounts() as $c) {
            $out[] = ['path' => '/rulate/' . $c['slug'], 'lastmod' => null];
        }
        foreach ($this->brandsWithCounts() as $b) {
            $out[] = ['path' => '/rulate/marca/' . $b['slug'], 'lastmod' => null];
        }
        foreach ($this->page(null, null, 1, 1000)['items'] as $v) {
            $out[] = ['path' => $v['url'], 'lastmod' => $v['updated_at']];
        }
        return $out;
    }

    // -- Admin ---------------------------------------------------------------

    /** Toate anunțurile; $state ∈ active|expired|inactive filtrează. */
    public function adminList(?string $state = null): array
    {
        $all = $this->shapeAll($this->all(self::SELECT . ' ORDER BY v.created_at DESC, v.id DESC'));
        if ($state === null || $state === '') {
            return $all;
        }
        return array_values(array_filter($all, static fn (array $v): bool => $v['state'] === $state));
    }

    /** Expirate automat (nu și cele dezactivate manual) — pentru dashboard. */
    public function expired(): array
    {
        return $this->adminList('expired');
    }

    public function activeCount(): int
    {
        return (int) ($this->one(
            'SELECT COUNT(*) AS n FROM used_vehicles v WHERE ' . self::IS_PUBLIC,
            [':now' => $this->now()]
        )['n'] ?? 0);
    }

    /**
     * Creează (id null) sau modifică un anunț și îi înlocuiește imaginile.
     * La creare: slug din titlu, activ, expiră peste DAYS zile. La editare
     * slug-ul și termenul rămân neschimbate.
     * @param array<string,mixed> $d
     * @param array<int,mixed> $filenames în ordinea afișării; prima = coperta
     */
    public function save(?int $id, array $d, array $filenames): int
    {
        $params = [];
        foreach (self::COLS as $c) {
            $params[':' . $c] = $d[$c] ?? null;
        }
        if ($id) {
            $set = implode(', ', array_map(static fn ($c) => "`$c` = :$c", self::COLS));
            $params[':id'] = $id;
            $this->pdo->prepare("UPDATE used_vehicles SET $set WHERE id = :id")->execute($params);
        } else {
            $names = implode(', ', array_map(static fn ($c) => "`$c`", self::COLS));
            $ph = implode(', ', array_map(static fn ($c) => ":$c", self::COLS));
            $params[':slug'] = self::slug((string) ($d['title'] ?? '')) ?: 'anunt';
            $params[':exp'] = $this->expiry();
            $this->pdo->prepare(
                "INSERT INTO used_vehicles ($names, slug, is_active, expires_at) VALUES ($ph, :slug, 1, :exp)"
            )->execute($params);
            $id = (int) $this->pdo->lastInsertId();
        }

        $this->pdo->prepare('DELETE FROM used_images WHERE vehicle_id = :id')->execute([':id' => $id]);
        $ins = $this->pdo->prepare(
            'INSERT INTO used_images (vehicle_id, filename, is_cover, position) VALUES (:v, :f, :c, :p)'
        );
        $pos = 0;
        foreach ($filenames as $f) {
            $f = basename(trim((string) $f));
            if ($f === '') {
                continue;
            }
            $ins->execute([':v' => $id, ':f' => $f, ':c' => $pos === 0 ? 1 : 0, ':p' => $pos]);
            $pos++;
        }
        return $id;
    }

    public function deactivate(int $id): void
    {
        $this->pdo->prepare('UPDATE used_vehicles SET is_active = 0 WHERE id = :id')->execute([':id' => $id]);
    }

    /** Activ din nou, pentru DAYS zile de acum. */
    public function reactivate(int $id): void
    {
        $this->pdo->prepare('UPDATE used_vehicles SET is_active = 1, expires_at = :exp WHERE id = :id')
            ->execute([':exp' => $this->expiry(), ':id' => $id]);
    }

    /** Șterge anunțul și fișierele lui (imagine + miniatură). */
    public function delete(int $id): void
    {
        $files = array_column(
            $this->all('SELECT filename FROM used_images WHERE vehicle_id = :id', [':id' => $id]),
            'filename'
        );
        $this->pdo->prepare('DELETE FROM used_vehicles WHERE id = :id')->execute([':id' => $id]);
        foreach ($files as $f) {
            $f = basename((string) $f);
            foreach ([$this->mediaDir . '/' . $f, $this->mediaDir . '/thumbs/' . $f] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    public function brands(): array
    {
        return $this->taxa('used_brands', 'brand_id');
    }

    public function categories(): array
    {
        return $this->taxa('used_categories', 'category_id');
    }

    public function addBrand(string $name): ?int
    {
        return $this->addTaxon('used_brands', $name, false);
    }

    /** Refuză și slug-urile care s-ar ciocni cu rutele /rulate/marca/… și /rulate/{id}-{slug}. */
    public function addCategory(string $name): ?int
    {
        return $this->addTaxon('used_categories', $name, true);
    }

    public function deleteBrand(int $id): bool
    {
        return $this->deleteTaxon('used_brands', 'brand_id', $id);
    }

    public function deleteCategory(int $id): bool
    {
        return $this->deleteTaxon('used_categories', 'category_id', $id);
    }

    // -- Intern --------------------------------------------------------------

    /**
     * Slug strict [a-z0-9-]: `slugify()` păstrează literele pe care nu le știe
     * translitera (Č, º…), iar rutele /rulate acceptă doar ASCII → URL-ul ar da 404.
     */
    private static function slug(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', slugify($text)), '-');
    }

    private function now(): string
    {
        if ($this->clock) {
            return (string) ($this->clock)();
        }
        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Bucharest')))->format('Y-m-d H:i:s');
    }

    private function expiry(): string
    {
        return (new DateTimeImmutable($this->now()))->modify('+' . self::DAYS . ' days')->format('Y-m-d H:i:s');
    }

    /** @param list<array<string,mixed>> $rows */
    private function shapeAll(array $rows): array
    {
        $now = $this->now();
        return array_map(fn (array $r): array => $this->shape($r, $now), $rows);
    }

    /** @param array<string,mixed> $r */
    private function shape(array $r, string $now): array
    {
        $active  = (int) $r['is_active'] === 1;
        $expires = (string) $r['expires_at'];
        $state   = !$active ? 'inactive' : ($expires > $now ? 'active' : 'expired');
        $year = $r['year'] !== null ? (int) $r['year'] : null;
        $km   = $r['km'] !== null ? (int) $r['km'] : null;
        $cc   = $r['cc'] !== null ? (int) $r['cc'] : null;

        $facts = [];
        if ($year) {
            $facts[] = (string) $year;
        }
        if ($km !== null) {
            $facts[] = number_format($km, 0, ',', '.') . ' km';
        }
        if ($cc) {
            $facts[] = $cc . ' cc';
        }

        $cover = (string) ($r['cover'] ?? '');
        $image = $cover !== '' ? self::IMG_BASE . rawurlencode($cover) : null;
        $thumb = $image;
        if ($cover !== '' && is_file($this->mediaDir . '/thumbs/' . $cover)) {
            $thumb = self::IMG_BASE . 'thumbs/' . rawurlencode($cover);
        }

        return [
            'id'               => (int) $r['id'],
            'title'            => (string) $r['title'],
            'slug'             => (string) $r['slug'],
            'url'              => '/rulate/' . (int) $r['id'] . '-' . $r['slug'],
            'brand_id'         => (int) $r['brand_id'],
            'brand_name'       => (string) $r['brand_name'],
            'brand_slug'       => (string) $r['brand_slug'],
            'category_id'      => (int) $r['category_id'],
            'category_name'    => (string) $r['category_name'],
            'category_slug'    => (string) $r['category_slug'],
            'price_eur'        => $r['price_eur'] !== null ? (float) $r['price_eur'] : null,
            'year'             => $year,
            'km'               => $km,
            'cc'               => $cc,
            'facts'            => implode(' · ', $facts),
            'description_html' => (string) ($r['description_html'] ?? ''),
            'video'            => (string) ($r['video'] ?? ''),
            'video_id'         => self::youtubeId($r['video'] ?? null),
            'image'            => $image,
            'thumb'            => $thumb,
            'is_active'        => $active,
            'expires_at'       => $expires,
            'created_at'       => (string) $r['created_at'],
            'updated_at'       => (string) $r['updated_at'],
            'state'            => $state,
            'days_left'        => $state === 'active'
                ? (int) ceil((strtotime($expires) - strtotime($now)) / 86400)
                : 0,
        ];
    }

    /** Taxonomii cu numărul de anunțuri publice (doar cele cu cel puțin unul). */
    private function taxaWithCounts(string $table, string $fk): array
    {
        $rows = $this->all(
            "SELECT t.id, t.name, t.slug, COUNT(v.id) AS n
             FROM `$table` t
             JOIN used_vehicles v ON v.`$fk` = t.id AND " . self::IS_PUBLIC . "
             GROUP BY t.id, t.name, t.slug, t.position
             ORDER BY t.position, t.name",
            [':now' => $this->now()]
        );
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'slug' => (string) $r['slug'], 'n' => (int) $r['n'],
        ], $rows);
    }

    /** Toate taxonomiile, cu numărul total de anunțuri care le folosesc. */
    private function taxa(string $table, string $fk): array
    {
        $rows = $this->all(
            "SELECT t.id, t.name, t.slug, COUNT(v.id) AS total
             FROM `$table` t LEFT JOIN used_vehicles v ON v.`$fk` = t.id
             GROUP BY t.id, t.name, t.slug, t.position
             ORDER BY t.position, t.name"
        );
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'slug' => (string) $r['slug'], 'total' => (int) $r['total'],
        ], $rows);
    }

    private function taxonBySlug(string $table, string $slug): ?array
    {
        $r = $this->one("SELECT id, name, slug FROM `$table` WHERE slug = :s", [':s' => $slug]);
        return $r ? ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'slug' => (string) $r['slug']] : null;
    }

    private function addTaxon(string $table, string $name, bool $routeSafe): ?int
    {
        $name = trim($name);
        $slug = self::slug($name);
        if ($name === '' || $slug === '') {
            return null;
        }
        if ($routeSafe && ($slug === 'marca' || preg_match('/^\d+-/', $slug))) {
            return null;
        }
        if ($this->taxonBySlug($table, $slug) !== null) {
            return null;
        }
        $pos = (int) ($this->one("SELECT COALESCE(MAX(position), -1) + 1 AS p FROM `$table`")['p'] ?? 0);
        $this->pdo->prepare("INSERT INTO `$table` (name, slug, position) VALUES (:n, :s, :p)")
            ->execute([':n' => $name, ':s' => $slug, ':p' => $pos]);
        return (int) $this->pdo->lastInsertId();
    }

    private function deleteTaxon(string $table, string $fk, int $id): bool
    {
        $used = (int) ($this->one("SELECT COUNT(*) AS n FROM used_vehicles WHERE `$fk` = :id", [':id' => $id])['n'] ?? 0);
        if ($used > 0) {
            return false;
        }
        $this->pdo->prepare("DELETE FROM `$table` WHERE id = :id")->execute([':id' => $id]);
        return true;
    }

    private function all(string $sql, array $params = []): array
    {
        if (!$this->pdo instanceof PDO) {
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

    private function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }
}
