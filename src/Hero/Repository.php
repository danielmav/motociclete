<?php

declare(strict_types=1);

namespace App\Hero;

use App\Database;
use PDO;
use Throwable;

/**
 * Reads the home-page hero slides from the `hero_slides` table (DB-driven so the
 * content can be managed from the admin later). Same discipline as the other
 * repositories: prepared statements, graceful degradation — if the table is
 * missing/unreachable it falls back to a built-in default set so the hero never
 * renders empty.
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

    // -- Admin CRUD -----------------------------------------------------------

    private const COLS = ['position', 'is_active', 'kicker', 'title_html', 'subtitle', 'cta_label', 'cta_href', 'image', 'image_alt', 'ghost', 'stats_json'];

    /** All slides incl. inactive, for the admin list. @return array<int,array<string,mixed>> */
    public function adminAll(): array
    {
        if (!$this->pdo) {
            return [];
        }
        try {
            return $this->pdo->query('SELECT * FROM hero_slides ORDER BY position, id')->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if (!$this->pdo) {
            return null;
        }
        try {
            $s = $this->pdo->prepare('SELECT * FROM hero_slides WHERE id = :id');
            $s->execute([':id' => $id]);
            return $s->fetch() ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Insert (id null) or update; returns the row id. @param array<string,mixed> $d */
    public function save(?int $id, array $d): int
    {
        $params = [];
        foreach (self::COLS as $c) {
            $params[':' . $c] = $d[$c] ?? null;
        }
        if ($id) {
            $set = implode(', ', array_map(static fn ($c) => "$c = :$c", self::COLS));
            $params[':id'] = $id;
            $this->pdo->prepare("UPDATE hero_slides SET $set WHERE id = :id")->execute($params);
            return $id;
        }
        $names = implode(', ', self::COLS);
        $ph = implode(', ', array_map(static fn ($c) => ":$c", self::COLS));
        $this->pdo->prepare("INSERT INTO hero_slides ($names) VALUES ($ph)")->execute($params);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        try {
            $this->pdo->prepare('DELETE FROM hero_slides WHERE id = :id')->execute([':id' => $id]);
        } catch (Throwable) {
            // ignore
        }
    }

    public function nextPosition(): int
    {
        try {
            return ((int) $this->pdo->query('SELECT COALESCE(MAX(position),0) FROM hero_slides')->fetchColumn()) + 1;
        } catch (Throwable) {
            return 1;
        }
    }

    /**
     * Active slides, ordered, shaped for `templates/home.twig`.
     * @return array<int,array<string,mixed>>
     */
    public function slides(): array
    {
        $rows = $this->fetch();
        if ($rows === []) {
            return self::defaults();
        }
        return array_map([$this, 'shape'], $rows);
    }

    /** @return array<int,array<string,mixed>> */
    private function fetch(): array
    {
        if (!$this->pdo instanceof PDO) {
            return [];
        }
        try {
            $stmt = $this->pdo->query(
                'SELECT kicker, title_html, subtitle, cta_label, cta_href, image, image_alt, ghost, stats_json
                 FROM hero_slides WHERE is_active = 1 ORDER BY position, id'
            );
            return $stmt ? $stmt->fetchAll() : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function shape(array $r): array
    {
        $stats = [];
        if (!empty($r['stats_json'])) {
            $decoded = json_decode((string) $r['stats_json'], true);
            if (is_array($decoded)) {
                $stats = $decoded;
            }
        }
        return [
            'kicker'    => $r['kicker'],
            'titleHtml' => $r['title_html'],
            'sub'       => $r['subtitle'],
            'cta'       => ['label' => $r['cta_label'], 'href' => $r['cta_href']],
            'image'     => $r['image'],
            'imageAlt'  => $r['image_alt'],
            'ghost'     => $r['ghost'],
            'stats'     => $stats !== [],
            'statsList' => $stats,
        ];
    }

    /**
     * Built-in fallback mirroring the seed (database/seed_hero.php), so the hero
     * still shows if the table is empty or the DB is down.
     * @return array<int,array<string,mixed>>
     */
    private static function defaults(): array
    {
        return [
            [
                'kicker' => '23 de ani pe două roți',
                'titleHtml' => 'Showroom-ul tău<br>Yamaha &amp; <span class="herov2__accent">CFMOTO</span>',
                'sub' => 'Dealer autorizat Yamaha și CFMOTO. Showroom Pipera, București — plus tot echipamentul și piesele potrivite pentru ea, din BikerShop.',
                'cta' => ['label' => 'Vezi gama 2026', 'href' => '/#modele'],
                'image' => '/media/hero/showroom.jpg', 'imageAlt' => 'Showroom Dual Motors Pipera', 'ghost' => '2026',
                'stats' => true,
                'statsList' => [
                    ['value' => '23', 'label' => 'ani experiență'],
                    ['value' => '2', 'label' => 'branduri ca dealer'],
                    ['value' => '7', 'label' => 'branduri importate'],
                ],
            ],
            [
                'kicker' => 'Mobilitate urbană',
                'titleHtml' => 'Orașul, pe<br><span class="herov2__accent">scuter Yamaha</span>',
                'sub' => 'De la TMAX la XMAX și NMAX — scutere Yamaha pentru o navetă rapidă, agilă și eficientă prin București.',
                'cta' => ['label' => 'Vezi scuterele', 'href' => '/yamaha/scutere'],
                'image' => '/media/hero/scuter.jpg', 'imageAlt' => 'Scuter Yamaha', 'ghost' => 'MAX',
                'stats' => false, 'statsList' => [],
            ],
            [
                'kicker' => 'Caracter îndrăzneț',
                'titleHtml' => 'Mai mult, pentru<br>mai puțin. <span class="herov2__accent">CFMOTO</span>',
                'sub' => 'Naked, sport, touring și heritage — gama CFMOTO îmbină design curajos cu un raport preț-dotări greu de egalat.',
                'cta' => ['label' => 'Descoperă CFMOTO', 'href' => '/cfmoto/naked'],
                'image' => '/media/hero/cfmoto.jpg', 'imageAlt' => 'Motocicletă CFMOTO', 'ghost' => 'CF',
                'stats' => false, 'statsList' => [],
            ],
            [
                'kicker' => 'Accesorii originale',
                'titleHtml' => 'Făcute pentru<br><span class="herov2__accent">Yamaha ta</span>',
                'sub' => 'Accesorii și echipament original Yamaha — fit perfect, calitate de fabrică și montaj în service-ul nostru.',
                'cta' => ['label' => 'Vezi accesoriile', 'href' => '/piese'],
                'image' => '/media/hero/accesorii.jpg', 'imageAlt' => 'Accesorii originale Yamaha', 'ghost' => 'OEM',
                'stats' => false, 'statsList' => [],
            ],
        ];
    }
}
