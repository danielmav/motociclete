# Newsletter propriu — Etapa 2: compunere — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Din adminul portalului se compune o campanie (știri sau oferte), cu prețuri reduse corecte, linkuri cu UTM și imagini servite de la noi; campania se salvează, se previzualizează, se trimite ca test și are o pagină publică „vezi în browser".

**Architecture:** Citirea reducerilor BikerShop se repară la sursă (`BikerShop\Client`), deci se corectează și prețurile de pe cardurile portalului. `Newsletter\Content` rezolvă datele unui mesaj (extras din generatorul YAML, care îl folosește de acum), `Newsletter\Renderer` produce HTML-ul de email dintr-un mediu Twig propriu, `Newsletter\Composer` le leagă, iar `Newsletter\Campaigns` păstrează ciornele. Trimiterea în masă rămâne pentru etapa 3; aici există doar trimiterea de test.

**Tech Stack:** PHP 8.1, Slim 4, Twig 3, PDO, GD, cURL, PHPMailer.

**Spec:** `docs/superpowers/specs/2026-10-07-newsletter-campanii-design.md` (secțiunea „Compunere"). Etapa 1 este implementată: `docs/superpowers/plans/2026-10-07-newsletter-etapa-1-abonati.md`.

**În afara acestei etape:** coada de trimitere, releul, webhook-ul (etapa 3); numărarea clicurilor (etapa 4); bifa de newsletter de pe formularele de ofertă/contact/service și comutatoarele din My Garage (plan separat, mic, după această etapă).

## Global Constraints

- PHP-ul de rulat local este `C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe` (în comenzi: `$PHP`).
- Prepared statements peste tot; cu `PDO::ATTR_EMULATE_PREPARES = false` un placeholder numit nu se repetă; coloanele numerice vin ca string (`(int)`/`(float)` înainte de comparații).
- Codul portalului nu scrie în baza BikerShop; orice citire din ea stă în `src/BikerShop/Client.php`. Fără funcții aplicate pe coloane în `WHERE`/`JOIN` peste `ps_product`.
- Prețuri BikerShop: RON, `ps_product_shop.price` e fără TVA; `shapeProduct` aplică TVA. Reducerile din magazin sunt **procentuale, cu TVA inclus**, în `ps_specific_price`, pe produs (`id_product_attribute = 0`) sau pe variantă.
- Prețuri modele portal: EUR cu TVA; `Catalog\Repository::product()` întoarce deja `price` și `old_price`.
- Listele sunt `oferte` și `stiri`; tipurile de mesaj sunt `stiri` și `oferte`.
- UTM doar pe linkuri către `motociclete.com.ro` și `bikershop.ro` (cu sau fără `www`): `utm_source=newsletter`, `utm_medium=email`, `utm_campaign=<campanie>`, `utm_content=<bloc>-<poziție>`.
- Marcajele de personalizare din HTML-ul salvat: `%%UNSUB_URL%%`, `%%PREFS_URL%%`, `%%VIEW_URL%%`, `%%EMAIL%%`. Blocul personal al footerului stă între `<!--nl:personal-->` și `<!--/nl:personal-->`.
- Email HTML: tabele, stiluri inline, lățime 600 px, roșu `#e3000f`, text `#414141`, font `Ubuntu, Arial, sans-serif`, colțuri 8 px (ca în `documente/newsletter/brevo-mirror-agv-k5.html`).
- `tests/` e servit din web: orice test nou include `tests/_nl.php` (are garda `PHP_SAPI !== 'cli'`).
- Repo public: fără secrete, fără adrese reale; adresele de test sunt pe `nl-test.invalid`. Fără `tmp_*.php` în commit-uri; `git add` explicit. Commit pe `main`, fără push.
- După editarea `assets/css/admin.css` crește `?v=N` din `templates/admin/layout.twig`.

## Review Focus

1. **Reducere expirată, viitoare sau de alt tip** în `ps_specific_price`: nu se aplică; prețul rămâne cel de listă. Test în Task 1.
2. **Link de produs cu o variantă** (`/722786-79528-….html`): se aplică reducerea variantei; dacă varianta nu are reducere, cea de la nivel de produs; un link fără variantă folosește nivelul de produs, apoi varianta implicită. Test în Task 1.
3. **Nume de produs sau model cu `<`, `&` ori ghilimele**: apare escapat în email, nu strică HTML-ul. Test în Task 5.
4. **Link extern sau link care are deja parametri/fragment**: linkul extern rămâne neatins; la celelalte UTM se adaugă corect și fragmentul rămâne la final. Test în Task 3.
5. **BikerShop indisponibil ori produs inactiv la compunere**: eroare clară care numește produsul; formularul completat nu se pierde. Test în Task 2 (eroarea) și verificare în Task 8 (formularul).

---

## Structura fișierelor

| Fișier | Rol |
|---|---|
| `src/BikerShop/Reduction.php` (nou) | Alege și aplică reducerea; fără dependențe |
| `src/BikerShop/Client.php` (modificat) | `withReductions()`; `productsByIds()` primește variantele cerute |
| `src/Newsletter/Content.php` (nou) | Formular → date structurate (știre, modele, produse, prețuri) |
| `src/Newsletter/Generator.php` (modificat) | Folosește `Content`; YAML-ul primește prețurile reduse |
| `src/Newsletter/Links.php` (nou) | UTM |
| `src/Newsletter/Images.php` (nou) | Copiază și redimensionează imaginile BikerShop în `/media/newsletter/bs/` |
| `src/Newsletter/Renderer.php` (nou) | HTML + text; personalizare |
| `templates/email/newsletter/*.twig` (noi) | `_base`, `_macros`, `_fixed`, `stiri`, `oferte` |
| `assets/img/newsletter/` (nou) | Logo și imaginile blocurilor fixe (deja descărcate, neurmărite încă) |
| `src/Newsletter/Campaigns.php` (nou) | Ciornele de campanie (`nl_campaigns`) |
| `src/Newsletter/Composer.php` (nou) | `Content` + `Images` + `Renderer` |
| `src/Newsletter/Transport.php` (nou) | Trimiterea unui mesaj (test); jurnal în dev |
| `src/Admin/CampaignController.php` (nou) | Listă, formular, previzualizare, test, ștergere |
| `templates/admin/newsletter/{campaigns,campaign_form}.twig` (noi) | Paginile de admin |
| `src/Controllers/NewsletterController.php` (modificat) | Pagina publică „vezi în browser" |
| `database/schema_newsletter.sql`, `config/settings.php`, `src/Bootstrap.php`, `src/Routes.php` (modificate) | Tabel, configurare, serviciu, rute |

---

### Task 1: Reducerile BikerShop, la sursă

**Files:**
- Create: `src/BikerShop/Reduction.php`
- Create: `tests/BikerShopReductionTest.php`
- Modify: `src/BikerShop/Client.php`

**Interfaces:**
- Consumes: nimic din etapa 1.
- Produces:
  - `App\BikerShop\Reduction::pick(array $rows, int $shopId, int $now): ?float` — fracția reducerii (0 < r < 1) pentru rândurile unei singure ținte.
  - `Reduction::forProduct(array $byAttr, ?int $attr, ?int $defaultAttr, int $shopId, int $now): ?float` — `$byAttr` = `[id_product_attribute => rânduri]`.
  - `Reduction::apply(float $gross, ?float $reduction): array{price:float,price_old:?float,reduction_pct:?int}`.
  - Fiecare produs întors de `Client` are în plus cheile `price_old` (`?float`) și `reduction_pct` (`?int`); `price` devine prețul de vânzare.
  - `Client::productsByIds(array $ids, int $limit = 12, array $attrs = [])` — `$attrs` = `[id_product => id_product_attribute]`.

- [ ] **Step 1: Scrie testul**

`tests/BikerShopReductionTest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/BikerShopReductionTest.php
 */

require __DIR__ . '/_nl.php';

use App\BikerShop\Reduction;

$now = strtotime('2026-10-07 12:00:00');
$row = static fn (array $over = []): array => $over + [
    'id_specific_price' => 1, 'id_shop' => 0, 'price' => '-1.000000', 'reduction' => '0.200000',
    'reduction_type' => 'percentage', 'from' => '0000-00-00 00:00:00', 'to' => '0000-00-00 00:00:00',
];

// --- pick(): ce rânduri sunt valabile ----------------------------------------
check('fără rânduri → null', Reduction::pick([], 1, $now) === null);
check('reducere nelimitată în timp → fracția', Reduction::pick([$row()], 1, $now) === 0.2);
check('reducere expirată → null', Reduction::pick([$row(['to' => '2026-05-31 23:59:59'])], 1, $now) === null);
check('reducere viitoare → null', Reduction::pick([$row(['from' => '2026-11-01 00:00:00'])], 1, $now) === null);
check('reducere în fereastră → fracția',
    Reduction::pick([$row(['from' => '2026-10-01 00:00:00', 'to' => '2026-10-31 23:59:59'])], 1, $now) === 0.2);
check('reducere în sumă fixă → ignorată', Reduction::pick([$row(['reduction_type' => 'amount', 'reduction' => '50'])], 1, $now) === null);
check('preț fix → ignorat', Reduction::pick([$row(['price' => '999.000000'])], 1, $now) === null);
check('alt magazin → ignorat', Reduction::pick([$row(['id_shop' => 2])], 1, $now) === null);
check('reducere 0 sau 100% → ignorată',
    Reduction::pick([$row(['reduction' => '0']), $row(['reduction' => '1.000000'])], 1, $now) === null);

// --- pick(): care câștigă -----------------------------------------------------
check('rândul magazinului bate rândul global',
    Reduction::pick([$row(['id_specific_price' => 9, 'reduction' => '0.10']), $row(['id_specific_price' => 2, 'id_shop' => 1, 'reduction' => '0.15'])], 1, $now) === 0.15);
check('la egalitate câștigă cel mai nou (id mai mare)',
    Reduction::pick([$row(['id_specific_price' => 5, 'reduction' => '0.07']), $row(['id_specific_price' => 8, 'reduction' => '0.20'])], 1, $now) === 0.2);
check('un rând expirat mai nou nu ascunde unul valabil',
    Reduction::pick([$row(['id_specific_price' => 5]), $row(['id_specific_price' => 8, 'reduction' => '0.50', 'to' => '2026-01-30 23:59:59'])], 1, $now) === 0.2);

// --- forProduct(): ținta (Review Focus 2) ------------------------------------
$byAttr = [
    0     => [$row(['reduction' => '0.05'])],
    79528 => [$row(['reduction' => '0.20'])],
    79529 => [$row(['reduction' => '0.30', 'to' => '2026-01-01 00:00:00'])], // expirată
];
check('varianta cerută are reducere → a ei', Reduction::forProduct($byAttr, 79528, 79529, 1, $now) === 0.2);
check('varianta cerută are doar reducere expirată → nivelul de produs', Reduction::forProduct($byAttr, 79529, 79528, 1, $now) === 0.05);
check('varianta cerută nu există → nivelul de produs', Reduction::forProduct($byAttr, 11111, 79528, 1, $now) === 0.05);
check('fără variantă cerută → nivelul de produs', Reduction::forProduct($byAttr, null, 79528, 1, $now) === 0.05);
check('fără variantă cerută și fără nivel de produs → varianta implicită',
    Reduction::forProduct([79528 => $byAttr[79528]], null, 79528, 1, $now) === 0.2);
check('varianta cerută fără reducere NU împrumută de la varianta implicită',
    Reduction::forProduct([79528 => $byAttr[79528]], 11111, 79528, 1, $now) === null);

// --- apply() -----------------------------------------------------------------
check('fără reducere: prețul rămâne, restul null',
    Reduction::apply(1585.0, null) === ['price' => 1585.0, 'price_old' => null, 'reduction_pct' => null]);
check('20% din 1585 → 1268, cu preț vechi și procent',
    Reduction::apply(1585.0, 0.2) === ['price' => 1268.0, 'price_old' => 1585.0, 'reduction_pct' => 20]);
$r = Reduction::apply(2339.99, 0.04658);
check('4,658% din 2339,99 → 2230,99 și procent rotunjit la 5', $r['price'] === 2230.99 && $r['reduction_pct'] === 5);

nl_done();
```

- [ ] **Step 2: Rulează testul și verifică eșecul**

```bash
PHP="C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe"
"$PHP" /c/laragon/www/motociclete/tests/BikerShopReductionTest.php
```

Expected: eroare fatală `Class "App\BikerShop\Reduction" not found`.

- [ ] **Step 3: Scrie `Reduction`**

`src/BikerShop/Reduction.php`:

```php
<?php

declare(strict_types=1);

namespace App\BikerShop;

/**
 * Reducerile din magazin (`ps_specific_price`), fără acces la DB: alege reducerea
 * valabilă și o aplică pe prețul brut. Tratăm doar forma folosită pe BikerShop:
 * procentuală, cu TVA inclus, fără preț fix, pentru toți clienții. Orice altceva
 * (sumă fixă, preț fix, alt magazin, în afara ferestrei) e ignorat, iar prețul
 * rămâne cel de listă.
 */
final class Reduction
{
    /**
     * @param array<int,array<string,mixed>> $rows rândurile UNEI ținte (un produs sau o variantă)
     * @return float|null fracția reducerii (0 < r < 1)
     */
    public static function pick(array $rows, int $shopId, int $now): ?float
    {
        $best = null;
        $bestKey = null;
        foreach ($rows as $r) {
            if (($r['reduction_type'] ?? '') !== 'percentage' || (float) ($r['price'] ?? -1) >= 0) {
                continue;
            }
            $reduction = (float) ($r['reduction'] ?? 0);
            if ($reduction <= 0 || $reduction >= 1) {
                continue;
            }
            $shop = (int) ($r['id_shop'] ?? 0);
            if ($shop !== 0 && $shop !== $shopId) {
                continue;
            }
            $from = self::ts($r['from'] ?? null);
            $to   = self::ts($r['to'] ?? null);
            if (($from !== null && $from > $now) || ($to !== null && $to < $now)) {
                continue;
            }
            // Rândul magazinului bate rândul global; la egalitate, cel mai nou.
            $key = [$shop === $shopId ? 1 : 0, (int) ($r['id_specific_price'] ?? 0)];
            if ($bestKey === null || $key > $bestKey) {
                $bestKey = $key;
                $best = $reduction;
            }
        }
        return $best;
    }

    /**
     * Ținta: varianta cerută, apoi nivelul de produs; fără variantă cerută: nivelul
     * de produs, apoi varianta implicită (ce arată pagina produsului).
     * @param array<int,array<int,array<string,mixed>>> $byAttr id_product_attribute => rânduri
     */
    public static function forProduct(array $byAttr, ?int $attr, ?int $defaultAttr, int $shopId, int $now): ?float
    {
        $order = $attr !== null ? [$attr, 0] : [0, $defaultAttr];
        foreach ($order as $target) {
            if ($target === null || !isset($byAttr[$target])) {
                continue;
            }
            $reduction = self::pick($byAttr[$target], $shopId, $now);
            if ($reduction !== null) {
                return $reduction;
            }
        }
        return null;
    }

    /** @return array{price:float,price_old:?float,reduction_pct:?int} */
    public static function apply(float $gross, ?float $reduction): array
    {
        if ($reduction === null) {
            return ['price' => $gross, 'price_old' => null, 'reduction_pct' => null];
        }
        return [
            'price'         => round($gross * (1 - $reduction), 2),
            'price_old'     => $gross,
            'reduction_pct' => (int) round($reduction * 100),
        ];
    }

    /** Data PrestaShop → timestamp; `0000-00-00…` și golul înseamnă „fără limită". */
    private static function ts(mixed $value): ?int
    {
        $value = (string) $value;
        if ($value === '' || str_starts_with($value, '0000')) {
            return null;
        }
        $t = strtotime($value);
        return $t === false ? null : $t;
    }
}
```

- [ ] **Step 4: Rulează testul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/BikerShopReductionTest.php
```

Expected: `21 verificări, 0 eșecuri`.

- [ ] **Step 5: Leagă reducerile în `Client`**

În `src/BikerShop/Client.php`:

a) În `shapeProduct()`, după linia `'price'        => round($excl * (1 + $rate / 100), 2), // brut RON, cu TVA`, adaugă:

```php
            'price_old'    => null, // completate de withReductions()
            'reduction_pct' => null,
```

b) Înaintea docblock-ului lui `shapeProduct()` (linia `/** @param array<string,mixed> $r @return array<string,mixed> */`) adaugă metoda:

```php
    /**
     * Aplică reducerile active din magazin (`ps_specific_price`): `price` devine prețul
     * de vânzare, `price_old` prețul de listă, `reduction_pct` procentul. Fără reducere
     * sau la orice eroare produsele rămân cu prețul de listă.
     * @param array<int,array<string,mixed>> $products rezultate shapeProduct()
     * @param array<int,int> $attrs id_product => id_product_attribute cerut explicit
     * @return array<int,array<string,mixed>>
     */
    private function withReductions(array $products, array $attrs = []): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $x): int => (int) $x['id'], $products)));
        if (!$ids || !$this->isAvailable()) {
            return $products;
        }
        $p = $this->prefix;
        $shop = $this->shopId; // trusted config int, inlined
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = [];
        $defaults = [];
        try {
            $st = $this->pdo->prepare(
                "SELECT id_specific_price, id_product, id_product_attribute, id_shop, price,
                        reduction, reduction_type, `from`, `to`
                 FROM {$p}specific_price
                 WHERE id_product IN ({$in}) AND id_shop IN (0, {$shop})
                   AND id_customer = 0 AND id_group = 0 AND id_cart = 0
                   AND id_country = 0 AND id_currency = 0 AND from_quantity <= 1"
            );
            $st->execute($ids);
            foreach ($st->fetchAll() as $r) {
                $rows[(int) $r['id_product']][(int) $r['id_product_attribute']][] = $r;
            }
            if (!$rows) {
                return $products;
            }
            $with = array_keys($rows);
            $st = $this->pdo->prepare(
                "SELECT id_product, id_product_attribute
                 FROM {$p}product_attribute_shop
                 WHERE id_product IN (" . implode(',', array_fill(0, count($with), '?')) . ")
                   AND id_shop = {$shop} AND default_on = 1"
            );
            $st->execute($with);
            foreach ($st->fetchAll() as $r) {
                $defaults[(int) $r['id_product']] = (int) $r['id_product_attribute'];
            }
        } catch (Throwable) {
            return $products;
        }
        $now = time();
        foreach ($products as $i => $prod) {
            $id = (int) $prod['id'];
            if (!isset($rows[$id])) {
                continue;
            }
            $reduction = Reduction::forProduct($rows[$id], $attrs[$id] ?? null, $defaults[$id] ?? null, $shop, $now);
            $products[$i] = array_merge($prod, Reduction::apply((float) $prod['price'], $reduction));
        }
        return $products;
    }

```

c) Semnătura `public function productsByIds(array $ids, int $limit = 12): array` devine:

```php
    public function productsByIds(array $ids, int $limit = 12, array $attrs = []): array
```

și în corpul ei linia `$shaped = array_map(fn (array $r) => $this->shapeProduct($r), $stmt->fetchAll());` devine:

```php
            $shaped = $this->withReductions(array_map(fn (array $r) => $this->shapeProduct($r), $stmt->fetchAll()), $attrs);
```

d) Celelalte trei apeluri (în `featuredProducts`, `compatibleProducts`, `searchProducts`): fiecare `return array_map(fn (array $r) => $this->shapeProduct($r), X);` devine `return $this->withReductions(array_map(fn (array $r) => $this->shapeProduct($r), X));` (X = `$rows`, respectiv `$stmt->fetchAll()`).

Verifică: `grep -c "withReductions(" src/BikerShop/Client.php` → `5` (definiția + 4 apeluri) și `"$PHP" -l src/BikerShop/Client.php`.

- [ ] **Step 6: Verifică pe date reale (citire)**

Scrie `tmp_nl_check.php` în rădăcina proiectului, rulează-l, apoi șterge-l:

```php
<?php
require __DIR__ . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
$s = require __DIR__ . '/config/settings.php';
$bs = new App\BikerShop\Client(new App\Database($s['db']), $s['db']['bikershop']);
$show = static fn (array $p) => printf("%d | %.2f | vechi=%s | %s%%\n", $p['id'], $p['price'], var_export($p['price_old'], true), var_export($p['reduction_pct'], true));
$show($bs->productsByIds([722786], 1, [722786 => 79528])[0]);
$show($bs->productsByIds([722786], 1)[0]);
$show($bs->productsByIds([725924], 1, [725924 => 81008])[0]);
```

```bash
cd /c/laragon/www/motociclete && "$PHP" tmp_nl_check.php; rm -f tmp_nl_check.php
```

Expected (dacă reducerile din magazin nu s-au schimbat între timp): prima linie `722786 | 1268.00 | vechi=1585.0 | 20%`; a treia `725924 | 2230.99 | vechi=2339.99 | 5%`. A doua linie arată ce se vede pe cardurile portalului pentru același produs fără variantă: fie prețul de listă cu `vechi=NULL`, fie reducerea variantei implicite. Dacă BikerShop nu e accesibil de pe mașina de dezvoltare, scriptul dă eroare la indexul `[0]`: notează și treci mai departe.

Verifică și că paginile portalului care folosesc produse BikerShop răspund:

```bash
curl -s -o /dev/null -w "home=%{http_code}\n" http://motociclete.test/
curl -s -o /dev/null -w "accesorii=%{http_code}\n" http://motociclete.test/accesorii
```

Expected: `200` la ambele.

- [ ] **Step 7: Commit**

```bash
cd /c/laragon/www/motociclete
git add src/BikerShop/Reduction.php src/BikerShop/Client.php tests/BikerShopReductionTest.php
git commit -m "fix(bikershop): preturile citesc reducerile active din magazin (produs sau varianta)"
```

---

### Task 2: `Newsletter\Content` și generatorul YAML peste el

**Files:**
- Create: `src/Newsletter/Content.php`
- Create: `tests/NewsletterContentTest.php`
- Modify: `src/Newsletter/Generator.php`

**Interfaces:**
- Consumes: `Client::productsByIds($ids, $limit, $attrs)` din Task 1; `Catalog\Repository::product(string $brand, string $slug)` (chei folosite: `name`, `price`, `old_price`, `cover`, `excerpt`, `description`, `url`, `is_active`), `canonicalForSlugRedirect()`.
- Produces:
  - `new Content(Closure $findModel, Closure $findProducts, string $site = Content::SITE)`; `$findModel(string $brand, string $slug): ?array`; `$findProducts(array $specs): array` unde `$specs` = listă de `['id' => int, 'attr' => ?int]`, iar rezultatul e indexat pe id.
  - `Content::fromServices(Catalog $catalog, Client $bikershop, Database $db, string $site = Content::SITE): self`
  - `Content::TYPES` (`stiri`, `oferte`), `Content::SITE`.
  - `resolve(string $type, array $in): array` — vezi forma mai jos; aruncă `RuntimeException` cu mesaj în română la date lipsă.
  - `models(array $specs): array`, `products(array $specs): array`, `warnings(): array`.
  - Statice: `eur()`, `lei()`, `excerpt()`, `paragraphs()`, `splitParagraphs()`, `productSpec()`.
- Forma întoarsă de `resolve()`:

```php
[
  'type' => 'stiri'|'oferte', 'subject' => string, 'preheader' => string,
  'news' => ['title_html' => string, 'image' => string, 'link' => string, 'button' => string, 'body_html' => string],
  'models' => [['name' => string, 'image' => string, 'price' => string, 'price_old' => ?string, 'desc' => string, 'url' => string], …],
  'products' => [['id' => int, 'name' => string, 'image' => string, 'price' => string, 'price_old' => ?string, 'pct' => ?int, 'url' => string, 'price_html' => ?string], …],
]
```

Intrarea `$in` păstrează forma generatorului: `subiect`, `preheader`, `stire{titlu_html, imagine, link, buton, paragrafe[]}`, `modele[]`, `produse[]`.

- [ ] **Step 1: Scrie testul**

`tests/NewsletterContentTest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterContentTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Content;

$catalog = [
    'yamaha/r7-2026' => ['name' => 'R7', 'price' => 10500, 'old_price' => 10900, 'cover' => '/media/yamaha/cover/r7.jpg',
        'excerpt' => '', 'description' => '<p>Noul R7 este aici.</p>', 'url' => '/yamaha/motociclete/supersport/r7-2026', 'is_active' => 1],
    'yamaha/mt-07-2026' => ['name' => 'MT-07', 'price' => 8990, 'old_price' => null, 'cover' => '/media/yamaha/cover/mt07.jpg',
        'excerpt' => 'Naked de referință.', 'description' => '', 'url' => '/yamaha/motociclete/hyper-naked/mt-07-2026', 'is_active' => 1],
    'cfmoto/450mt-2026' => ['name' => '450MT', 'price' => 0, 'old_price' => null, 'cover' => '',
        'excerpt' => 'Adventure.', 'description' => '', 'url' => '/cfmoto/adventure/450mt-2026', 'is_active' => 0],
];
$shop = [
    722786 => ['id' => 722786, 'name' => 'Jacheta Dainese Tempest 4', 'image' => 'https://bikershop.ro/1-large_default/jacheta.jpg',
        'price' => 1268.0, 'price_old' => 1585.0, 'reduction_pct' => 20, 'url' => 'https://bikershop.ro/722786-jacheta.html'],
    20771 => ['id' => 20771, 'name' => 'Geaca Brera', 'image' => 'https://bikershop.ro/2-large_default/geaca.jpg',
        'price' => 2200.0, 'price_old' => null, 'reduction_pct' => null, 'url' => 'https://bikershop.ro/20771-geaca.html'],
];
$asked = [];
$content = new Content(
    static fn (string $brand, string $slug): ?array => $catalog["$brand/$slug"] ?? null,
    static function (array $specs) use ($shop, &$asked): array {
        $asked = $specs;
        $out = [];
        foreach ($specs as $s) {
            if (isset($shop[$s['id']])) {
                $out[$s['id']] = $shop[$s['id']];
            }
        }
        return $out;
    },
    'https://www.motociclete.com.ro'
);

$throws = static function (callable $fn): string {
    try {
        $fn();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
    return '';
};

// --- productSpec(): id + variantă din URL ------------------------------------
check('URL cu variantă → id și attr',
    Content::productSpec('https://bikershop.ro/jachete/722786-79528-jacheta-dainese.html') + ['x' => 1]
    === ['url' => 'https://bikershop.ro/jachete/722786-79528-jacheta-dainese.html', 'id' => 722786, 'attr' => 79528, 'x' => 1]);
$plain = Content::productSpec('https://bikershop.ro/27821-casca-agv.html');
check('URL fără variantă → doar id', $plain['id'] === 27821 && !isset($plain['attr']));
check('ID simplu', Content::productSpec(' 20771 ')['id'] === 20771);
check('URL fără id → excepție', $throws(fn () => Content::productSpec('https://bikershop.ro/765-integrale')) !== '');

// --- modele ------------------------------------------------------------------
$m = $content->models(['https://www.motociclete.com.ro/yamaha/motociclete/supersport/r7-2026?utm=x', 'mt-07-2026']);
check('model cu reducere: preț nou + preț vechi', $m[0]['price'] === '10.500 €' && $m[0]['price_old'] === '10.900 €');
check('model fără reducere: fără preț vechi', $m[1]['price'] === '8.990 €' && $m[1]['price_old'] === null);
check('descriere din excerpt sau din descriere', $m[0]['desc'] === 'Noul R7 este aici.' && $m[1]['desc'] === 'Naked de referință.');
check('imagine și link absolute',
    $m[0]['image'] === 'https://www.motociclete.com.ro/media/yamaha/cover/r7.jpg'
    && $m[0]['url'] === 'https://www.motociclete.com.ro/yamaha/motociclete/supersport/r7-2026');
check('slug simplu → brandul implicit yamaha', $m[1]['name'] === 'MT-07');

$content->models([['url' => '/cfmoto/adventure/450mt-2026']]);
$poa = $content->models([['url' => '/cfmoto/adventure/450mt-2026']])[0];
check('model fără preț → „Preț la cerere"', $poa['price'] === 'Preț la cerere' && $poa['price_old'] === null);
check('model inactiv și fără copertă → două avertismente', count($content->warnings()) >= 2);
check('model necunoscut → excepție care îl numește',
    str_contains($throws(fn () => $content->models(['yamaha/nu-exista'])), 'yamaha/nu-exista'));
$over = $content->models([['slug' => 'r7-2026', 'nume' => 'R7 2026', 'pret' => '9.999 €', 'pret_vechi' => '10.900 €']])[0];
check('câmpurile completate manual au prioritate', $over['name'] === 'R7 2026' && $over['price'] === '9.999 €' && $over['price_old'] === '10.900 €');

// --- produse -----------------------------------------------------------------
$p = $content->products(['https://bikershop.ro/jachete/722786-79528-jacheta.html', '20771']);
check('varianta din URL ajunge la căutare', $asked[0] === ['id' => 722786, 'attr' => 79528] && $asked[1] === ['id' => 20771, 'attr' => null]);
check('produs cu reducere: preț nou, vechi, procent',
    $p[0]['price'] === '1.268 lei' && $p[0]['price_old'] === '1.585 lei' && $p[0]['pct'] === 20);
check('produs fără reducere: fără preț vechi', $p[1]['price'] === '2.200 lei' && $p[1]['price_old'] === null && $p[1]['pct'] === null);
check('linkul lipit (cu varianta) e păstrat', $p[0]['url'] === 'https://bikershop.ro/jachete/722786-79528-jacheta.html');
check('fără link lipit → linkul din magazin', $p[1]['url'] === 'https://bikershop.ro/20771-geaca.html');

$man = $content->products([['id' => 20771, 'pret' => '1999', 'pret_vechi' => '2200']])[0];
check('preț manual + preț vechi manual → procent calculat', $man['price'] === '1.999 lei' && $man['price_old'] === '2.200 lei' && $man['pct'] === 9);
$man2 = $content->products([['id' => 722786, 'pret' => '1500']])[0];
check('doar preț manual → fără preț vechi din magazin', $man2['price'] === '1.500 lei' && $man2['price_old'] === null);

// Review Focus 5: produs inactiv / BikerShop indisponibil
$msg = $throws(fn () => $content->products(['https://bikershop.ro/99999-produs-disparut.html']));
check('produs negăsit și fără date manuale → excepție care îl numește', str_contains($msg, '99999'));
$manual = $content->products([['id' => 99999, 'nume' => 'Produs manual', 'imagine' => 'https://x.test/a.jpg', 'link' => 'https://bikershop.ro/x', 'pret' => '100']])[0];
check('produs negăsit dar completat manual → acceptat cu avertisment',
    $manual['name'] === 'Produs manual' && str_contains(implode(' ', $content->warnings()), '99999'));

// --- resolve() ---------------------------------------------------------------
$base = [
    'subiect' => 'Noutăți de toamnă', 'preheader' => 'Casca AGV K5',
    'stire' => ['titlu_html' => 'Noua AGV K5', 'imagine' => '/media/newsletter/k5.jpg', 'link' => 'https://bikershop.ro/765-integrale',
        'buton' => '', 'paragrafe' => ['Primul paragraf.', '<p>Al <b>doilea</b>.</p>']],
    'modele' => ['r7-2026', 'mt-07-2026'],
    'produse' => [722786, 20771, 722786, 20771, 722786, 20771],
];
$c = $content->resolve('stiri', $base);
check('stiri: 2 modele + 6 produse', count($c['models']) === 2 && count($c['products']) === 6 && $c['type'] === 'stiri');
check('imaginea știrii devine absolută', $c['news']['image'] === 'https://www.motociclete.com.ro/media/newsletter/k5.jpg');
check('butonul gol → „Detalii"', $c['news']['button'] === 'Detalii');
check('paragrafele devin HTML', $c['news']['body_html'] === '<p>Primul paragraf.</p><p>Al <b>doilea</b>.</p>');
check('subiect și preheader', $c['subject'] === 'Noutăți de toamnă' && $c['preheader'] === 'Casca AGV K5');

check('stiri fără subiect → excepție', $throws(fn () => $content->resolve('stiri', ['subiect' => ' '] + $base)) !== '');
check('stiri cu 5 produse → excepție', str_contains($throws(fn () => $content->resolve('stiri', ['produse' => [1, 2, 3, 4, 5]] + $base)), '6'));
check('stiri cu 1 model → excepție', str_contains($throws(fn () => $content->resolve('stiri', ['modele' => ['r7-2026']] + $base)), '2'));
check('stiri fără imagine → excepție',
    $throws(fn () => $content->resolve('stiri', ['stire' => ['imagine' => ''] + $base['stire']] + $base)) !== '');
check('tip necunoscut → excepție', $throws(fn () => $content->resolve('altceva', $base)) !== '');

$of = $content->resolve('oferte', ['subiect' => 'Oferte', 'stire' => ['titlu_html' => 'Reduceri la echipament'], 'produse' => [722786, 20771]]);
check('oferte: fără modele, link implicit spre magazin, imagine goală',
    $of['models'] === [] && count($of['products']) === 2 && $of['news']['link'] === 'https://bikershop.ro/' && $of['news']['image'] === '');
check('oferte cu un singur produs → excepție', $throws(fn () => $content->resolve('oferte', ['subiect' => 'x', 'stire' => ['titlu_html' => 'y'], 'produse' => [722786]])) !== '');

// --- utilitare ---------------------------------------------------------------
check('splitParagraphs: linie goală = paragraf nou', Content::splitParagraphs("Unu\ndoi\n\nTrei") === ["Unu\ndoi", 'Trei']);
check('excerpt: taie la propoziție', Content::excerpt('<p>Prima propoziție. A doua este mult mai lungă decât limita.</p>', 30) === 'Prima propoziție.');

nl_done();
```

- [ ] **Step 2: Rulează testul și verifică eșecul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterContentTest.php
```

Expected: eroare fatală `Class "App\Newsletter\Content" not found`.

- [ ] **Step 3: Scrie `Content`**

`src/Newsletter/Content.php`:

```php
<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\BikerShop\Client;
use App\Catalog\Repository as Catalog;
use App\Database;
use Closure;
use RuntimeException;

/**
 * Datele unui mesaj de newsletter, rezolvate din formularul de admin: știrea, modelele
 * din catalogul local și produsele BikerShop, cu prețul de vânzare și, unde există
 * reducere, prețul vechi. Orice câmp completat manual are prioritate.
 *
 * Căutările sunt injectate ca funcții, ca logica să fie testabilă fără baze de date;
 * `fromServices()` le leagă de catalog și de BikerShop.
 */
final class Content
{
    public const SITE = 'https://www.motociclete.com.ro';

    /** Tip de mesaj → descriere. */
    public const TYPES = [
        'stiri'  => 'Știri: o știre + 2 modele + 6 produse',
        'oferte' => 'Oferte: produse BikerShop',
    ];

    /** @var string[] avertismente ne-fatale de la ultima rezolvare */
    private array $warnings = [];

    /**
     * @param Closure(string,string):?array $findModel (brand, slug) → rândul din catalog
     * @param Closure(array<int,array{id:int,attr:?int}>):array<int,array<string,mixed>> $findProducts → produse indexate pe id
     */
    public function __construct(private Closure $findModel, private Closure $findProducts, private string $site = self::SITE)
    {
    }

    public static function fromServices(Catalog $catalog, Client $bikershop, Database $db, string $site = self::SITE): self
    {
        $findModel = static function (string $brand, string $slug) use ($catalog, $db): ?array {
            $p = $catalog->product($brand, $slug);
            if (!$p && ($canon = $catalog->canonicalForSlugRedirect($brand, $slug))) {
                $p = $catalog->product($brand, basename($canon));
            }
            if (!$p) { // slug fără an (ex. "mt-09" → "mt-09-2026")
                $st = $db->local()->prepare(
                    'SELECT slug FROM products WHERE brand = :b AND is_active = 1 AND slug REGEXP :re ORDER BY year DESC LIMIT 1'
                );
                $st->execute([':b' => $brand, ':re' => '^' . preg_quote($slug) . '-[0-9]{4}$']);
                if ($s = $st->fetchColumn()) {
                    $p = $catalog->product($brand, (string) $s);
                }
            }
            return $p ?: null;
        };
        $findProducts = static function (array $specs) use ($bikershop): array {
            $ids = [];
            $attrs = [];
            foreach ($specs as $s) {
                $ids[] = (int) $s['id'];
                if (!empty($s['attr'])) {
                    $attrs[(int) $s['id']] = (int) $s['attr'];
                }
            }
            $out = [];
            foreach ($bikershop->productsByIds($ids, max(1, count($ids)), $attrs) as $p) {
                $out[(int) $p['id']] = $p;
            }
            return $out;
        };
        return new self($findModel, $findProducts, $site);
    }

    /** @return string[] */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param array<string,mixed> $in subiect, preheader, stire{…}, modele[], produse[]
     * @return array<string,mixed>
     * @throws RuntimeException la date lipsă, model sau produs negăsit
     */
    public function resolve(string $type, array $in): array
    {
        $this->warnings = [];
        if (!isset(self::TYPES[$type])) {
            throw new RuntimeException("Tip de mesaj necunoscut: {$type}");
        }
        $subject = trim((string) ($in['subiect'] ?? ''));
        if ($subject === '') {
            throw new RuntimeException('Lipsește subiectul.');
        }
        $news  = (array) ($in['stire'] ?? []);
        $title = trim((string) ($news['titlu_html'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Lipsește titlul.');
        }
        $button = trim((string) ($news['buton'] ?? ''));
        $out = [
            'type'      => $type,
            'subject'   => $subject,
            'preheader' => trim((string) ($in['preheader'] ?? '')),
            'news'      => [
                'title_html' => $title,
                'image'      => $this->absolute(trim((string) ($news['imagine'] ?? ''))),
                'link'       => trim((string) ($news['link'] ?? '')),
                'button'     => $button !== '' ? $button : 'Detalii',
                'body_html'  => self::paragraphs($news['paragrafe'] ?? $news['text_html'] ?? ''),
            ],
            'models'   => [],
            'products' => [],
        ];
        $productSpecs = array_values((array) ($in['produse'] ?? []));

        if ($type === 'stiri') {
            foreach (['image' => 'imaginea', 'link' => 'linkul'] as $key => $label) {
                if ($out['news'][$key] === '') {
                    throw new RuntimeException("Lipsește {$label} știrii principale.");
                }
            }
            $modelSpecs = array_values((array) ($in['modele'] ?? []));
            if (count($modelSpecs) !== 2) {
                throw new RuntimeException('Sunt necesare exact 2 modele.');
            }
            if (count($productSpecs) !== 6) {
                throw new RuntimeException('Sunt necesare exact 6 produse BikerShop.');
            }
            $out['models'] = $this->models($modelSpecs);
        } else {
            if (count($productSpecs) < 2 || count($productSpecs) > 12) {
                throw new RuntimeException('Mesajul de oferte are între 2 și 12 produse.');
            }
            if ($out['news']['link'] === '') {
                $out['news']['link'] = 'https://bikershop.ro/';
            }
        }
        $out['products'] = $this->products($productSpecs);
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>|string> $specs URL / slug / obiect {slug|url, brand, nume, pret, pret_vechi, descriere, imagine, link}
     * @return array<int,array<string,mixed>>
     */
    public function models(array $specs): array
    {
        return array_map(function ($spec): array {
            $o    = is_array($spec) ? $spec : ['slug' => (string) $spec];
            $ref  = (string) preg_replace('#^https?://[^/]+#', '', trim((string) ($o['url'] ?? $o['slug'] ?? '')));
            $ref  = (string) preg_replace('/[?#].*$/', '', $ref);
            $segs = array_values(array_filter(explode('/', trim($ref, '/')), static fn (string $s): bool => $s !== ''));
            $brand = (string) ($o['brand'] ?? (count($segs) > 1 ? $segs[0] : 'yamaha'));
            $slug  = $segs ? (string) end($segs) : '';
            if ($slug === '') {
                throw new RuntimeException('Model lipsă (completează URL-ul sau slug-ul).');
            }
            $p = ($this->findModel)($brand, $slug);
            if (!$p) {
                throw new RuntimeException("Model negăsit în catalog: {$brand}/{$slug}");
            }
            if ((int) ($p['is_active'] ?? 1) === 0) {
                $this->warnings[] = "{$p['name']} e scos din ofertă.";
            }
            if (empty($o['imagine']) && empty($p['cover'])) {
                $this->warnings[] = "{$p['name']} nu are imagine de copertă în catalog.";
            }
            $price = (int) ($p['price'] ?? 0);
            $old   = (int) ($p['old_price'] ?? 0);
            $manualOld = isset($o['pret_vechi']) && $o['pret_vechi'] !== '';
            return [
                'name'      => (string) ($o['nume'] ?? $p['name']),
                'image'     => (string) ($o['imagine'] ?? ($this->site . ($p['cover'] ?? ''))),
                'price'     => (string) ($o['pret'] ?? ($price > 0 ? self::eur($price) : 'Preț la cerere')),
                'price_old' => $manualOld
                    ? (string) $o['pret_vechi']
                    : (!isset($o['pret']) && $price > 0 && $old > $price ? self::eur($old) : null),
                'desc'      => (string) ($o['descriere'] ?? self::excerpt((string) (($p['excerpt'] ?? '') ?: ($p['description'] ?? '')))),
                'url'       => (string) ($o['link'] ?? ($this->site . $p['url'])),
            ];
        }, array_values($specs));
    }

    /**
     * @param array<int,array<string,mixed>|string|int> $specs id / URL / obiect {id|url, attr, nume, pret, pret_vechi, pret_html, imagine, link}
     * @return array<int,array<string,mixed>>
     */
    public function products(array $specs): array
    {
        $parsed = array_map(static fn ($s): array => self::productSpec($s), array_values($specs));
        $live = ($this->findProducts)(array_map(
            static fn (array $o): array => ['id' => (int) $o['id'], 'attr' => isset($o['attr']) ? (int) $o['attr'] : null],
            $parsed
        ));
        $out = [];
        foreach ($parsed as $o) {
            $id = (int) $o['id'];
            $p  = $live[$id] ?? null;
            if (!$p) {
                $this->warnings[] = "Produsul {$id} nu e activ sau nu există pe BikerShop (ori BikerShop nu răspunde).";
            }
            $missing = [];
            foreach (['nume' => 'name', 'imagine' => 'image', 'link' => 'url'] as $manual => $liveKey) {
                if (empty($o[$manual]) && empty($p[$liveKey]) && !($manual === 'link' && !empty($o['url']))) {
                    $missing[] = $manual;
                }
            }
            if ($missing) {
                throw new RuntimeException(
                    "Produsul {$id} nu a fost găsit pe BikerShop și lipsesc: " . implode(', ', $missing)
                    . '. Verifică linkul sau dacă produsul e activ.'
                );
            }
            $manualPrice = isset($o['pret']) && $o['pret'] !== '';
            $cur = $manualPrice ? (float) $o['pret'] : (float) ($p['price'] ?? 0);
            if (isset($o['pret_vechi']) && $o['pret_vechi'] !== '') {
                $old = (float) $o['pret_vechi'];
            } else {
                // Un preț pus manual nu se compară cu prețul vechi din magazin.
                $old = $manualPrice ? 0.0 : (float) ($p['price_old'] ?? 0);
            }
            $hasOld = $cur > 0 && $old > $cur;
            $out[] = [
                'id'         => $id,
                'name'       => (string) ($o['nume'] ?? $p['name']),
                'image'      => (string) ($o['imagine'] ?? $p['image']),
                'price'      => $cur > 0 ? self::lei($cur) : '',
                'price_old'  => $hasOld ? self::lei($old) : null,
                'pct'        => $hasOld ? (int) round((1 - $cur / $old) * 100) : null,
                'url'        => (string) ($o['link'] ?? $o['url'] ?? $p['url']),
                'price_html' => isset($o['pret_html']) ? (string) $o['pret_html'] : null,
            ];
        }
        return $out;
    }

    /**
     * id (+ varianta) dintr-un URL BikerShop: `/722786-79528-nume.html` → id 722786, attr 79528.
     * @return array<string,mixed>
     */
    public static function productSpec(array|string|int $spec): array
    {
        $spec = is_string($spec) ? trim($spec) : $spec;
        $o = is_array($spec) ? $spec : (is_numeric($spec) ? ['id' => (int) $spec] : ['url' => (string) $spec]);
        if (empty($o['id']) && !empty($o['url']) && preg_match('#/(\d+)(?:-(\d+))?-[^/]*\.html#', (string) $o['url'], $m)) {
            $o['id'] = (int) $m[1];
            if (!isset($o['attr']) && ($m[2] ?? '') !== '') {
                $o['attr'] = (int) $m[2];
            }
        }
        if (empty($o['id'])) {
            throw new RuntimeException('Produs BikerShop fără id: ' . json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return $o;
    }

    // ------------------------------------------------------------ formatare

    public static function eur(int|float $v): string
    {
        return number_format((float) $v, 0, ',', '.') . ' €';
    }

    public static function lei(int|float $v): string
    {
        return number_format((float) $v, 0, ',', '.') . ' lei';
    }

    public static function excerpt(string $html, int $max = 260): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (mb_strlen($t) <= $max) {
            return $t;
        }
        $t = mb_substr($t, 0, $max);
        $dot = mb_strrpos($t, '. ');
        if ($dot !== false && $dot > $max / 2) {
            return mb_substr($t, 0, $dot + 1);
        }
        $sp = mb_strrpos($t, ' ');
        return rtrim(mb_substr($t, 0, $sp ?: $max), ' ,;:') . '…';
    }

    /** Listă de paragrafe (sau string) → HTML; elementele care încep cu '<' rămân ca atare. */
    public static function paragraphs(array|string $p): string
    {
        $p = is_array($p) ? $p : [$p];
        $p = array_filter(array_map(static fn ($x): string => trim((string) $x), $p), static fn (string $x): bool => $x !== '');
        return implode('', array_map(static fn (string $x): string => str_starts_with($x, '<') ? $x : '<p>' . $x . '</p>', $p));
    }

    /** Text cu paragrafe separate prin linie goală → listă. */
    public static function splitParagraphs(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R\s*\R/', $text) ?: []), static fn (string $x): bool => $x !== ''));
    }

    private function absolute(string $url): string
    {
        return str_starts_with($url, '/') ? $this->site . $url : $url;
    }
}
```

- [ ] **Step 4: Rulează testul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterContentTest.php
```

Expected: `36 verificări, 0 eșecuri`.

- [ ] **Step 5: Mută generatorul YAML pe `Content`**

În `src/Newsletter/Generator.php`:

a) În `generate()`, înlocuiește blocul de la `$models = array_map(fn ($m) => $this->resolveModel($m), array_values($in['modele'] ?? []));` până la (inclusiv) verificarea `throw new RuntimeException('Sunt necesare exact 6 produse BikerShop.'); }` cu:

```php
        $modelSpecs   = array_values($in['modele'] ?? []);
        $productSpecs = array_values($in['produse'] ?? []);
        if (count($modelSpecs) !== 2) {
            throw new RuntimeException('Sunt necesare exact 2 modele.');
        }
        if (count($productSpecs) !== 6) {
            throw new RuntimeException('Sunt necesare exact 6 produse BikerShop.');
        }
        if (!$this->bikershop->isAvailable()) {
            $this->warnings[] = 'Baza BikerShop e indisponibilă — se folosesc doar câmpurile completate manual.';
        }
        $content = Content::fromServices($this->catalog, $this->bikershop, $this->db, $this->site);
        $models = array_map(static fn (array $m): array => [
            'IMAGE' => $m['image'],
            'NAME'  => $m['name'],
            'PRICE' => $m['price_old'] !== null ? '<s>' . $m['price_old'] . '</s> ' . $m['price'] : $m['price'],
            'DESC'  => $m['desc'],
            'URL'   => $m['url'],
        ], $content->models($modelSpecs));
        $products = array_map(static function (array $p): array {
            $html = $p['price_html'];
            if ($html === null) {
                $html = $p['price_old'] !== null
                    ? '<p><strong>' . $p['price'] . '</strong> <s>' . $p['price_old'] . '</s> -' . $p['pct'] . '%</p>'
                    : '<p><strong>' . $p['price'] . '</strong></p>';
            }
            return ['IMAGE' => $p['image'], 'NAME' => $p['name'], 'PRICE_HTML' => $html, 'URL' => $p['url']];
        }, $content->products($productSpecs));
        $this->warnings = array_merge($this->warnings, $content->warnings());
```

b) Șterge metodele private `resolveModel()`, `productSpecs()` și `resolveProducts()` (împreună cu separatoarele `// --- modele` și `// --- produse BikerShop`).

c) Înlocuiește corpurile metodelor statice `eur`, `lei`, `excerpt`, `paragraphs`, `splitParagraphs` cu delegări, de exemplu:

```php
    public static function eur(int|float $v): string
    {
        return Content::eur($v);
    }
```

(la fel pentru `lei($v)`, `excerpt($html, $max)`, `paragraphs($p)`, `splitParagraphs($text)`).

d) În docblock-ul clasei, schimbă rândul despre produse în: `produse[6] (id/URL bikershop sau obiect {id|url, attr, nume, pret, pret_vechi, pret_html, imagine, link}).` și adaugă: `Rezolvarea modelelor și a produselor (inclusiv prețurile reduse) e în App\Newsletter\Content.`

Verifică: `"$PHP" -l src/Newsletter/Generator.php` și `grep -c "resolveModel\|resolveProducts\|productSpecs" src/Newsletter/Generator.php` → `0`.

- [ ] **Step 6: Verifică generatorul YAML pe date reale**

```bash
cd /c/laragon/www/motociclete
"$PHP" database/newsletter_brevo.php documente/newsletter/input.json > storage/newsletter/out-etapa2.yml; echo "exit=$?"
grep -c "<s>" storage/newsletter/out-etapa2.yml
grep -o "<strong>[^<]*</strong> <s>[^<]*</s> -[0-9]*%" storage/newsletter/out-etapa2.yml | head -6
```

Expected: `exit=0` (sau un mesaj clar despre un model/produs din `input.json` care nu mai există: atunci înlocuiește-l în fișierul de intrare, care e gitignored, și reia). Dacă produsele din `input.json` au reduceri în magazin, apar linii de forma `<strong>1.268 lei</strong> <s>1.585 lei</s> -20%`. Fișierul generat rămâne în `storage/newsletter/` (gitignored).

- [ ] **Step 7: Commit**

```bash
cd /c/laragon/www/motociclete
git add src/Newsletter/Content.php src/Newsletter/Generator.php tests/NewsletterContentTest.php
git commit -m "feat(newsletter): Content rezolva modelele si produsele cu pret redus; generatorul YAML il foloseste"
```

---

### Task 3: Linkuri cu UTM

**Files:**
- Create: `src/Newsletter/Links.php`
- Create: `tests/NewsletterLinksTest.php`

**Interfaces:**
- Consumes: nimic.
- Produces: `App\Newsletter\Links::utm(string $url, string $campaign, string $content): string`.

- [ ] **Step 1: Scrie testul**

`tests/NewsletterLinksTest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterLinksTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Links;

$tail = 'utm_source=newsletter&utm_medium=email&utm_campaign=nl-7-toamna&utm_content=produs-1';

check('link simplu spre portal',
    Links::utm('https://www.motociclete.com.ro/yamaha/motociclete', 'nl-7-toamna', 'produs-1')
    === 'https://www.motociclete.com.ro/yamaha/motociclete?' . $tail);
check('link spre bikershop.ro fără www',
    Links::utm('https://bikershop.ro/722786-79528-jacheta.html', 'nl-7-toamna', 'produs-1')
    === 'https://bikershop.ro/722786-79528-jacheta.html?' . $tail);
check('link cu parametri existenți → se adaugă cu &',
    Links::utm('https://bikershop.ro/cauta?q=casca', 'nl-7-toamna', 'produs-1') === 'https://bikershop.ro/cauta?q=casca&' . $tail);
check('fragmentul rămâne la final',
    Links::utm('https://www.motociclete.com.ro/service#programare', 'nl-7-toamna', 'produs-1')
    === 'https://www.motociclete.com.ro/service?' . $tail . '#programare');
check('parametri + fragment',
    Links::utm('https://www.motociclete.com.ro/x?a=1#b', 'nl-7-toamna', 'produs-1') === 'https://www.motociclete.com.ro/x?a=1&' . $tail . '#b');
check('link extern rămâne neatins',
    Links::utm('https://www.yamaha-motor.eu/ro/ro/', 'nl-7-toamna', 'produs-1') === 'https://www.yamaha-motor.eu/ro/ro/');
check('domeniu care doar seamănă rămâne neatins',
    Links::utm('https://bikershop.ro.evil.example/x', 'nl-7-toamna', 'produs-1') === 'https://bikershop.ro.evil.example/x');
check('link care are deja utm_source rămâne neatins',
    Links::utm('https://bikershop.ro/x?utm_source=facebook', 'nl-7-toamna', 'produs-1') === 'https://bikershop.ro/x?utm_source=facebook');
check('tel: și mailto: rămân neatinse',
    Links::utm('tel:0722354437', 'c', 'x') === 'tel:0722354437' && Links::utm('mailto:info@motociclete.com.ro', 'c', 'x') === 'mailto:info@motociclete.com.ro');
check('marcajele de personalizare și linkurile goale rămân neatinse',
    Links::utm('%%UNSUB_URL%%', 'c', 'x') === '%%UNSUB_URL%%' && Links::utm('', 'c', 'x') === '' && Links::utm('#', 'c', 'x') === '#');
check('valorile sunt codate pentru URL',
    str_contains(Links::utm('https://bikershop.ro/', 'campanie cu spații & semne', 'bloc/1'), 'utm_campaign=campanie%20cu%20spa%C8%9Bii%20%26%20semne&utm_content=bloc%2F1'));
check('gazda e comparată fără diferență de litere mari',
    str_contains(Links::utm('https://WWW.BikerShop.ro/x', 'c', 'x'), 'utm_source=newsletter'));
check('domeniul local de dezvoltare primește UTM',
    str_contains(Links::utm('http://motociclete.test/yamaha', 'c', 'x'), 'utm_source=newsletter'));

nl_done();
```

- [ ] **Step 2: Rulează testul și verifică eșecul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterLinksTest.php
```

Expected: eroare fatală `Class "App\Newsletter\Links" not found`.

- [ ] **Step 3: Scrie `Links`**

`src/Newsletter/Links.php`:

```php
<?php

declare(strict_types=1);

namespace App\Newsletter;

/**
 * Linkurile din newsletter: parametri UTM pe linkurile către siturile noastre, ca
 * vizitele și vânzările din newsletter să se vadă în Google Analytics pe ambele.
 * Linkurile externe, `tel:`/`mailto:` și marcajele de personalizare rămân neatinse.
 */
final class Links
{
    /** Gazdele care primesc UTM (motociclete.test = dezvoltare locală). */
    private const HOSTS = [
        'motociclete.com.ro', 'www.motociclete.com.ro',
        'bikershop.ro', 'www.bikershop.ro',
        'motociclete.test',
    ];

    public static function utm(string $url, string $campaign, string $content): string
    {
        if (!preg_match('~^https?://~i', $url)) {
            return $url;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!in_array($host, self::HOSTS, true)) {
            return $url;
        }
        $fragment = '';
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }
        if (preg_match('/[?&]utm_source=/', $url)) {
            return $url . $fragment;
        }
        $utm = 'utm_source=newsletter&utm_medium=email'
            . '&utm_campaign=' . rawurlencode($campaign)
            . '&utm_content=' . rawurlencode($content);
        return $url . (str_contains($url, '?') ? '&' : '?') . $utm . $fragment;
    }
}
```

- [ ] **Step 4: Rulează testul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterLinksTest.php
```

Expected: `13 verificări, 0 eșecuri`.

- [ ] **Step 5: Commit**

```bash
cd /c/laragon/www/motociclete
git add src/Newsletter/Links.php tests/NewsletterLinksTest.php
git commit -m "feat(newsletter): parametri UTM pe linkurile catre portal si BikerShop"
```

---

### Task 4: Imaginile produselor, servite de la noi

**Files:**
- Create: `src/Newsletter/Images.php`
- Create: `tests/NewsletterImagesTest.php`

**Interfaces:**
- Consumes: GD, cURL.
- Produces:
  - `new Images(string $mediaDir, string $siteUrl, array $allowedHosts = ['bikershop.ro', 'www.bikershop.ro'], ?Closure $fetch = null)` — `$mediaDir` = folderul `media` al proiectului; `$fetch(string $url): ?string` înlocuiește descărcarea în teste.
  - `localize(string $url): string` — URL-ul local (`{siteUrl}/media/newsletter/bs/<sha1>.jpg`) sau URL-ul original dacă nu se poate.
  - `warnings(): array`
  - `Images::fit(string $bytes, int $maxWidth = 600, int $quality = 82): ?string` — JPEG; `null` dacă nu e imagine.

- [ ] **Step 1: Scrie testul**

`tests/NewsletterImagesTest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterImagesTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Images;

$png = static function (int $w, int $h, bool $alpha = false): string {
    $im = imagecreatetruecolor($w, $h);
    if ($alpha) {
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    } else {
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
    }
    ob_start();
    imagepng($im);
    return (string) ob_get_clean();
};
$size = static fn (string $jpeg): array => array_slice((array) getimagesizefromstring($jpeg), 0, 2);

// --- fit() -------------------------------------------------------------------
$big = Images::fit($png(1200, 800));
check('imagine lată → 600 px, proporții păstrate', $big !== null && $size($big) === [600, 400]);
$small = Images::fit($png(300, 200));
check('imagine mică → dimensiune neschimbată', $small !== null && $size($small) === [300, 200]);
check('rezultatul e JPEG', $big !== null && str_starts_with($big, "\xFF\xD8"));
check('conținut care nu e imagine → null', Images::fit('<html>403 Forbidden</html>') === null && Images::fit('') === null);
$alpha = Images::fit($png(100, 100, true));
$im = imagecreatefromstring((string) $alpha);
$rgb = imagecolorat($im, 50, 50);
check('transparența devine fundal alb, nu negru', (($rgb >> 16) & 0xFF) > 240 && (($rgb >> 8) & 0xFF) > 240 && ($rgb & 0xFF) > 240);

// --- localize() --------------------------------------------------------------
$dir = sys_get_temp_dir() . '/nl-img-' . bin2hex(random_bytes(4));
mkdir($dir);
$calls = 0;
$images = new Images($dir, 'https://www.motociclete.com.ro', ['bikershop.ro', 'www.bikershop.ro'],
    static function (string $url) use (&$calls, $png): ?string {
        $calls++;
        return str_contains($url, 'lipsa') ? null : (str_contains($url, 'html') ? '<html>challenge</html>' : $png(1000, 1000));
    });

$url = 'https://bikershop.ro/12345-large_default/jacheta.jpg';
$local = $images->localize($url);
$file = $dir . '/newsletter/bs/' . sha1($url) . '.jpg';
check('imaginea BikerShop primește URL local', $local === 'https://www.motociclete.com.ro/media/newsletter/bs/' . sha1($url) . '.jpg');
check('fișierul e scris și redimensionat', is_file($file) && $size((string) file_get_contents($file)) === [600, 600]);
$images->localize($url);
check('a doua cerere pentru aceeași imagine nu mai descarcă', $calls === 1);

check('gazdă nepermisă → URL-ul original, fără descărcare',
    $images->localize('https://evil.example/x.jpg') === 'https://evil.example/x.jpg' && $calls === 1);
check('imagine de pe situl nostru → neatinsă',
    $images->localize('https://www.motociclete.com.ro/media/newsletter/k5.jpg') === 'https://www.motociclete.com.ro/media/newsletter/k5.jpg');
check('descărcare eșuată → URL-ul original + avertisment',
    $images->localize('https://bikershop.ro/lipsa.jpg') === 'https://bikershop.ro/lipsa.jpg' && count($images->warnings()) === 1);
check('răspuns care nu e imagine → URL-ul original + avertisment',
    $images->localize('https://bikershop.ro/html.jpg') === 'https://bikershop.ro/html.jpg' && count($images->warnings()) === 2);
check('URL gol → gol', $images->localize('') === '');

array_map('unlink', glob($dir . '/newsletter/bs/*') ?: []);
@rmdir($dir . '/newsletter/bs');
@rmdir($dir . '/newsletter');
@rmdir($dir);

nl_done();
```

- [ ] **Step 2: Rulează testul și verifică eșecul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterImagesTest.php
```

Expected: eroare fatală `Class "App\Newsletter\Images" not found`.

- [ ] **Step 3: Scrie `Images`**

`src/Newsletter/Images.php`:

```php
<?php

declare(strict_types=1);

namespace App\Newsletter;

use Closure;

/**
 * Imaginile produselor BikerShop din newsletter se copiază pe situl nostru
 * (`/media/newsletter/bs/`), redimensionate la cel mult 600 px: protecția Cloudflare
 * a magazinului le poate bloca în clienții de email. Se descarcă doar de pe gazdele
 * permise. La orice eșec rămâne URL-ul original și se adaugă un avertisment.
 */
final class Images
{
    private const SUBDIR = 'newsletter/bs';

    /** @var string[] */
    private array $warnings = [];

    private Closure $fetch;

    /**
     * @param string[] $allowedHosts
     * @param Closure(string):?string|null $fetch înlocuiește descărcarea (teste)
     */
    public function __construct(
        private string $mediaDir,
        private string $siteUrl,
        private array $allowedHosts = ['bikershop.ro', 'www.bikershop.ro'],
        ?Closure $fetch = null,
    ) {
        $this->siteUrl = rtrim($siteUrl, '/');
        $this->fetch = $fetch ?? Closure::fromCallable([self::class, 'download']);
    }

    /** @return string[] */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function localize(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($url === '' || !in_array($host, $this->allowedHosts, true)) {
            return $url;
        }
        $name   = sha1($url) . '.jpg';
        $dir    = rtrim($this->mediaDir, '/\\') . '/' . self::SUBDIR;
        $public = $this->siteUrl . '/media/' . self::SUBDIR . '/' . $name;
        if (is_file($dir . '/' . $name)) {
            return $public;
        }
        $bytes = ($this->fetch)($url);
        $jpeg  = $bytes !== null ? self::fit($bytes) : null;
        if ($jpeg === null) {
            $this->warnings[] = "Imaginea {$url} nu a putut fi copiată; rămâne servită de pe BikerShop.";
            return $url;
        }
        if ((!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) || @file_put_contents($dir . '/' . $name, $jpeg) === false) {
            $this->warnings[] = "Nu pot scrie în {$dir}; imaginea rămâne servită de pe BikerShop.";
            return $url;
        }
        return $public;
    }

    /** Redimensionează la cel mult $maxWidth și întoarce JPEG; null dacă nu e imagine. */
    public static function fit(string $bytes, int $maxWidth = 600, int $quality = 82): ?string
    {
        if ($bytes === '') {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $nw = min($w, $maxWidth);
        $nh = (int) max(1, round($h * $nw / $w));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // fundal alb sub transparență
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($dst, null, $quality);
        $out = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        return $out !== '' ? $out : null;
    }

    private static function download(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (newsletter motociclete.com.ro)',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return is_string($body) && $code === 200 && $body !== '' ? $body : null;
    }
}
```

- [ ] **Step 4: Rulează testul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterImagesTest.php
```

Expected: `13 verificări, 0 eșecuri`.

- [ ] **Step 5: Verifică o descărcare reală**

```bash
cd /c/laragon/www/motociclete
cat > tmp_nl_check.php <<'EOF'
<?php
require __DIR__ . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
$s = require __DIR__ . '/config/settings.php';
$bs = new App\BikerShop\Client(new App\Database($s['db']), $s['db']['bikershop']);
$p = $bs->productsByIds([722786], 1)[0] ?? null;
if (!$p) { echo "BikerShop indisponibil\n"; exit; }
$img = new App\Newsletter\Images(__DIR__ . '/media', $s['app']['url']);
echo $p['image'], "\n", $img->localize((string) $p['image']), "\n", implode("\n", $img->warnings()), "\n";
EOF
"$PHP" tmp_nl_check.php; rm -f tmp_nl_check.php; ls -la media/newsletter/bs/ | tail -3
```

Expected: a doua linie este `http://motociclete.test/media/newsletter/bs/<40 hex>.jpg` și fișierul există. Dacă rămâne URL-ul original cu avertisment, cauza e protecția Cloudflare a magazinului față de mașina de dezvoltare (regula existentă exceptează doar căile cu `_default/`): notează în raport; comportamentul de rezervă e cel corect. `media/` e gitignored.

- [ ] **Step 6: Commit**

```bash
cd /c/laragon/www/motociclete
git add src/Newsletter/Images.php tests/NewsletterImagesTest.php
git commit -m "feat(newsletter): imaginile produselor BikerShop copiate si redimensionate local"
```

---

### Task 5: `Renderer` și șabloanele de email

**Files:**
- Create: `src/Newsletter/Renderer.php`
- Create: `templates/email/newsletter/_base.twig`, `_macros.twig`, `_fixed.twig`, `stiri.twig`, `oferte.twig`
- Create: `tests/NewsletterRendererTest.php`
- Add: `assets/img/newsletter/` (imaginile blocurilor fixe, deja descărcate din mesajul AGV K5)

**Interfaces:**
- Consumes: `Links::utm()` (Task 3); forma de conținut din Task 2.
- Produces:
  - `new Renderer(string $templatesDir, string $siteUrl)` — `$templatesDir` = `<root>/templates/email/newsletter`.
  - `render(array $content, string $campaign, array $brand = []): array{html:string,text:string}` — `$brand` = `['address' => string, 'schedule' => string, 'departments' => [['label' => string, 'phone' => string], …]]`.
  - `Renderer::personalize(string $body, array $vars, bool $html = true): string` — chei `UNSUB_URL`, `PREFS_URL`, `VIEW_URL`, `EMAIL`.
  - `Renderer::stripPersonal(string $html): string` — scoate blocul dintre `<!--nl:personal-->` și `<!--/nl:personal-->`.

- [ ] **Step 1: Pregătește imaginile fixe**

Imaginile sunt în `assets/img/newsletter/` (`logo.png`, `desene-yamaha.png`, `desene-cfmoto.png`, `23-ani.jpg`, `footer-mic.png`). PNG-urile cu desene au ~350 KB fiecare; convertește-le în JPEG la cel mult 600 px și șterge ce nu se folosește:

```bash
cd /c/laragon/www/motociclete
cat > tmp_nl_check.php <<'EOF'
<?php
require __DIR__ . '/vendor/autoload.php';
$dir = __DIR__ . '/assets/img/newsletter';
foreach (['desene-yamaha.png' => 'desene-yamaha.jpg', 'desene-cfmoto.png' => 'desene-cfmoto.jpg', '23-ani.jpg' => '23-ani.jpg'] as $from => $to) {
    $jpeg = App\Newsletter\Images::fit((string) file_get_contents("$dir/$from"), 600, 84);
    file_put_contents("$dir/$to", $jpeg);
    if ($from !== $to) { unlink("$dir/$from"); }
    echo $to, ' ', strlen($jpeg), "\n";
}
unlink("$dir/footer-mic.png");
EOF
"$PHP" tmp_nl_check.php; rm -f tmp_nl_check.php; ls -la assets/img/newsletter/
```

Expected: rămân `logo.png`, `desene-yamaha.jpg`, `desene-cfmoto.jpg`, `23-ani.jpg`, fiecare JPEG sub 120 KB.

- [ ] **Step 2: Scrie testul**

`tests/NewsletterRendererTest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterRendererTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Renderer;

$renderer = new Renderer(dirname(__DIR__) . '/templates/email/newsletter', 'https://www.motociclete.com.ro');
$brand = ['address' => 'Șoseaua Pipera 48, București', 'schedule' => 'Luni – Vineri: 09.30 - 18.00',
    'departments' => [['label' => 'Vânzări moto', 'phone' => '0722 354 437'], ['label' => 'Service', 'phone' => '0724 371 365']]];

$product = static fn (int $i, ?string $old = null, ?int $pct = null, string $name = ''): array => [
    'id' => $i, 'name' => $name !== '' ? $name : "Produs {$i}", 'image' => "https://www.motociclete.com.ro/media/newsletter/bs/p{$i}.jpg",
    'price' => (1000 + $i) . ' lei', 'price_old' => $old, 'pct' => $pct,
    'url' => "https://bikershop.ro/{$i}-produs-{$i}.html", 'price_html' => null,
];
$stiri = [
    'type' => 'stiri', 'subject' => 'Noua AGV K5', 'preheader' => 'Spirit sportiv și protecție',
    'news' => ['title_html' => 'Noua <em>AGV K5</em>', 'image' => 'https://www.motociclete.com.ro/media/newsletter/k5.jpg',
        'link' => 'https://bikershop.ro/765-integrale', 'button' => 'Vezi căștile', 'body_html' => '<p>Text <b>știre</b>.</p>'],
    'models' => [
        ['name' => 'R7', 'image' => 'https://www.motociclete.com.ro/media/yamaha/cover/r7.jpg', 'price' => '10.500 €', 'price_old' => '10.900 €',
            'desc' => 'Noul R7 este aici.', 'url' => 'https://www.motociclete.com.ro/yamaha/motociclete/supersport/r7-2026'],
        ['name' => 'Ténéré 700 "Rally" <2026>', 'image' => 'https://www.motociclete.com.ro/media/yamaha/cover/t7.jpg', 'price' => 'Preț la cerere', 'price_old' => null,
            'desc' => 'Tom & Jerry aprobă.', 'url' => 'https://www.motociclete.com.ro/yamaha/motociclete/adventure/tenere-700'],
    ],
    'products' => [
        $product(1, '1.585 lei', 20, 'Jacheta <b>Dainese</b> & "Tempest"'), $product(2), $product(3), $product(4), $product(5), $product(6, '2.100 lei', 15),
    ],
];

$out  = $renderer->render($stiri, 'nl-7-agv-k5', $brand);
$html = $out['html'];

// --- structură ---------------------------------------------------------------
check('document HTML complet', str_starts_with(ltrim($html), '<!doctype html') && str_contains($html, '</html>'));
check('lățime 600 px și culoarea de brand', str_contains($html, 'max-width:600px') && str_contains($html, '#e3000f'));
check('nu rămân marcaje Twig', !str_contains($html, '{{') && !str_contains($html, '{%'));
check('subiectul e titlul documentului, preheaderul e ascuns',
    str_contains($html, '<title>Noua AGV K5</title>') && str_contains($html, 'Spirit sportiv și protecție'));
check('titlul știrii păstrează HTML-ul din admin', str_contains($html, 'Noua <em>AGV K5</em>'));
check('corpul știrii păstrează HTML-ul din admin', str_contains($html, '<p>Text <b>știre</b>.</p>'));
check('butonul știrii', str_contains($html, 'Vezi căștile'));
check('blocurile fixe apar la știri', str_contains($html, 'desenele tehnice') && str_contains($html, '23 ani de Dual Motors'));
check('imaginile fixe vin de pe situl nostru', str_contains($html, 'https://www.motociclete.com.ro/assets/img/newsletter/logo.png'));
check('datele de contact din setări', str_contains($html, 'Șoseaua Pipera 48') && str_contains($html, '0724 371 365'));

// --- prețuri -----------------------------------------------------------------
check('model cu reducere: ambele prețuri, cel vechi tăiat',
    str_contains($html, '10.500 €') && (bool) preg_match('~line-through[^>]*>\s*10\.900 €~', $html));
check('produs cu reducere: preț vechi tăiat și procent',
    (bool) preg_match('~line-through[^>]*>\s*1\.585 lei~', $html) && str_contains($html, '−20%'));
check('doar produsele cu reducere au preț tăiat', substr_count($html, 'line-through') === 3);
check('modelul fără preț afișează „Preț la cerere"', str_contains($html, 'Preț la cerere'));

// --- escapare (Review Focus 3) -----------------------------------------------
check('numele de produs cu HTML e escapat',
    str_contains($html, 'Jacheta &lt;b&gt;Dainese&lt;/b&gt; &amp; &quot;Tempest&quot;') && !str_contains($html, 'Jacheta <b>Dainese</b>'));
check('numele de model cu HTML e escapat', str_contains($html, 'Ténéré 700 &quot;Rally&quot; &lt;2026&gt;'));
check('descrierea modelului e escapată', str_contains($html, 'Tom &amp; Jerry aprobă.'));

// --- linkuri -----------------------------------------------------------------
check('linkurile produselor au UTM cu poziția',
    str_contains($html, 'https://bikershop.ro/1-produs-1.html?utm_source=newsletter&amp;utm_medium=email&amp;utm_campaign=nl-7-agv-k5&amp;utm_content=produs-1')
    && str_contains($html, 'utm_content=produs-6'));
check('linkurile modelelor au UTM', str_contains($html, 'utm_content=model-1') && str_contains($html, 'utm_content=model-2'));
check('linkul știrii are UTM', str_contains($html, 'https://bikershop.ro/765-integrale?utm_source=newsletter') && str_contains($html, 'utm_content=stire'));
check('toate cele 6 produse apar', substr_count($html, 'utm_content=produs-') >= 6 && str_contains($html, 'Produs 4'));

// --- personalizare -----------------------------------------------------------
check('marcajele de personalizare sunt în HTML',
    str_contains($html, '%%UNSUB_URL%%') && str_contains($html, '%%PREFS_URL%%') && str_contains($html, '%%VIEW_URL%%') && str_contains($html, '%%EMAIL%%'));
$p = Renderer::personalize($html, ['UNSUB_URL' => 'https://x.test/u?a=1&b=2', 'PREFS_URL' => 'https://x.test/p', 'VIEW_URL' => 'https://x.test/v', 'EMAIL' => 'ion@nl-test.invalid']);
check('personalize: înlocuiește toate marcajele', !str_contains($p, '%%') && str_contains($p, 'ion@nl-test.invalid'));
check('personalize: valorile sunt escapate în HTML', str_contains($p, 'https://x.test/u?a=1&amp;b=2'));
check('personalize pe text: fără escapare',
    Renderer::personalize('Dezabonare: %%UNSUB_URL%%', ['UNSUB_URL' => 'https://x.test/u?a=1&b=2'], false) === 'Dezabonare: https://x.test/u?a=1&b=2');
$public = Renderer::stripPersonal($html);
check('stripPersonal: scoate adresa și dezabonarea, păstrează restul',
    !str_contains($public, '%%EMAIL%%') && !str_contains($public, '%%UNSUB_URL%%') && str_contains($public, 'Produs 4') && str_contains($public, '</html>'));

// --- varianta text -----------------------------------------------------------
$text = $out['text'];
check('text: titlu fără HTML, corp, modele și produse',
    str_contains($text, 'Noua AGV K5') && !str_contains($text, '<em>') && !str_contains($text, '<p>') && str_contains($text, 'R7 — 10.500 €') && str_contains($text, 'Produs 4'));
check('text: prețul vechi e menționat', str_contains($text, '1.585 lei'));
check('text: linkuri cu UTM și marcaje de dezabonare',
    str_contains($text, 'https://bikershop.ro/1-produs-1.html?utm_source=newsletter') && str_contains($text, '%%UNSUB_URL%%'));

// --- oferte ------------------------------------------------------------------
$oferte = ['type' => 'oferte', 'subject' => 'Reduceri de toamnă', 'preheader' => '',
    'news' => ['title_html' => 'Reduceri la echipament', 'image' => '', 'link' => 'https://bikershop.ro/', 'button' => 'Vezi toate ofertele',
        'body_html' => '<p>Până la −20%.</p>'],
    'models' => [], 'products' => [$product(1, '1.585 lei', 20), $product(2), $product(3)]];
$oh = $renderer->render($oferte, 'nl-8-reduceri', $brand)['html'];
check('oferte: titlu, intro, produse și buton spre magazin',
    str_contains($oh, 'Reduceri la echipament') && str_contains($oh, 'Până la −20%.') && str_contains($oh, 'Produs 3') && str_contains($oh, 'Vezi toate ofertele'));
check('oferte: fără blocurile fixe de la știri și fără imagine goală',
    !str_contains($oh, '23 ani de Dual Motors') && !str_contains($oh, 'src=""'));
check('oferte: număr impar de produse nu strică tabelul', substr_count($oh, '<tr') === substr_count($oh, '</tr>'));
check('oferte: UTM cu campania ei', str_contains($oh, 'utm_campaign=nl-8-reduceri'));

nl_done();
```

- [ ] **Step 3: Rulează testul și verifică eșecul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterRendererTest.php
```

Expected: eroare fatală `Class "App\Newsletter\Renderer" not found`.

- [ ] **Step 4: Scrie `Renderer`**

`src/Newsletter/Renderer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Newsletter;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Produce HTML-ul și varianta text a unui mesaj din datele rezolvate de Content.
 * Folosește un mediu Twig propriu (șabloanele din templates/email/newsletter), cu
 * funcția `utm(url, bloc)`. HTML-ul rezultat conține marcajele %%UNSUB_URL%%,
 * %%PREFS_URL%%, %%VIEW_URL%% și %%EMAIL%%, completate per destinatar la trimitere.
 */
final class Renderer
{
    private const MARKERS = ['UNSUB_URL', 'PREFS_URL', 'VIEW_URL', 'EMAIL'];

    private Environment $twig;
    private string $siteUrl;
    private string $campaign = '';

    public function __construct(string $templatesDir, string $siteUrl)
    {
        $this->siteUrl = rtrim($siteUrl, '/');
        $this->twig = new Environment(new FilesystemLoader($templatesDir), [
            'autoescape'       => 'html',
            'strict_variables' => true,
            'cache'            => false,
        ]);
        $this->twig->addFunction(new TwigFunction(
            'utm',
            fn (string $url, string $content): string => Links::utm($url, $this->campaign, $content)
        ));
    }

    /**
     * @param array<string,mixed> $content rezultatul Content::resolve()
     * @param array<string,mixed> $brand address, schedule, departments[{label, phone}]
     * @return array{html:string,text:string}
     */
    public function render(array $content, string $campaign, array $brand = []): array
    {
        $this->campaign = $campaign;
        $html = $this->twig->render($content['type'] . '.twig', [
            'c'     => $content,
            'site'  => $this->siteUrl,
            'img'   => $this->siteUrl . '/assets/img/newsletter',
            'nav'   => [
                ['label' => 'Motociclete Yamaha', 'url' => $this->siteUrl . '/yamaha/motociclete'],
                ['label' => 'Scutere Yamaha',     'url' => $this->siteUrl . '/yamaha/scutere'],
                ['label' => 'ATV-uri Yamaha',     'url' => $this->siteUrl . '/yamaha/atvuri'],
                ['label' => 'Motociclete CFMOTO', 'url' => $this->siteUrl . '/cfmoto'],
                ['label' => 'Echipament moto',    'url' => 'https://bikershop.ro/'],
            ],
            'brand' => $brand + ['address' => '', 'schedule' => '', 'departments' => []],
            'year'  => date('Y'),
        ]);
        return ['html' => $html, 'text' => $this->text($content)];
    }

    /** @param array<string,string> $vars UNSUB_URL, PREFS_URL, VIEW_URL, EMAIL */
    public static function personalize(string $body, array $vars, bool $html = true): string
    {
        $map = [];
        foreach (self::MARKERS as $key) {
            if (array_key_exists($key, $vars)) {
                $value = (string) $vars[$key];
                $map['%%' . $key . '%%'] = $html ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
            }
        }
        return strtr($body, $map);
    }

    /** Scoate blocul personal al footerului (pentru pagina publică „vezi în browser"). */
    public static function stripPersonal(string $html): string
    {
        return (string) preg_replace('~<!--nl:personal-->.*?<!--/nl:personal-->~s', '', $html);
    }

    /** @param array<string,mixed> $c */
    private function text(array $c): string
    {
        $plain = static fn (string $html): string => trim((string) preg_replace(
            "/\n{3,}/",
            "\n\n",
            html_entity_decode(strip_tags((string) preg_replace('~</p>|<br\s*/?>~i', "\n\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ));
        $lines = [$plain((string) $c['news']['title_html']), ''];
        $body = $plain((string) $c['news']['body_html']);
        if ($body !== '') {
            $lines[] = $body;
            $lines[] = '';
        }
        if ($c['news']['link'] !== '') {
            $lines[] = $c['news']['button'] . ': ' . Links::utm((string) $c['news']['link'], $this->campaign, 'stire');
            $lines[] = '';
        }
        $item = function (array $x, string $block, int $i) use (&$lines): void {
            $price = $x['price'] !== '' ? ' — ' . $x['price'] : '';
            $old   = $x['price_old'] !== null ? ' (în loc de ' . $x['price_old'] . ')' : '';
            $lines[] = $x['name'] . $price . $old;
            $lines[] = Links::utm((string) $x['url'], $this->campaign, $block . '-' . $i);
            $lines[] = '';
        };
        foreach ($c['models'] as $i => $m) {
            $item($m, 'model', $i + 1);
        }
        foreach ($c['products'] as $i => $p) {
            $item($p, 'produs', $i + 1);
        }
        $lines[] = '--';
        $lines[] = 'Dual Motors — ' . $this->siteUrl;
        $lines[] = 'Mesaj trimis către %%EMAIL%%.';
        $lines[] = 'Dezabonare: %%UNSUB_URL%%';
        $lines[] = 'Preferințe: %%PREFS_URL%%';
        return implode("\n", $lines) . "\n";
    }
}
```

- [ ] **Step 5: Scrie șabloanele**

`templates/email/newsletter/_macros.twig`:

```twig
{# Elemente comune ale emailurilor de newsletter. Macro-urile nu văd variabilele
   apelantului: primesc totul ca argumente (funcția utm() e disponibilă). #}

{% macro button(url, label) %}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto"><tr><td style="background:#e3000f;border-radius:8px"><a href="{{ url }}" style="display:inline-block;padding:12px 28px;color:#ffffff;font-family:Ubuntu,Arial,sans-serif;font-size:16px;font-weight:700;text-decoration:none">{{ label }}</a></td></tr></table>
{% endmacro %}

{% macro price(now, old, pct) %}
{% if old %}<span style="color:#858588;font-size:14px;text-decoration:line-through">{{ old }}</span>{% if pct %} <span style="color:#e3000f;font-size:13px;font-weight:700">−{{ pct }}%</span>{% endif %}<br>{% endif %}<strong style="color:#e3000f;font-size:18px">{{ now }}</strong>
{% endmacro %}

{% macro product(p, url, imgWidth) %}
<a href="{{ url }}" style="text-decoration:none"><img src="{{ p.image }}" width="{{ imgWidth }}" alt="{{ p.name }}" style="display:block;width:100%;max-width:{{ imgWidth }}px;height:auto;margin:0 auto;border:0;border-radius:8px"></a>
<p style="margin:10px 0 6px;font-size:14px;line-height:1.35;color:#231f1e;font-weight:700"><a href="{{ url }}" style="color:#231f1e;text-decoration:none">{{ p.name }}</a></p>
<p style="margin:0 0 10px;line-height:1.4">{{ _self.price(p.price, p.price_old, p.pct) }}</p>
<p style="margin:0"><a href="{{ url }}" style="color:#e3000f;font-size:14px;font-weight:700">Detalii</a></p>
{% endmacro %}
```

`templates/email/newsletter/_base.twig`:

```twig
<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<title>{{ c.subject }}</title>
<style>
body { margin:0; padding:0; background:#f5f5f5; }
img { border:0; }
p { margin:0 0 14px; }
@media (max-width:620px) {
  .col { display:block !important; width:100% !important; box-sizing:border-box; }
  .pad { padding-left:16px !important; padding-right:16px !important; }
  .nav a { display:inline-block !important; padding:4px 6px !important; }
  .h1 { font-size:24px !important; }
}
</style>
</head>
<body style="margin:0;padding:0;background:#f5f5f5">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:#f5f5f5">{{ c.preheader }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f5f5"><tr><td align="center" style="padding:16px 8px">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:8px;font-family:Ubuntu,Arial,sans-serif;font-size:16px;line-height:1.5;color:#414141">
<tr><td align="center" style="padding:10px 16px 0;font-size:12px"><a href="%%VIEW_URL%%" style="color:#858588">Vezi în browser</a></td></tr>
<tr><td align="center" style="padding:12px 24px"><a href="{{ utm(site ~ '/', 'antet-logo') }}"><img src="{{ img }}/logo.png" width="180" alt="Dual Motors" style="display:block;width:180px;height:auto;margin:0 auto"></a></td></tr>
<tr><td class="nav" align="center" style="padding:0 12px 16px;font-size:13px;line-height:1.9">
{% for n in nav %}<a href="{{ utm(n.url, 'antet-' ~ loop.index) }}" style="color:#414141;font-weight:700;text-decoration:none;padding:0 6px;white-space:nowrap">{{ n.label }}</a> {% endfor %}
</td></tr>
{% block body %}{% endblock %}
<tr><td style="padding:24px 16px 0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#231f1e;border-radius:8px"><tr><td class="pad" align="center" style="padding:24px 32px;color:#ffffff;font-size:13px;line-height:1.6">
<img src="{{ img }}/logo.png" width="140" alt="Dual Motors" style="display:block;width:140px;height:auto;margin:0 auto 12px">
<strong>Dual Tours SRL</strong><br>
Dealer autorizat Yamaha și CFMOTO<br>
{% if brand.address %}Showroom: {{ brand.address }}<br>{% endif %}
{% if brand.schedule %}{{ brand.schedule }}<br>{% endif %}
{% for d in brand.departments %}{% if d.phone %}{{ d.label }}: <a href="tel:{{ d.phone|replace({' ': ''}) }}" style="color:#ffffff;font-weight:700;text-decoration:none">{{ d.phone }}</a>{% if not loop.last %} · {% endif %}{% endif %}{% endfor %}
</td></tr></table></td></tr>
<tr><td class="pad" align="center" style="padding:16px 32px 24px;font-size:12px;line-height:1.6;color:#858588">
<!--nl:personal-->Acest mesaj a fost trimis către %%EMAIL%%, pentru că ești abonat la newsletterul Dual Motors.<br>
<a href="%%UNSUB_URL%%" style="color:#858588">Dezabonare</a> · <a href="%%PREFS_URL%%" style="color:#858588">Preferințe</a><br><!--/nl:personal-->
© {{ year }} Dual Tours SRL · <a href="{{ utm(site ~ '/', 'footer') }}" style="color:#858588">motociclete.com.ro</a> · <a href="{{ utm('https://bikershop.ro/', 'footer') }}" style="color:#858588">bikershop.ro</a>
</td></tr>
</table>
</td></tr></table>
</body>
</html>
```

`templates/email/newsletter/_fixed.twig`:

```twig
{# Blocurile fixe ale newsletterului de știri (preluate din mesajele trimise până acum).
   Un șablon inclus nu vede macro-urile importate de părinte, deci le importă el însuși. #}
{% import '_macros.twig' as ui %}
<tr><td class="pad" style="padding:28px 32px 8px">
<h2 style="margin:0 0 10px;font-size:22px;line-height:1.3;color:#e3000f">Nou!</h2>
<p style="margin:0 0 8px;font-weight:700;color:#231f1e">Pe BikerShop găsiți desenele tehnice ale tuturor pieselor pentru Yamaha și CFMOTO.</p>
<p style="margin:0">Desenele la dimensiune completă oferă detalii despre fiecare piesă, ajutându-vă să identificați și să comandați piesele corecte de care aveți nevoie.</p>
</td></tr>
<tr><td style="padding:8px 16px 0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td class="col" width="50%" valign="top" style="padding:8px"><a href="{{ utm('https://bikershop.ro/content/11-piese-yamaha', 'desene-yamaha') }}"><img src="{{ img }}/desene-yamaha.jpg" width="268" alt="Desene tehnice piese Yamaha" style="display:block;width:100%;height:auto;border-radius:8px"></a></td>
<td class="col" width="50%" valign="top" style="padding:8px"><a href="{{ utm('https://bikershop.ro/content/14-piese-cfmoto', 'desene-cfmoto') }}"><img src="{{ img }}/desene-cfmoto.jpg" width="268" alt="Desene tehnice piese CFMOTO" style="display:block;width:100%;height:auto;border-radius:8px"></a></td>
</tr></table></td></tr>
<tr><td class="pad" style="padding:28px 32px 8px">
<h2 style="margin:0 0 10px;font-size:22px;line-height:1.3;color:#231f1e">23 ani de Dual Motors: din culisele unui sport intitulat business moto</h2>
<p>Faptele vorbesc de la sine. 23 ani de excelență sună prea pretențios și ne place să credem că ceea ce facem noi este mai mult decât un simplu business. Este un stil de viață. Așa că preferăm să folosim termeni precum aventură, socializare, căutare, risc, satisfacție, iar lista rămâne deschisă.</p>
<p style="margin:0">„Firma a fost înființată în 1997, dar business-ul efectiv a pornit în 2003, când am deschis în Bulevardul Ghencea, la parterul unui bloc, un magazin de 100 metri pătrați”, își aduce aminte Ciprian Popescu.</p>
</td></tr>
<tr><td style="padding:16px 16px 0"><a href="{{ utm(site ~ '/despre_dual_motors', '23-ani') }}"><img src="{{ img }}/23-ani.jpg" width="568" alt="Dual Motors" style="display:block;width:100%;height:auto;border-radius:8px"></a></td></tr>
<tr><td style="padding:16px 32px 0">{{ ui.button(utm(site ~ '/despre_dual_motors', '23-ani'), 'Povestea noastră') }}</td></tr>
```

`templates/email/newsletter/stiri.twig`:

```twig
{% extends '_base.twig' %}
{% import '_macros.twig' as ui %}

{% block body %}
<tr><td class="pad" style="padding:8px 32px 16px"><h1 class="h1" style="margin:0;font-size:28px;line-height:1.25;color:#231f1e;text-align:center">{{ c.news.title_html|raw }}</h1></td></tr>
<tr><td><a href="{{ utm(c.news.link, 'stire-imagine') }}"><img src="{{ c.news.image }}" width="600" alt="" style="display:block;width:100%;height:auto"></a></td></tr>
<tr><td class="pad" style="padding:20px 32px 6px">{{ c.news.body_html|raw }}</td></tr>
<tr><td style="padding:6px 32px 28px">{{ ui.button(utm(c.news.link, 'stire-buton'), c.news.button) }}</td></tr>

{% for m in c.models %}
{% set url = utm(m.url, 'model-' ~ loop.index) %}
<tr><td style="padding:0 16px 16px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f5f5;border-radius:8px"><tr>
<td class="col" width="50%" valign="middle" style="padding:16px"><a href="{{ url }}"><img src="{{ m.image }}" width="252" alt="{{ m.name }}" style="display:block;width:100%;height:auto;border-radius:8px"></a></td>
<td class="col" width="50%" valign="middle" style="padding:16px">
<h2 style="margin:0 0 6px;font-size:22px;line-height:1.25;color:#231f1e">{{ m.name }}</h2>
<p style="margin:0 0 10px;line-height:1.4">{{ ui.price(m.price, m.price_old) }}</p>
<p style="margin:0 0 14px;font-size:14px;line-height:1.5">{{ m.desc }}</p>
{{ ui.button(url, 'Detalii') }}
</td>
</tr></table></td></tr>
{% endfor %}

<tr><td class="pad" align="center" style="padding:16px 32px 8px"><h2 style="margin:0;font-size:20px;line-height:1.3;color:#231f1e">Recomandările noastre de pe <a href="{{ utm('https://bikershop.ro/', 'titlu-produse') }}" style="color:#e3000f;text-decoration:none">www.bikershop.ro</a></h2></td></tr>
{% for row in c.products|batch(3) %}
<tr><td style="padding:0 8px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
{% for p in row %}
<td class="col" width="33%" valign="top" align="center" style="padding:12px 8px">{{ ui.product(p, utm(p.url, 'produs-' ~ (loop.parent.loop.index0 * 3 + loop.index)), 178) }}</td>
{% endfor %}
</tr></table></td></tr>
{% endfor %}

{% include '_fixed.twig' %}
{% endblock %}
```

`templates/email/newsletter/oferte.twig`:

```twig
{% extends '_base.twig' %}
{% import '_macros.twig' as ui %}

{% block body %}
<tr><td class="pad" style="padding:8px 32px 12px"><h1 class="h1" style="margin:0;font-size:28px;line-height:1.25;color:#231f1e;text-align:center">{{ c.news.title_html|raw }}</h1></td></tr>
{% if c.news.image %}
<tr><td><a href="{{ utm(c.news.link, 'intro-imagine') }}"><img src="{{ c.news.image }}" width="600" alt="" style="display:block;width:100%;height:auto"></a></td></tr>
{% endif %}
{% if c.news.body_html %}
<tr><td class="pad" style="padding:16px 32px 4px;text-align:center">{{ c.news.body_html|raw }}</td></tr>
{% endif %}

{% for row in c.products|batch(2) %}
<tr><td style="padding:0 8px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
{% for p in row %}
<td class="col" width="50%" valign="top" align="center" style="padding:14px 8px">{{ ui.product(p, utm(p.url, 'produs-' ~ (loop.parent.loop.index0 * 2 + loop.index)), 268) }}</td>
{% endfor %}
{% if row|length == 1 %}<td class="col" width="50%" style="padding:14px 8px">&nbsp;</td>{% endif %}
</tr></table></td></tr>
{% endfor %}

<tr><td style="padding:16px 32px 8px">{{ ui.button(utm(c.news.link, 'buton-magazin'), c.news.button) }}</td></tr>
{% endblock %}
```

- [ ] **Step 6: Rulează testul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterRendererTest.php
```

Expected: `33 verificări, 0 eșecuri`. Șabloanele sunt prima versiune: dacă o verificare pică din cauza unui detaliu de șablon (de exemplu macro-ul `product` nu poate apela `_self.price`, caz în care adaugă `{% import _self as m %}` în macro și apelează `m.price`), repară șablonul, nu testul.

- [ ] **Step 7: Verifică vizual**

Generează un mesaj de probă din fixture și fă capturi la 600 px și 390 px:

```bash
cd /c/laragon/www/motociclete
cat > tmp_nl_check.php <<'EOF'
<?php
require __DIR__ . '/vendor/autoload.php';
$r = new App\Newsletter\Renderer(__DIR__ . '/templates/email/newsletter', 'http://motociclete.test');
$prod = fn (int $i, ?string $old = null, ?int $pct = null) => ['id' => $i, 'name' => "Jacheta Dainese Tempest 4 D-Dry, negru/gri/albastru {$i}",
    'image' => 'http://motociclete.test/assets/img/newsletter/23-ani.jpg', 'price' => '1.268 lei', 'price_old' => $old, 'pct' => $pct,
    'url' => "https://bikershop.ro/{$i}-x.html", 'price_html' => null];
$c = ['type' => $argv[1], 'subject' => 'Probă', 'preheader' => 'Preheader de probă',
    'news' => ['title_html' => 'Spiritul sportiv, protecția și versatilitatea se reunesc în noul model de cască AGV K5',
        'image' => 'http://motociclete.test/assets/img/newsletter/23-ani.jpg', 'link' => 'https://bikershop.ro/765-integrale', 'button' => 'Detalii',
        'body_html' => '<p>De la deplasările urbane până la ieșirile în afara orașului, aceasta îmbină un design aerodinamic compact cu un confort ridicat.</p>'],
    'models' => $argv[1] === 'stiri' ? [
        ['name' => 'R7', 'image' => 'http://motociclete.test/assets/img/newsletter/23-ani.jpg', 'price' => '10.500 €', 'price_old' => '10.900 €', 'desc' => 'Noul R7 este aici. Rafinat, receptiv și mai accesibil.', 'url' => 'http://motociclete.test/yamaha'],
        ['name' => 'Ténéré 700', 'image' => 'http://motociclete.test/assets/img/newsletter/23-ani.jpg', 'price' => '10.840 €', 'price_old' => null, 'desc' => 'Unul dintre cele mai emblematice nume din motociclism.', 'url' => 'http://motociclete.test/yamaha'],
    ] : [],
    'products' => [$prod(1, '1.585 lei', 20), $prod(2), $prod(3, '2.200 lei', 20), $prod(4), $prod(5), $prod(6, '2.100 lei', 15)]];
$html = App\Newsletter\Renderer::personalize($r->render($c, 'proba', ['address' => 'Șoseaua Pipera 48, București', 'schedule' => 'Luni – Vineri: 09.30 - 18.00',
    'departments' => [['label' => 'Vânzări moto', 'phone' => '0722 354 437'], ['label' => 'Service', 'phone' => '0724 371 365']]])['html'],
    ['UNSUB_URL' => '#', 'PREFS_URL' => '#', 'VIEW_URL' => '#', 'EMAIL' => 'adresa@exemplu.ro']);
file_put_contents(__DIR__ . "/storage/shots/nl-email-{$argv[1]}.html", $html);
EOF
"$PHP" tmp_nl_check.php stiri && "$PHP" tmp_nl_check.php oferte; rm -f tmp_nl_check.php
```

Apoi, cu puppeteer (script `.mjs` temporar în `storage/shots/`, șters după), deschide fișierele prin `file:///C:/laragon/www/motociclete/storage/shots/nl-email-stiri.html` (și `…-oferte.html`; `storage/` e blocat din web), la `setViewport({width: 700})` și `{width: 390}`, cu `fullPage: true`. Verifică: lățimea conținutului 600 px pe desktop; pe 390 px coloanele se stivuiesc și `scrollWidth === clientWidth`; prețurile vechi sunt tăiate; butoanele sunt roșii cu text alb; footerul întunecat are text lizibil. Ajustează șabloanele până arată curat și reia testul din Step 6 după fiecare modificare.

- [ ] **Step 8: Commit**

```bash
cd /c/laragon/www/motociclete
git add src/Newsletter/Renderer.php templates/email/newsletter tests/NewsletterRendererTest.php assets/img/newsletter
git commit -m "feat(newsletter): sabloane de email (stiri, oferte) si Renderer cu UTM si personalizare"
```

---

### Task 6: Ciornele de campanie și `Composer`

**Files:**
- Modify: `database/schema_newsletter.sql` (tabel nou la final)
- Create: `src/Newsletter/Campaigns.php`
- Create: `src/Newsletter/Composer.php`
- Create: `tests/NewsletterCampaignsTest.php`
- Modify: `tests/_nl.php` (`nl_isolate()` golește și `nl_campaigns`)
- Modify: `src/Bootstrap.php` (serviciu `newsletter_campaigns`)

**Interfaces:**
- Consumes: `Content::resolve()`, `Content::warnings()` (Task 2); `Images::localize()`, `Images::warnings()` (Task 4); `Renderer::render()` (Task 5); `Repository::LISTS`; `Content::TYPES`.
- Produces:
  - `App\Newsletter\Campaigns` (`new Campaigns(App\Database $db)`; în container `$container['newsletter_campaigns']`):
    - `create(string $list, string $type, string $subject): int` — ciornă nouă, cu `view_key` aleator de 16 caractere hex.
    - `update(int $id, array $fields): void` — chei permise: `list_key`, `type`, `subject`, `preheader`, `input_json`, `html`, `body_text`; aruncă `RuntimeException` dacă campania nu e `draft`.
    - `find(int $id): ?array`, `findPublic(int $id, string $key): ?array`, `all(): array` (fără coloanele mari), `delete(int $id): bool` (doar ciorne).
  - `App\Newsletter\Composer`: `new Composer(Content $content, Renderer $renderer, ?Images $images = null)`; `compose(string $type, array $input, string $campaign, array $brand): array{html:string,text:string,warnings:array}`.

- [ ] **Step 1: Adaugă tabelul**

La finalul `database/schema_newsletter.sql`:

```sql

CREATE TABLE IF NOT EXISTS `nl_campaigns` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `list_key`     ENUM('oferte','stiri') NOT NULL,
    `type`         ENUM('stiri','oferte') NOT NULL,
    `subject`      VARCHAR(200) NOT NULL,
    `preheader`    VARCHAR(200) NULL,
    `view_key`     CHAR(16) NOT NULL,
    `input_json`   MEDIUMTEXT NULL,
    `html`         MEDIUMTEXT NULL,
    `body_text`    MEDIUMTEXT NULL,
    `status`       ENUM('draft','queued','sending','paused','sent') NOT NULL DEFAULT 'draft',
    `pause_reason` VARCHAR(255) NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NULL,
    `queued_at`    DATETIME NULL,
    `finished_at`  DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_nl_campaign_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```bash
cd /c/laragon/www/motociclete && "$PHP" database/migrate_admin.php | tail -1
"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe" -uroot motociclete -N -e "SHOW TABLES LIKE 'nl\_campaigns'" | tr -d '\r'
```

Expected: `migrate_admin: done.` și `nl_campaigns`.

În `tests/_nl.php`, în `nl_isolate()`, după linia `$pdo->exec('DELETE FROM nl_subscribers');` adaugă:

```php
    $pdo->exec('DELETE FROM nl_campaigns');
```

- [ ] **Step 2: Scrie testul**

`tests/NewsletterCampaignsTest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterCampaignsTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Campaigns;
use App\Newsletter\Composer;
use App\Newsletter\Content;
use App\Newsletter\Images;
use App\Newsletter\Renderer;

$pdo = nl_isolate();
$campaigns = new Campaigns(nl_db());

// --- ciorne ------------------------------------------------------------------
$id = $campaigns->create('stiri', 'stiri', 'Noua AGV K5');
$row = $campaigns->find($id);
check('create: ciornă cu cheie de vizualizare de 16 caractere hex',
    $id > 0 && $row['status'] === 'draft' && (bool) preg_match('/^[a-f0-9]{16}$/', (string) $row['view_key'])
    && $row['list_key'] === 'stiri' && $row['type'] === 'stiri' && $row['subject'] === 'Noua AGV K5');

$campaigns->update($id, ['subject' => 'Subiect nou', 'preheader' => 'Pre', 'html' => '<p>salut</p>', 'body_text' => 'salut',
    'input_json' => '{"a":1}', 'list_key' => 'oferte', 'type' => 'oferte', 'status' => 'sent', 'view_key' => 'x']);
$row = $campaigns->find($id);
check('update: scrie câmpurile permise',
    $row['subject'] === 'Subiect nou' && $row['preheader'] === 'Pre' && $row['html'] === '<p>salut</p>' && $row['body_text'] === 'salut'
    && $row['input_json'] === '{"a":1}' && $row['list_key'] === 'oferte' && $row['type'] === 'oferte' && $row['updated_at'] !== null);
check('update: ignoră câmpurile nepermise (status, view_key)',
    $row['status'] === 'draft' && (bool) preg_match('/^[a-f0-9]{16}$/', (string) $row['view_key']));

check('findPublic: cheia corectă', (int) ($campaigns->findPublic($id, (string) $row['view_key'])['id'] ?? 0) === $id);
check('findPublic: cheie greșită → null', $campaigns->findPublic($id, str_repeat('0', 16)) === null);
check('find: id necunoscut → null', $campaigns->find(999999) === null);

$id2 = $campaigns->create('oferte', 'oferte', 'A doua');
$all = $campaigns->all();
check('all: cele mai noi primele, fără coloanele mari',
    count($all) === 2 && (int) $all[0]['id'] === $id2 && !array_key_exists('html', $all[0]) && array_key_exists('subject', $all[0]));

// O campanie pusă la trimis nu mai poate fi modificată sau ștearsă.
$pdo->exec("UPDATE nl_campaigns SET status = 'queued' WHERE id = {$id2}");
$threw = false;
try {
    $campaigns->update($id2, ['subject' => 'Modificat']);
} catch (RuntimeException) {
    $threw = true;
}
check('update pe o campanie care nu e ciornă → excepție, fără modificare',
    $threw && $campaigns->find($id2)['subject'] === 'A doua');
check('delete pe o campanie care nu e ciornă → false', $campaigns->delete($id2) === false && $campaigns->find($id2) !== null);
check('delete pe o ciornă → true', $campaigns->delete($id) === true && $campaigns->find($id) === null);

// --- Composer ----------------------------------------------------------------
$shop = [
    1 => ['id' => 1, 'name' => 'Produs unu', 'image' => 'https://bikershop.ro/1-large_default/unu.jpg', 'price' => 800.0, 'price_old' => 1000.0, 'reduction_pct' => 20, 'url' => 'https://bikershop.ro/1-unu.html'],
    2 => ['id' => 2, 'name' => 'Produs doi', 'image' => 'https://bikershop.ro/2-large_default/doi.jpg', 'price' => 500.0, 'price_old' => null, 'reduction_pct' => null, 'url' => 'https://bikershop.ro/2-doi.html'],
];
$content = new Content(
    static fn (string $b, string $s): ?array => null,
    static fn (array $specs): array => array_intersect_key($shop, array_flip(array_column($specs, 'id')))
);
$renderer = new Renderer(dirname(__DIR__) . '/templates/email/newsletter', 'https://www.motociclete.com.ro');
$dir = sys_get_temp_dir() . '/nl-cmp-' . bin2hex(random_bytes(4));
mkdir($dir);
$im = imagecreatetruecolor(800, 800);
ob_start();
imagepng($im);
$png = (string) ob_get_clean();
$images = new Images($dir, 'https://www.motociclete.com.ro', ['bikershop.ro'],
    static fn (string $url): ?string => str_contains($url, 'doi') ? null : $png);

$input = ['subiect' => 'Reduceri', 'stire' => ['titlu_html' => 'Reduceri de toamnă'], 'produse' => [1, 2]];
$out = (new Composer($content, $renderer, $images))->compose('oferte', $input, 'nl-1-reduceri', []);
check('compose: HTML cu produsele și prețul redus',
    str_contains($out['html'], 'Produs unu') && str_contains($out['html'], '800 lei') && str_contains($out['html'], '1.000 lei'));
check('compose: imaginea copiată e servită de la noi',
    str_contains($out['html'], 'https://www.motociclete.com.ro/media/newsletter/bs/' . sha1('https://bikershop.ro/1-large_default/unu.jpg') . '.jpg'));
check('compose: imaginea necopiată rămâne pe BikerShop, cu avertisment',
    str_contains($out['html'], 'https://bikershop.ro/2-large_default/doi.jpg') && count($out['warnings']) === 1);
check('compose: UTM cu numele campaniei', str_contains($out['html'], 'utm_campaign=nl-1-reduceri'));
check('compose: și varianta text', str_contains($out['text'], 'Produs unu — 800 lei (în loc de 1.000 lei)'));

$noImages = (new Composer($content, $renderer))->compose('oferte', $input, 'x', []);
check('compose fără Images: imaginile rămân cele originale', str_contains($noImages['html'], 'https://bikershop.ro/1-large_default/unu.jpg'));

$threw = '';
try {
    (new Composer($content, $renderer))->compose('oferte', ['subiect' => 'x', 'stire' => ['titlu_html' => 'y'], 'produse' => [1, 77]], 'x', []);
} catch (RuntimeException $e) {
    $threw = $e->getMessage();
}
check('compose: produs negăsit → excepția din Content ajunge la apelant', str_contains($threw, '77'));

array_map('unlink', glob($dir . '/newsletter/bs/*') ?: []);
@rmdir($dir . '/newsletter/bs');
@rmdir($dir . '/newsletter');
@rmdir($dir);

nl_done();
```

- [ ] **Step 3: Rulează testul și verifică eșecul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterCampaignsTest.php
```

Expected: eroare fatală `Class "App\Newsletter\Campaigns" not found`.

- [ ] **Step 4: Scrie `Campaigns` și `Composer`**

`src/Newsletter/Campaigns.php`:

```php
<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\Database;
use PDO;
use RuntimeException;

/**
 * Campaniile de newsletter (`nl_campaigns`). O campanie e `draft` cât timp se
 * compune; din momentul în care e pusă la trimis (etapa 3) nu mai poate fi
 * modificată sau ștearsă. Erorile de DB nu sunt înghițite aici.
 */
final class Campaigns
{
    /** Câmpurile pe care le poate scrie formularul de compunere. */
    private const EDITABLE = ['list_key', 'type', 'subject', 'preheader', 'input_json', 'html', 'body_text'];

    public function __construct(private Database $db)
    {
    }

    private function pdo(): PDO
    {
        return $this->db->local();
    }

    public function create(string $list, string $type, string $subject): int
    {
        $this->pdo()->prepare(
            'INSERT INTO nl_campaigns (list_key, type, subject, view_key) VALUES (:l, :t, :s, :k)'
        )->execute([':l' => $list, ':t' => $type, ':s' => $subject, ':k' => bin2hex(random_bytes(8))]);
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $fields doar cheile din EDITABLE sunt scrise
     * @throws RuntimeException dacă campania nu mai e ciornă
     */
    public function update(int $id, array $fields): void
    {
        $row = $this->find($id);
        if ($row === null || $row['status'] !== 'draft') {
            throw new RuntimeException('Campania nu mai poate fi modificată (nu este ciornă).');
        }
        $set = [];
        $params = [':id' => $id];
        foreach (self::EDITABLE as $col) {
            if (array_key_exists($col, $fields)) {
                $set[] = "`{$col}` = :{$col}";
                $params[':' . $col] = $fields[$col];
            }
        }
        if (!$set) {
            return;
        }
        $this->pdo()->prepare(
            'UPDATE nl_campaigns SET ' . implode(', ', $set) . ", updated_at = NOW() WHERE id = :id AND status = 'draft'"
        )->execute($params);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $s = $this->pdo()->prepare('SELECT * FROM nl_campaigns WHERE id = :id');
        $s->execute([':id' => $id]);
        return $s->fetch() ?: null;
    }

    /** Campania pentru pagina publică „vezi în browser" (id + cheie secretă). @return array<string,mixed>|null */
    public function findPublic(int $id, string $key): ?array
    {
        $row = $this->find($id);
        return $row !== null && hash_equals((string) $row['view_key'], $key) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> fără coloanele mari, cele mai noi primele */
    public function all(): array
    {
        return $this->pdo()->query(
            'SELECT id, list_key, type, subject, view_key, status, created_at, updated_at, queued_at, finished_at,
                    (html IS NOT NULL AND html <> \'\') AS has_html
             FROM nl_campaigns ORDER BY id DESC'
        )->fetchAll();
    }

    /** Șterge doar ciornele. */
    public function delete(int $id): bool
    {
        $s = $this->pdo()->prepare("DELETE FROM nl_campaigns WHERE id = :id AND status = 'draft'");
        $s->execute([':id' => $id]);
        return $s->rowCount() === 1;
    }
}
```

`src/Newsletter/Composer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Newsletter;

/**
 * Compunerea unui mesaj: Content rezolvă datele din formular, Images mută imaginile
 * produselor pe situl nostru, Renderer produce HTML-ul și textul. Excepțiile lui
 * Content (date lipsă, produs negăsit) ajung la apelant.
 */
final class Composer
{
    public function __construct(private Content $content, private Renderer $renderer, private ?Images $images = null)
    {
    }

    /**
     * @param array<string,mixed> $input forma formularului (subiect, preheader, stire, modele, produse)
     * @param array<string,mixed> $brand datele de contact din footer
     * @return array{html:string,text:string,warnings:array<int,string>}
     */
    public function compose(string $type, array $input, string $campaign, array $brand): array
    {
        $content = $this->content->resolve($type, $input);
        $warnings = $this->content->warnings();
        if ($this->images !== null) {
            foreach ($content['products'] as $i => $product) {
                $content['products'][$i]['image'] = $this->images->localize((string) $product['image']);
            }
            $warnings = array_merge($warnings, $this->images->warnings());
        }
        $out = $this->renderer->render($content, $campaign, $brand);
        return ['html' => $out['html'], 'text' => $out['text'], 'warnings' => array_values($warnings)];
    }
}
```

- [ ] **Step 5: Rulează testul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterCampaignsTest.php
```

Expected: `17 verificări, 0 eșecuri`.

- [ ] **Step 6: Înregistrează serviciul**

În `src/Bootstrap.php`, după linia `'newsletter' => new Newsletter\Repository($db),` adaugă:

```php
            'newsletter_campaigns' => new Newsletter\Campaigns($db),
```

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://motociclete.test/health
```

Expected: `200`.

- [ ] **Step 7: Commit**

```bash
cd /c/laragon/www/motociclete
git add database/schema_newsletter.sql src/Newsletter/Campaigns.php src/Newsletter/Composer.php src/Bootstrap.php tests/_nl.php tests/NewsletterCampaignsTest.php
git commit -m "feat(newsletter): ciorne de campanie (nl_campaigns) si Composer"
```

---

### Task 7: `Transport` (trimiterea de test)

**Files:**
- Create: `src/Newsletter/Transport.php`
- Create: `tests/NewsletterTransportTest.php`
- Modify: `config/settings.php` (bloc `newsletter`, după blocul `mail`)
- Modify: `.env.example` (variabilele `NL_*`, comentate)

**Interfaces:**
- Consumes: PHPMailer (deja în `vendor/`).
- Produces:
  - `$settings['newsletter']` = `['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_secure', 'from', 'from_name', 'reply_to']`, din `NL_*` cu rezervă pe valorile `mail` existente.
  - `new Transport(array $cfg, string $logDir, bool $dev)`; `send(string $to, string $subject, string $html, string $text, array $headers = []): bool`; `lastError(): string`.
  - În `dev` sau fără `smtp_host`: nu trimite; adaugă în `<logDir>/newsletter.log` și scrie ultimul HTML în `<logDir>/newsletter-last.html`.

- [ ] **Step 1: Scrie testul**

`tests/NewsletterTransportTest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterTransportTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Transport;

$dir = sys_get_temp_dir() . '/nl-tr-' . bin2hex(random_bytes(4));
mkdir($dir);
$cfg = ['smtp_host' => 'smtp.exemplu.invalid', 'smtp_port' => 587, 'smtp_user' => '', 'smtp_pass' => '', 'smtp_secure' => 'tls',
    'from' => 'noutati@news.motociclete.com.ro', 'from_name' => 'Dual Motors', 'reply_to' => 'info@motociclete.com.ro'];

// --- modul dev: scrie în jurnal, nu trimite ----------------------------------
$t = new Transport($cfg, $dir, true);
$ok = $t->send('ion@nl-test.invalid', 'Subiect de probă', '<p>Salut <b>Ion</b></p>', "Salut Ion\n",
    ['List-Unsubscribe' => '<https://x.test/u>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
$log = (string) @file_get_contents($dir . '/newsletter.log');
check('dev: send întoarce true', $ok === true);
check('dev: jurnalul are destinatarul, subiectul și expeditorul',
    str_contains($log, 'TO: ion@nl-test.invalid') && str_contains($log, 'SUBJECT: Subiect de probă') && str_contains($log, 'FROM: Dual Motors <noutati@news.motociclete.com.ro>'));
check('dev: jurnalul are headerele și textul', str_contains($log, 'List-Unsubscribe: <https://x.test/u>') && str_contains($log, 'Salut Ion'));
check('dev: ultimul HTML e salvat separat', file_get_contents($dir . '/newsletter-last.html') === '<p>Salut <b>Ion</b></p>');

$t->send('ana@nl-test.invalid', 'Al doilea', '<p>2</p>', '2');
check('dev: jurnalul se completează, nu se suprascrie',
    substr_count((string) file_get_contents($dir . '/newsletter.log'), 'TO: ') === 2);

// --- validare ----------------------------------------------------------------
check('adresă invalidă → false cu motiv', $t->send('nu-e-email', 'x', '<p>x</p>', 'x') === false && $t->lastError() !== '');
check('încercare de injectare în subiect → false',
    $t->send('ion@nl-test.invalid', "Subiect\r\nBcc: victima@nl-test.invalid", '<p>x</p>', 'x') === false);
check('încercare de injectare în header → false',
    $t->send('ion@nl-test.invalid', 'x', '<p>x</p>', 'x', ['X-Test' => "a\r\nBcc: victima@nl-test.invalid"]) === false);
check('după un eșec, jurnalul nu primește mesajul', substr_count((string) file_get_contents($dir . '/newsletter.log'), 'TO: ') === 2);

// --- fără gazdă SMTP configurată: tot jurnal, chiar și în afara dev ----------
$t2 = new Transport(['smtp_host' => ''] + $cfg, $dir, false);
check('fără smtp_host: scrie în jurnal în loc să trimită',
    $t2->send('ion@nl-test.invalid', 'Fără SMTP', '<p>x</p>', 'x') === true
    && str_contains((string) file_get_contents($dir . '/newsletter.log'), 'SUBJECT: Fără SMTP'));

array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);

nl_done();
```

- [ ] **Step 2: Rulează testul și verifică eșecul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterTransportTest.php
```

Expected: eroare fatală `Class "App\Newsletter\Transport" not found`.

- [ ] **Step 3: Scrie `Transport`**

`src/Newsletter/Transport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Newsletter;

use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

/**
 * Trimite UN mesaj de newsletter (HTML + text) prin SMTP. Nu trece prin
 * Support\Mailer: acela salvează corpul fiecărui email în `email_log`.
 *
 * În dev sau fără gazdă SMTP configurată nu trimite nimic: adaugă mesajul în
 * storage/logs/newsletter.log și păstrează ultimul HTML în newsletter-last.html.
 */
final class Transport
{
    private string $lastError = '';

    /** @param array<string,mixed> $cfg blocul `newsletter` din config/settings.php */
    public function __construct(private array $cfg, private string $logDir, private bool $dev = false)
    {
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    /** @param array<string,string> $headers headere suplimentare (ex. List-Unsubscribe) */
    public function send(string $to, string $subject, string $html, string $text, array $headers = []): bool
    {
        $this->lastError = '';
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->lastError = 'Adresă de email invalidă.';
            return false;
        }
        foreach (array_merge([$subject], array_keys($headers), array_values($headers)) as $value) {
            if (preg_match('/[\r\n]/', (string) $value)) {
                $this->lastError = 'Subiectul și headerele nu pot conține linii noi.';
                return false;
            }
        }
        if ($this->dev || empty($this->cfg['smtp_host'])) {
            return $this->log($to, $subject, $html, $text, $headers);
        }
        try {
            $m = new PHPMailer(true);
            $m->isSMTP();
            $m->Host = (string) $this->cfg['smtp_host'];
            $m->Port = (int) ($this->cfg['smtp_port'] ?? 587);
            $m->SMTPAuth = ($this->cfg['smtp_user'] ?? '') !== '';
            $m->Username = (string) ($this->cfg['smtp_user'] ?? '');
            $m->Password = (string) ($this->cfg['smtp_pass'] ?? '');
            $secure = (string) ($this->cfg['smtp_secure'] ?? '');
            $m->SMTPSecure = $secure;
            $m->SMTPAutoTLS = $secure !== '';
            $m->CharSet = 'UTF-8';
            // Quoted-printable: HTML-ul are linii lungi, iar peste 998 de caractere pe linie
            // mesajul pleacă „trimis" dar nu ajunge.
            $m->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
            $m->setFrom((string) $this->cfg['from'], (string) ($this->cfg['from_name'] ?? ''));
            if (!empty($this->cfg['reply_to'])) {
                $m->addReplyTo((string) $this->cfg['reply_to']);
            }
            $m->addAddress($to);
            foreach ($headers as $name => $value) {
                $m->addCustomHeader((string) $name, (string) $value);
            }
            $m->Subject = $subject;
            $m->isHTML(true);
            $m->Body = $html;
            $m->AltBody = $text;
            return $m->send();
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /** @param array<string,string> $headers */
    private function log(string $to, string $subject, string $html, string $text, array $headers): bool
    {
        $lines = [
            '[' . date('Y-m-d H:i:s') . '] TO: ' . $to,
            'FROM: ' . ($this->cfg['from_name'] ?? '') . ' <' . ($this->cfg['from'] ?? '') . '>',
            'SUBJECT: ' . $subject,
        ];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $lines[] = str_repeat('-', 40);
        $lines[] = rtrim($text);
        $lines[] = '';
        $dir = rtrim($this->logDir, '/\\');
        if (@file_put_contents($dir . '/newsletter.log', implode("\n", $lines) . "\n", FILE_APPEND) === false) {
            $this->lastError = "Nu pot scrie în {$dir}/newsletter.log.";
            return false;
        }
        @file_put_contents($dir . '/newsletter-last.html', $html);
        return true;
    }
}
```

- [ ] **Step 4: Rulează testul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterTransportTest.php
```

Expected: `10 verificări, 0 eșecuri`.

- [ ] **Step 5: Adaugă configurarea**

În `config/settings.php`, imediat după blocul `'mail' => [ … ],` adaugă:

```php

    // Newsletter propriu. Până la configurarea releului (NL_SMTP_*), trimiterea de test
    // folosește SMTP-ul sitului; expeditorul final este noutati@news.motociclete.com.ro.
    'newsletter' => [
        'smtp_host'   => $_ENV['NL_SMTP_HOST'] ?? ($_ENV['SMTP_HOST'] ?? ''),
        'smtp_port'   => (int) ($_ENV['NL_SMTP_PORT'] ?? ($_ENV['SMTP_PORT'] ?? 587)),
        'smtp_user'   => $_ENV['NL_SMTP_USER'] ?? ($_ENV['SMTP_USER'] ?? ''),
        'smtp_pass'   => $_ENV['NL_SMTP_PASS'] ?? ($_ENV['SMTP_PASS'] ?? ''),
        'smtp_secure' => $_ENV['NL_SMTP_SECURE'] ?? ($_ENV['SMTP_SECURE'] ?? 'tls'),
        'from'        => $_ENV['NL_FROM'] ?? ($_ENV['MAIL_FROM'] ?? 'noreply@motociclete.com.ro'),
        'from_name'   => $_ENV['NL_FROM_NAME'] ?? 'Dual Motors',
        'reply_to'    => $_ENV['NL_REPLY_TO'] ?? ($_ENV['MAIL_DEALER'] ?? 'info@motociclete.com.ro'),
    ],
```

La finalul `.env.example` adaugă:

```
# Newsletter propriu (etapa 3: releu SMTP). Cât timp lipsesc, trimiterea de test din
# admin folosește SMTP_* de mai sus; pe DEV mesajele merg în storage/logs/newsletter.log.
# NL_SMTP_HOST=
# NL_SMTP_PORT=587
# NL_SMTP_USER=
# NL_SMTP_PASS=
# NL_SMTP_SECURE=tls
# NL_FROM=noutati@news.motociclete.com.ro
# NL_FROM_NAME=Dual Motors
# NL_REPLY_TO=info@motociclete.com.ro
```

```bash
"$PHP" -r 'require "C:/laragon/www/motociclete/vendor/autoload.php"; Dotenv\Dotenv::createImmutable("C:/laragon/www/motociclete")->safeLoad(); $s = require "C:/laragon/www/motociclete/config/settings.php"; echo implode(",", array_keys($s["newsletter"])), "\n";'
```

Expected: `smtp_host,smtp_port,smtp_user,smtp_pass,smtp_secure,from,from_name,reply_to`.

- [ ] **Step 6: Commit**

```bash
cd /c/laragon/www/motociclete
git add src/Newsletter/Transport.php tests/NewsletterTransportTest.php config/settings.php .env.example
git commit -m "feat(newsletter): Transport pentru trimiterea unui mesaj (jurnal in dev)"
```

---

### Task 8: Compunerea în admin și pagina publică

**Files:**
- Create: `src/Admin/CampaignController.php`
- Create: `templates/admin/newsletter/campaigns.twig`
- Create: `templates/admin/newsletter/campaign_form.twig`
- Modify: `src/Controllers/NewsletterController.php` (metoda `view`)
- Modify: `src/Routes.php`
- Modify: `templates/admin/newsletter/index.twig` și `templates/admin/newsletter/subscribers.twig` (link „Campanii")
- Modify: `tests/NewsletterPublicTest.php` (pagina publică)

**Interfaces:**
- Consumes: `Campaigns` și `Composer` (Task 6), `Content::fromServices`, `Content::TYPES`, `Content::splitParagraphs` (Task 2), `Images` (Task 4), `Renderer` + `personalize` + `stripPersonal` (Task 5), `Transport` (Task 7), `Repository::LISTS`, `Repository::findByEmail` (etapa 1), `Admin\BaseController`.
- Produces — rute de admin (sub `{ADMIN_PATH}`):
  - `GET /newsletter/campanii` — lista.
  - `GET /newsletter/campanii/{id}` — formular (`0` = campanie nouă).
  - `POST /newsletter/campanii/{id}` — salvează și regenerează HTML-ul.
  - `GET /newsletter/campanii/{id}/preview` — HTML-ul campaniei, pentru iframe.
  - `POST /newsletter/campanii/{id}/test` — `email`; trimite un test.
  - `POST /newsletter/campanii/{id}/delete`.
- Produces — rută publică: `GET /newsletter/c/{id}-{key}` (`id` numeric, `key` 16 caractere hex) → HTML-ul campaniei fără blocul personal, `X-Robots-Tag: noindex`.
- Numele campaniei pentru UTM: `nl-{id}-{slug al subiectului, cel mult 40 de caractere}`.

- [ ] **Step 1: Testul paginii publice**

În `tests/NewsletterPublicTest.php`, înaintea liniei `nl_done();`, adaugă:

```php

// --- pagina publică „vezi în browser" ----------------------------------------
$campaigns = new App\Newsletter\Campaigns(nl_db());
$cid = $campaigns->create('stiri', 'stiri', 'Campanie de test nl-test.invalid');
$campaigns->update($cid, ['html' => '<html><body><h1>Salut din campanie</h1><a href="%%VIEW_URL%%">vezi</a>'
    . '<!--nl:personal-->Trimis către %%EMAIL%% <a href="%%UNSUB_URL%%">Dezabonare</a><!--/nl:personal--></body></html>']);
$key = (string) $campaigns->find($cid)['view_key'];
register_shutdown_function(static function () use ($campaigns, $cid): void {
    $campaigns->delete($cid);
});

[$c, $b] = $http('GET', $base . "/newsletter/c/{$cid}-{$key}", [], false);
check('pagina publică → 200 cu conținutul campaniei', $c === 200 && str_contains($b, 'Salut din campanie'));
check('pagina publică: fără blocul personal și fără marcaje',
    !str_contains($b, 'Dezabonare') && !str_contains($b, '%%'));
check('pagina publică: linkul „vezi în browser" duce la ea însăși', str_contains($b, "/newsletter/c/{$cid}-{$key}"));
[$c] = $http('GET', $base . "/newsletter/c/{$cid}-" . str_repeat('0', 16), [], false);
check('cheie greșită → 404', $c === 404);
[$c] = $http('GET', $base . '/newsletter/c/999999-' . $key, [], false);
check('campanie inexistentă → 404', $c === 404);
$empty = $campaigns->create('stiri', 'stiri', 'Goală nl-test.invalid');
$ekey = (string) $campaigns->find($empty)['view_key'];
[$c] = $http('GET', $base . "/newsletter/c/{$empty}-{$ekey}", [], false);
check('campanie fără HTML generat → 404', $c === 404);
$campaigns->delete($empty);
```

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterPublicTest.php | grep -v "✓"
```

Expected: cele 6 verificări noi eșuează (ruta nu există), restul trec.

- [ ] **Step 2: Pagina publică**

În `src/Controllers/NewsletterController.php`:

a) În constructor, după `$this->mailer  = $container['mailer'];`, adaugă:

```php
        $this->campaigns = $container['newsletter_campaigns'];
```

și declară proprietatea lângă celelalte: `private \App\Newsletter\Campaigns $campaigns;`

b) Înaintea separatorului `// ------------------------------------------------------------------` care precedă metodele private, adaugă:

```php
    /** GET /newsletter/c/{id}-{key} — „vezi în browser" (fără blocul personal). */
    public function view(Request $request, Response $response, array $args): Response
    {
        $row = $this->campaigns->findPublic((int) ($args['id'] ?? 0), (string) ($args['key'] ?? ''));
        if ($row === null || trim((string) ($row['html'] ?? '')) === '') {
            throw new HttpNotFoundException($request);
        }
        $html = \App\Newsletter\Renderer::personalize(
            \App\Newsletter\Renderer::stripPersonal((string) $row['html']),
            [
                'VIEW_URL'  => $this->siteUrl . '/newsletter/c/' . $row['id'] . '-' . $row['view_key'],
                'UNSUB_URL' => $this->siteUrl . '/',
                'PREFS_URL' => $this->siteUrl . '/',
                'EMAIL'     => '',
            ]
        );
        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

```

c) În `src/Routes.php`, după linia `$app->post('/newsletter/dezabonare/{token:[a-f0-9]{32}}', $nl('prefsSave'));`, adaugă:

```php
    $app->get('/newsletter/c/{id:[0-9]+}-{key:[a-f0-9]{16}}', $nl('view'));
```

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterPublicTest.php | tail -1
```

Expected: `36 verificări, 0 eșecuri`.

- [ ] **Step 3: Scrie controllerul de admin**

`src/Admin/CampaignController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Admin;

use App\Newsletter\Campaigns;
use App\Newsletter\Composer;
use App\Newsletter\Content;
use App\Newsletter\Images;
use App\Newsletter\Renderer;
use App\Newsletter\Repository;
use App\Newsletter\Transport;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Admin Newsletter → Campanii: compunerea unui mesaj (știri sau oferte), salvat ca
 * ciornă cu HTML-ul generat, previzualizare și trimitere de test. Trimiterea către
 * listă vine în etapa următoare.
 */
final class CampaignController extends BaseController
{
    private const PATH = '/newsletter/campanii';
    private const DRAFT_KEY = 'nl_campaign_draft';
    private const WARN_KEY  = 'nl_campaign_warnings';

    /** GET {base}/newsletter/campanii */
    public function index(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $q = $request->getQueryParams();
        try {
            $rows = $this->campaigns()->all();
            $err  = (string) ($q['err'] ?? '');
        } catch (Throwable) {
            $rows = [];
            $err  = 'Tabelul de campanii lipsește sau baza de date nu răspunde. Rulează database/migrate_admin.php.';
        }
        return $this->render($response, 'admin/newsletter/campaigns.twig', [
            'active'    => 'newsletter',
            'campaigns' => $rows,
            'lists'     => Repository::LISTS,
            'site_url'  => $this->siteUrl(),
            'msg'       => (string) ($q['msg'] ?? ''),
            'err'       => $err,
        ]);
    }

    /** GET {base}/newsletter/campanii/{id} */
    public function form(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $id  = (int) ($args['id'] ?? 0);
        $row = $id > 0 ? $this->campaigns()->find($id) : null;
        if ($id > 0 && $row === null) {
            return $this->to($response, self::PATH . '?err=' . rawurlencode('Campania nu există.'));
        }
        // Formularul nesalvat din cauza unei erori are prioritate față de ce e în DB.
        $draft = $_SESSION[self::DRAFT_KEY][$id] ?? null;
        unset($_SESSION[self::DRAFT_KEY][$id]);
        $form = $draft ?? ($row !== null ? $this->formFromRow($row) : $this->defaults());
        $warnings = $_SESSION[self::WARN_KEY][$id] ?? [];
        unset($_SESSION[self::WARN_KEY][$id]);
        $q = $request->getQueryParams();

        return $this->view($response, $id, $row, $form, [
            'saved'    => isset($q['salvat']),
            'msg'      => (string) ($q['msg'] ?? ''),
            'error'    => (string) ($q['err'] ?? ''),
            'warnings' => $warnings,
        ]);
    }

    /** POST {base}/newsletter/campanii/{id} */
    public function save(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $id   = (int) ($args['id'] ?? 0);
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, self::PATH . '/' . $id . '?err=' . rawurlencode('Sesiune expirată. Reîncarcă pagina.'));
        }
        $form = $this->formFrom($body);
        $campaigns = $this->campaigns();
        try {
            $row = $id > 0 ? $campaigns->find($id) : null;
            if ($id > 0 && $row === null) {
                return $this->to($response, self::PATH . '?err=' . rawurlencode('Campania nu există.'));
            }
            if ($row !== null && $row['status'] !== 'draft') {
                return $this->to($response, self::PATH . '/' . $id . '?err=' . rawurlencode('Campania a fost pusă la trimis și nu mai poate fi modificată.'));
            }
            // Întâi compunem: dacă datele sunt greșite, nu rămâne o ciornă goală în listă.
            $input = $this->inputFrom($form);
            $this->composer()->compose($form['tip'], $input, 'nl-0', $this->brand());
            if ($id === 0) {
                $id = $campaigns->create($form['lista'], $form['tip'], $form['subiect']);
            }
            // A doua compunere pune în UTM numele campaniei, care are nevoie de id.
            $name = 'nl-' . $id . '-' . substr(slugify($form['subiect']), 0, 40);
            $out  = $this->composer()->compose($form['tip'], $input, $name, $this->brand());
            $campaigns->update($id, [
                'list_key'   => $form['lista'],
                'type'       => $form['tip'],
                'subject'    => $form['subiect'],
                'preheader'  => $form['preheader'] !== '' ? $form['preheader'] : null,
                'input_json' => json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'html'       => $out['html'],
                'body_text'  => $out['text'],
            ]);
            $_SESSION[self::WARN_KEY][$id] = $out['warnings'];
            return $this->to($response, self::PATH . '/' . $id . '?salvat=1');
        } catch (Throwable $e) {
            // Păstrăm formularul completat: operatorul corectează și salvează din nou.
            $_SESSION[self::DRAFT_KEY][$id] = $form;
            return $this->to($response, self::PATH . '/' . $id . '?err=' . rawurlencode($e->getMessage()));
        }
    }

    /** GET {base}/newsletter/campanii/{id}/preview */
    public function preview(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $row = $this->campaigns()->find((int) ($args['id'] ?? 0));
        $html = $row !== null && trim((string) ($row['html'] ?? '')) !== ''
            ? Renderer::personalize((string) $row['html'], $this->vars($row, 'adresa@exemplu.ro', null))
            : '<p style="font-family:sans-serif;padding:24px">Campania nu are încă un mesaj generat. Salvează formularul.</p>';
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /** POST {base}/newsletter/campanii/{id}/test */
    public function test(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $id   = (int) ($args['id'] ?? 0);
        $back = self::PATH . '/' . $id;
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->to($response, $back . '?err=' . rawurlencode('Sesiune expirată. Reîncarcă pagina.'));
        }
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->to($response, $back . '?err=' . rawurlencode('Introdu o adresă de email validă pentru test.'));
        }
        $row = $this->campaigns()->find($id);
        if ($row === null || trim((string) ($row['html'] ?? '')) === '') {
            return $this->to($response, $back . '?err=' . rawurlencode('Salvează întâi campania.'));
        }
        try {
            $sub = $this->container['newsletter']->findByEmail($email);
        } catch (Throwable) {
            $sub = null;
        }
        $vars = $this->vars($row, $email, $sub);
        $transport = new Transport(
            $this->settings['newsletter'],
            dirname(__DIR__, 2) . '/storage/logs',
            ($this->settings['app']['env'] ?? 'prod') === 'dev'
        );
        $ok = $transport->send(
            $email,
            '[TEST] ' . $row['subject'],
            Renderer::personalize((string) $row['html'], $vars),
            Renderer::personalize((string) ($row['body_text'] ?? ''), $vars, false)
        );
        return $this->to($response, $back . ($ok
            ? '?msg=' . rawurlencode("Test trimis la {$email}.")
            : '?err=' . rawurlencode('Testul nu a putut fi trimis: ' . $transport->lastError())));
    }

    /** POST {base}/newsletter/campanii/{id}/delete */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        if (!$this->csrfOk($this->body($request))) {
            return $this->to($response, self::PATH . '?err=' . rawurlencode('Sesiune expirată. Reîncarcă pagina.'));
        }
        $ok = $this->campaigns()->delete((int) ($args['id'] ?? 0));
        return $this->to($response, self::PATH . ($ok
            ? '?msg=' . rawurlencode('Ciorna a fost ștearsă.')
            : '?err=' . rawurlencode('Doar ciornele pot fi șterse.')));
    }

    // ------------------------------------------------------------------

    /** @param array<string,mixed>|null $row @param array<string,mixed> $form @param array<string,mixed> $extra */
    private function view(Response $response, int $id, ?array $row, array $form, array $extra): Response
    {
        return $this->render($response, 'admin/newsletter/campaign_form.twig', $extra + [
            'active'     => 'newsletter',
            'id'         => $id,
            'campaign'   => $row,
            'form'       => $form,
            'lists'      => Repository::LISTS,
            'types'      => Content::TYPES,
            'editable'   => $row === null || $row['status'] === 'draft',
            'has_html'   => $row !== null && trim((string) ($row['html'] ?? '')) !== '',
            'public_url' => $row !== null ? $this->siteUrl() . '/newsletter/c/' . $row['id'] . '-' . $row['view_key'] : '',
            'site'       => Content::SITE,
        ]);
    }

    private function campaigns(): Campaigns
    {
        return $this->container['newsletter_campaigns'];
    }

    private function composer(): Composer
    {
        $root = dirname(__DIR__, 2);
        return new Composer(
            Content::fromServices($this->container['catalog'], $this->container['bikershop'], $this->container['db']),
            new Renderer($root . '/templates/email/newsletter', $this->siteUrl()),
            new Images($root . '/media', $this->siteUrl())
        );
    }

    private function siteUrl(): string
    {
        return rtrim((string) ($this->settings['app']['url'] ?? ''), '/') . $this->base;
    }

    /** Datele de contact din footerul emailului (aceleași ca în footerul sitului). @return array<string,mixed> */
    private function brand(): array
    {
        $s = $this->container['app_settings'];
        return [
            'address'     => (string) $s->get('address', ''),
            'schedule'    => str_replace('|', ' · ', (string) $s->get('schedule', '')),
            'departments' => array_map(
                static fn (array $d): array => ['label' => (string) ($d['label'] ?? ''), 'phone' => (string) ($d['phone'] ?? '')],
                $this->container['content']->departments()
            ),
        ];
    }

    /**
     * Valorile marcajelor pentru previzualizare și test.
     * @param array<string,mixed> $row @param array<string,mixed>|null $subscriber
     * @return array<string,string>
     */
    private function vars(array $row, string $email, ?array $subscriber): array
    {
        $site  = $this->siteUrl();
        $prefs = $subscriber !== null ? $site . '/newsletter/dezabonare/' . $subscriber['token'] : $site . '/';
        return [
            'VIEW_URL'  => $site . '/newsletter/c/' . $row['id'] . '-' . $row['view_key'],
            'UNSUB_URL' => $subscriber !== null ? $prefs . '?l=' . $row['list_key'] : $prefs,
            'PREFS_URL' => $prefs,
            'EMAIL'     => $email,
        ];
    }

    /** @return array<string,mixed> */
    private function defaults(): array
    {
        return [
            'tip' => 'stiri', 'lista' => 'stiri', 'subiect' => '', 'preheader' => '',
            'titlu' => '', 'imagine' => '', 'imagine_url' => '', 'link' => '', 'buton' => 'Detalii', 'paragrafe' => '',
            'modele' => ['', ''], 'produse' => ['', '', '', '', '', ''], 'produse_oferte' => '',
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function formFromRow(array $row): array
    {
        $saved = json_decode((string) ($row['input_json'] ?? ''), true);
        return (is_array($saved) ? $saved : []) + ['tip' => $row['type'], 'lista' => $row['list_key'], 'subiect' => $row['subject']] + $this->defaults();
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function formFrom(array $body): array
    {
        $s = static fn (string $k): string => trim((string) ($body[$k] ?? ''));
        $list = static fn (string $k, int $n): array => array_map(
            static fn (int $i): string => trim((string) (((array) ($body[$k] ?? []))[$i] ?? '')),
            range(0, $n - 1)
        );
        $tip   = isset(Content::TYPES[$s('tip')]) ? $s('tip') : 'stiri';
        $lista = isset(Repository::LISTS[$s('lista')]) ? $s('lista') : 'stiri';
        return [
            'tip' => $tip, 'lista' => $lista,
            'subiect' => $s('subiect'), 'preheader' => $s('preheader'),
            'titlu' => $s('titlu'), 'imagine' => $s('imagine'), 'imagine_url' => $s('imagine_url'),
            'link' => $s('link'), 'buton' => $s('buton'),
            'paragrafe' => trim((string) ($body['paragrafe'] ?? '')),
            'modele' => $list('modele', 2), 'produse' => $list('produse', 6),
            'produse_oferte' => trim((string) ($body['produse_oferte'] ?? '')),
        ];
    }

    /** Formular → intrarea lui Content. @param array<string,mixed> $f @return array<string,mixed> */
    private function inputFrom(array $f): array
    {
        $filled = static fn (array $xs): array => array_values(array_filter($xs, static fn ($x): bool => trim((string) $x) !== ''));
        return [
            'subiect'   => $f['subiect'],
            'preheader' => $f['preheader'],
            'stire'     => [
                'titlu_html' => $f['titlu'],
                'imagine'    => $f['imagine'] !== '' ? $f['imagine'] : $f['imagine_url'],
                'link'       => $f['link'],
                'buton'      => $f['buton'],
                'paragrafe'  => Content::splitParagraphs($f['paragrafe']),
            ],
            'modele'  => $f['tip'] === 'stiri' ? $filled($f['modele']) : [],
            'produse' => $f['tip'] === 'stiri'
                ? $filled($f['produse'])
                : $filled(preg_split('/\R/', $f['produse_oferte']) ?: []),
        ];
    }
}
```

- [ ] **Step 4: Scrie șabloanele de admin**

`templates/admin/newsletter/campaigns.twig`:

```twig
{% extends 'admin/layout.twig' %}
{% set active = 'newsletter' %}
{% block title %}Campanii newsletter{% endblock %}
{% block actions %}
    <a class="adm-btn" href="{{ admin_base }}/newsletter/abonati">Abonați</a>
    <a class="adm-btn" href="{{ admin_base }}/newsletter">Generator Brevo</a>
    <a class="adm-btn adm-btn--primary" href="{{ admin_base }}/newsletter/campanii/0">+ Campanie nouă</a>
{% endblock %}

{% block content %}
    {% if msg %}<div class="adm-flash adm-flash--ok">{{ msg }}</div>{% endif %}
    {% if err %}<div class="adm-flash adm-flash--err">{{ err }}</div>{% endif %}

    {% set status_names = {draft: 'ciornă', queued: 'în coadă', sending: 'se trimite', paused: 'în pauză', sent: 'trimisă'} %}
    <p class="adm-muted" style="margin-bottom:1rem">O campanie se compune și se salvează ca ciornă; o poți previzualiza și trimite ca test. Trimiterea către listă se adaugă într-o etapă următoare.</p>
    <table class="adm-table">
        <thead><tr><th>Subiect</th><th>Listă</th><th>Tip</th><th>Stare</th><th>Modificată</th><th></th></tr></thead>
        <tbody>
        {% for c in campaigns %}
            <tr>
                <td><strong>{{ c.subject ?: '(fără subiect)' }}</strong></td>
                <td class="adm-muted">{{ lists[c.list_key] ?? c.list_key }}</td>
                <td class="adm-muted">{{ c.type == 'oferte' ? 'oferte' : 'știri' }}</td>
                <td>{% if c.status == 'draft' %}<span class="adm-badge adm-badge--off">ciornă</span>{% else %}<span class="adm-badge adm-badge--on">{{ status_names[c.status] ?? c.status }}</span>{% endif %}</td>
                <td class="adm-muted">{{ (c.updated_at ?: c.created_at)|date('d.m.Y H:i') }}</td>
                <td style="white-space:nowrap">
                    <a class="adm-btn adm-btn--sm" href="{{ admin_base }}/newsletter/campanii/{{ c.id }}">{{ c.status == 'draft' ? 'Editează' : 'Vezi' }}</a>
                    {% if c.has_html %}<a class="adm-btn adm-btn--sm" href="{{ site_url }}/newsletter/c/{{ c.id }}-{{ c.view_key }}" target="_blank" rel="noopener">Pagina publică</a>{% endif %}
                    {% if c.status == 'draft' %}
                    <form method="post" action="{{ admin_base }}/newsletter/campanii/{{ c.id }}/delete" style="display:inline" onsubmit="return confirm('Ștergi ciorna?')">
                        <input type="hidden" name="_csrf" value="{{ csrf }}">
                        <button class="adm-btn adm-btn--sm adm-btn--danger" type="submit">Șterge</button>
                    </form>
                    {% endif %}
                </td>
            </tr>
        {% else %}
            <tr><td colspan="6" class="adm-muted">Nicio campanie încă. Începe cu „+ Campanie nouă".</td></tr>
        {% endfor %}
        </tbody>
    </table>
{% endblock %}
```

`templates/admin/newsletter/campaign_form.twig`:

```twig
{% extends 'admin/layout.twig' %}
{% set active = 'newsletter' %}
{% block title %}{{ id ? 'Campanie: ' ~ (form.subiect ?: 'fără subiect') : 'Campanie nouă' }}{% endblock %}
{% block actions %}<a class="adm-btn" href="{{ admin_base }}/newsletter/campanii">← Toate campaniile</a>{% endblock %}

{% block content %}
{% if saved %}<div class="adm-flash adm-flash--ok">Campania a fost salvată și mesajul regenerat.</div>{% endif %}
{% if msg %}<div class="adm-flash adm-flash--ok">{{ msg }}</div>{% endif %}
{% if error %}<div class="adm-flash adm-flash--err">{{ error }}</div>{% endif %}
{% for w in warnings %}<div class="adm-flash adm-flash--warn">⚠ {{ w }}</div>{% endfor %}
{% if not editable %}<div class="adm-flash adm-flash--warn">Campania a fost pusă la trimis și nu mai poate fi modificată.</div>{% endif %}

<form class="adm-form" method="post" action="{{ admin_base }}/newsletter/campanii/{{ id }}" style="max-width:980px" data-nl-form>
    <input type="hidden" name="_csrf" value="{{ csrf }}">
    <fieldset {{ editable ? '' : 'disabled' }} style="border:0;padding:0;margin:0;min-width:0">

    <div class="adm-fieldset">
        <span class="adm-fieldset__t">Email</span>
        <div class="adm-grid2" style="margin-top:.6rem">
            <label>Tipul mesajului
                <select name="tip" data-nl-type>
                    {% for key, name in types %}<option value="{{ key }}" {{ form.tip == key ? 'selected' }}>{{ name }}</option>{% endfor %}
                </select></label>
            <label>Lista de destinatari
                <select name="lista">
                    {% for key, name in lists %}<option value="{{ key }}" {{ form.lista == key ? 'selected' }}>{{ name }}</option>{% endfor %}
                </select></label>
        </div>
        <label style="margin-top:.6rem">Subiect <input type="text" name="subiect" value="{{ form.subiect }}" maxlength="200" required></label>
        <label style="margin-top:.6rem">Preheader (textul scurt de lângă subiect, în inbox; opțional) <input type="text" name="preheader" value="{{ form.preheader }}" maxlength="200"></label>
    </div>

    <div class="adm-fieldset">
        <span class="adm-fieldset__t">1. <span data-nl-only="stiri">Știrea principală</span><span data-nl-only="oferte">Titlu și introducere</span></span>
        <label style="margin-top:.6rem">Titlu <input type="text" name="titlu" value="{{ form.titlu }}" required></label>
        <div class="adm-grid2" style="margin-top:.6rem">
            <label>Link <span data-nl-only="stiri">(unde duc imaginea și butonul)</span><span data-nl-only="oferte">(butonul de la final; gol = bikershop.ro)</span>
                <input type="url" name="link" value="{{ form.link }}" placeholder="https://…"></label>
            <label>Text buton <input type="text" name="buton" value="{{ form.buton }}"></label>
        </div>
        <div style="margin-top:.8rem">
            <span class="adm-help">Imagine <span data-nl-only="oferte">(opțională)</span> — se servește de pe {{ site }}/media/newsletter/</span>
            <div class="adm-imgmgr" data-imgmgr data-single data-store="url" data-context="newsletter" data-name="imagine">
                <div class="adm-drop" data-dropzone>Trage o imagine aici sau click</div>
                <div class="adm-imgs" data-images>
                    {% if form.imagine %}
                        <div class="adm-img" data-filename="{{ form.imagine }}">
                            <img src="{{ base }}{{ form.imagine }}" alt="">
                            <div class="adm-img__bar"><button type="button" class="adm-img__btn" data-del>Șterge</button></div>
                            <input type="hidden" name="imagine" value="{{ form.imagine }}">
                        </div>
                    {% endif %}
                </div>
            </div>
            <label style="margin-top:.6rem">…sau URL extern al imaginii (folosit doar dacă nu ai încărcat una)
                <input type="url" name="imagine_url" value="{{ form.imagine_url }}" placeholder="https://…/poza.jpg"></label>
        </div>
        <label style="margin-top:.8rem">Text (paragrafe separate prin linie goală; HTML inline permis: &lt;b&gt;, &lt;a href=""&gt;)
            <textarea name="paragrafe" rows="8">{{ form.paragrafe }}</textarea></label>
    </div>

    <div class="adm-fieldset" data-nl-only="stiri">
        <span class="adm-fieldset__t">2. Două modele de pe motociclete.com.ro</span>
        <span class="adm-help">Lipește URL-ul paginii de model. Numele, prețul (și prețul vechi, dacă există reducere), imaginea și descrierea vin din catalog.</span>
        <div class="adm-grid2" style="margin-top:.6rem">
            {% for i in 0..1 %}
                <label>Model {{ i + 1 }} <input type="text" name="modele[]" value="{{ form.modele[i]|default('') }}" placeholder="https://www.motociclete.com.ro/yamaha/…" required></label>
            {% endfor %}
        </div>
    </div>

    <div class="adm-fieldset" data-nl-only="stiri">
        <span class="adm-fieldset__t">3. Șase produse de pe bikershop.ro</span>
        <span class="adm-help">Lipește URL-ul produsului, exact cum apare în browser (cu mărimea/culoarea aleasă, dacă reducerea e doar pe acea variantă). Numele, prețul redus, prețul vechi și imaginea vin live din magazin.</span>
        <div class="adm-grid2" style="margin-top:.6rem">
            {% for i in 0..5 %}
                <label>Produs {{ i + 1 }} <input type="text" name="produse[]" value="{{ form.produse[i]|default('') }}" placeholder="https://bikershop.ro/…/27821-….html" required></label>
            {% endfor %}
        </div>
    </div>

    <div class="adm-fieldset" data-nl-only="oferte">
        <span class="adm-fieldset__t">2. Produsele din ofertă (2–12)</span>
        <span class="adm-help">Câte un URL de produs bikershop.ro pe linie, exact cum apare în browser. Apar câte două pe rând, în ordinea de aici.</span>
        <label style="margin-top:.6rem">Produse <textarea name="produse_oferte" rows="8" placeholder="https://bikershop.ro/…/27821-….html" required>{{ form.produse_oferte }}</textarea></label>
    </div>

    <div class="adm-actions">
        <button class="adm-btn adm-btn--primary" type="submit">Salvează și generează mesajul</button>
    </div>
    </fieldset>
</form>

{% if has_html %}
<div class="adm-fieldset" style="max-width:980px;margin-top:1.4rem">
    <span class="adm-fieldset__t">Previzualizare</span>
    <div class="adm-actions" style="margin:.6rem 0">
        <a class="adm-btn" href="{{ admin_base }}/newsletter/campanii/{{ id }}/preview" target="_blank" rel="noopener">Deschide într-un tab nou</a>
        <a class="adm-btn" href="{{ public_url }}" target="_blank" rel="noopener">Pagina publică „vezi în browser"</a>
        <button type="button" class="adm-btn" data-nl-width="390">Telefon</button>
        <button type="button" class="adm-btn" data-nl-width="700">Desktop</button>
    </div>
    <iframe src="{{ admin_base }}/newsletter/campanii/{{ id }}/preview" title="Previzualizare" data-nl-frame
            style="display:block;width:700px;max-width:100%;height:900px;border:1px solid #e4e4e7;border-radius:8px;background:#f5f5f5"></iframe>
</div>

<form class="adm-fieldset" method="post" action="{{ admin_base }}/newsletter/campanii/{{ id }}/test" style="max-width:980px;margin-top:1.4rem">
    <input type="hidden" name="_csrf" value="{{ csrf }}">
    <span class="adm-fieldset__t">Trimite un test</span>
    <label style="margin-top:.6rem">Adresa de email <input type="email" name="email" required></label>
    <p class="adm-help" style="margin:.6rem 0 0">Subiectul primește prefixul [TEST]. Dacă adresa e un abonat, linkurile de dezabonare sunt cele reale ale lui.</p>
    <div class="adm-actions" style="margin:.8rem 0 0"><button class="adm-btn adm-btn--primary" type="submit">Trimite testul</button></div>
</form>
{% endif %}

<script>
(function () {
    var form = document.querySelector('[data-nl-form]');
    var type = form && form.querySelector('[data-nl-type]');
    if (!type) return;
    // Blocurile unui tip sunt ascunse pentru celălalt; câmpurile ascunse se dezactivează,
    // ca să nu fie cerute de validare și nici trimise.
    function apply() {
        form.querySelectorAll('[data-nl-only]').forEach(function (el) {
            var on = el.getAttribute('data-nl-only') === type.value;
            el.hidden = !on;
            el.querySelectorAll('input, textarea, select').forEach(function (f) { f.disabled = !on; });
        });
        var image = form.querySelector('[name="link"]');
        if (image) image.required = type.value === 'stiri';
    }
    type.addEventListener('change', apply);
    apply();
    var frame = document.querySelector('[data-nl-frame]');
    document.querySelectorAll('[data-nl-width]').forEach(function (btn) {
        btn.addEventListener('click', function () { if (frame) frame.style.width = btn.getAttribute('data-nl-width') + 'px'; });
    });
})();
</script>
{% endblock %}
```

- [ ] **Step 5: Adaugă rutele și linkurile**

În `src/Routes.php`, după linia cu `$adminCtl('SubscriberController', 'unsubscribe'));`, adaugă:

```php
    // Newsletter — campanii (compunere, previzualizare, test)
    $app->get($adminBase . '/newsletter/campanii',                         $adminCtl('CampaignController', 'index'));
    $app->get($adminBase . '/newsletter/campanii/{id:[0-9]+}',             $adminCtl('CampaignController', 'form'));
    $app->post($adminBase . '/newsletter/campanii/{id:[0-9]+}',            $adminCtl('CampaignController', 'save'));
    $app->get($adminBase . '/newsletter/campanii/{id:[0-9]+}/preview',     $adminCtl('CampaignController', 'preview'));
    $app->post($adminBase . '/newsletter/campanii/{id:[0-9]+}/test',       $adminCtl('CampaignController', 'test'));
    $app->post($adminBase . '/newsletter/campanii/{id:[0-9]+}/delete',     $adminCtl('CampaignController', 'delete'));
```

În `templates/admin/newsletter/index.twig`, linia `{% block actions %}…{% endblock %}` devine:

```twig
{% block actions %}<a class="adm-btn adm-btn--primary" href="{{ admin_base }}/newsletter/campanii">Campanii</a> <a class="adm-btn" href="{{ admin_base }}/newsletter/abonati">Abonați</a>{% endblock %}
```

În `templates/admin/newsletter/subscribers.twig`, în `{% block actions %}`, înaintea linkului `Generator`, adaugă:

```twig
    <a class="adm-btn" href="{{ admin_base }}/newsletter/campanii">Campanii</a>
```

- [ ] **Step 6: Verifică fluxul din admin cu `curl`**

Alege din baza locală două modele active și folosește produsele din mesajul AGV K5. Parola utilizatorului temporar se generează la rulare.

```bash
cd /c/laragon/www/motociclete
M="C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe"
MODELS=$("$M" -uroot motociclete -N -e "SELECT CONCAT(brand,'/',slug) FROM products WHERE is_active=1 AND price>0 AND brand='yamaha' ORDER BY (discount_pct>0) DESC, id DESC LIMIT 2" | tr -d '\r')
M1=$(echo "$MODELS" | sed -n 1p); M2=$(echo "$MODELS" | sed -n 2p); echo "modele: $M1 $M2"
TMPPASS=$(openssl rand -hex 12); "$PHP" database/seed_admin_user.php __tmp_nl "$TMPPASS" | tail -1
J=storage/shots/nl-admin.jar; B=http://motociclete.test/dm-control; rm -f $J
T=$(curl -s -c $J "$B/login" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b $J -c $J --data-urlencode "_csrf=$T" -d "username=__tmp_nl" --data-urlencode "password=$TMPPASS" "$B/login"
C=$(curl -s -b $J "$B/newsletter/campanii" | grep -o 'window.CSRF="[^"]*"' | sed 's/.*="//;s/"//')
curl -s -o /dev/null -w "lista=%{http_code}\n" -b $J "$B/newsletter/campanii"
curl -s -o /dev/null -w "formular_nou=%{http_code}\n" -b $J "$B/newsletter/campanii/0"

# 1. salvare cu un produs inexistent: eroare clară, formularul păstrat (Review Focus 5)
curl -s -o /dev/null -w "eroare=%{http_code} %{redirect_url}\n" -b $J --data-urlencode "_csrf=$C" -d "tip=stiri&lista=stiri" \
  --data-urlencode "subiect=Test nl-test.invalid" --data-urlencode "titlu=Titlu de test" --data-urlencode "link=https://bikershop.ro/765-integrale" \
  --data-urlencode "imagine_url=http://motociclete.test/assets/img/newsletter/23-ani.jpg" --data-urlencode "paragrafe=Un paragraf." \
  --data-urlencode "modele[]=$M1" --data-urlencode "modele[]=$M2" \
  -d "produse[]=722786&produse[]=20771&produse[]=725924&produse[]=20820&produse[]=22792&produse[]=999999999" "$B/newsletter/campanii/0" | cut -c1-200
curl -s -b $J "$B/newsletter/campanii/0" | grep -c 'value="Titlu de test"'

# 2. salvare corectă
LOC=$(curl -s -o /dev/null -w "%{redirect_url}" -b $J --data-urlencode "_csrf=$C" -d "tip=stiri&lista=stiri" \
  --data-urlencode "subiect=Test nl-test.invalid" --data-urlencode "preheader=Preheader de test" --data-urlencode "titlu=Titlu de test" \
  --data-urlencode "link=https://bikershop.ro/765-integrale" --data-urlencode "imagine_url=http://motociclete.test/assets/img/newsletter/23-ani.jpg" \
  --data-urlencode "paragrafe=Un paragraf." --data-urlencode "modele[]=$M1" --data-urlencode "modele[]=$M2" \
  --data-urlencode "produse[]=https://bikershop.ro/jachete/722786-79528-jacheta-dainese-tempest-4-d-dry-negrugrialbastru.html" \
  -d "produse[]=20771&produse[]=725924&produse[]=20820&produse[]=22792&produse[]=725942" "$B/newsletter/campanii/0")
echo "salvare: $LOC"; ID=$(echo "$LOC" | grep -o 'campanii/[0-9]*' | grep -o '[0-9]*$')
curl -s -b $J "$B/newsletter/campanii/$ID/preview" -o storage/shots/nl-preview.html -w "preview=%{http_code}\n"
grep -c "utm_campaign=nl-$ID-test-nl-test-invalid" storage/shots/nl-preview.html
grep -o "1\.268 lei\|1\.585 lei" storage/shots/nl-preview.html | sort -u | tr '\n' ' '; echo
grep -c "%%" storage/shots/nl-preview.html
curl -s -o /dev/null -w "preview_fara_login=%{http_code}\n" "$B/newsletter/campanii/$ID/preview"

# 3. test + pagina publică
curl -s -o /dev/null -w "test=%{http_code} %{redirect_url}\n" -b $J --data-urlencode "_csrf=$C" --data-urlencode "email=test@nl-test.invalid" "$B/newsletter/campanii/$ID/test" | cut -c1-160
tail -n 30 storage/logs/newsletter.log | grep -c "SUBJECT: \[TEST\] Test nl-test.invalid"
KEY=$("$M" -uroot motociclete -N -e "SELECT view_key FROM nl_campaigns WHERE id=$ID" | tr -d '\r')
curl -s -o /dev/null -w "public=%{http_code}\n" "http://motociclete.test/newsletter/c/$ID-$KEY"
echo "ID=$ID"
```

Expected: `lista=200`, `formular_nou=200`; `eroare=303` cu `err=` care conține `999999999`, apoi `1` (titlul completat e încă în formular); `salvare:` se termină în `/newsletter/campanii/<ID>?salvat=1`; `preview=200`; numărul de linkuri cu UTM mai mare ca `0`; apar ambele prețuri `1.268 lei` și `1.585 lei` (dacă reducerea din magazin e încă activă); `0` marcaje `%%`; `preview_fara_login=303`; `test=303` cu `msg=`; `1` în jurnal; `public=200`. Dacă BikerShop nu e accesibil local, salvarea eșuează cu mesajul despre produs: verifică atunci fluxul cu tipul `oferte` după deploy sau din rețeaua autorizată și notează.

- [ ] **Step 7: Verifică vizual mesajul real și formularul**

Cu puppeteer (cookie-ul `dm_garage` din `storage/shots/nl-admin.jar`, `waitUntil: 'domcontentloaded'`), fă capturi pentru: `…/newsletter/campanii/$ID/preview` la 700 px și 390 px (pagină întreagă) și `…/newsletter/campanii/$ID` (formularul). Verifică pe mesajul real: imaginile produselor se încarcă de pe `motociclete.test/media/newsletter/bs/`, prețurile reduse apar corect, nimic nu iese din lățime pe 390 px, iar în formular schimbarea tipului pe „Oferte" ascunde modelele și cele 6 câmpuri de produs și arată lista de URL-uri. Ajustează șabloanele dacă e nevoie și reia testele din Task 5.

Curăță:

```bash
cd /c/laragon/www/motociclete
"$M" -uroot motociclete -e "DELETE FROM nl_campaigns WHERE subject LIKE '%nl-test.invalid%'; DELETE FROM admin_users WHERE username='__tmp_nl';"
rm -f storage/shots/nl-admin.jar storage/shots/nl-preview.html storage/shots/*.mjs
```

- [ ] **Step 8: Commit**

```bash
cd /c/laragon/www/motociclete
git add src/Admin/CampaignController.php templates/admin/newsletter/campaigns.twig templates/admin/newsletter/campaign_form.twig \
  templates/admin/newsletter/index.twig templates/admin/newsletter/subscribers.twig \
  src/Controllers/NewsletterController.php src/Routes.php tests/NewsletterPublicTest.php
git commit -m "feat(newsletter): compunerea campaniilor in admin, previzualizare, test si pagina publica"
```

---

### Task 9: Suita de teste, documentație, specificație

**Files:**
- Modify: `tests/run_newsletter_suite.sh`
- Modify: `CLAUDE.md`
- Modify: `docs/superpowers/specs/2026-10-07-newsletter-campanii-design.md`

**Interfaces:**
- Consumes: toate testele din Task 1–8.
- Produces: o singură comandă care rulează toată suita; documentația la zi.

- [ ] **Step 1: Adaugă testele noi în suită**

În `tests/run_newsletter_suite.sh`, lista din `for t in …; do` devine:

```bash
for t in NewsletterAddressTest NewsletterRepositoryTest NewsletterSyncTest NewsletterBrevoImportTest EmailTemplateLinkTest BikerShopReductionTest NewsletterContentTest NewsletterLinksTest NewsletterImagesTest NewsletterRendererTest NewsletterCampaignsTest NewsletterTransportTest NewsletterPublicTest NewsletterRetentionTest FitmentMatcherTest; do
```

```bash
cd /c/laragon/www/motociclete && bash tests/run_newsletter_suite.sh; echo "exit=$?"
```

Expected: fiecare linie se termină cu `0 eșecuri (exit 0)` (ultima: `ALL 16 CHECKS PASSED (exit 0)`), `exit=0`.

- [ ] **Step 2: Actualizează `CLAUDE.md`**

a) În secțiunea „## Newsletter propriu (înlocuiește Brevo pentru campanii)", prima linie devine:

```markdown
Specificație: `docs/superpowers/specs/2026-10-07-newsletter-campanii-design.md`. Etapa 1 (abonați) și etapa 2 (compunere) livrate; trimiterea prin releu SMTP și statisticile sunt etape separate.
```

b) La finalul aceleiași secțiuni (după rândul „- **Teste:** …") adaugă:

```markdown
- **Compunere (etapa 2):** admin `{base}/newsletter/campanii` (`Admin\CampaignController`): formular → `Newsletter\Composer` = `Content` (rezolvă știrea, modelele din catalog și produsele BikerShop, cu preț redus + preț vechi) → `Images` (copiază imaginile BikerShop în `/media/newsletter/bs/`, max 600 px; la eșec rămâne URL-ul original + avertisment) → `Renderer` (mediu Twig PROPRIU pe `templates/email/newsletter/`, funcția `utm(url, bloc)`; șabloane `stiri` și `oferte`). HTML-ul se salvează în `nl_campaigns` (`Newsletter\Campaigns`; doar ciornele se pot modifica/șterge) cu marcajele `%%UNSUB_URL%%`, `%%PREFS_URL%%`, `%%VIEW_URL%%`, `%%EMAIL%%`, completate cu `Renderer::personalize()`. Blocul personal din footer stă între `<!--nl:personal-->…<!--/nl:personal-->` și e scos pe pagina publică `/newsletter/c/{id}-{view_key}` (`noindex`). Trimiterea de test = `Newsletter\Transport` (în dev scrie `storage/logs/newsletter.log` + `newsletter-last.html`); config `newsletter` din `settings.php` (`NL_*`, cu rezervă pe `SMTP_*`/`MAIL_*`).
- **Imaginile fixe ale emailului** (logo, desene tehnice, „23 ani"): `assets/img/newsletter/`. Textele blocurilor fixe: `templates/email/newsletter/_fixed.twig`.
- **Generatorul YAML Brevo** (`Newsletter\Generator`) folosește același `Content`, deci primește și el prețurile reduse; se retrage după prima campanie trimisă din modulul nou.
```

c) În secțiunea „## Convenții", rândul care începe cu „- Prețuri BikerShop = **RON (Lei)**" se termină cu „Reducerile `specific_price` NU sunt citite (preț standard)." Înlocuiește această ultimă propoziție cu:

```markdown
Reducerile din magazin (`ps_specific_price`: procentuale, cu TVA, pe produs sau pe variantă) SUNT aplicate de `Client::withReductions()` (logica în `BikerShop\Reduction`): `price` = prețul de vânzare, `price_old` = prețul de listă, `reduction_pct`. `productsByIds($ids, $limit, $attrs)` primește varianta cerută (`id_product => id_product_attribute`, din URL-ul `/{id}-{attr}-slug.html`); fără variantă: nivelul de produs, apoi varianta implicită. Reducerile în sumă fixă / cu preț fix sunt ignorate (nu există în magazin).
```

- [ ] **Step 3: Actualizează specificația**

În `docs/superpowers/specs/2026-10-07-newsletter-campanii-design.md`:

a) În secțiunea „## Compunere", propoziția „Funcții: previzualizare, trimitere de test la o adresă, pagină publică „vezi în browser" la `/newsletter/{id}-{slug}` (`noindex`)." devine:

```markdown
Funcții: previzualizare, trimitere de test la o adresă, pagină publică „vezi în browser"
la `/newsletter/c/{id}-{cheie}` (`noindex`; cheia aleatoare face ca o ciornă să nu poată
fi ghicită).
```

b) În „### Prețuri", paragraful despre produsele BikerShop devine:

```markdown
- **Produse BikerShop:** `BikerShop\Client` aplică reducerile active din
  `ps_specific_price` (verificat pe date reale: toate sunt procentuale, cu TVA inclus,
  pe produs sau pe variantă). Linkul lipit în formular poartă varianta
  (`/722786-79528-….html`), deci prețul din mesaj e cel al variantei alese. Corecția
  se vede și pe cardurile de accesorii de pe portal, care afișau prețul de listă.
```

c) În „### Linkuri", scoate al doilea punct (cel cu `/nl/c/{link}/{token}`) și adaugă în locul lui:

```markdown
- Numărarea clicurilor (rescrierea linkurilor prin `/nl/c/{link}/{token}`) se adaugă în
  etapa 4; până atunci linkurile sunt directe, cu UTM.
```

- [ ] **Step 4: Commit**

```bash
cd /c/laragon/www/motociclete
git add tests/run_newsletter_suite.sh CLAUDE.md docs/superpowers/specs/2026-10-07-newsletter-campanii-design.md
git commit -m "docs(newsletter): etapa 2 (compunere) - suita de teste, CLAUDE.md, specificatie"
```

- [ ] **Step 5: Livrare (doar cu acordul lui Daniel)**

Se livrează împreună cu etapa 1; pașii ei sunt la finalul planului etapei 1. În plus pentru etapa 2:

1. `migrate_admin.php` pe server creează și `nl_campaigns`.
2. Folderul `media/newsletter/bs/` trebuie să poată fi creat și scris de PHP (ca restul folderelor din `media/`).
3. După deploy, compune o campanie de probă din admin și trimite un test la o adresă proprie: verifică în Gmail (desktop și telefon) că imaginile se încarcă, prețurile sunt corecte și linkurile au UTM.
4. Corecția de prețuri schimbă și ce afișează portalul pe cardurile de accesorii (prețul de vânzare în loc de cel de listă): verifică o pagină de model cu tabul „Piese & accesorii" și pagina `/accesorii`. Cardurile de pe home sunt în cache 6 ore (`storage/cache/home_accessories.cache`); șterge fișierul ca să vezi prețurile noi imediat.
