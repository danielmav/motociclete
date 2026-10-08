# Secțiunea „Rulate" — plan de implementare

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Secțiune publică `/rulate` cu anunțuri de vehicule second hand administrate din back-office, care expiră singure după 30 de zile.

**Architecture:** Modul propriu, separat de catalog: tabele `used_*`, `App\Used\Repository` (singurul loc care le atinge și singurul care știe regula de vizibilitate), un controller public și unul de admin, pe tiparul modulului Evenimente. Expirarea se evaluează la citire, fără cron.

**Tech Stack:** PHP 8.1, Slim 4, Twig 3, PDO (MySQL 8 local / MariaDB pe server), CSS + JS vanilla, GD pentru miniaturi.

**Spec:** `docs/superpowers/specs/2026-10-08-vehicule-rulate-design.md`

## Global Constraints

- PHP CLI: `C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe` (în plan: `$PHP`). `php` din PATH nu are `pdo_mysql`.
- Toate interogările = prepared statements. PDO are prepares native: **un placeholder numit nu se repetă într-o interogare**.
- PDO întoarce numerele ca string: `(int)` înainte de `===` și de chei de array.
- „Acum" se calculează în PHP pe `Europe/Bucharest` și se trimite ca parametru. Niciun `NOW()` în SQL-ul modulului.
- Durata unui anunț: 30 de zile (`Repository::DAYS`).
- Public = `is_active = 1` și `expires_at > acum`. Regula există o singură dată, în `Repository`.
- Preț: EUR cu TVA; RON prin `prices(eur, 'yamaha')` (curs BNR), indiferent de marcă. Preț gol = „Preț la cerere".
- Schema: doar `CREATE TABLE IF NOT EXISTS` + `ensure_column`; nimic distructiv.
- Fișierele din `tests/` sunt servite din web: orice test are garda `PHP_SAPI !== 'cli'` → 403 (vine din `tests/_nl.php`).
- Texte de interfață în română, cu diacritice. Fișierele se salvează UTF-8.
- Commit direct pe `main`. Niciun `tmp_*.php` în commit. Mesajele de commit se termină cu `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Twig escapează ghilimelele din `{{ cond ? ' attr="x"' }}` → atributele cu valoare se scriu cu `{% if %}`, nu cu ternar.
- După modificări în `app.css` / `app.js` / `admin.css` / `admin.js`: bump `?v=N` în `templates/layout.twig`, respectiv `templates/admin/layout.twig`.

## Review Focus

1. **Categorie al cărei slug arată ca un URL de anunț sau ca `marca`** (`125-cc`, `marca`): ruta de anunț sau cea de marcă ar prinde URL-ul și categoria ar da 404. Așteptat: adminul refuză crearea. Test în Task 2.
2. **Anunț fără nicio imagine:** cardul arată un placeholder, pagina anunțului nu are galerie, `og:image` cade pe imaginea implicită, JSON-LD nu are `image`. Test de `shape` în Task 2, verificare vizuală în Task 5.
3. **Câmpul video completat cu URL întreg, link scurt sau doar ID:** toate dau același ID de 11 caractere; un text oarecare dă `null`, nu un iframe stricat. Test în Task 2.
4. **Formular trimis doar cu telefon, fără email:** mesajul se salvează și pleacă, fără Reply-To; un email completat greșit e respins. Verificare în Task 6.
5. **`?p=` invalid sau peste numărul de pagini:** `?p=abc` și `?p=0` afișează prima pagină; `?p=99` dă 404, nu o listă goală indexabilă. Verificare în Task 5.

---

## Structura fișierelor

| Fișier | Rol |
|---|---|
| `database/schema_used.sql` (nou) | Tabelele `used_brands`, `used_categories`, `used_vehicles`, `used_images` |
| `database/seed_used.php` (nou) | Mărci și categorii inițiale |
| `database/migrate_admin.php` | Rulează schema; adaugă `rulate` în `site_messages.type` |
| `src/Used/Repository.php` (nou) | Tot accesul la `used_*`, regula de vizibilitate, forma datelor |
| `src/Used/Thumb.php` (nou) | Miniaturi 800 px pentru carduri |
| `src/Admin/UsedController.php` (nou) | CRUD anunțuri + mărci + categorii |
| `src/Controllers/UsedController.php` (nou) | Liste, anunț, 410 |
| `src/Controllers/ContactController.php` | Metoda `rulate` (formularul din coloană) |
| `src/Content/Repository.php` | `departmentBySlug()` |
| `templates/used/{index,show,gone}.twig`, `templates/partials/{_used_card,_used_sidebar}.twig` (noi) | Pagini publice |
| `templates/admin/used/{index,form}.twig` (noi) | Admin |
| `tests/UsedRepositoryTest.php`, `tests/UsedThumbTest.php` (noi) | Teste |

---

### Task 1: Schema, migrare, seed

**Files:**
- Create: `database/schema_used.sql`, `database/seed_used.php`
- Modify: `database/migrate_admin.php`, `database/schema_messages.sql:7`

**Interfaces:**
- Produces: tabelele `used_brands(id,name,slug,position)`, `used_categories(id,name,slug,position)`, `used_vehicles(id,title,slug,brand_id,category_id,price_eur,year,km,cc,description_html,video,is_active,expires_at,created_at,updated_at)`, `used_images(id,vehicle_id,filename,is_cover,position)`; valoarea `'rulate'` în `site_messages.type`.

- [ ] **Step 1: Scrie `database/schema_used.sql`**

```sql
-- Vehicule rulate (second hand). Modul separat de catalog. Vezi
-- docs/superpowers/specs/2026-10-08-vehicule-rulate-design.md

CREATE TABLE IF NOT EXISTS `used_brands` (
    `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`     VARCHAR(120) NOT NULL,
    `slug`     VARCHAR(140) NOT NULL,
    `position` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_usedbrand_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `used_categories` (
    `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`     VARCHAR(120) NOT NULL,
    `slug`     VARCHAR(140) NOT NULL,
    `position` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_usedcat_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `used_vehicles` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`            VARCHAR(255) NOT NULL,
    `slug`             VARCHAR(255) NOT NULL,
    `brand_id`         INT UNSIGNED NOT NULL,
    `category_id`      INT UNSIGNED NOT NULL,
    `price_eur`        DECIMAL(10,2) NULL,
    `year`             SMALLINT UNSIGNED NULL,
    `km`               INT UNSIGNED NULL,
    `cc`               SMALLINT UNSIGNED NULL,
    `description_html` MEDIUMTEXT NULL,
    `video`            VARCHAR(255) NULL,
    `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
    `expires_at`       DATETIME NOT NULL,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_used_public` (`is_active`, `expires_at`),
    KEY `idx_used_cat` (`category_id`),
    KEY `idx_used_brand` (`brand_id`),
    CONSTRAINT `fk_used_brand` FOREIGN KEY (`brand_id`) REFERENCES `used_brands` (`id`),
    CONSTRAINT `fk_used_cat` FOREIGN KEY (`category_id`) REFERENCES `used_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `used_images` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `vehicle_id` INT UNSIGNED NOT NULL,
    `filename`   VARCHAR(255) NOT NULL,
    `is_cover`   TINYINT(1) NOT NULL DEFAULT 0,
    `position`   INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_usedimg_vehicle` (`vehicle_id`, `position`),
    CONSTRAINT `fk_usedimg_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `used_vehicles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Leagă schema în `database/migrate_admin.php`**

După linia `run_sql_file($pdo, __DIR__ . '/schema_newsletter.sql');` adaugă:

```php
run_sql_file($pdo, __DIR__ . '/schema_used.sql');
```

Înainte de `echo "migrate_admin: done.\n";` adaugă:

```php
// Formularul din /rulate salvează în site_messages cu tipul `rulate`.
$type = (string) $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_messages' AND COLUMN_NAME = 'type'"
)->fetchColumn();
if ($type !== '' && !str_contains($type, "'rulate'")) {
    $pdo->exec("ALTER TABLE `site_messages` MODIFY `type` ENUM('oferta','test_ride','contact','rulate') NOT NULL");
    echo "  ~ site_messages.type += rulate\n";
}
```

În `database/schema_messages.sql`, linia 7 devine:

```sql
    `type`         ENUM('oferta','test_ride','contact','rulate') NOT NULL,
```

- [ ] **Step 3: Scrie `database/seed_used.php`**

```php
<?php
declare(strict_types=1);
// Mărci și categorii inițiale pentru /rulate. Idempotent: umple doar tabele goale.
//   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/seed_used.php

require __DIR__ . '/../vendor/autoload.php';
if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}
$settings = require __DIR__ . '/../config/settings.php';
$pdo = (new App\Database($settings['db']))->local();

$seed = [
    'used_brands'     => ['Yamaha', 'CFMOTO', 'Honda'],
    'used_categories' => ['Motociclete', 'Scutere', 'ATV'],
];
foreach ($seed as $table => $names) {
    if ((int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() > 0) {
        echo "  = $table: are deja date\n";
        continue;
    }
    $ins = $pdo->prepare("INSERT INTO `$table` (name, slug, position) VALUES (:n, :s, :p)");
    foreach ($names as $i => $name) {
        $ins->execute([':n' => $name, ':s' => slugify($name), ':p' => $i]);
    }
    echo "  + $table: " . count($names) . "\n";
}
echo "seed_used: done.\n";
```

- [ ] **Step 4: Rulează și verifică**

```bash
PHP=C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe
cd /c/laragon/www/motociclete
"$PHP" database/migrate_admin.php && "$PHP" database/seed_used.php && "$PHP" database/seed_used.php
/c/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe -uroot motociclete -e "SHOW TABLES LIKE 'used\_%'; SELECT slug FROM used_brands; SHOW COLUMNS FROM site_messages LIKE 'type'"
```

Expected: `migrate_admin: done.`, prima rulare de seed `+ used_brands: 3` / `+ used_categories: 3`, a doua `= … are deja date`; 4 tabele `used_*`; slug-urile `yamaha`, `cfmoto`, `honda`; enum-ul conține `'rulate'`.

- [ ] **Step 5: Commit**

```bash
git add database/schema_used.sql database/seed_used.php database/migrate_admin.php database/schema_messages.sql
git commit -m "feat(rulate): schema used_* + seed marci/categorii + tip mesaj rulate"
```

---

### Task 2: `App\Used\Repository`

**Files:**
- Create: `src/Used/Repository.php`, `tests/UsedRepositoryTest.php`
- Modify: `src/Bootstrap.php:95` (container)

**Interfaces:**
- Consumes: tabelele din Task 1; `App\Database::local()`; helperul global `slugify()`.
- Produces (toate publice):
  - `__construct(App\Database $db, string $mediaDir, ?callable $clock = null)` — `$clock` întoarce `'Y-m-d H:i:s'`; implicit ora `Europe/Bucharest`.
  - `const DAYS = 30`
  - `static youtubeId(?string $value): ?string`
  - `page(?int $categoryId, ?int $brandId, int $page, int $perPage): array{items: list<array>, total: int}`
  - `find(int $id): ?array` — orice stare; conține și `images: list<array{filename:string,url:string}>`
  - `isPublic(array $vehicle): bool`
  - `latest(int $limit, ?int $excludeId = null): array`
  - `categoriesWithCounts(): array`, `brandsWithCounts(): array` — rânduri `{id:int,name:string,slug:string,n:int}`
  - `categoryBySlug(string $slug): ?array`, `brandBySlug(string $slug): ?array`
  - `sitemapEntries(): array` — rânduri `{path:string,lastmod:?string}`
  - `adminList(?string $state = null): array`, `expired(): array`, `activeCount(): int`
  - `save(?int $id, array $data, array $filenames): int`, `deactivate(int $id): void`, `reactivate(int $id): void`, `delete(int $id): void`
  - `brands(): array`, `categories(): array` — toate, pentru admin: `{id,name,slug,total}`
  - `addBrand(string $name): ?int`, `addCategory(string $name): ?int` — `null` = refuzat
  - `deleteBrand(int $id): bool`, `deleteCategory(int $id): bool` — `false` = folosită
  - Forma unui vehicul (`shape`): `id:int, title, slug, url ('/rulate/{id}-{slug}'), brand_id:int, brand_name, brand_slug, category_id:int, category_name, category_slug, price_eur:?float, year:?int, km:?int, cc:?int, facts:string, description_html:string, video:string, video_id:?string, image:?string, thumb:?string, is_active:bool, expires_at:string, created_at:string, updated_at:string, state:'active'|'expired'|'inactive', days_left:int`

- [ ] **Step 1: Scrie testul care pică**

`tests/UsedRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/_nl.php';

use App\Used\Repository;

$pdo = nl_db()->local();
$pdo->beginTransaction();
$pdo->exec('DELETE FROM used_images');
$pdo->exec('DELETE FROM used_vehicles');
$pdo->exec('DELETE FROM used_brands');
$pdo->exec('DELETE FROM used_categories');
register_shutdown_function(static function () use ($pdo): void {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
});

$media = sys_get_temp_dir() . '/used-test-' . bin2hex(random_bytes(4));
mkdir($media . '/thumbs', 0775, true);

$now = '2026-10-08 12:00:00';
$clock = static function () use (&$now): string {
    return $now;
};
$repo = new Repository(nl_db(), $media, $clock);

echo "Taxonomii\n";
$yamaha = $repo->addBrand('Yamaha');
$honda  = $repo->addBrand('Honda');
$moto   = $repo->addCategory('Motociclete');
$scut   = $repo->addCategory('Scutere');
check('marca se creează', is_int($yamaha) && is_int($honda));
check('marca duplicată e refuzată', $repo->addBrand('yamaha') === null);
check('nume gol e refuzat', $repo->addBrand('  ') === null);
check('categoria „Marca” e refuzată (slug rezervat)', $repo->addCategory('Marca') === null);
check('categoria „125 cc” e refuzată (arată ca un URL de anunț)', $repo->addCategory('125 cc') === null);
check('categoria „Enduro 125” e acceptată', is_int($repo->addCategory('Enduro 125')));

echo "Creare și formă\n";
$base = ['title' => 'Yamaha MT-07 ABS', 'brand_id' => $yamaha, 'category_id' => $moto,
         'price_eur' => 6500, 'year' => 2021, 'km' => 12400, 'cc' => 689,
         'description_html' => '<p>Stare bună</p>', 'video' => 'https://youtu.be/dQw4w9WgXcQ'];
$a = $repo->save(null, $base, ['a.jpg', 'b.jpg']);
$v = $repo->find($a);
check('anunț nou e public', $v !== null && $repo->isPublic($v));
check('stare active, 30 de zile rămase', $v['state'] === 'active' && $v['days_left'] === 30);
check('expiră peste 30 de zile', $v['expires_at'] === '2026-11-07 12:00:00');
check('url cu id și slug', $v['url'] === '/rulate/' . $a . '-yamaha-mt-07-abs');
check('linia de date', $v['facts'] === '2021 · 12.400 km · 689 cc');
check('prima imagine e coperta', $v['image'] === '/media/rulate/a.jpg' && count($v['images']) === 2);
check('fără miniatură pe disc, thumb = imaginea', $v['thumb'] === '/media/rulate/a.jpg');
check('id video extras', $v['video_id'] === 'dQw4w9WgXcQ');
check('numerele sunt int/float', $v['year'] === 2021 && $v['price_eur'] === 6500.0 && $v['brand_id'] === $yamaha);

$bare = $repo->save(null, ['title' => 'Honda SH 150', 'brand_id' => $honda, 'category_id' => $scut], []);
$b = $repo->find($bare);
check('fără imagini: image și thumb sunt null', $b['image'] === null && $b['thumb'] === null && $b['images'] === []);
check('fără date: facts gol, preț null', $b['facts'] === '' && $b['price_eur'] === null && $b['video_id'] === null);

echo "Video\n";
check('URL watch', Repository::youtubeId('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=5') === 'dQw4w9WgXcQ');
check('URL shorts', Repository::youtubeId('https://youtube.com/shorts/dQw4w9WgXcQ') === 'dQw4w9WgXcQ');
check('doar ID', Repository::youtubeId('dQw4w9WgXcQ') === 'dQw4w9WgXcQ');
check('text oarecare → null', Repository::youtubeId('vezi pe facebook') === null);
check('gol → null', Repository::youtubeId('') === null && Repository::youtubeId(null) === null);

echo "Expirare\n";
$now = '2026-11-07 11:59:59';
check('cu o secundă înainte de termen e public', $repo->isPublic($repo->find($a)));
$now = '2026-11-07 12:00:00';
$v = $repo->find($a);
check('exact la termen nu mai e public', !$repo->isPublic($v) && $v['state'] === 'expired');
check('dispare din listă', $repo->page(null, null, 1, 12)['total'] === 0);
check('apare la expirate', array_column($repo->expired(), 'id') === [$bare, $a] || array_column($repo->expired(), 'id') === [$a, $bare]);

echo "Editarea nu prelungește\n";
$repo->save($a, ['title' => 'Yamaha MT-07 ABS (redus)'] + $base, ['b.jpg']);
$v = $repo->find($a);
check('termenul rămâne', $v['expires_at'] === '2026-11-07 12:00:00');
check('slug-ul rămâne cel de la creare', $v['slug'] === 'yamaha-mt-07-abs');
check('imaginile sunt înlocuite', count($v['images']) === 1 && $v['image'] === '/media/rulate/b.jpg');

echo "Reactivare și dezactivare\n";
$repo->reactivate($a);
$v = $repo->find($a);
check('reactivat: public, +30 de zile de acum', $repo->isPublic($v) && $v['expires_at'] === '2026-12-07 12:00:00');
$repo->deactivate($a);
$v = $repo->find($a);
check('dezactivat: nu e public, stare inactive', !$repo->isPublic($v) && $v['state'] === 'inactive');
check('dezactivatul nu apare la expirate', !in_array($a, array_column($repo->expired(), 'id'), true));
check('termenul nu s-a schimbat la dezactivare', $v['expires_at'] === '2026-12-07 12:00:00');
$repo->reactivate($a);
check('reactivarea unui dezactivat îl face public', $repo->isPublic($repo->find($a)));

echo "Liste și numărători\n";
$repo->reactivate($bare);
$all = $repo->page(null, null, 1, 12);
check('două publice', $all['total'] === 2 && count($all['items']) === 2);
check('filtru pe categorie', $repo->page($moto, null, 1, 12)['total'] === 1);
check('filtru pe marcă', $repo->page(null, $honda, 1, 12)['items'][0]['id'] === $bare);
check('paginare: pagina 2 din 1 pe pagină', count($repo->page(null, null, 2, 1)['items']) === 1);
$cats = $repo->categoriesWithCounts();
check('doar categoriile cu anunțuri publice', array_column($cats, 'slug') === ['motociclete', 'scutere'] && $cats[0]['n'] === 1);
$repo->deactivate($bare);
check('categoria fără anunțuri publice dispare', array_column($repo->categoriesWithCounts(), 'slug') === ['motociclete']);
check('la fel marca', array_column($repo->brandsWithCounts(), 'slug') === ['yamaha']);
check('activeCount', $repo->activeCount() === 1);
check('latest exclude anunțul curent', $repo->latest(6, $a) === []);
check('categoryBySlug', ($repo->categoryBySlug('motociclete')['id'] ?? null) === $moto && $repo->categoryBySlug('nu-exista') === null);
$paths = array_column($repo->sitemapEntries(), 'path');
check('sitemap: categorie, marcă, anunț', $paths === ['/rulate/motociclete', '/rulate/marca/yamaha', '/rulate/' . $a . '-yamaha-mt-07-abs']);
check('adminList pe stare', array_column($repo->adminList('inactive'), 'id') === [$bare] && count($repo->adminList()) === 2);

echo "Ștergere\n";
check('marca folosită nu se șterge', $repo->deleteBrand($yamaha) === false);
file_put_contents($media . '/b.jpg', 'x');
file_put_contents($media . '/thumbs/b.jpg', 'x');
$repo->delete($a);
check('anunțul dispare', $repo->find($a) === null);
check('fișierele dispar', !is_file($media . '/b.jpg') && !is_file($media . '/thumbs/b.jpg'));
check('marca nefolosită se șterge', $repo->deleteBrand($yamaha) === true);

@rmdir($media . '/thumbs');
@rmdir($media);
nl_done();
```

- [ ] **Step 2: Rulează, confirmă că pică**

```bash
"$PHP" tests/UsedRepositoryTest.php
```

Expected: `Fatal error: … Class "App\Used\Repository" not found`.

- [ ] **Step 3: Scrie `src/Used/Repository.php`**

```php
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
            $params[':slug'] = slugify((string) ($d['title'] ?? '')) ?: 'anunt';
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
        $slug = slugify($name);
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
```

- [ ] **Step 4: Rulează testul până trece**

```bash
"$PHP" tests/UsedRepositoryTest.php
```

Expected: ultima linie `52 verificări, 0 eșecuri` (numărul exact poate diferi cu ±1; contează `0 eșecuri` și exit 0).

Dacă un `check` întoarce gol fără eroare, suspectează un placeholder repetat: `all()` înghite `HY093`.

- [ ] **Step 5: Înregistrează în container**

În `src/Bootstrap.php`, după linia `'events'    => new Event\Repository($db),` adaugă:

```php
            'used'      => new Used\Repository($db, $root . '/media/rulate'),
```

Verifică: `curl -s -o /dev/null -w "%{http_code}\n" http://motociclete.test/health` → `200`.

- [ ] **Step 6: Commit**

```bash
git add src/Used/Repository.php tests/UsedRepositoryTest.php src/Bootstrap.php
git commit -m "feat(rulate): Used\\Repository - vizibilitate, expirare la 30 de zile, taxonomii"
```

---

### Task 3: Miniaturi pentru carduri (`App\Used\Thumb`)

Fotografiile de telefon au câțiva MB; o listă de 12 carduri nu le poate servi ca atare. La salvare se generează o miniatură de 800 px, pe care `Repository::shape()` o preferă deja când există (`thumb`).

**Files:**
- Create: `src/Used/Thumb.php`, `tests/UsedThumbTest.php`

**Interfaces:**
- Produces: `App\Used\Thumb::make(string $mediaDir, string $filename, int $maxWidth = 800): bool` — scrie `$mediaDir/thumbs/$filename` (același format ca sursa); `true` dacă miniatura există la final.

- [ ] **Step 1: Scrie testul care pică**

`tests/UsedThumbTest.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/_nl.php';

use App\Used\Thumb;

$dir = sys_get_temp_dir() . '/used-thumb-' . bin2hex(random_bytes(4));
mkdir($dir, 0775, true);

$big = imagecreatetruecolor(2000, 1500);
imagejpeg($big, $dir . '/mare.jpg', 90);
$small = imagecreatetruecolor(400, 300);
imagepng($small, $dir . '/mica.png');
file_put_contents($dir . '/stricat.jpg', 'nu sunt o imagine');

check('imagine mare: miniatură creată', Thumb::make($dir, 'mare.jpg') === true);
[$w, $h] = getimagesize($dir . '/thumbs/mare.jpg');
check('lățime 800, proporție păstrată', $w === 800 && $h === 600);
check('imagine mică: copiată la dimensiunea ei', Thumb::make($dir, 'mica.png') && getimagesize($dir . '/thumbs/mica.png')[0] === 400);
check('a doua rulare nu strică nimic', Thumb::make($dir, 'mare.jpg') === true);
check('fișier care nu e imagine → false', Thumb::make($dir, 'stricat.jpg') === false);
check('fișier lipsă → false', Thumb::make($dir, 'lipsa.jpg') === false);
check('cale cu ../ e redusă la numele fișierului', Thumb::make($dir, '../mare.jpg') === true);

array_map('unlink', glob($dir . '/thumbs/*') ?: []);
@rmdir($dir . '/thumbs');
array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);
nl_done();
```

- [ ] **Step 2: Rulează, confirmă că pică**

```bash
"$PHP" tests/UsedThumbTest.php
```

Expected: `Class "App\Used\Thumb" not found`.

- [ ] **Step 3: Scrie `src/Used/Thumb.php`**

```php
<?php

declare(strict_types=1);

namespace App\Used;

/**
 * Miniaturi pentru cardurile de rulate: /media/rulate/thumbs/<fișier>, max 800 px
 * lățime, același format ca sursa. Eșecul nu e fatal — cardul folosește originalul.
 */
final class Thumb
{
    public static function make(string $mediaDir, string $filename, int $maxWidth = 800): bool
    {
        $filename = basename($filename);
        $src = $mediaDir . '/' . $filename;
        $dst = $mediaDir . '/thumbs/' . $filename;
        if (is_file($dst)) {
            return true;
        }
        if (!is_file($src)) {
            return false;
        }
        $info = @getimagesize($src);
        if ($info === false) {
            return false;
        }
        [$w, $h, $type] = $info;
        $img = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
            IMAGETYPE_PNG  => @imagecreatefrompng($src),
            IMAGETYPE_WEBP => @imagecreatefromwebp($src),
            default        => false,
        };
        if (!$img) {
            return false;
        }
        if (!is_dir($mediaDir . '/thumbs')) {
            @mkdir($mediaDir . '/thumbs', 0775, true);
        }
        $nw = min($w, $maxWidth);
        $nh = (int) round($h * $nw / $w);
        $out = imagecreatetruecolor($nw, $nh);
        if ($type !== IMAGETYPE_JPEG) {
            imagealphablending($out, false);
            imagesavealpha($out, true);
        }
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $ok = match ($type) {
            IMAGETYPE_JPEG => imagejpeg($out, $dst, 82),
            IMAGETYPE_PNG  => imagepng($out, $dst, 6),
            IMAGETYPE_WEBP => imagewebp($out, $dst, 82),
        };
        imagedestroy($img);
        imagedestroy($out);
        return $ok && is_file($dst);
    }
}
```

- [ ] **Step 4: Rulează testul până trece**

```bash
"$PHP" tests/UsedThumbTest.php
```

Expected: `7 verificări, 0 eșecuri`.

- [ ] **Step 5: Commit**

```bash
git add src/Used/Thumb.php tests/UsedThumbTest.php
git commit -m "feat(rulate): miniaturi 800px pentru cardurile de anunt"
```

---

### Task 4: Admin — anunțuri, mărci, categorii, dashboard

**Files:**
- Create: `src/Admin/UsedController.php`, `templates/admin/used/index.twig`, `templates/admin/used/form.twig`
- Modify: `src/Admin/UploadController.php:65` (context), `src/Routes.php:155` (rute), `templates/admin/layout.twig:27` (meniu), `src/Admin/DashboardController.php`, `templates/admin/dashboard.twig`

**Interfaces:**
- Consumes: `$this->container['used']` (`App\Used\Repository`, Task 2), `App\Used\Thumb::make()` (Task 3), `BaseController` (`requireAuth`, `csrfOk`, `body`, `render`, `to`), macro-urile `w.editor` și `w.imgmgr` din `templates/admin/_widgets.twig`.
- Produces: rutele `{admin}/rulate…`; contextul de upload `rulate` → `/media/rulate`.

- [ ] **Step 1: Contextul de upload**

În `src/Admin/UploadController.php`, în `resolveSubdir`, după `case 'newsletter': return 'newsletter';` adaugă:

```php
            case 'rulate':
                return 'rulate';
```

- [ ] **Step 2: Scrie `src/Admin/UsedController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Admin;

use App\Used\Repository;
use App\Used\Thumb;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin pentru vehicule rulate (`used_*`): anunțuri + mărci + categorii.
 * Un anunț expiră singur după 30 de zile; „Reactivează" îi dă încă 30.
 */
final class UsedController extends BaseController
{
    private const FLASH = [
        'salvat'      => ['ok', 'Anunțul a fost salvat.'],
        'reactivat'   => ['ok', 'Anunțul e activ din nou, pentru 30 de zile.'],
        'dezactivat'  => ['ok', 'Anunțul a fost dezactivat.'],
        'sters'       => ['ok', 'Anunțul a fost șters.'],
        'tax-ok'      => ['ok', 'Lista a fost actualizată.'],
        'tax-refuzat' => ['err', 'Numele e gol, există deja sau nu poate fi folosit (o categorie nu se poate numi „Marca" și nu poate începe cu un număr urmat de cratimă).'],
        'tax-folosit' => ['err', 'Nu se poate șterge: există anunțuri care o folosesc.'],
    ];

    private function repo(): Repository
    {
        return $this->container['used'];
    }

    /** GET {base}/rulate[?stare=active|expired|inactive] */
    public function index(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $q = $request->getQueryParams();
        $state = in_array($q['stare'] ?? '', ['active', 'expired', 'inactive'], true) ? (string) $q['stare'] : '';
        return $this->render($response, 'admin/used/index.twig', [
            'active'     => 'used',
            'vehicles'   => $this->repo()->adminList($state ?: null),
            'state'      => $state,
            'brands'     => $this->repo()->brands(),
            'categories' => $this->repo()->categories(),
            'flash'      => self::FLASH[(string) ($q['msg'] ?? '')] ?? null,
        ]);
    }

    /** GET {base}/rulate/{id} — formular (id 0 = anunț nou). */
    public function form(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $id = (int) ($args['id'] ?? 0);
        $vehicle = $id > 0 ? $this->repo()->find($id) : null;
        if ($id > 0 && $vehicle === null) {
            return $this->to($response, '/rulate');
        }
        return $this->renderForm($response, $id, $vehicle, [], isset($request->getQueryParams()['ok']));
    }

    /** POST {base}/rulate/{id} */
    public function save(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, '/rulate');
        }
        $id = (int) ($args['id'] ?? 0);
        $images = array_values(array_filter(array_map(
            static fn ($f): string => basename(trim((string) $f)),
            (array) ($body['images'] ?? [])
        )));

        $data = [
            'title'            => trim((string) ($body['title'] ?? '')),
            'brand_id'         => (int) ($body['brand_id'] ?? 0),
            'category_id'      => (int) ($body['category_id'] ?? 0),
            'price_eur'        => $this->number($body['price_eur'] ?? ''),
            'year'             => $this->number($body['year'] ?? ''),
            'km'               => $this->number($body['km'] ?? ''),
            'cc'               => $this->number($body['cc'] ?? ''),
            'description_html' => trim((string) ($body['description_html'] ?? '')),
            'video'            => trim((string) ($body['video'] ?? '')) ?: null,
        ];

        $errors = $this->validate($data);
        if ($errors !== []) {
            // Valorile invalide (-1) nu se reafișează; restul formularului rămâne completat.
            $draft = array_map(static fn ($x) => $x === -1.0 ? null : $x, $data) + [
                'images' => array_map(static fn (string $f): array => ['filename' => $f], $images),
            ];
            return $this->renderForm($response->withStatus(422), $id, $draft, $errors, false);
        }
        foreach (['year', 'km', 'cc'] as $k) {
            $data[$k] = $data[$k] !== null ? (int) $data[$k] : null;
        }

        $vid = $this->repo()->save($id > 0 ? $id : null, $data, $images);
        $media = dirname(__DIR__, 2) . '/media/rulate';
        foreach ($images as $f) {
            Thumb::make($media, $f);
        }
        return $this->to($response, '/rulate/' . $vid . '?ok=1');
    }

    /** POST {base}/rulate/{id}/dezactiveaza */
    public function deactivate(Request $request, Response $response, array $args): Response
    {
        return $this->act($request, $response, $args, 'deactivate', 'dezactivat');
    }

    /** POST {base}/rulate/{id}/reactiveaza — câmpul `back=dashboard` întoarce la dashboard. */
    public function reactivate(Request $request, Response $response, array $args): Response
    {
        return $this->act($request, $response, $args, 'reactivate', 'reactivat');
    }

    /** POST {base}/rulate/{id}/delete */
    public function delete(Request $request, Response $response, array $args): Response
    {
        return $this->act($request, $response, $args, 'delete', 'sters');
    }

    /** POST {base}/rulate/marca */
    public function addBrand(Request $request, Response $response): Response
    {
        return $this->taxon($request, $response, fn (array $b): bool => $this->repo()->addBrand((string) ($b['name'] ?? '')) !== null, 'tax-refuzat');
    }

    /** POST {base}/rulate/marca/{id}/delete */
    public function deleteBrand(Request $request, Response $response, array $args): Response
    {
        return $this->taxon($request, $response, fn (): bool => $this->repo()->deleteBrand((int) ($args['id'] ?? 0)), 'tax-folosit');
    }

    /** POST {base}/rulate/categorie */
    public function addCategory(Request $request, Response $response): Response
    {
        return $this->taxon($request, $response, fn (array $b): bool => $this->repo()->addCategory((string) ($b['name'] ?? '')) !== null, 'tax-refuzat');
    }

    /** POST {base}/rulate/categorie/{id}/delete */
    public function deleteCategory(Request $request, Response $response, array $args): Response
    {
        return $this->taxon($request, $response, fn (): bool => $this->repo()->deleteCategory((int) ($args['id'] ?? 0)), 'tax-folosit');
    }

    private function act(Request $request, Response $response, array $args, string $method, string $msg): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if ($this->csrfOk($body)) {
            $this->repo()->{$method}((int) ($args['id'] ?? 0));
        }
        if (($body['back'] ?? '') === 'dashboard') {
            return $this->to($response, '');
        }
        return $this->to($response, '/rulate?msg=' . $msg);
    }

    private function taxon(Request $request, Response $response, callable $do, string $failMsg): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, '/rulate');
        }
        return $this->to($response, '/rulate?msg=' . ($do($body) ? 'tax-ok' : $failMsg));
    }

    /** @param array<string,mixed>|null $vehicle @param list<string> $errors */
    private function renderForm(Response $response, int $id, ?array $vehicle, array $errors, bool $saved): Response
    {
        return $this->render($response, 'admin/used/form.twig', [
            'active'     => 'used',
            'id'         => $id,
            'v'          => $vehicle,
            'errors'     => $errors,
            'saved'      => $saved,
            'brands'     => $this->repo()->brands(),
            'categories' => $this->repo()->categories(),
        ]);
    }

    /** Număr nenegativ din formular („12.400", „6500,50") sau null dacă e gol; -1 = invalid. */
    private function number(mixed $raw): ?float
    {
        $s = str_replace([' ', '.'], '', trim((string) $raw));
        $s = str_replace(',', '.', $s);
        if ($s === '') {
            return null;
        }
        return is_numeric($s) && (float) $s >= 0 ? (float) $s : -1.0;
    }

    /** @param array<string,mixed> $d @return list<string> */
    private function validate(array $d): array
    {
        $e = [];
        if ($d['title'] === '') {
            $e[] = 'Titlul e obligatoriu.';
        }
        $ids = fn (array $rows): array => array_column($rows, 'id');
        if (!in_array($d['brand_id'], $ids($this->repo()->brands()), true)) {
            $e[] = 'Alege marca.';
        }
        if (!in_array($d['category_id'], $ids($this->repo()->categories()), true)) {
            $e[] = 'Alege categoria.';
        }
        foreach (['price_eur' => 'Prețul', 'km' => 'Kilometrajul', 'cc' => 'Cilindreea'] as $k => $label) {
            if ($d[$k] !== null && $d[$k] < 0) {
                $e[] = $label . ' trebuie să fie un număr pozitiv.';
            }
        }
        $maxYear = (int) date('Y') + 1;
        if ($d['year'] !== null && ($d['year'] < 1950 || $d['year'] > $maxYear)) {
            $e[] = 'Anul trebuie să fie între 1950 și ' . $maxYear . '.';
        }
        // Limitele coloanelor (INT / SMALLINT / DECIMAL(10,2)) — altfel INSERT-ul ar da 500.
        foreach (['km' => 4000000, 'cc' => 65000, 'price_eur' => 9999999] as $k => $max) {
            if ($d[$k] !== null && $d[$k] > $max) {
                $e[] = 'Valoare prea mare la ' . ['km' => 'kilometri', 'cc' => 'cilindree', 'price_eur' => 'preț'][$k] . '.';
            }
        }
        return $e;
    }
}
```

Notă pentru câmpul preț: `number()` scoate punctele (separator de mii în română), deci „6.500" = 6500. Textul de ajutor din formular spune asta.

- [ ] **Step 3: Rutele de admin**

În `src/Routes.php`, după blocul `// Events` (după linia cu `EventController', 'delete'`) adaugă:

```php
    // Rulate (vehicule second hand) — anunțuri + mărci + categorii
    $app->get($adminBase . '/rulate',                              $adminCtl('UsedController', 'index'));
    $app->post($adminBase . '/rulate/marca',                       $adminCtl('UsedController', 'addBrand'));
    $app->post($adminBase . '/rulate/marca/{id:[0-9]+}/delete',    $adminCtl('UsedController', 'deleteBrand'));
    $app->post($adminBase . '/rulate/categorie',                   $adminCtl('UsedController', 'addCategory'));
    $app->post($adminBase . '/rulate/categorie/{id:[0-9]+}/delete', $adminCtl('UsedController', 'deleteCategory'));
    $app->get($adminBase . '/rulate/{id:[0-9]+}',                  $adminCtl('UsedController', 'form'));
    $app->post($adminBase . '/rulate/{id:[0-9]+}',                 $adminCtl('UsedController', 'save'));
    $app->post($adminBase . '/rulate/{id:[0-9]+}/dezactiveaza',    $adminCtl('UsedController', 'deactivate'));
    $app->post($adminBase . '/rulate/{id:[0-9]+}/reactiveaza',     $adminCtl('UsedController', 'reactivate'));
    $app->post($adminBase . '/rulate/{id:[0-9]+}/delete',          $adminCtl('UsedController', 'delete'));
```

În `templates/admin/layout.twig`, după linia `{k:'events',    label:'Evenimente', href:'/evenimente'},` adaugă:

```twig
            {k:'used',      label:'Rulate',     href:'/rulate'},
```

- [ ] **Step 4: Scrie `templates/admin/used/index.twig`**

```twig
{% extends 'admin/layout.twig' %}
{% set active = 'used' %}
{% block title %}Rulate{% endblock %}
{% block actions %}<a class="adm-btn adm-btn--primary" href="{{ admin_base }}/rulate/0">+ Anunț nou</a>{% endblock %}

{% macro taxa(title, placeholder, action, rows, admin_base, csrf) %}
    <div class="adm-panel">
        <h2 style="font-size:1.05rem">{{ title }}</h2>
        <table class="adm-table" style="margin-bottom:1rem">
            <tbody>
            {% for t in rows %}
                <tr>
                    <td>{{ t.name }} <span class="adm-muted">({{ t.total }})</span></td>
                    <td style="text-align:right">
                        {% if t.total == 0 %}
                            <form method="post" action="{{ admin_base }}/rulate/{{ action }}/{{ t.id }}/delete" style="display:inline" onsubmit="return confirm('Ștergi „{{ t.name }}”?')">
                                <input type="hidden" name="_csrf" value="{{ csrf }}">
                                <button class="adm-btn adm-btn--sm adm-btn--danger" type="submit">×</button>
                            </form>
                        {% endif %}
                    </td>
                </tr>
            {% else %}
                <tr><td class="adm-muted">Nimic încă.</td></tr>
            {% endfor %}
            </tbody>
        </table>
        <form method="post" action="{{ admin_base }}/rulate/{{ action }}" class="adm-form">
            <input type="hidden" name="_csrf" value="{{ csrf }}">
            <label>Adaugă <input type="text" name="name" placeholder="{{ placeholder }}" required></label>
            <div><button class="adm-btn adm-btn--primary adm-btn--sm" type="submit">Adaugă</button></div>
        </form>
    </div>
{% endmacro %}

{% block content %}
{% if flash %}<div class="adm-flash adm-flash--{{ flash[0] }}">{{ flash[1] }}</div>{% endif %}

<p style="margin-bottom:1rem">
    {% for key, label in {'': 'Toate', 'active': 'Active', 'expired': 'Expirate', 'inactive': 'Dezactivate'} %}
        <a class="adm-btn adm-btn--sm{{ state == key ? ' adm-btn--primary' }}" href="{{ admin_base }}/rulate{{ key ? '?stare=' ~ key }}">{{ label }}</a>
    {% endfor %}
</p>

<div style="display:grid;grid-template-columns:minmax(0,3fr) minmax(220px,1fr);gap:1.4rem;align-items:start">
    <table class="adm-table">
        <thead><tr><th></th><th>Anunț</th><th>Preț</th><th>Stare</th><th></th></tr></thead>
        <tbody>
        {% for v in vehicles %}
            <tr>
                <td>{% if v.thumb %}<img class="adm-thumb" src="{{ base }}{{ v.thumb }}" alt="">{% endif %}</td>
                <td>
                    <strong>{{ v.title }}</strong><br>
                    <span class="adm-muted">{{ v.brand_name }} · {{ v.category_name }}{{ v.facts ? ' · ' ~ v.facts }}</span>
                </td>
                <td class="adm-muted">{{ v.price_eur is not null ? v.price_eur|money : 'la cerere' }}</td>
                <td>
                    {% if v.state == 'active' %}
                        <span class="adm-badge adm-badge--on">activ</span><br>
                        <span class="adm-muted">expiră în {{ v.days_left }} {{ v.days_left == 1 ? 'zi' : 'zile' }}</span>
                    {% elseif v.state == 'expired' %}
                        <span class="adm-badge adm-badge--off" style="background:#fff4d6;color:#8a5a00">expirat</span><br>
                        <span class="adm-muted">din {{ v.expires_at|date('d.m.Y') }}</span>
                    {% else %}
                        <span class="adm-badge adm-badge--off">dezactivat</span>
                    {% endif %}
                </td>
                <td style="white-space:nowrap">
                    <a class="adm-btn adm-btn--sm" href="{{ admin_base }}/rulate/{{ v.id }}">Editează</a>
                    {% if v.state == 'active' %}
                        <a class="adm-btn adm-btn--sm" href="{{ base }}{{ v.url }}" target="_blank" rel="noopener">Vezi</a>
                        <form method="post" action="{{ admin_base }}/rulate/{{ v.id }}/dezactiveaza" style="display:inline">
                            <input type="hidden" name="_csrf" value="{{ csrf }}">
                            <button class="adm-btn adm-btn--sm" type="submit">Dezactivează</button>
                        </form>
                    {% else %}
                        <form method="post" action="{{ admin_base }}/rulate/{{ v.id }}/reactiveaza" style="display:inline">
                            <input type="hidden" name="_csrf" value="{{ csrf }}">
                            <button class="adm-btn adm-btn--sm adm-btn--primary" type="submit">Reactivează 30 de zile</button>
                        </form>
                    {% endif %}
                    <form method="post" action="{{ admin_base }}/rulate/{{ v.id }}/delete" style="display:inline" onsubmit="return confirm('Ștergi definitiv anunțul și imaginile lui?')">
                        <input type="hidden" name="_csrf" value="{{ csrf }}">
                        <button class="adm-btn adm-btn--sm adm-btn--danger" type="submit">Șterge</button>
                    </form>
                </td>
            </tr>
        {% else %}
            <tr><td colspan="5" class="adm-muted">Niciun anunț{{ state ? ' în această stare' }}.</td></tr>
        {% endfor %}
        </tbody>
    </table>

    <div>
        {{ _self.taxa('Mărci', 'ex. Kawasaki', 'marca', brands, admin_base, csrf) }}
        {{ _self.taxa('Categorii', 'ex. Enduro', 'categorie', categories, admin_base, csrf) }}
    </div>
</div>
{% endblock %}
```

Macro-urile nu văd variabilele apelantului, de aceea `admin_base` și `csrf` sunt pasate ca argumente.

- [ ] **Step 5: Scrie `templates/admin/used/form.twig`**

```twig
{% extends 'admin/layout.twig' %}
{% import 'admin/_widgets.twig' as w %}
{% set active = 'used' %}
{% block title %}{{ id > 0 ? 'Editează anunț' : 'Anunț nou' }}{% endblock %}

{% block content %}
{% if saved %}<div class="adm-flash adm-flash--ok">Anunțul a fost salvat.</div>{% endif %}
{% for err in errors %}<div class="adm-flash adm-flash--err">{{ err }}</div>{% endfor %}

{% if id > 0 and v.state is defined %}
    <div class="adm-flash adm-flash--{{ v.state == 'active' ? 'ok' : 'warn' }}">
        {% if v.state == 'active' %}Activ pe site. Expiră în {{ v.days_left }} {{ v.days_left == 1 ? 'zi' : 'zile' }} ({{ v.expires_at|date('d.m.Y H:i') }}).
        {% elseif v.state == 'expired' %}Expirat din {{ v.expires_at|date('d.m.Y') }}. Nu mai apare pe site până îl reactivezi din lista de anunțuri.
        {% else %}Dezactivat. Nu apare pe site până îl reactivezi din lista de anunțuri.{% endif %}
    </div>
{% endif %}

<form class="adm-form" method="post" action="{{ admin_base }}/rulate/{{ id }}">
    <input type="hidden" name="_csrf" value="{{ csrf }}">

    <label>Titlu
        <input type="text" name="title" value="{{ v.title|default('') }}" placeholder="ex. Yamaha MT-07 ABS" required>
    </label>

    <div class="adm-grid3">
        <label>Marcă
            <select name="brand_id" required>
                <option value="">Alege…</option>
                {% for b in brands %}<option value="{{ b.id }}"{{ (b.id ~ '') == (v.brand_id|default('') ~ '') ? ' selected' }}>{{ b.name }}</option>{% endfor %}
            </select>
        </label>
        <label>Categorie
            <select name="category_id" required>
                <option value="">Alege…</option>
                {% for c in categories %}<option value="{{ c.id }}"{{ (c.id ~ '') == (v.category_id|default('') ~ '') ? ' selected' }}>{{ c.name }}</option>{% endfor %}
            </select>
        </label>
        <label>Preț (EUR, cu TVA) <span class="adm-help">gol = „la cerere”; fără zecimale</span>
            <input type="text" inputmode="numeric" name="price_eur" value="{{ v.price_eur is defined and v.price_eur is not null ? v.price_eur|round }}">
        </label>
    </div>

    <div class="adm-grid3">
        <label>An fabricație
            <input type="text" inputmode="numeric" name="year" value="{{ v.year|default('') }}">
        </label>
        <label>Kilometri
            <input type="text" inputmode="numeric" name="km" value="{{ v.km is defined and v.km is not null ? v.km }}">
        </label>
        <label>Cilindree (cc)
            <input type="text" inputmode="numeric" name="cc" value="{{ v.cc|default('') }}">
        </label>
    </div>

    <div class="adm-fieldset">
        <span class="adm-fieldset__t">Descriere</span>
        <div style="margin-top:.6rem">{{ w.editor('description_html', v.description_html|default('')) }}</div>
    </div>

    <div class="adm-fieldset">
        <span class="adm-fieldset__t">Imagini</span>
        <span class="adm-help">Prima imagine e coperta. Trage-le ca să le reordonezi.</span>
        <div style="margin-top:.6rem">{{ w.imgmgr('images', 'rulate', 'rulate', false, v.images|default([])) }}</div>
    </div>

    <label>Video YouTube <span class="adm-help">link sau ID; opțional</span>
        <input type="text" name="video" value="{{ v.video|default('') }}" placeholder="https://youtu.be/…">
    </label>

    <div class="adm-actions">
        <button class="adm-btn adm-btn--primary" type="submit">Salvează</button>
        <a class="adm-btn" href="{{ admin_base }}/rulate">Înapoi</a>
    </div>
</form>
{% endblock %}
```

- [ ] **Step 6: Dashboard**

În `src/Admin/DashboardController.php`, în `index()`, înlocuiește apelul `render` cu:

```php
        $used = $this->container['used'];
        return $this->render($response, 'admin/dashboard.twig', [
            'stats' => [
                'products' => $this->count('SELECT COUNT(*) FROM products WHERE is_active = 1'),
                'news'     => $this->count('SELECT COUNT(*) FROM news WHERE is_active = 1'),
                'events'   => $this->count('SELECT COUNT(*) FROM events'),
                'used'     => $used->activeCount(),
                'messages' => $this->count("SELECT COUNT(*) FROM site_messages"),
                'requests' => $this->count("SELECT COUNT(*) FROM service_requests WHERE status = 'nou'"),
            ],
            'used_expired' => $used->expired(),
        ]);
```

În `templates/admin/dashboard.twig`, după cardul „Evenimente" adaugă:

```twig
        <a class="adm-card" href="{{ admin_base }}/rulate">
            <span class="adm-card__n">{{ stats.used }}</span>
            <span class="adm-card__l">Rulate active</span>
        </a>
```

și, între `</div>` de la `.adm-cards` și panoul „Bun venit", adaugă:

```twig
    {% if used_expired|length %}
        <div class="adm-panel" style="border-color:#e0b100">
            <h2>Anunțuri rulate expirate ({{ used_expired|length }})</h2>
            <p class="adm-muted">Au trecut 30 de zile și nu mai apar pe site. Reactivează-le pe cele încă de vânzare.</p>
            <table class="adm-table">
                <tbody>
                {% for v in used_expired %}
                    <tr>
                        <td><a href="{{ admin_base }}/rulate/{{ v.id }}"><strong>{{ v.title }}</strong></a><br>
                            <span class="adm-muted">{{ v.brand_name }} · expirat din {{ v.expires_at|date('d.m.Y') }}</span></td>
                        <td style="text-align:right;white-space:nowrap">
                            <form method="post" action="{{ admin_base }}/rulate/{{ v.id }}/reactiveaza" style="display:inline">
                                <input type="hidden" name="_csrf" value="{{ csrf }}">
                                <input type="hidden" name="back" value="dashboard">
                                <button class="adm-btn adm-btn--sm adm-btn--primary" type="submit">Reactivează 30 de zile</button>
                            </form>
                            <form method="post" action="{{ admin_base }}/rulate/{{ v.id }}/dezactiveaza" style="display:inline">
                                <input type="hidden" name="_csrf" value="{{ csrf }}">
                                <input type="hidden" name="back" value="dashboard">
                                <button class="adm-btn adm-btn--sm" type="submit">Nu mai e de vânzare</button>
                            </form>
                        </td>
                    </tr>
                {% endfor %}
                </tbody>
            </table>
        </div>
    {% endif %}
```

„Nu mai e de vânzare" dezactivează anunțul, deci îl scoate din casetă fără să-l șteargă.

- [ ] **Step 7: Verifică cu curl (sesiune de admin)**

```bash
cd /c/laragon/www/motociclete
TMPPASS=$(openssl rand -hex 12)
"$PHP" database/seed_admin_user.php __tmp "$TMPPASS"
A=http://motociclete.test$(grep -m1 '^ADMIN_PATH=' .env | cut -d= -f2- | tr -d "'\r"); [ "$A" = "http://motociclete.test" ] && A=http://motociclete.test/dm-control
J=storage/cache/used.jar
T=$(curl -s -c $J "$A/login" | grep -o 'name="_csrf" value="[^"]*"' | cut -d'"' -f4)
curl -s -o /dev/null -b $J -c $J -d "_csrf=$T&username=__tmp&password=$TMPPASS" "$A/login"
T=$(curl -s -b $J "$A/rulate" | grep -o 'window.CSRF="[^"]*"' | cut -d'"' -f2)
B=$(curl -s -b $J "$A/rulate/0" | grep -o '<option value="[0-9]*"' | head -1 | grep -o '[0-9]*')
echo "lista: $(curl -s -o /dev/null -w '%{http_code}' -b $J "$A/rulate")"
echo "fara titlu: $(curl -s -o /dev/null -w '%{http_code}' -b $J -d "_csrf=$T&title=&brand_id=$B&category_id=$B" "$A/rulate/0")"
echo "an gresit: $(curl -s -o /dev/null -w '%{http_code}' -b $J -d "_csrf=$T&title=Test&brand_id=$B&category_id=$B&year=1800" "$A/rulate/0")"
echo "valid: $(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b $J -d "_csrf=$T&title=Test rulat&brand_id=$B&category_id=$B&year=2020&km=12.400&price_eur=6.500" "$A/rulate/0")"
echo "categorie Marca: $(curl -s -o /dev/null -w '%{redirect_url}' -b $J -d "_csrf=$T&name=Marca" "$A/rulate/categorie")"
echo "fara csrf: $(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b $J -d "title=X&brand_id=$B&category_id=$B" "$A/rulate/0")"
```

Expected:
```
lista: 200
fara titlu: 422
an gresit: 422
valid: 303 …/rulate/<id>?ok=1
categorie Marca: …/rulate?msg=tax-refuzat
fara csrf: 303 …/rulate
```

Apoi verifică în DB că „6.500" a devenit 6500 și „12.400" 12400:

```bash
/c/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe -uroot motociclete -e "SELECT id,title,price_eur,km,year,is_active,expires_at FROM used_vehicles"
```

Deschide în browser `…/rulate/<id>`, încarcă două imagini, salvează; confirmă că există `media/rulate/thumbs/<fișier>`. Schimbă în DB `expires_at` în trecut (`UPDATE used_vehicles SET expires_at='2020-01-01' WHERE id=<id>`), deschide dashboardul: caseta „Anunțuri rulate expirate (1)" apare; „Reactivează 30 de zile" o face să dispară și întoarce la dashboard.

La final: `rm storage/cache/used.jar` și șterge userul `__tmp` (`DELETE FROM admin_users WHERE username='__tmp'`). Lasă anunțul de test pentru Task 5.

- [ ] **Step 8: Commit**

```bash
git add src/Admin/UsedController.php src/Admin/UploadController.php src/Admin/DashboardController.php src/Routes.php templates/admin/used templates/admin/layout.twig templates/admin/dashboard.twig
git commit -m "feat(rulate): admin anunturi + marci/categorii + caseta expirate in dashboard"
```

---

### Task 5: Pagini publice, SEO, navigare

**Files:**
- Create: `src/Controllers/UsedController.php`, `templates/used/index.twig`, `templates/used/show.twig`, `templates/used/gone.twig`, `templates/partials/_used_card.twig`, `templates/partials/_used_sidebar.twig`
- Modify: `src/Content/Repository.php:78` (`departmentBySlug`), `src/Routes.php` (rute publice + `legacyMap`), `src/Support/NavigationV2.php:111`, `templates/partials/footer.twig:31`, `src/Controllers/SeoController.php:64`, `assets/css/app.css`, `templates/layout.twig:93`

**Interfaces:**
- Consumes: `App\Used\Repository` (Task 2); funcția Twig `prices(eur, brand)` → `{eur, ron, eur_raw, ron_raw}`; blocurile din `layout.twig`: `title`, `description`, `meta_robots`, `og_type`, `head_extra`, `content`; variabilele `canonical_path`, `og_image`; JS existent: `[data-zoom]` + `[data-zoom-group]`, `[data-video]` + `data-video-id` + `[data-video-play]`, `[data-ajax-form]` + `[data-form-err]` + `[data-form-thanks]`.
- Produces: `App\Content\Repository::departmentBySlug(string $slug): ?array` (rând din `contact_departments` al cărui `slugify(label)` e `$slug`); rutele `/rulate`, `/rulate/marca/{marca}`, `/rulate/{id}-{slug}`, `/rulate/{cat}`; partialul `_used_sidebar.twig` cu un formular care trimite la `{{ base }}/api/lead/rulate` (endpointul vine în Task 6).

- [ ] **Step 1: `departmentBySlug` în `src/Content/Repository.php`**

După metoda `departments()` adaugă:

```php
    /** Departamentul a cărui etichetă dă slug-ul cerut (ex. „Vânzări moto" → vanzari-moto). */
    public function departmentBySlug(string $slug): ?array
    {
        foreach ($this->departments() as $row) {
            if (slugify((string) $row['label']) === $slug) {
                return $row;
            }
        }
        return null;
    }
```

- [ ] **Step 2: Scrie `src/Controllers/UsedController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Used\Repository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Secțiunea publică „Rulate": liste (toate / categorie / marcă) + pagina anunțului.
 * Un anunț expirat sau dezactivat răspunde 410 cu alternative.
 */
final class UsedController
{
    private const PER_PAGE = 12;
    private const SALES_SLUG = 'vanzari-moto';

    private Repository $repo;
    private string $base;
    private string $appUrl;
    /** @var array<string,mixed> */
    private array $container;

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->container = $container;
        $this->repo   = $container['used'];
        $this->base   = (string) ($container['settings']['app']['base_path'] ?? '');
        $this->appUrl = rtrim((string) ($container['settings']['app']['url'] ?? ''), '/');
    }

    /** GET /rulate */
    public function index(Request $request, Response $response): Response
    {
        return $this->renderList($request, $response, null, null);
    }

    /** GET /rulate/{cat} */
    public function category(Request $request, Response $response, array $args): Response
    {
        $cat = $this->repo->categoryBySlug((string) ($args['cat'] ?? ''));
        if ($cat === null) {
            throw new HttpNotFoundException($request);
        }
        return $this->renderList($request, $response, $cat, null);
    }

    /** GET /rulate/marca/{marca} */
    public function brand(Request $request, Response $response, array $args): Response
    {
        $brand = $this->repo->brandBySlug((string) ($args['marca'] ?? ''));
        if ($brand === null) {
            throw new HttpNotFoundException($request);
        }
        return $this->renderList($request, $response, null, $brand);
    }

    /** GET /rulate/{id}-{slug} */
    public function show(Request $request, Response $response, array $args): Response
    {
        $v = $this->repo->find((int) ($args['id'] ?? 0));
        if ($v === null) {
            throw new HttpNotFoundException($request);
        }
        if (!$this->repo->isPublic($v)) {
            return $this->twig->render($response->withStatus(410), 'used/gone.twig', $this->sidebar() + [
                'v'              => $v,
                'others'         => $this->repo->latest(6, $v['id']),
                'canonical_path' => $v['url'],
            ]);
        }
        if ((string) ($args['slug'] ?? '') !== $v['slug']) {
            return $response->withHeader('Location', $this->base . $v['url'])->withStatus(301);
        }

        $price = $v['price_eur'] !== null ? (int) round($v['price_eur']) : null;
        $title = $v['title'] . ($v['year'] ? ' (' . $v['year'] . ')' : '') . ' — rulat'
            . ($price !== null ? ', ' . number_format($price, 0, ',', '.') . ' EUR' : '');
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($v['description_html']), ENT_QUOTES, 'UTF-8')));
        $desc = trim(($v['facts'] !== '' ? $v['facts'] . '. ' : '') . mb_substr($text, 0, 140));
        if ($desc === '') {
            $desc = $v['brand_name'] . ' rulat, de vânzare la Dual Motors, showroom Pipera, București.';
        }

        return $this->twig->render($response, 'used/show.twig', $this->sidebar() + [
            'v'              => $v,
            'others'         => $this->repo->latest(6, $v['id']),
            'seo'            => ['title' => $title, 'description' => $desc],
            'canonical_path' => $v['url'],
            'og_image'       => $v['image'],
            'ld_json'        => $this->json([$this->vehicleLd($v, $price, $desc), $this->crumbsLd([
                ['Rulate', '/rulate'],
                [$v['category_name'], '/rulate/' . $v['category_slug']],
                [$v['title'], null],
            ])]),
        ]);
    }

    /** @param array<string,mixed>|null $cat @param array<string,mixed>|null $brand */
    private function renderList(Request $request, Response $response, ?array $cat, ?array $brand): Response
    {
        $page = max(1, (int) ($request->getQueryParams()['p'] ?? 1));
        $res = $this->repo->page($cat['id'] ?? null, $brand['id'] ?? null, $page, self::PER_PAGE);
        $pages = max(1, (int) ceil($res['total'] / self::PER_PAGE));
        if ($page > $pages) {
            throw new HttpNotFoundException($request);
        }

        $path = '/rulate';
        $heading = 'Vehicule rulate';
        $seo = [
            'title'       => 'Motociclete rulate și second hand — Dual Motors',
            'description' => 'Motociclete, scutere și ATV-uri rulate, verificate de Dual Motors. Vezi anunțurile active și scrie-ne direct din pagină — showroom Pipera, București.',
        ];
        $crumbs = [['Rulate', null]];
        if ($cat) {
            $path = '/rulate/' . $cat['slug'];
            $heading = $cat['name'] . ' rulate';
            $seo = [
                'title'       => $cat['name'] . ' rulate — Dual Motors',
                'description' => $cat['name'] . ' rulate de vânzare la Dual Motors: anunțuri active cu an, kilometraj și preț. Showroom Pipera, București.',
            ];
            $crumbs = [['Rulate', '/rulate'], [$cat['name'], null]];
        } elseif ($brand) {
            $path = '/rulate/marca/' . $brand['slug'];
            $heading = $brand['name'] . ' rulate';
            $seo = [
                'title'       => $brand['name'] . ' rulate, second hand — Dual Motors',
                'description' => 'Vehicule ' . $brand['name'] . ' rulate de vânzare la Dual Motors: anunțuri active cu an, kilometraj și preț. Showroom Pipera, București.',
            ];
            $crumbs = [['Rulate', '/rulate'], [$brand['name'], null]];
        }
        if ($page > 1) {
            $seo['title'] .= ' — pagina ' . $page;
        }

        $items = [];
        foreach ($res['items'] as $i => $v) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => $this->appUrl . $this->base . $v['url'], 'name' => $v['title']];
        }

        return $this->twig->render($response, 'used/index.twig', $this->sidebar() + [
            'vehicles'         => $res['items'],
            'total'            => $res['total'],
            'page'             => $page,
            'pages'            => $pages,
            'list_path'        => $path,
            'heading'          => $heading,
            'seo'              => $seo,
            'current_category' => $cat['slug'] ?? '',
            'current_brand'    => $brand['slug'] ?? '',
            // O categorie/marcă fără anunțuri publice nu merită indexată.
            'noindex'          => ($cat || $brand) && $res['total'] === 0,
            'canonical_path'   => $path . ($page > 1 ? '?p=' . $page : ''),
            'ld_json'          => $this->json([
                ['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => $heading, 'itemListElement' => $items],
                $this->crumbsLd($crumbs),
            ]),
        ]);
    }

    /** Datele coloanei din dreapta, comune tuturor paginilor. */
    private function sidebar(): array
    {
        $dept = $this->container['content']->departmentBySlug(self::SALES_SLUG);
        return [
            'categories' => $this->repo->categoriesWithCounts(),
            'brands'     => $this->repo->brandsWithCounts(),
            'sales'      => [
                'phone' => (string) ($dept['phone'] ?? '') ?: '0722 354 437',
                'email' => (string) ($dept['email'] ?? '') ?: 'showroom@motociclete.com.ro',
            ],
        ];
    }

    /** @param array<string,mixed> $v */
    private function vehicleLd(array $v, ?int $price, string $desc): array
    {
        $url = $this->appUrl . $this->base . $v['url'];
        $ld = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Vehicle',
            'name'        => $v['title'],
            'description' => $desc,
            'url'         => $url,
            'brand'       => ['@type' => 'Brand', 'name' => $v['brand_name']],
            'itemCondition' => 'https://schema.org/UsedCondition',
        ];
        if (!empty($v['images'])) {
            $ld['image'] = array_map(fn (array $i): string => $this->appUrl . $this->base . $i['url'], $v['images']);
        }
        if ($v['year']) {
            $ld['vehicleModelDate'] = (string) $v['year'];
        }
        if ($v['km'] !== null) {
            $ld['mileageFromOdometer'] = ['@type' => 'QuantitativeValue', 'value' => $v['km'], 'unitCode' => 'KMT'];
        }
        if ($v['cc']) {
            $ld['vehicleEngine'] = ['@type' => 'EngineSpecification', 'engineDisplacement' => [
                '@type' => 'QuantitativeValue', 'value' => $v['cc'], 'unitCode' => 'CMQ',
            ]];
        }
        if ($price !== null) {
            $ld['offers'] = [
                '@type'         => 'Offer',
                'url'           => $url,
                'price'         => $price,
                'priceCurrency' => 'EUR',
                'itemCondition' => 'https://schema.org/UsedCondition',
                'availability'  => 'https://schema.org/InStock',
                'seller'        => ['@type' => 'MotorcycleDealer', 'name' => 'Dual Motors'],
            ];
        }
        return $ld;
    }

    /** @param list<array{0:string,1:?string}> $crumbs */
    private function crumbsLd(array $crumbs): array
    {
        $items = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Acasă', 'item' => $this->appUrl . $this->base . '/']];
        foreach ($crumbs as $i => [$name, $path]) {
            $item = ['@type' => 'ListItem', 'position' => $i + 2, 'name' => $name];
            if ($path !== null) {
                $item['item'] = $this->appUrl . $this->base . $path;
            }
            $items[] = $item;
        }
        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /** JSON pentru <script type="application/ld+json">: „/" rămâne escapat, deci „</script>" nu poate apărea. */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
```

- [ ] **Step 3: Rutele publice**

În `src/Routes.php`, imediat după blocul `// --- Events (public section) ---` (după `$app->get('/evenimente/{slug}', $ev('show'));`) adaugă:

```php
    // --- Rulate (vehicule second hand). Ordinea contează: anunțul ({id}-{slug})
    // înaintea categoriei, altfel „12-yamaha-mt-07" ar fi căutat ca o categorie.
    $used = function (string $method) use ($twig, $container) {
        return function ($request, $response, $args) use ($twig, $container, $method) {
            return (new \App\Controllers\UsedController($twig, $container))->{$method}($request, $response, $args);
        };
    };
    $app->get('/rulate', $used('index'));
    $app->get('/rulate/marca/{marca:[a-z0-9-]+}', $used('brand'));
    $app->get('/rulate/{id:[0-9]+}-{slug:[a-z0-9-]*}', $used('show'));
    $app->get('/rulate/{cat:[a-z0-9-]+}', $used('category'));
```

În `$legacyMap`, înlocuiește cele trei linii despre second-hand cu:

```php
        // Secțiunea de rulate a revenit pe situl nou.
        '/second-hand.php'       => '/rulate',
        '/second-hand-item.php'  => '/rulate',
```

- [ ] **Step 4: Partialele**

`templates/partials/_used_card.twig`:

```twig
{# Card de anunț rulat. Import: {% import 'partials/_used_card.twig' as uc %} → {{ uc.card(v) }} #}
{% macro card(v) %}
    <a class="used-card" href="{{ base }}{{ v.url }}">
        <div class="used-card__media{{ v.thumb ? '' : ' media-ph' }}">
            {% if v.thumb %}
                <img src="{{ base }}{{ v.thumb }}" alt="{{ v.title }}" loading="lazy" width="800" height="600">
            {% else %}
                <span class="media-ph__label">{{ v.brand_name }}</span>
            {% endif %}
        </div>
        <span class="used-card__cat">{{ v.brand_name }} · {{ v.category_name }}</span>
        <h2 class="used-card__title">{{ v.title }}</h2>
        {% if v.facts %}<p class="used-card__facts">{{ v.facts }}</p>{% endif %}
        <p class="used-card__price">
            {% if v.price_eur is not null %}
                {% set pr = prices(v.price_eur, 'yamaha') %}
                <strong>{{ pr.eur }}</strong> <span>{{ pr.ron }}</span>
            {% else %}
                <strong>Preț la cerere</strong>
            {% endif %}
        </p>
    </a>
{% endmacro %}
```

`templates/partials/_used_sidebar.twig`:

```twig
{# Coloana din dreapta a secțiunii Rulate: formular Vânzări moto + categorii + mărci.
   Variabile: sales{phone,email}, categories, brands, current_category, current_brand, v (opțional). #}
<aside class="used-side">
    <div class="booking-card">
        <h2 class="booking-card__title">{{ v is defined and v.state == 'active' ? 'Întreabă despre acest vehicul' : 'Cauți un vehicul rulat?' }}</h2>
        <p class="booking-card__lead">
            Vânzări moto:
            <a href="tel:{{ sales.phone|replace({' ': ''}) }}">{{ sales.phone }}</a> ·
            <a href="mailto:{{ sales.email }}">{{ sales.email }}</a>
        </p>

        <form data-ajax-form action="{{ base }}/api/lead/rulate" method="post" class="booking-form" novalidate>
            <input type="text" name="website" tabindex="-1" autocomplete="off" class="hp" aria-hidden="true">
            {% if v is defined and v.state == 'active' %}<input type="hidden" name="vehicle_id" value="{{ v.id }}">{% endif %}
            <label>Nume<input type="text" name="name" autocomplete="name" required></label>
            <label>Telefon<input type="tel" name="phone" autocomplete="tel"></label>
            <label>Email<input type="email" name="email" autocomplete="email"></label>
            <label>Mesaj<textarea name="message" rows="4"></textarea></label>
            {% include 'partials/_consent-field.twig' %}
            <p class="booking-form__err" data-form-err hidden></p>
            <button type="submit" class="btn btn--primary booking-form__submit">Trimite mesajul</button>
        </form>
        <div class="booking-thanks" data-form-thanks hidden>
            <p class="booking-thanks__title">Mulțumim!</p>
            <p>Mesajul a ajuns la colegii de la Vânzări moto. Te contactăm cât de curând.</p>
        </div>
    </div>

    {% if categories|length %}
        <nav class="used-filter" aria-label="Categorii">
            <h2 class="used-filter__title">Categorii</h2>
            <ul>
                {% for c in categories %}
                    <li><a class="{{ current_category|default('') == c.slug ? 'is-current' }}" href="{{ base }}/rulate/{{ c.slug }}">{{ c.name }} <span>{{ c.n }}</span></a></li>
                {% endfor %}
            </ul>
        </nav>
    {% endif %}

    {% if brands|length %}
        <nav class="used-filter" aria-label="Mărci">
            <h2 class="used-filter__title">Mărci</h2>
            <ul>
                {% for b in brands %}
                    <li><a class="{{ current_brand|default('') == b.slug ? 'is-current' }}" href="{{ base }}/rulate/marca/{{ b.slug }}">{{ b.name }} <span>{{ b.n }}</span></a></li>
                {% endfor %}
            </ul>
        </nav>
    {% endif %}

    {% if current_category|default('') or current_brand|default('') %}
        <a class="link-arrow link-arrow--sm" href="{{ base }}/rulate">← Toate rulatele</a>
    {% endif %}
</aside>
```

- [ ] **Step 5: `templates/used/index.twig`**

```twig
{% extends 'layout.twig' %}
{% import 'partials/_used_card.twig' as uc %}
{% block title %}{{ seo.title }}{% endblock %}
{% block description %}{{ seo.description }}{% endblock %}
{% block meta_robots %}{% if noindex %}noindex,follow{% else %}{{ parent() }}{% endif %}{% endblock %}
{% block head_extra %}<script type="application/ld+json">{{ ld_json|raw }}</script>{% endblock %}

{% block content %}
<section class="section">
    <div class="container">
        <header class="section__head">
            <div>
                <span class="kicker">Second hand</span>
                <h1 class="section__title">{{ heading }}</h1>
            </div>
        </header>

        <div class="used-layout">
            <div class="used-main">
                {% if vehicles|length %}
                    <div class="used-grid">
                        {% for v in vehicles %}{{ uc.card(v) }}{% endfor %}
                    </div>

                    {% if pages > 1 %}
                        <nav class="pager" aria-label="Paginare">
                            {% for n in 1..pages %}
                                <a class="pager__link{{ n == page ? ' is-current' }}" href="{{ base }}{{ list_path }}{{ n > 1 ? '?p=' ~ n }}"{% if n == page %} aria-current="page"{% endif %}>{{ n }}</a>
                            {% endfor %}
                        </nav>
                    {% endif %}
                {% else %}
                    <div class="empty-state">
                        <p>{{ current_category or current_brand ? 'Nu avem anunțuri aici în acest moment.' : 'Momentan nu avem vehicule rulate.' }}</p>
                        <p class="empty-state__hint">Spune-ne ce cauți din formularul alăturat și te anunțăm când apare ceva potrivit.</p>
                    </div>
                {% endif %}
            </div>

            {% include 'partials/_used_sidebar.twig' %}
        </div>
    </div>
</section>
{% endblock %}
```

- [ ] **Step 6: `templates/used/show.twig`**

```twig
{% extends 'layout.twig' %}
{% import 'partials/_used_card.twig' as uc %}
{% block title %}{{ seo.title }}{% endblock %}
{% block description %}{{ seo.description }}{% endblock %}
{% block og_type %}product{% endblock %}
{% block head_extra %}<script type="application/ld+json">{{ ld_json|raw }}</script>{% endblock %}

{% block content %}
<article class="section">
    <div class="container">
        <nav class="used-crumbs" aria-label="Ești aici">
            <a href="{{ base }}/rulate">Rulate</a> ›
            <a href="{{ base }}/rulate/{{ v.category_slug }}">{{ v.category_name }}</a> ›
            <a href="{{ base }}/rulate/marca/{{ v.brand_slug }}">{{ v.brand_name }}</a>
        </nav>

        <div class="used-layout">
            <div class="used-main">
                <header class="used-head">
                    <h1 class="section__title">{{ v.title }}</h1>
                    <p class="used-head__price">
                        {% if v.price_eur is not null %}
                            {% set pr = prices(v.price_eur, 'yamaha') %}
                            <strong>{{ pr.eur }}</strong> <span>{{ pr.ron }} · TVA inclus</span>
                        {% else %}
                            <strong>Preț la cerere</strong>
                        {% endif %}
                    </p>
                </header>

                {% if v.images|length %}
                    <div class="used-gallery" data-zoom-group>
                        {% for img in v.images %}
                            <a class="used-gallery__item{{ loop.first ? ' used-gallery__item--main' }}" href="{{ base }}{{ img.url }}" data-zoom>
                                <img src="{{ base }}{{ img.url }}" alt="{{ v.title }} — imaginea {{ loop.index }}"
                                     {% if loop.first %}fetchpriority="high"{% else %}loading="lazy"{% endif %} width="1200" height="900">
                            </a>
                        {% endfor %}
                    </div>
                {% endif %}

                <dl class="used-facts">
                    <div><dt>Marcă</dt><dd>{{ v.brand_name }}</dd></div>
                    <div><dt>Categorie</dt><dd>{{ v.category_name }}</dd></div>
                    {% if v.year %}<div><dt>An fabricație</dt><dd>{{ v.year }}</dd></div>{% endif %}
                    {% if v.km is not null %}<div><dt>Kilometri</dt><dd>{{ v.km|number_format(0, ',', '.') }} km</dd></div>{% endif %}
                    {% if v.cc %}<div><dt>Cilindree</dt><dd>{{ v.cc }} cc</dd></div>{% endif %}
                </dl>

                {% if v.description_html|striptags|trim is not empty %}
                    <h2 class="used-h2">Descriere</h2>
                    <div class="prose">{{ v.description_html|raw }}</div>
                {% endif %}

                {% if v.video_id %}
                    <h2 class="used-h2">Video</h2>
                    <div class="product-video" data-video data-video-id="{{ v.video_id }}">
                        <img class="product-video__cover" src="https://i.ytimg.com/vi/{{ v.video_id }}/hqdefault.jpg" alt="{{ v.title }} — video" loading="lazy">
                        <button class="product-video__play" type="button" data-video-play aria-label="Pornește videoclipul"><span aria-hidden="true">▶</span></button>
                    </div>
                {% endif %}
            </div>

            {% include 'partials/_used_sidebar.twig' with { current_category: v.category_slug, current_brand: v.brand_slug } %}
        </div>

        {% if others|length %}
            <section class="used-others">
                <h2 class="used-h2">Alte rulate</h2>
                <div class="used-grid used-grid--wide">
                    {% for o in others %}{{ uc.card(o) }}{% endfor %}
                </div>
            </section>
        {% endif %}
    </div>
</article>
{% endblock %}
```

- [ ] **Step 7: `templates/used/gone.twig`**

```twig
{% extends 'layout.twig' %}
{% import 'partials/_used_card.twig' as uc %}
{% block title %}Anunțul nu mai este disponibil — Rulate Dual Motors{% endblock %}
{% block description %}Acest vehicul rulat nu mai este de vânzare. Vezi celelalte anunțuri active de la Dual Motors.{% endblock %}
{% block meta_robots %}noindex,follow{% endblock %}

{% block content %}
<section class="section">
    <div class="container">
        <div class="used-layout">
            <div class="used-main">
                <span class="kicker">Rulate</span>
                <h1 class="section__title">Anunțul nu mai este disponibil</h1>
                <p class="used-gone__lead">„{{ v.title }}” nu mai este de vânzare. {{ others|length ? 'Uite ce avem acum:' : 'Momentan nu avem alte anunțuri; scrie-ne ce cauți.' }}</p>

                {% if others|length %}
                    <div class="used-grid">
                        {% for o in others %}{{ uc.card(o) }}{% endfor %}
                    </div>
                {% endif %}
                <p style="margin-top:2rem"><a class="link-arrow" href="{{ base }}/rulate">Toate rulatele <span aria-hidden="true">→</span></a></p>
            </div>

            {% include 'partials/_used_sidebar.twig' %}
        </div>
    </div>
</section>
{% endblock %}
```

În `gone.twig` variabila `v` există, dar `v.state` nu e `active`, deci coloana arată titlul generic și nu trimite `vehicle_id`.

- [ ] **Step 8: CSS**

Adaugă la sfârșitul `assets/css/app.css`:

```css
/* ---- Rulate (vehicule second hand) ---- */
.used-layout { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: clamp(1.8rem, 4vw, 3rem); align-items: start; }
.used-main, .used-side { min-width: 0; }
.used-side { display: grid; gap: 1.4rem; position: sticky; top: 96px; }
.used-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: clamp(1.2rem, 2.5vw, 2rem); }
.used-grid--wide { grid-template-columns: repeat(4, minmax(0, 1fr)); }
.used-card { display: block; min-width: 0; }
.used-card__media { position: relative; overflow: hidden; aspect-ratio: 4 / 3; border-radius: var(--radius-sm); margin-bottom: .9rem; background: #f3f3f4; }
.used-card__media img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; transition: transform .35s var(--ease); }
.used-card:hover .used-card__media img { transform: scale(1.04); }
.used-card__cat { font-family: var(--head); font-weight: 700; font-size: .72rem; letter-spacing: .1em; text-transform: uppercase; color: var(--red); }
.used-card__title { font-family: var(--head); font-weight: 700; font-size: 1.1rem; line-height: 1.25; margin-top: .3rem; }
.used-card:hover .used-card__title { color: var(--red); }
.used-card__facts { color: var(--muted); font-size: .88rem; margin-top: .3rem; }
.used-card__price { margin-top: .5rem; }
.used-card__price strong { font-family: var(--head); font-weight: 800; font-size: 1.1rem; }
.used-card__price span { color: var(--muted); font-size: .85rem; margin-left: .3rem; }
.used-filter__title { font-family: var(--head); font-weight: 800; font-size: .8rem; letter-spacing: .1em; text-transform: uppercase; margin-bottom: .6rem; }
.used-filter ul { list-style: none; margin: 0; padding: 0; border-top: 1px solid var(--line); }
.used-filter a { display: flex; justify-content: space-between; gap: 1rem; padding: .6rem 0; border-bottom: 1px solid var(--line); font-weight: 600; }
.used-filter a span { color: var(--muted); font-weight: 400; }
.used-filter a:hover, .used-filter a.is-current { color: var(--red); }
.used-crumbs { color: var(--muted); font-size: .88rem; margin-bottom: 1.2rem; }
.used-crumbs a:hover { color: var(--red); }
.used-head { margin-bottom: 1.4rem; }
.used-head__price { margin-top: .6rem; }
.used-head__price strong { font-family: var(--display); font-weight: 800; font-size: clamp(1.5rem, 3vw, 2rem); color: var(--red); }
.used-head__price span { color: var(--muted); margin-left: .5rem; }
.used-gallery { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .6rem; }
.used-gallery__item { position: relative; overflow: hidden; aspect-ratio: 4 / 3; border-radius: var(--radius-sm); background: #f3f3f4; cursor: zoom-in; }
.used-gallery__item--main { grid-column: 1 / -1; }
.used-gallery__item img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.used-facts { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 1rem; margin: 1.8rem 0; padding: 1.2rem 0; border-block: 1px solid var(--line); }
.used-facts dt { font-family: var(--head); font-weight: 600; font-size: .72rem; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
.used-facts dd { margin: .2rem 0 0; font-family: var(--head); font-weight: 700; font-size: 1.05rem; }
.used-h2 { font-family: var(--head); font-weight: 800; font-size: 1.3rem; margin: 2rem 0 1rem; }
.used-others { margin-top: 3rem; padding-top: 1rem; border-top: 1px solid var(--line); }
.used-gone__lead { font-size: 1.1rem; color: var(--ink-2); margin: 1rem 0 2rem; }
@media (max-width: 1199px) {
    .used-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .used-grid--wide { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 991px) {
    .used-layout { grid-template-columns: minmax(0, 1fr); }
    .used-side { position: static; }
}
@media (max-width: 640px) {
    .used-grid, .used-grid--wide { grid-template-columns: minmax(0, 1fr); }
    .used-gallery { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
```

În `templates/layout.twig` schimbă `app.css?v=51` în `app.css?v=52`.

- [ ] **Step 9: Navigare, footer, sitemap**

În `src/Support/NavigationV2.php`, în `build()`, înaintea liniei cu `'label' => 'Service'` adaugă:

```php
        $items[] = ['type' => 'link', 'label' => 'Rulate', 'href' => '/rulate'];
```

Apoi șterge cache-ul meniului: `rm -f storage/cache/navv2.cache`.

În `templates/partials/footer.twig`, înaintea liniei `<a href="{{ base }}/service">Service</a>` adaugă:

```twig
                <a href="{{ base }}/rulate">Rulate</a>
```

În `src/Controllers/SeoController.php`:
- adaugă `use App\Used\Repository as Used;`, proprietatea `private Used $used;` și în constructor `$this->used = $container['used'];`
- în lista de pagini statice din `sitemap()` adaugă `'/rulate'` după `'/evenimente'`
- în `array_merge(...)` adaugă, după linia cu `$this->news->sitemapArticles(),`:

```php
            $this->used->sitemapEntries(),
```

- [ ] **Step 10: Verifică**

```bash
cd /c/laragon/www/motociclete
U=http://motociclete.test
ID=$(/c/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe -uroot motociclete -N -e "SELECT id FROM used_vehicles ORDER BY id LIMIT 1" | tr -d '\r')
SLUG=$(/c/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe -uroot motociclete -N -e "SELECT slug FROM used_vehicles WHERE id=$ID" | tr -d '\r')
for p in "rulate" "rulate?p=abc" "rulate?p=0" "rulate?p=99" "rulate/motociclete" "rulate/nu-exista" "rulate/marca/yamaha" "rulate/marca/nu-exista" "rulate/$ID-$SLUG" "rulate/$ID-altceva" "rulate/999999-x" "second-hand.php"; do
  echo "cale $p → $(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$U/$p")"
done
```

Expected (categoria/marca anunțului de test trebuie să fie cele din URL; ajustează dacă ai ales altele):
```
cale rulate → 200
cale rulate?p=abc → 200
cale rulate?p=0 → 200
cale rulate?p=99 → 404
cale rulate/motociclete → 200
cale rulate/nu-exista → 404
cale rulate/marca/yamaha → 200
cale rulate/marca/nu-exista → 404
cale rulate/<id>-<slug> → 200
cale rulate/<id>-altceva → 301 http://motociclete.test/rulate/<id>-<slug>
cale rulate/999999-x → 404
cale second-hand.php → 301 http://motociclete.test/rulate
```

Anunț expirat → 410, apoi revino:

```bash
M=/c/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe
$M -uroot motociclete -e "UPDATE used_vehicles SET expires_at='2020-01-01 00:00:00' WHERE id=$ID"
echo "expirat → $(curl -s -o /dev/null -w '%{http_code}' "$U/rulate/$ID-$SLUG")"
echo "in sitemap: $(curl -s $U/sitemap.xml | grep -c "rulate/$ID-")"
echo "categorie goala noindex: $(curl -s $U/rulate/motociclete | grep -c 'noindex,follow')"
$M -uroot motociclete -e "UPDATE used_vehicles SET expires_at=DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id=$ID"
echo "in sitemap dupa: $(curl -s $U/sitemap.xml | grep -c "rulate/$ID-")"
```

Expected: `expirat → 410`, `in sitemap: 0`, `categorie goala noindex: 1` (dacă era singurul anunț din categorie), `in sitemap dupa: 1`.

SEO pe pagina anunțului:

```bash
curl -s "$U/rulate/$ID-$SLUG" | grep -E '<title>|rel="canonical"|og:type|og:image"|<h1' ; curl -s "$U/rulate/$ID-$SLUG" | grep -c '<h1'
curl -s "$U/rulate/$ID-$SLUG" | grep -o '<script type="application/ld+json">\[.*\]</script>' | sed 's/<[^>]*>//g' | "$PHP" -r 'var_dump(json_decode(stream_get_contents(STDIN)) !== null);'
```

Expected: titlu cu „— rulat", canonical `…/rulate/<id>-<slug>`, `og:type` = `product`, `og:image` = imaginea anunțului (sau `og-default.jpg` dacă n-are imagini), un singur `<h1>` (`1`), JSON-LD valid (`bool(true)`).

Vizual, cu puppeteer (`.mjs`, `puppeteer-core`, `executablePath` = Chrome): capturi la 1440 px și la 390 px pentru `/rulate` și pagina anunțului, salvate în `storage/shots/`; la 390 px verifică `document.documentElement.scrollWidth === clientWidth`. Verifică în capturi: coloana e în dreapta pe desktop și sub conținut pe mobil; „Rulate" apare în bara de meniu și în meniul de mobil; un anunț fără imagini arată placeholderul, nu o imagine ruptă.

- [ ] **Step 11: Commit**

```bash
git add src/Controllers/UsedController.php src/Controllers/SeoController.php src/Content/Repository.php src/Routes.php src/Support/NavigationV2.php templates/used templates/partials/_used_card.twig templates/partials/_used_sidebar.twig templates/partials/footer.twig templates/layout.twig assets/css/app.css
git commit -m "feat(rulate): pagini publice /rulate, 410 la anunt expirat, SEO + JSON-LD, meniu"
```

---

### Task 6: Formularul de contact (`POST /api/lead/rulate`)

**Files:**
- Modify: `src/Controllers/ContactController.php`, `src/Routes.php:42`, `templates/admin/messages/index.twig:20,23`

**Interfaces:**
- Consumes: `App\Used\Repository::find()`, `isPublic()`; `App\Content\Repository::departmentBySlug('vanzari-moto')` (Task 5); `Mailer::send(string $to, string $subject, string $body, string $context = '', string $replyTo = ''): bool`; tabela `site_messages` cu tipul `rulate` (Task 1; coloanele `email` și `phone` sunt `NOT NULL` → șir gol când lipsesc).
- Produces: `POST /api/lead/rulate` → JSON `{ok:true}` (200) sau `{ok:false,error:string}` (422).

- [ ] **Step 1: Metoda `rulate` în `ContactController`**

Adaugă proprietățile și inițializarea în constructor:

```php
    private \App\Used\Repository $used;
    private \App\Content\Repository $content;
```

```php
        $this->used    = $container['used'];
        $this->content = $container['content'];
```

După metoda `testRide()` adaugă:

```php
    /**
     * POST /api/lead/rulate — formularul din coloana secțiunii Rulate. Merge la
     * Vânzări moto (departament din `contact_departments`, rezervă: dealer).
     * E de ajuns un mijloc de contact: telefon SAU email.
     */
    public function rulate(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();

        if (trim((string) ($data['website'] ?? '')) !== '') {
            return $this->json($response, ['ok' => true]);
        }
        if (trim((string) ($data['consent'] ?? '')) !== '1') {
            return $this->json($response->withStatus(422), ['ok' => false, 'error' => 'Bifează acordul privind prelucrarea datelor personale.']);
        }

        $name    = mb_substr(trim((string) ($data['name'] ?? '')), 0, 120);
        $email   = trim((string) ($data['email'] ?? ''));
        $phone   = mb_substr(trim((string) ($data['phone'] ?? '')), 0, 40);
        $message = mb_substr(trim((string) ($data['message'] ?? '')), 0, 4000);

        if ($name === '') {
            return $this->json($response->withStatus(422), ['ok' => false, 'error' => 'Completează numele.']);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json($response->withStatus(422), ['ok' => false, 'error' => 'Adresa de email nu e validă.']);
        }
        if ($email === '' && $phone === '') {
            return $this->json($response->withStatus(422), ['ok' => false, 'error' => 'Lasă-ne un telefon sau un email ca să te putem contacta.']);
        }

        // Anunțul e opțional; unul șters sau expirat între timp nu blochează mesajul.
        $vehicle = null;
        $vid = (int) ($data['vehicle_id'] ?? 0);
        if ($vid > 0) {
            $found = $this->used->find($vid);
            if ($found !== null && $this->used->isPublic($found)) {
                $vehicle = $found;
            }
        }
        $title = $vehicle['title'] ?? '';
        $path  = $vehicle['url'] ?? '';
        $ip    = $this->clientIp($request);

        try {
            $this->db->local()->prepare(
                'INSERT INTO site_messages (type, brand, product_slug, product_name, name, email, phone, message, ip)
                 VALUES (:type, :brand, :slug, :pname, :name, :email, :phone, :message, :ip)'
            )->execute([
                ':type' => 'rulate',
                ':brand' => $vehicle ? mb_substr((string) $vehicle['brand_name'], 0, 32) : null,
                ':slug' => $path !== '' ? mb_substr($path, 0, 191) : null,
                ':pname' => $title !== '' ? mb_substr($title, 0, 191) : null,
                ':name' => $name, ':email' => $email, ':phone' => $phone,
                ':message' => $message ?: null, ':ip' => $ip,
            ]);
        } catch (Throwable) {
            // mergem mai departe: încercăm măcar emailul
        }

        $dept = $this->content->departmentBySlug('vanzari-moto');
        $to = $dept && filter_var((string) $dept['email'], FILTER_VALIDATE_EMAIL) ? (string) $dept['email'] : $this->dealer;
        $site = rtrim((string) ($this->settings['app']['url'] ?? ''), '/') . (string) ($this->settings['app']['base_path'] ?? '');
        $lines = [
            'Mesaj din secțiunea Rulate — motociclete.com.ro',
            'Anunț: ' . ($title !== '' ? $title : '— (mesaj general)'),
        ];
        if ($path !== '') {
            $lines[] = '';
            $lines[] = $site . $path;
        }
        array_push(
            $lines,
            '',
            'Nume: ' . $name,
            'Telefon: ' . ($phone !== '' ? $phone : '—'),
            'Email: ' . ($email !== '' ? $email : '—'),
            'Mesaj: ' . ($message !== '' ? $message : '—'),
            '',
            'IP: ' . $ip,
            'Data: ' . date('Y-m-d H:i:s')
        );
        try {
            $this->mailer->send($to, 'Rulate: ' . ($title !== '' ? $title : 'mesaj de la ' . $name), implode("\n", $lines), 'rulate', $email);
        } catch (Throwable) {
            // eșecul emailului nu strică răspunsul; mesajul e în site_messages
        }

        return $this->json($response, ['ok' => true]);
    }
```

Metoda folosește `$this->settings`; adaugă proprietatea și în constructor:

```php
    /** @var array<string,mixed> */
    private array $settings;
```

```php
        $this->settings = $container['settings'];
```

Linia cu URL-ul anunțului stă singură pe rând: `EmailTemplate::textToHtml()` o transformă în buton (e URL al sitului).

- [ ] **Step 2: Ruta**

În `src/Routes.php`, după `$app->post('/api/lead/test-ride', $lead('testRide'));` adaugă:

```php
    $app->post('/api/lead/rulate',    $lead('rulate'));
```

- [ ] **Step 3: Eticheta în admin → Mesaje**

În `templates/admin/messages/index.twig`, linia 20, înlocuiește expresia etichetei:

```twig
{{ m.type == 'test_ride' ? 'drive test' : (m.type == 'contact' ? 'contact' : (m.type == 'rulate' ? 'rulate' : 'ofertă')) }}
```

Linia 23 rămâne cum e: pentru `rulate` afișează `product_name` (titlul anunțului) și marca.

- [ ] **Step 4: Verifică**

```bash
cd /c/laragon/www/motociclete
U=http://motociclete.test/api/lead/rulate
ID=$(/c/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe -uroot motociclete -N -e "SELECT id FROM used_vehicles ORDER BY id LIMIT 1" | tr -d '\r')
p() { echo "$1 → $(curl -s -w ' [%{http_code}]' "${@:2}" $U)"; }
p "fara acord"        -d "name=Ion&phone=0700000000"
p "fara nume"         -d "consent=1&phone=0700000000"
p "fara contact"      -d "consent=1&name=Ion"
p "email gresit"      -d "consent=1&name=Ion&email=nu-e-email&phone=0700000000"
p "capcana"           -d "consent=1&name=Bot&website=http://spam"
p "doar telefon"      -d "consent=1&name=Ion Telefon&phone=0700000000&vehicle_id=$ID"
p "doar email"        -d "consent=1&name=Ion Email&email=ion@example.com&message=Mai e disponibil?"
p "anunt inexistent"  -d "consent=1&name=Ion Fantoma&phone=0700000000&vehicle_id=999999"
/c/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe -uroot motociclete -e "SELECT name,email,phone,product_name,product_slug FROM site_messages WHERE type='rulate' ORDER BY id DESC LIMIT 4"
tail -40 storage/logs/mail.log | grep -E "^(To|Subject|Reply-To)|Rulate" | tail -12
```

Expected: primele patru `{"ok":false,"error":"…"} [422]` cu mesajele din cod; următoarele patru `{"ok":true} [200]`. În DB apar **trei** rânduri (Telefon, Email, Fantoma), nu și „Bot"; „Ion Telefon" are `product_name` și `product_slug` completate, „Ion Fantoma" le are `NULL`. În `mail.log`: destinatar = emailul departamentului „Vânzări moto" din baza locală, subiect `Rulate: <titlu>` pentru primul și `Rulate: mesaj de la Ion Email` pentru al doilea.

În browser: trimite formularul din `/rulate/<id>-<slug>` fără bifă (apare eroarea sub formular), apoi corect (formularul dispare, apare „Mulțumim!"). În admin → Mesaje, rândurile au eticheta „rulate".

Șterge rândurile de test: `DELETE FROM site_messages WHERE type='rulate'`.

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/ContactController.php src/Routes.php templates/admin/messages/index.twig
git commit -m "feat(rulate): formular de contact catre Vanzari moto (site_messages + email)"
```

---

### Task 7: Documentație și livrare

**Files:**
- Modify: `CLAUDE.md`, `DEPLOY.md` (dacă are o listă de pași post-deploy)

- [ ] **Step 1: Rulează toată suita**

```bash
"$PHP" tests/UsedRepositoryTest.php | tail -1
"$PHP" tests/UsedThumbTest.php | tail -1
bash tests/run_newsletter_suite.sh
```

Expected: `0 eșecuri` la ambele teste noi; suita de newsletter iese cu 0 (n-am atins-o, dar `ContactController`, `Routes` și `Bootstrap` sunt comune).

- [ ] **Step 2: Secțiune în `CLAUDE.md`**

După secțiunea „Newsletter propriu…" adaugă:

```markdown
## Rulate (vehicule second hand)

Specificație: `docs/superpowers/specs/2026-10-08-vehicule-rulate-design.md`.

- Modul separat de catalog: tabele `used_brands`, `used_categories`, `used_vehicles`, `used_images` (`database/schema_used.sql`, rulat din `migrate_admin.php`; seed `database/seed_used.php`). `App\Used\Repository` (container `used`) = singurul loc care le atinge.
- **Un anunț e public doar dacă `is_active = 1` ȘI `expires_at > acum`**; regula e constanta `IS_PUBLIC` din repo, evaluată la citire (fără cron). „Acum" vine din PHP pe `Europe/Bucharest`, nu din `NOW()`. Creare și „Reactivează" = +30 de zile (`Repository::DAYS`); editarea NU prelungește. Stări în admin: activ / expirat (`is_active=1`, termen depășit) / dezactivat (`is_active=0`). Dashboardul listează doar expiratele.
- Public (`Controllers\UsedController`): `/rulate`, `/rulate/{categorie}`, `/rulate/marca/{marca}`, `/rulate/{id}-{slug}`. Anunț nepublic → **410** cu `used/gone.twig`; slug greșit → 301. ⚠️ Ruta de anunț e declarată înaintea celei de categorie, deci o categorie nu poate avea slug `marca` sau de forma `123-…` (`addCategory` le refuză).
- Coloana din dreapta (`partials/_used_sidebar.twig`): formular → `POST /api/lead/rulate` (`ContactController::rulate`) → `site_messages` (`type='rulate'`) + email la departamentul cu eticheta „Vânzări moto" (`Content\Repository::departmentBySlug('vanzari-moto')`; redenumirea departamentului din Setări trimite mesajele la `MAIL_DEALER`). E de ajuns telefon SAU email.
- Imagini în `/media/rulate/` (context de upload `rulate`), prima = coperta; la salvare `Used\Thumb::make()` scrie `/media/rulate/thumbs/<fișier>` (800 px) pentru carduri. Video = link sau ID YouTube (`Repository::youtubeId`).
- Preț în EUR cu TVA, RON la cursul Yamaha (BNR) indiferent de marcă.
- „Rulate" din meniu e în `NavigationV2::build()` → după modificări șterge `storage/cache/navv2.cache`.
- Teste: `tests/UsedRepositoryTest.php` (tranzacție cu rollback pe baza locală, ceas injectat), `tests/UsedThumbTest.php`.
```

În tabelul „Arhitectură" nu e nevoie de rând nou.

- [ ] **Step 3: Commit și push**

```bash
git status --short            # niciun tmp_*.php, niciun fișier din storage/
git add CLAUDE.md
git commit -m "docs(claude): sectiunea Rulate"
git push origin main
```

- [ ] **Step 4: Livrare pe server** (scrieri pe live — cere confirmarea lui Daniel pe pașii concreți înainte)

```bash
ssh dualmotors 'cd /home/dualmotors/public_html/motociclete.com.ro && git log --oneline -1'
```

Dacă serverul a rămas în urmă: `git pull --ff-only origin main`. Apoi, în comenzi separate:

```bash
ssh dualmotors 'cd /home/dualmotors/public_html/motociclete.com.ro && /usr/local/bin/ea-php81 database/migrate_admin.php'
ssh dualmotors 'cd /home/dualmotors/public_html/motociclete.com.ro && /usr/local/bin/ea-php81 database/seed_used.php'
ssh dualmotors 'cd /home/dualmotors/public_html/motociclete.com.ro && mkdir -p media/rulate/thumbs && chmod 755 media/rulate media/rulate/thumbs && rm -f storage/cache/navv2.cache'
```

Expected: `~ site_messages.type += rulate`, `migrate_admin: done.`, `+ used_brands: 3`, `+ used_categories: 3`.

Verifică pe live:

```bash
for p in rulate rulate/motociclete rulate/999999-x second-hand.php; do echo "cale $p → $(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' https://www.motociclete.com.ro/$p)"; done
curl -s https://www.motociclete.com.ro/ | grep -c 'href="/rulate"'
```

Expected: `200`, `200` (cu `noindex`, fiindcă nu sunt încă anunțuri), `404`, `301 …/rulate`; linkul „Rulate" apare în pagină (≥ 1). Pe live testează doar validarea formularului (un POST valid trimite email real la Vânzări moto).

Verifică și eticheta departamentului pe live: dacă nu e exact „Vânzări moto" sau emailul nu e `showroom@motociclete.com.ro`, se corectează din admin → Setări → Departamente (local e `vanzari@…`, iar baza locală e în urma celei live).
