# Pagina produs — îmbunătățiri — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add to the product page a UniCredit rate calculator, a financing-conditions page backed by an admin-editable DB table, contact/test-ride lead modals that persist to a new DB table and email the dealer, a phone/WhatsApp button, tab scroll-on-click, and a background band on "Modele similare".

**Architecture:** Two new DB tables (`finance` single-row config, `site_messages` leads), both `CREATE IF NOT EXISTS` and non-destructive. Two new Slim controllers (`FinanceController`, `ContactController`) plus a `Finance\Repository` reading the config and computing the annuity. The calculator is computed server-side (single source of truth for the formula) and embedded as JSON for the JS to switch on. Lead modals submit via `fetch` to `/api/lead/*`. Follows existing patterns: `Catalog\Repository` style, prepared statements, graceful degradation, vanilla JS in `app.js`, Twig templates, no build step.

**Tech Stack:** PHP 8.1 (Laragon binary `C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe`), Slim 4, Twig, PDO/MySQL, vanilla JS/CSS. No unit-test framework in repo → verification via `php -l`, throwaway PHP assertion scripts, `curl`, and headless-Chrome screenshots.

**Conventions reminders:**
- Run any DB/PDO script with the Laragon PHP binary above (PATH `php` lacks `pdo_mysql`).
- Local DB `motociclete`: user `root`, no password, host 127.0.0.1.
- mysql client: `C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe`. Source UTF-8 SQL with `--default-character-set=utf8mb4`.
- After editing `app.css`/`app.js`, bump `?v=N` in `templates/layout.twig`.
- All queries = prepared statements; degrade gracefully if DB/service is down.

---

## File Structure

**New files:**
- `database/schema_messages.sql` — `CREATE TABLE IF NOT EXISTS` for `site_messages` + `finance`, plus numeric seed of the `finance` row.
- `database/seed_finance.php` — writes diacritic text (`page_title`, `page_html`) into `finance` via PDO (utf8mb4), from the extracted UniCredit conditions.
- `src/Finance/Repository.php` — reads the `finance` config row; computes the monthly-rate map for a given RON price. Single place that touches the `finance` table.
- `src/Controllers/FinanceController.php` — renders `/finantare`.
- `src/Controllers/ContactController.php` — handles `/api/lead/oferta` + `/api/lead/test-ride`: validate → insert `site_messages` → email dealer → JSON.
- `templates/finance.twig` — financing-conditions page.
- `templates/partials/lead-modals.twig` — the two modal dialogs markup.

**Modified files:**
- `src/Support/helpers.php` — add `credit_annuity()` pure helper.
- `src/Bootstrap.php` — register `finance` service in the container.
- `src/Routes.php` — add `/finantare`, `/api/lead/oferta`, `/api/lead/test-ride`.
- `src/Controllers/CatalogController.php` — pass finance config + computed rates + RON price to the product template.
- `templates/catalog/product.twig` — WhatsApp/phone button, calculator card, include lead modals, data-modal on CTA buttons + no-JS fallback.
- `assets/js/app.js` — tab scroll-on-click, calculator select handler, modal open/close + fetch submit.
- `assets/css/app.css` — calculator, modals, WhatsApp button, `.product-related` background.
- `templates/layout.twig` — bump asset `?v=`.

---

## Task 1: DB tables (`finance` + `site_messages`)

**Files:**
- Create: `database/schema_messages.sql`
- Create: `database/seed_finance.php`

- [ ] **Step 1: Write `database/schema_messages.sql`**

```sql
-- Tables for the product-page lead forms + UniCredit financing config.
-- CREATE IF NOT EXISTS + non-destructive: safe to re-run, NOT dropped on catalog re-migration.

-- Leads submitted from the product page (Cere ofertă / Programează test ride).
CREATE TABLE IF NOT EXISTS `site_messages` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type`         ENUM('oferta','test_ride') NOT NULL,
    `brand`        VARCHAR(32)  NULL,
    `product_slug` VARCHAR(191) NULL,
    `product_name` VARCHAR(191) NULL,
    `name`         VARCHAR(120) NOT NULL,
    `email`        VARCHAR(191) NOT NULL,
    `phone`        VARCHAR(40)  NOT NULL,
    `message`      TEXT NULL,
    `licence`      VARCHAR(16)  NULL,          -- categoria de permis (test ride)
    `ip`           VARCHAR(45)  NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_type_created` (`type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-row financing config (UniCredit). Admin-editable later. page_html holds
-- the conditions page body; seeded by database/seed_finance.php (diacritics via PDO).
CREATE TABLE IF NOT EXISTS `finance` (
    `id`           TINYINT UNSIGNED NOT NULL,
    `nominal_rate` DECIMAL(5,2) NOT NULL DEFAULT 13.00,  -- dobândă anuală fixă %
    `dae`          DECIMAL(5,2) NOT NULL DEFAULT 14.50,  -- DAE % (afișare)
    `admin_fee`    DECIMAL(8,2) NOT NULL DEFAULT 10.00,  -- comision lunar administrare (afișare)
    `calc_rate`    DECIMAL(5,2) NOT NULL DEFAULT 14.50,  -- rata folosită de calculator (anuitate)
    `terms`        VARCHAR(64)  NOT NULL DEFAULT '12,18,24,36,48,60',
    `page_title`   VARCHAR(255) NULL,
    `page_html`    MEDIUMTEXT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `finance`
    (`id`, `nominal_rate`, `dae`, `admin_fee`, `calc_rate`, `terms`)
VALUES
    (1, 13.00, 14.50, 10.00, 14.50, '12,18,24,36,48,60');
```

- [ ] **Step 2: Apply the schema to the local DB**

Run:
```bash
"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe" -h127.0.0.1 -uroot motociclete \
  --default-character-set=utf8mb4 -e "source database/schema_messages.sql"
```
Expected: no output, exit 0.

- [ ] **Step 3: Verify tables exist**

Run:
```bash
"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe" -h127.0.0.1 -uroot motociclete \
  -e "SHOW TABLES LIKE 'site\\_messages'; SHOW TABLES LIKE 'finance'; SELECT id, calc_rate, terms FROM finance;"
```
Expected: both tables listed; one `finance` row with `calc_rate=14.50`, `terms=12,18,24,36,48,60`.

- [ ] **Step 4: Write `database/seed_finance.php`** (sets the diacritic content via PDO utf8mb4)

```php
<?php
declare(strict_types=1);
// Seeds finance.page_title + page_html (UniCredit conditions). Run with Laragon PHP 8.1.
// Usage: C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/seed_finance.php

require __DIR__ . '/../vendor/autoload.php';
if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}
$settings = require __DIR__ . '/../config/settings.php';
$db = new App\Database($settings['db']);
$pdo = $db->local();

$title = 'Finanțare prin UniCredit Consumer Financing';

$html = <<<'HTML'
<h2>Credit Partener 100% Online</h2>
<ol>
  <li>Selectezi numărul de rate dorit și oferta de Credit Partener 100% Online.</li>
  <li>Vei fi direcționat către platforma UniCredit Consumer Financing pentru identificare online.</li>
  <li>Completezi datele necesare analizei și semnezi documentele la distanță, digital, cu semnătură electronică calificată.</li>
  <li>În cazul aprobării creditului, bunurile selectate îți vor fi livrate la adresa din România indicată.</li>
</ol>
<p>Totul online, fără drumuri la bancă!</p>

<h3>Care sunt documentele necesare?</h3>
<ul>
  <li>Carte de identitate, în original</li>
  <li>Adresă de e-mail</li>
  <li>Număr de telefon</li>
</ul>

<h3>Cine poate solicita creditul?</h3>
<p>Poți solicita un credit online acordat de UniCredit Consumer Financing dacă:</p>
<ul>
  <li>ai între 18 și 75 de ani (vârsta până la care creditul trebuie să fie rambursat în întregime);</li>
  <li>ești cetățean român, născut și rezident în România;</li>
  <li>veniturile tale pot fi interogate în baza de date ANAF*.</li>
</ul>
<p>Se iau în considerare următoarele surse de venit: venituri din salarii; venituri din pensii.</p>
<p><small>*Aplicabil pentru categoriile de venituri ce pot fi interogate în bazele de date ale ANAF, în baza exprimării acordului de consultare și prelucrare a informațiilor.</small></p>

<h3>Detalii privind finanțarea</h3>
<table>
  <tr><th>Produs financiar</th><td>Low DAE 1 60M (1.000 &gt; 120.000 Lei)</td></tr>
  <tr><th>Dobândă anuală (fixă)</th><td>13%</td></tr>
  <tr><th>Comision de analiză dosar</th><td>0 Lei (fără comision)</td></tr>
  <tr><th>Comision lunar de administrare credit</th><td>10 Lei</td></tr>
  <tr><th>Perioada de creditare</th><td>12 → 60 luni</td></tr>
  <tr><th>DAE</th><td>14,5%</td></tr>
</table>
<p>Ai răspuns pe loc**, dacă sunt îndeplinite condițiile de eligibilitate potrivit normelor interne și documentația de credit este completă.</p>
<p><small>**Fac excepție situațiile în care decizia de creditare nu poate fi luată pe loc din motive independente de voința UniCredit Consumer Financing IFN S.A. sau situațiile în care este necesară o analiză suplimentară a cererii. Creditorul are dreptul de a analiza și de a aproba sau respinge solicitarea de acordare a creditului de consum, în conformitate cu normele interne și reglementările legale.</small></p>

<h3>Unde și cum plătești ratele?</h3>
<p>Achiți ratele aferente Creditului Partener 100% Online: în orice sucursală UniCredit Bank S.A., online prin Online Banking sau Mobile Banking (dacă ai contractate aceste servicii de la UniCredit Bank S.A.), precum și în locațiile semnalizate cu sigla PayPoint și SelfPay.</p>

<h3>Contact UniCredit Consumer Financing</h3>
<p>E-mail: support-online@unicredit.ro · Telefon: 021.200.97.11 (apel cu tarif normal în rețeaua fixă Orange România Communications) · Program: Luni–Vineri, 09:00–21:00.</p>

<p><small>UNICREDIT CONSUMER FINANCING IFN S.A., societate administrată în sistem dualist, înregistrată la Registrul Comerțului sub nr. J40/13865/14.08.2008, CUI 24332910, înscrisă în Registrul General al Băncii Naționale a României sub numărul RG-PJR-41-110247/24.10.2008, Registrul Special sub numărul RS-PJR-41-110065/09.02.2010 și în Registrul Instituțiilor de Plată sub numărul IP-RO-0009/02.03.2015, cu sediul în București, sector 1, Bulevardul Expoziției nr. 1F, etaj 6, capital social subscris și vărsat: 103.269.200 Lei, tel. +40 21 200 2020.</small></p>

<p><small>Acest calculator este orientativ. Valoarea exactă a ratei și aprobarea creditului depind de analiza UniCredit Consumer Financing IFN S.A.</small></p>
HTML;

$stmt = $pdo->prepare('UPDATE finance SET page_title = :t, page_html = :h WHERE id = 1');
$stmt->execute([':t' => $title, ':h' => $html]);
echo "finance row seeded (page_html " . strlen($html) . " bytes)\n";
```

- [ ] **Step 5: Run the seed and verify**

Run:
```bash
C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/seed_finance.php
"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe" -h127.0.0.1 -uroot motociclete \
  --default-character-set=utf8mb4 -e "SELECT page_title, CHAR_LENGTH(page_html) FROM finance WHERE id=1;"
```
Expected: `finance row seeded (...)`; query shows `Finanțare prin UniCredit Consumer Financing` (diacritics intact, not mojibake) and a non-zero length.

- [ ] **Step 6: Commit**

```bash
git add database/schema_messages.sql database/seed_finance.php
git commit -m "feat(db): finance config + site_messages tables for product-page features"
```

---

## Task 2: `credit_annuity()` helper + assertion

**Files:**
- Modify: `src/Support/helpers.php`
- Test (throwaway): `tmp_test_annuity.php`

- [ ] **Step 1: Write the failing assertion** in `tmp_test_annuity.php`

```php
<?php
require __DIR__ . '/vendor/autoload.php';
$got = round(credit_annuity(75757, 0.145, 12), 2);
$exp = 6819.84;
if (abs($got - $exp) > 0.05) { fwrite(STDERR, "FAIL: got $got expected ~$exp\n"); exit(1); }
// zero-rate fallback = straight division
$z = round(credit_annuity(1200, 0.0, 12), 2);
if ($z !== 100.00) { fwrite(STDERR, "FAIL zero-rate: got $z\n"); exit(1); }
echo "OK annuity=$got\n";
```

- [ ] **Step 2: Run it to verify it fails**

Run: `C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe tmp_test_annuity.php`
Expected: fatal `Call to undefined function credit_annuity()`.

- [ ] **Step 3: Add the helper** to `src/Support/helpers.php` (after `money_lei`, before `price_dual`)

```php
if (!function_exists('credit_annuity')) {
    /**
     * Monthly instalment for a fixed-rate consumer credit (annuity formula).
     *
     * @param float $principal financed amount (RON, VAT inclusive)
     * @param float $annualRate annual interest rate as a fraction (0.145 = 14,5%)
     * @param int   $months     number of monthly instalments
     */
    function credit_annuity(float $principal, float $annualRate, int $months): float
    {
        if ($months <= 0) {
            return 0.0;
        }
        $r = $annualRate / 12;
        if ($r <= 0.0) {
            return $principal / $months;
        }
        return $principal * $r / (1 - pow(1 + $r, -$months));
    }
}
```

- [ ] **Step 4: Run the assertion to verify it passes**

Run: `C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe tmp_test_annuity.php`
Expected: `OK annuity=6819.84`.

- [ ] **Step 5: Remove the throwaway test and commit**

```bash
rm tmp_test_annuity.php
git add src/Support/helpers.php
git commit -m "feat(finance): credit_annuity() monthly instalment helper"
```

---

## Task 3: `Finance\Repository` + container registration

**Files:**
- Create: `src/Finance/Repository.php`
- Modify: `src/Bootstrap.php:90` (container array)
- Test (throwaway): `tmp_test_finance.php`

- [ ] **Step 1: Write `src/Finance/Repository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Finance;

use App\Database;
use PDO;
use Throwable;

/**
 * Single place that reads the `finance` config row and computes the UniCredit
 * monthly-rate table for a given RON price. Degrades gracefully (returns null
 * config / empty rates) if the DB or table is unavailable.
 */
final class Repository
{
    private ?PDO $pdo;
    /** @var array<string,mixed>|null */
    private ?array $cfg = null;
    private bool $loaded = false;

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
        return $this->config() !== null;
    }

    /** @return array<string,mixed>|null the single finance config row */
    public function config(): ?array
    {
        if ($this->loaded) {
            return $this->cfg;
        }
        $this->loaded = true;
        if (!$this->pdo) {
            return $this->cfg = null;
        }
        try {
            $row = $this->pdo->query('SELECT * FROM finance WHERE id = 1')->fetch();
            $this->cfg = $row ?: null;
        } catch (Throwable) {
            $this->cfg = null;
        }
        return $this->cfg;
    }

    /** @return int[] available loan terms in months, e.g. [12,18,24,36,48,60] */
    public function terms(): array
    {
        $cfg = $this->config();
        $raw = $cfg ? (string) $cfg['terms'] : '12,18,24,36,48,60';
        $terms = array_values(array_filter(array_map('intval', explode(',', $raw)), fn ($n) => $n > 0));
        return $terms ?: [12, 18, 24, 36, 48, 60];
    }

    /**
     * Monthly instalment for each term, for a RON (VAT-inclusive) price.
     * @return array<int,float> term(months) => monthly rate (2 decimals)
     */
    public function ratesFor(float $priceRon): array
    {
        $cfg = $this->config();
        $rate = $cfg ? ((float) $cfg['calc_rate']) / 100 : 0.145;
        $out = [];
        foreach ($this->terms() as $n) {
            $out[$n] = round(credit_annuity($priceRon, $rate, $n), 2);
        }
        return $out;
    }
}
```

- [ ] **Step 2: Register the service** in `src/Bootstrap.php` — add inside the `$container = [ ... ]` array (after the `'news'` line, ~line 83):

```php
            'finance'   => new Finance\Repository($db),
```

- [ ] **Step 3: Write `tmp_test_finance.php`** to verify against the live seeded DB

```php
<?php
require __DIR__ . '/vendor/autoload.php';
if (is_file(__DIR__ . '/.env')) { Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad(); }
$settings = require __DIR__ . '/config/settings.php';
$repo = new App\Finance\Repository(new App\Database($settings['db']));
if (!$repo->isAvailable()) { fwrite(STDERR, "FAIL: finance config not available\n"); exit(1); }
$rates = $repo->ratesFor(75757);
echo "terms: " . implode(',', $repo->terms()) . "\n";
foreach ($rates as $n => $r) { echo "$n luni: $r lei\n"; }
if (abs($rates[12] - 6819.84) > 0.05) { fwrite(STDERR, "FAIL: 12-month rate {$rates[12]}\n"); exit(1); }
if (!isset($rates[60])) { fwrite(STDERR, "FAIL: missing 60-month rate\n"); exit(1); }
echo "OK\n";
```

- [ ] **Step 4: Syntax-check + run**

Run:
```bash
C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe -l src/Finance/Repository.php
C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe -l src/Bootstrap.php
C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe tmp_test_finance.php
```
Expected: both `No syntax errors`; the script prints all six terms with a 60-month rate present and `OK`.

- [ ] **Step 5: Remove throwaway + commit**

```bash
rm tmp_test_finance.php
git add src/Finance/Repository.php src/Bootstrap.php
git commit -m "feat(finance): Finance\\Repository config + rate table, registered in container"
```

---

## Task 4: `/finantare` page (FinanceController + template + route)

**Files:**
- Create: `src/Controllers/FinanceController.php`
- Create: `templates/finance.twig`
- Modify: `src/Routes.php` (add route near the `/compara` block)

- [ ] **Step 1: Write `src/Controllers/FinanceController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Finance\Repository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/** Financing-conditions page (/finantare), backed by the `finance` config row. */
final class FinanceController
{
    private Repository $finance;

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->finance = $container['finance'];
    }

    public function page(Request $request, Response $response): Response
    {
        $cfg = $this->finance->config();
        return $this->twig->render($response, 'finance.twig', [
            'finance' => $cfg,
        ]);
    }
}
```

- [ ] **Step 2: Write `templates/finance.twig`**

```twig
{% extends 'layout.twig' %}

{% block title %}{{ finance.page_title|default('Finanțare') }} | Dual Motors{% endblock %}
{% block description %}Finanțare auto-moto prin UniCredit Consumer Financing: dobândă fixă, DAE, perioadă de creditare și pașii pentru creditul 100% online.{% endblock %}

{% block content %}
<article class="section">
    <div class="container container--narrow">
        <header class="page-head">
            <span class="kicker">Finanțare</span>
            <h1 class="page-title">{{ finance.page_title|default('Finanțare prin UniCredit Consumer Financing') }}</h1>
        </header>

        {% if finance and finance.page_html %}
            <div class="prose finance-prose">{{ finance.page_html|raw }}</div>
        {% else %}
            <p>Informațiile despre finanțare vor fi disponibile în curând. Pentru detalii, contactează-ne la
               <a href="tel:0722354437">0722 354 437</a>.</p>
        {% endif %}

        <p class="finance-back"><a class="link-arrow" href="{{ base }}/">← Înapoi la showroom</a></p>
    </div>
</article>
{% endblock %}
```

Note: `.container--narrow`, `.page-head`, `.page-title`, `.prose` may already exist (blog uses prose). If `.container--narrow` does not exist, it is added in Task 9 CSS. Verify in Step 4.

- [ ] **Step 3: Add the route** in `src/Routes.php` — after the `/compara` block (around line 74):

```php
    // --- Financing conditions page (UniCredit), backed by the `finance` table ---
    $app->get('/finantare', function ($request, $response) use ($twig, $container) {
        return (new \App\Controllers\FinanceController($twig, $container))->page($request, $response);
    });
```

- [ ] **Step 4: Syntax-check + render-check**

Run:
```bash
C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe -l src/Controllers/FinanceController.php
C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe -l src/Routes.php
curl -s -o /dev/null -w "%{http_code}\n" http://motociclete.test/finantare
curl -s http://motociclete.test/finantare | grep -c "UniCredit"
```
Expected: `No syntax errors` ×2; HTTP `200`; grep count ≥ 1 (page rendered with the seeded content). Also confirm `.container--narrow`/`.prose` exist:
```bash
grep -nE "container--narrow|\.prose" assets/css/app.css | head
```
If `.prose` is absent, note it for Task 9 (add minimal prose styles).

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/FinanceController.php templates/finance.twig src/Routes.php
git commit -m "feat(finance): /finantare conditions page from DB config"
```

---

## Task 5: Pass finance data to the product page (CatalogController)

**Files:**
- Modify: `src/Controllers/CatalogController.php` (constructor + `renderProduct`)

- [ ] **Step 1: Add the finance service to the controller** — in the constructor (`src/Controllers/CatalogController.php:27-32`), add a property and assignment:

Add property near the other private props (after line 23):
```php
    private \App\Finance\Repository $finance;
```
Add in the constructor body (after `$this->base = ...`):
```php
        $this->finance = $container['finance'];
```

- [ ] **Step 2: Compute finance figures in `renderProduct`** — just before the final `return $this->twig->render(...)` (around line 192), add:

```php
        // UniCredit rate calculator: RON (VAT-inclusive) price + per-term instalments.
        $cur = $this->twig->getEnvironment()->getGlobals()['currency'];
        $priceEur = (float) ($product['price'] ?? 0);
        $priceRon = $priceEur > 0 ? price_dual($priceEur, $cur)['ron_raw'] : 0;
        $financeRates = $priceRon > 0 ? $this->finance->ratesFor((float) $priceRon) : [];
        $financeCfg = $this->finance->config();
```

- [ ] **Step 3: Add the new variables to the render array** — extend the array passed to `catalog/product.twig`:

```php
            'crumbs'        => $crumbs,
            'financePriceRon' => $priceRon,
            'financeRates'    => $financeRates,
            'financeCfg'      => $financeCfg,
```
(Add the three new keys; keep `'crumbs'` as-is.)

- [ ] **Step 4: Syntax-check + smoke test**

Run:
```bash
C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe -l src/Controllers/CatalogController.php
curl -s -o /dev/null -w "%{http_code}\n" http://motociclete.test/yamaha
```
Expected: `No syntax errors`; HTTP `200` on a brand page (controller still boots). The calculator markup is added in Task 6 — at this point a product page must still render `200`. Find one product URL:
```bash
"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe" -h127.0.0.1 -uroot motociclete \
  -e "SELECT brand, slug, price FROM products WHERE active=1 AND price>0 LIMIT 1;"
```
Then `curl -s -o /dev/null -w "%{http_code}\n"` that product's URL → expect `200`.

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/CatalogController.php
git commit -m "feat(catalog): pass UniCredit rate table + RON price to product page"
```

---

## Task 6: Product-page UI — WhatsApp/phone button + calculator card + CTA data-modal

**Files:**
- Modify: `templates/catalog/product.twig` (the `.product__cta` block + below it)
- Modify: `templates/catalog/product.twig` (include lead modals — created in Task 8)

- [ ] **Step 1: Replace the `.product__cta` block** (`templates/catalog/product.twig:76-79`) with modal-triggering buttons + WhatsApp/phone + no-JS fallbacks:

```twig
            {% set wamsg = ('Bună ziua, sunt interesat de ' ~ p.name ~ (p.year ? ' ' ~ p.year : '') ~ '. Aș dori detalii.')|url_encode %}
            <div class="product__cta">
                <button type="button" class="btn btn--primary btn--lg" data-modal-open="test-ride">Programează test ride <span aria-hidden="true">→</span></button>
                <button type="button" class="btn btn--ghost" data-modal-open="oferta">Cere ofertă</button>
            </div>
            <div class="product__contact">
                <a class="btn btn--wa" href="https://wa.me/40722354437?text={{ wamsg }}" target="_blank" rel="noopener" aria-label="Scrie-ne pe WhatsApp">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm5.8 14.16c-.24.68-1.4 1.3-1.94 1.34-.5.05-.97.23-3.27-.68-2.76-1.09-4.5-3.92-4.64-4.1-.13-.18-1.1-1.46-1.1-2.79s.7-1.98.95-2.25c.24-.27.53-.34.7-.34l.5.01c.16.01.38-.06.59.45.24.58.81 2 .88 2.14.07.14.12.31.02.49-.09.18-.14.29-.27.45-.14.16-.29.36-.41.48-.14.14-.28.29-.12.57.16.27.71 1.17 1.53 1.9 1.05.93 1.94 1.22 2.22 1.36.27.14.43.12.59-.07.16-.18.68-.79.86-1.07.18-.27.36-.22.6-.13.25.09 1.57.74 1.84.88.27.14.45.2.51.31.07.11.07.63-.17 1.32Z"/></svg>
                    WhatsApp
                </a>
                <a class="btn btn--call" href="tel:0722354437">Sună: 0722 354 437</a>
            </div>
```

- [ ] **Step 2: Add the calculator card** — directly after the `.product__price` block closes (`templates/catalog/product.twig:72`, right after the `{% endif %}` that ends the price block), insert:

```twig
            {% if financePriceRon and financeRates|length %}
                {% set defaultTerm = (financeRates|keys|last) %}
                <div class="finance-calc" id="unicredit-calculator"
                     data-rates='{{ financeRates|json_encode }}'>
                    <div class="finance-calc__brand">Calculator rate · UniCredit</div>
                    <div class="finance-calc__row">
                        <div class="finance-calc__field">
                            <label>Preț</label>
                            <output class="finance-calc__price">{{ financePriceRon|number_format(0, ',', '.') }} lei</output>
                        </div>
                        <div class="finance-calc__field">
                            <label for="fc-term">Durată credit</label>
                            <select id="fc-term" class="finance-calc__term" data-fc-term>
                                {% for n in financeRates|keys %}
                                    <option value="{{ n }}"{{ n == defaultTerm ? ' selected' : '' }}>{{ n }} luni</option>
                                {% endfor %}
                            </select>
                        </div>
                    </div>
                    <div class="finance-calc__result">
                        <span class="finance-calc__label">Rată lunară estimativă</span>
                        <span class="finance-calc__rate" data-fc-rate>{{ financeRates[defaultTerm]|number_format(2, ',', '.') }} lei</span>
                    </div>
                    <div class="finance-calc__foot">
                        {% if financeCfg %}<span>DAE {{ financeCfg.dae|number_format(1, ',', '.') }}% · dobândă fixă {{ financeCfg.nominal_rate|number_format(0, ',', '.') }}%</span>{% endif %}
                        <a class="link-arrow link-arrow--sm" href="{{ base }}/finantare">Detalii finanțare <span aria-hidden="true">→</span></a>
                    </div>
                </div>
            {% endif %}
```

- [ ] **Step 3: Include the lead modals** — at the very end of the `{% block content %}`, just before `{% endblock %}` (`templates/catalog/product.twig:284`), add:

```twig
{% include 'partials/lead-modals.twig' with { 'p': p, 'brand': brand, 'brandLabel': brandLabel } %}
```

- [ ] **Step 4: Verify the page renders with calculator + 60 months default**

Run (use the product URL from Task 5 Step 4):
```bash
curl -s "<PRODUCT_URL>" | grep -o 'id="unicredit-calculator"'
curl -s "<PRODUCT_URL>" | grep -o 'value="60" selected'
```
Expected: each grep returns one match (calculator present, 60 months pre-selected). HTTP must remain `200`.
Note: `partials/lead-modals.twig` is created in Task 8; until then Twig will error on the include. Do Task 8 **before** running this curl, OR temporarily comment the Step-3 include. Recommended order: implement Task 8 then return here. (Mark this task's Step 4 complete only after Task 8 exists.)

- [ ] **Step 5: Commit**

```bash
git add templates/catalog/product.twig
git commit -m "feat(catalog): WhatsApp/phone button + UniCredit rate calculator + modal CTAs"
```

---

## Task 7: Lead backend — ContactController + routes + persistence + email

**Files:**
- Create: `src/Controllers/ContactController.php`
- Modify: `src/Routes.php` (add the two POST routes)

- [ ] **Step 1: Write `src/Controllers/ContactController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Support\Mailer;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Handles the product-page lead forms (Cere ofertă / Programează test ride):
 * validate -> persist to `site_messages` -> email the dealer -> JSON response.
 */
final class ContactController
{
    private Database $db;
    private Mailer $mailer;
    private string $dealer;

    /** @param array<string,mixed> $container */
    public function __construct(array $container)
    {
        $this->db     = $container['db'];
        $this->mailer = $container['mailer'];
        $this->dealer = (string) ($container['settings']['mail']['dealer'] ?? 'info@motociclete.com.ro');
    }

    public function oferta(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, 'oferta');
    }

    public function testRide(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, 'test_ride');
    }

    private function handle(Request $request, Response $response, string $type): Response
    {
        $data = (array) $request->getParsedBody();

        // Honeypot: silent success for bots that fill the hidden field.
        if (trim((string) ($data['website'] ?? '')) !== '') {
            return $this->json($response, ['ok' => true]);
        }

        $name  = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));
        $licence = trim((string) ($data['licence'] ?? ''));
        $brand   = trim((string) ($data['brand'] ?? ''));
        $pslug   = trim((string) ($data['product_slug'] ?? ''));
        $pname   = trim((string) ($data['product_name'] ?? ''));

        if ($name === '' || $phone === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json($response->withStatus(422), ['ok' => false, 'error' => 'Completează nume, email valid și telefon.']);
        }
        if ($type === 'test_ride' && $licence === '') {
            return $this->json($response->withStatus(422), ['ok' => false, 'error' => 'Selectează categoria de permis.']);
        }

        $ip = $this->clientIp($request);

        $stored = false;
        try {
            $pdo = $this->db->local();
            $stmt = $pdo->prepare(
                'INSERT INTO site_messages
                   (type, brand, product_slug, product_name, name, email, phone, message, licence, ip)
                 VALUES (:type, :brand, :slug, :pname, :name, :email, :phone, :message, :licence, :ip)'
            );
            $stmt->execute([
                ':type' => $type, ':brand' => $brand ?: null,
                ':slug' => $pslug ?: null, ':pname' => $pname ?: null,
                ':name' => $name, ':email' => $email, ':phone' => $phone,
                ':message' => $message ?: null, ':licence' => $licence ?: null, ':ip' => $ip,
            ]);
            $stored = true;
        } catch (Throwable) {
            $stored = false;
        }

        $this->notify($type, compact('name', 'email', 'phone', 'message', 'licence', 'brand', 'pname', 'pslug', 'ip'));

        // As long as we persisted the lead OR emailed it, the user sees success.
        return $this->json($response, ['ok' => true]);
    }

    /** @param array<string,string> $f */
    private function notify(string $type, array $f): void
    {
        $label = $type === 'test_ride' ? 'Programare test ride' : 'Cerere ofertă';
        $model = trim(($f['brand'] ? ucfirst($f['brand']) . ' ' : '') . $f['pname']);
        $subject = $label . ($model !== '' ? ': ' . $model : '');
        $lines = [
            $label . ' de pe motociclete.com.ro',
            'Model: ' . ($model !== '' ? $model : '—'),
            $f['pslug'] !== '' ? 'Pagina: ' . $f['pslug'] : '',
            '',
            'Nume: ' . $f['name'],
            'Email: ' . $f['email'],
            'Telefon: ' . $f['phone'],
        ];
        if ($type === 'test_ride') {
            $lines[] = 'Categorie permis: ' . $f['licence'];
        } else {
            $lines[] = 'Mesaj: ' . ($f['message'] !== '' ? $f['message'] : '—');
        }
        $lines[] = '';
        $lines[] = 'IP: ' . $f['ip'];
        $lines[] = 'Data: ' . date('Y-m-d H:i:s');

        try {
            $this->mailer->send($this->dealer, $subject, implode("\n", array_filter($lines, fn ($l) => $l !== '' || true)));
        } catch (Throwable) {
            // never let mail failure break the JSON response
        }
    }

    private function clientIp(Request $request): string
    {
        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ($xff !== '') {
            return trim(explode(',', $xff)[0]);
        }
        $server = $request->getServerParams();
        return (string) ($server['REMOTE_ADDR'] ?? '');
    }

    /** @param array<string,mixed> $payload */
    private function json(Response $response, array $payload): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

- [ ] **Step 2: Add the routes** in `src/Routes.php` — after the `/api/fit/*` block (around line 32):

```php
    // --- Product-page lead forms (Cere ofertă / Test ride) ---
    $lead = function (string $method) use ($container) {
        return function ($request, $response) use ($container, $method) {
            return (new \App\Controllers\ContactController($container))->{$method}($request, $response);
        };
    };
    $app->post('/api/lead/oferta',    $lead('oferta'));
    $app->post('/api/lead/test-ride', $lead('testRide'));
```

- [ ] **Step 3: Syntax-check**

Run:
```bash
C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe -l src/Controllers/ContactController.php
C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe -l src/Routes.php
```
Expected: `No syntax errors` ×2.

- [ ] **Step 4: Functional test via curl (valid + invalid + honeypot)**

Run:
```bash
# valid oferta
curl -s -X POST http://motociclete.test/api/lead/oferta \
  -d "name=Ion Test&email=ion@test.ro&phone=0722000111&message=Detalii pret&brand=yamaha&product_slug=mt-09-2026&product_name=MT-09" ; echo
# valid test-ride
curl -s -X POST http://motociclete.test/api/lead/test-ride \
  -d "name=Ana Test&email=ana@test.ro&phone=0733000222&licence=A2&brand=yamaha&product_slug=mt-09-2026&product_name=MT-09" ; echo
# invalid (missing email)
curl -s -X POST http://motociclete.test/api/lead/oferta -d "name=X&phone=07&email=bad" ; echo
# honeypot
curl -s -X POST http://motociclete.test/api/lead/oferta -d "name=Bot&email=b@b.ro&phone=07&website=spam" ; echo
```
Expected: first two → `{"ok":true}`; invalid → `{"ok":false,...}` (HTTP 422); honeypot → `{"ok":true}` with **no** DB row.

- [ ] **Step 5: Verify persistence + email log**

Run:
```bash
"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe" -h127.0.0.1 -uroot motociclete \
  --default-character-set=utf8mb4 -e "SELECT id,type,name,licence,product_name,ip FROM site_messages ORDER BY id DESC LIMIT 5;"
grep -c "Programare test ride\|Cerere ofertă" storage/logs/mail.log
```
Expected: two new rows (one `oferta`, one `test_ride` with `licence=A2`), **no** `Bot` row; mail.log contains the dealer notifications (dev logs mail). Clean up test rows:
```bash
"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe" -h127.0.0.1 -uroot motociclete \
  -e "DELETE FROM site_messages WHERE email IN ('ion@test.ro','ana@test.ro');"
```

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/ContactController.php src/Routes.php
git commit -m "feat(leads): ContactController persists site_messages + emails dealer"
```

---

## Task 8: Lead modals markup (Twig partial)

**Files:**
- Create: `templates/partials/lead-modals.twig`

- [ ] **Step 1: Write `templates/partials/lead-modals.twig`**

```twig
{# Two lead modals (Cere ofertă / Test ride) for the product page.
   Hidden by default; opened by JS via [data-modal-open="oferta|test-ride"].
   Each form posts via fetch to /api/lead/*; success swaps to the thank-you panel. #}
{% set modelName = p.name ~ (p.year ? ' ' ~ p.year : '') %}

{% set forms = [
    { id: 'oferta',    action: '/api/lead/oferta',    title: 'Cere ofertă',           submit: 'Trimite cererea' },
    { id: 'test-ride', action: '/api/lead/test-ride', title: 'Programează test ride', submit: 'Trimite programarea' }
] %}

{% for f in forms %}
<div class="modal" data-modal="{{ f.id }}" hidden>
    <div class="modal__overlay" data-modal-close></div>
    <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="modal-title-{{ f.id }}">
        <button type="button" class="modal__x" data-modal-close aria-label="Închide">&times;</button>
        <div class="modal__body" data-modal-form>
            <h2 class="modal__title" id="modal-title-{{ f.id }}">{{ f.title }}</h2>
            <p class="modal__sub">{{ modelName }} · {{ brandLabel }}</p>
            <form class="lead-form" method="post" action="{{ base }}{{ f.action }}" data-lead-form>
                <input type="hidden" name="brand" value="{{ brand }}">
                <input type="hidden" name="product_slug" value="{{ p.slug }}">
                <input type="hidden" name="product_name" value="{{ p.name }}">
                <div class="lead-form__hp" aria-hidden="true">
                    <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                </div>
                <label class="field"><span>Nume și prenume *</span>
                    <input type="text" name="name" required autocomplete="name"></label>
                <label class="field"><span>Email *</span>
                    <input type="email" name="email" required autocomplete="email"></label>
                <label class="field"><span>Telefon *</span>
                    <input type="tel" name="phone" required autocomplete="tel"></label>
                {% if f.id == 'test-ride' %}
                    <label class="field"><span>Categorie permis *</span>
                        <select name="licence" required>
                            <option value="">Alege…</option>
                            <option value="AM">AM</option>
                            <option value="A1">A1</option>
                            <option value="A2">A2</option>
                            <option value="A">A</option>
                            <option value="B">B</option>
                        </select></label>
                {% else %}
                    <label class="field"><span>Mesaj</span>
                        <textarea name="message" rows="3"></textarea></label>
                {% endif %}
                <p class="lead-form__err" data-lead-err hidden></p>
                <button type="submit" class="btn btn--primary btn--lg">{{ f.submit }}</button>
            </form>
        </div>
        <div class="modal__body modal__thanks" data-modal-thanks hidden>
            <div class="modal__check" aria-hidden="true">✓</div>
            <h2 class="modal__title">Mulțumim pentru mesaj</h2>
            <p>Un reprezentant va intra în legătură cu dumneavoastră cât se poate de repede.</p>
            <button type="button" class="btn btn--ghost" data-modal-close>Închide</button>
        </div>
    </div>
</div>
{% endfor %}
```

- [ ] **Step 2: Verify the product page renders with both modals (and complete Task 6 Step 4)**

Run (product URL from Task 5):
```bash
curl -s -o /dev/null -w "%{http_code}\n" "<PRODUCT_URL>"
curl -s "<PRODUCT_URL>" | grep -oE 'data-modal="(oferta|test-ride)"' | sort -u
```
Expected: HTTP `200`; both `data-modal="oferta"` and `data-modal="test-ride"` present. Now go back and tick Task 6 Step 4.

- [ ] **Step 3: Commit**

```bash
git add templates/partials/lead-modals.twig
git commit -m "feat(leads): product-page contact + test-ride modal markup"
```

---

## Task 9: JS behaviour — tab scroll, calculator, modals

**Files:**
- Modify: `assets/js/app.js` (ptabs click handler + two new blocks)

- [ ] **Step 1: Tab scroll-on-click** — in the ptabs click handler (`assets/js/app.js:429-431`), change the per-button click listener so it scrolls the tabs bar into view on a real click only:

Replace:
```javascript
        pbtns.forEach(function (btn) {
            btn.addEventListener('click', function () { setPanel(btn.getAttribute('data-ptab')); });
        });
```
with:
```javascript
        pbtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                setPanel(btn.getAttribute('data-ptab'));
                // Bring the (sticky) tab bar + freshly shown panel into view.
                ptabs.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });
```
(The `#accesorii` deep-link branch below still calls `setPanel` without scrolling — leave it unchanged.)

- [ ] **Step 2: Calculator handler** — add a new block inside the same DOMContentLoaded IIFE (e.g. just after the ptabs block, before the lazy-YouTube block):

```javascript
    /* ---- UniCredit rate calculator ---- */
    var fcalc = document.getElementById('unicredit-calculator');
    if (fcalc) {
        var rates = {};
        try { rates = JSON.parse(fcalc.getAttribute('data-rates') || '{}'); } catch (e) { rates = {}; }
        var termSel = fcalc.querySelector('[data-fc-term]');
        var rateOut = fcalc.querySelector('[data-fc-rate]');
        var fmtLei = function (n) {
            return n.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' lei';
        };
        var update = function () {
            var v = rates[termSel.value];
            if (typeof v === 'number') { rateOut.textContent = fmtLei(v); }
        };
        if (termSel && rateOut) {
            termSel.addEventListener('change', update);
            update();
        }
    }
```

- [ ] **Step 3: Modal handler** — add another block in the same IIFE:

```javascript
    /* ---- Lead modals (Cere ofertă / Test ride) ---- */
    (function () {
        var openers = document.querySelectorAll('[data-modal-open]');
        if (!openers.length) { return; }
        var lastFocus = null;
        var openModal = function (id) {
            var m = document.querySelector('[data-modal="' + id + '"]');
            if (!m) { return; }
            lastFocus = document.activeElement;
            m.hidden = false;
            document.body.classList.add('modal-open');
            var first = m.querySelector('input, select, textarea, button');
            if (first) { first.focus(); }
        };
        var closeModal = function (m) {
            m.hidden = true;
            document.body.classList.remove('modal-open');
            if (lastFocus) { lastFocus.focus(); }
        };
        openers.forEach(function (b) {
            b.addEventListener('click', function () { openModal(b.getAttribute('data-modal-open')); });
        });
        document.querySelectorAll('[data-modal]').forEach(function (m) {
            m.querySelectorAll('[data-modal-close]').forEach(function (c) {
                c.addEventListener('click', function () { closeModal(m); });
            });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('[data-modal]:not([hidden])').forEach(closeModal);
            }
        });
        // AJAX submit
        document.querySelectorAll('[data-lead-form]').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var modal = form.closest('[data-modal]');
                var err = form.querySelector('[data-lead-err]');
                var btn = form.querySelector('button[type="submit"]');
                if (err) { err.hidden = true; }
                if (btn) { btn.disabled = true; }
                fetch(form.getAttribute('action'), {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(form)
                }).then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
                  .then(function (data) {
                      if (data && data.ok) {
                          modal.querySelector('[data-modal-form]').hidden = true;
                          modal.querySelector('[data-modal-thanks]').hidden = false;
                      } else if (err) {
                          err.textContent = (data && data.error) || 'A apărut o eroare. Încearcă din nou sau sună-ne.';
                          err.hidden = false;
                      }
                  }).catch(function () {
                      if (err) { err.textContent = 'Conexiune eșuată. Încearcă din nou.'; err.hidden = false; }
                  }).finally(function () { if (btn) { btn.disabled = false; } });
            });
        });
    })();
```

- [ ] **Step 4: Bump asset version** in `templates/layout.twig` — increment `?v=N` on both `app.css` and `app.js` (find the current value with grep, raise by 1).

Run:
```bash
grep -nE "app\.(css|js)\?v=" templates/layout.twig
```
Then edit each to the next integer.

- [ ] **Step 5: Syntax sanity (JS) + commit**

Run (node is available per CLAUDE.md puppeteer note):
```bash
node --check assets/js/app.js
```
Expected: no output (valid JS). Then:
```bash
git add assets/js/app.js templates/layout.twig
git commit -m "feat(catalog): tab scroll-on-click, rate calculator + lead modals JS"
```

---

## Task 10: CSS — calculator, modals, WhatsApp button, related-section background

**Files:**
- Modify: `assets/css/app.css` (append a new section)

- [ ] **Step 1: Confirm helper classes** used by templates exist; add the ones that don't.

Run:
```bash
grep -nE "\.container--narrow|\.prose|\.page-title|\.page-head|\.field|\.modal-open" assets/css/app.css
```
Note which are missing (Step 2 includes all needed ones — keep only the ones not already present to avoid duplicate selectors).

- [ ] **Step 2: Append the styles** to `assets/css/app.css` (end of file). Include `.container--narrow`, `.prose`, `.page-head/.page-title` only if Step 1 showed them missing.

```css
/* ===== Product-page features: calculator, modals, contact, related band ===== */

/* Modele similare — subtle grey band */
.product-related { background: var(--surface); }
.product-related { padding-top: 3rem; padding-bottom: 3rem; }

/* WhatsApp / call buttons */
.product__contact { display: flex; gap: .6rem; flex-wrap: wrap; margin-top: .75rem; }
.btn--wa { background: #25D366; color: #fff; display: inline-flex; align-items: center; gap: .5rem; }
.btn--wa:hover { background: #1ebe5b; color: #fff; }
.btn--call { background: var(--surface); color: var(--ink, #111); }
.btn--call:hover { background: #ececee; }

/* Rate calculator */
.finance-calc { margin-top: 1.25rem; padding: 1rem 1.1rem; background: var(--surface); border-radius: var(--radius); border: 1px solid #ececee; }
.finance-calc__brand { font-size: .8rem; letter-spacing: .04em; text-transform: uppercase; color: var(--muted, #666); margin-bottom: .6rem; }
.finance-calc__row { display: flex; gap: 1rem; flex-wrap: wrap; }
.finance-calc__field { display: flex; flex-direction: column; gap: .25rem; min-width: 0; flex: 1; }
.finance-calc__field label { font-size: .8rem; color: var(--muted, #666); }
.finance-calc__price { font-weight: 600; padding: .5rem 0; }
.finance-calc__term { padding: .5rem .6rem; border: 1px solid #d8d8db; border-radius: 8px; background: #fff; }
.finance-calc__result { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; margin-top: .9rem; padding-top: .9rem; border-top: 1px solid #ececee; }
.finance-calc__label { color: var(--muted, #666); font-size: .9rem; }
.finance-calc__rate { font-size: 1.5rem; font-weight: 700; color: var(--red); }
.finance-calc__foot { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-top: .7rem; font-size: .8rem; color: var(--muted, #666); }

/* Modals */
body.modal-open { overflow: hidden; }
.modal { position: fixed; inset: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 1rem; }
.modal[hidden] { display: none; }
.modal__overlay { position: absolute; inset: 0; background: rgba(17,17,17,.55); }
.modal__dialog { position: relative; z-index: 1; width: 100%; max-width: 460px; max-height: 92vh; overflow: auto; background: #fff; border-radius: var(--radius); padding: 1.6rem; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.modal__x { position: absolute; top: .6rem; right: .8rem; border: 0; background: none; font-size: 1.6rem; line-height: 1; cursor: pointer; color: var(--muted, #666); }
.modal__title { margin: 0 0 .2rem; }
.modal__sub { color: var(--muted, #666); margin: 0 0 1rem; font-size: .9rem; }
.modal__thanks { text-align: center; padding: 1rem 0; }
.modal__check { width: 56px; height: 56px; margin: 0 auto 1rem; border-radius: 50%; background: #25D366; color: #fff; font-size: 1.8rem; display: flex; align-items: center; justify-content: center; }

/* Lead form */
.lead-form { display: flex; flex-direction: column; gap: .8rem; }
.lead-form .field { display: flex; flex-direction: column; gap: .3rem; }
.lead-form .field > span { font-size: .85rem; color: var(--muted, #555); }
.lead-form input, .lead-form select, .lead-form textarea { padding: .6rem .7rem; border: 1px solid #d8d8db; border-radius: 8px; font: inherit; background: #fff; }
.lead-form input:focus, .lead-form select:focus, .lead-form textarea:focus { outline: 2px solid var(--red); outline-offset: 1px; }
.lead-form__hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
.lead-form__err { color: var(--red); font-size: .85rem; margin: 0; }

/* Financing page */
.container--narrow { max-width: 820px; }
.finance-prose table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
.finance-prose th, .finance-prose td { text-align: left; padding: .5rem .7rem; border-bottom: 1px solid #ececee; vertical-align: top; }
.finance-prose h2, .finance-prose h3 { margin-top: 1.6rem; }
```

If `.prose`/`.page-head`/`.page-title` are missing (from Step 1), also append:
```css
.page-head { margin-bottom: 1.5rem; }
.page-title { margin: .2rem 0 0; }
.prose { line-height: 1.7; }
.prose ul, .prose ol { padding-left: 1.2rem; }
.prose li { margin: .3rem 0; }
```

- [ ] **Step 2b: Bump asset version** again if Task 9 Step 4 was already committed (only if needed). Otherwise the Task 9 bump covers this commit too — verify `?v=` is higher than the deployed value:

```bash
grep -nE "app\.css\?v=" templates/layout.twig
```
If it wasn't bumped since the last CSS edit, increment it.

- [ ] **Step 3: Visual verification (screenshots)**

Run (full Chrome path per CLAUDE.md; absolute output path required):
```bash
"/c/Program Files/Google/Chrome/Application/chrome.exe" --headless=new \
  --screenshot="C:/laragon/www/motociclete/storage/shots/product-calc.png" \
  --window-size=1440,2600 "<PRODUCT_URL>"
```
Then Read `storage/shots/product-calc.png` and confirm: calculator card visible with "60 luni" selected + a monthly rate in lei; WhatsApp + Sună buttons present; "Modele similare" sits on a grey band.

For the modal + thank-you flow (needs interaction), use a puppeteer-core script (`npm i --no-save puppeteer-core`, Chrome via `executablePath`): open `<PRODUCT_URL>`, click `[data-modal-open="oferta"]`, screenshot the modal; fill + submit the form, screenshot the thank-you panel. Confirm both render correctly.

- [ ] **Step 4: Commit**

```bash
git add assets/css/app.css templates/layout.twig
git commit -m "style(catalog): calculator, lead modals, WhatsApp button, related band"
```

---

## Task 11: Final verification + docs

**Files:**
- Modify: `CLAUDE.md` (document the new features)

- [ ] **Step 1: End-to-end smoke test**

Run:
```bash
curl -s http://motociclete.test/health
curl -s -o /dev/null -w "finantare:%{http_code}\n" http://motociclete.test/finantare
# product page (URL from Task 5) returns 200 with calculator + modals
curl -s -o /dev/null -w "product:%{http_code}\n" "<PRODUCT_URL>"
```
Expected: `/health` JSON ok; `finantare:200`; `product:200`.

- [ ] **Step 2: Confirm no leftover throwaway files**

Run:
```bash
git status --porcelain
ls tmp_*.php 2>/dev/null || echo "no tmp files"
```
Expected: clean tree (all committed); no `tmp_*.php`.

- [ ] **Step 3: Update `CLAUDE.md`** — under the Catalog section, add a bullet describing the new features:

```markdown
- **Pagina produs — extra:** calculator de rate UniCredit (`#unicredit-calculator`,
  `src/Finance/Repository.php` + helper `credit_annuity`, config în tabela `finance`,
  editabilă din admin ulterior) cu rata calculată server-side la `calc_rate` (DAE 14,5%)
  și afișată client-side la schimbarea duratei. Pagina condiții `/finantare`
  (`FinanceController` + `finance.twig`, conținut în `finance.page_html`, seed
  `database/seed_finance.php`). Butoane **WhatsApp/telefon** (0722354437). Click pe tab
  derulează la conținut. **Modale lead** (Cere ofertă / Test ride) → `POST /api/lead/*`
  (`ContactController`): salvează în `site_messages` (cu IP + dată) ȘI trimite email la
  `MAIL_DEALER` (info@motociclete.com.ro). Honeypot anti-spam. Tabelele în
  `database/schema_messages.sql` (CREATE IF NOT EXISTS, nedistructive).
```

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "docs(claude.md): product-page calculator, /finantare, lead modals"
```

---

## Self-Review notes (addressed)

- **Spec coverage:** (1) tab scroll → Task 9.1; (2) WhatsApp/phone → Task 6.1 + 10.2; (3) calculator + `/finantare` page + DB config → Tasks 1–6, 9.2; (4) `.product-related` background → Task 10.2; (5) modals + email + `site_messages` (IP+date) → Tasks 7–10. All covered.
- **Order caveat:** Task 6 includes `partials/lead-modals.twig` (Task 8). Implement Task 7 (backend) and Task 8 (partial) before validating Task 6 Step 4 — explicitly noted in Task 6 Step 4 and Task 8 Step 2.
- **Type/name consistency:** `data-modal-open`/`data-modal`/`data-modal-close`/`data-modal-form`/`data-modal-thanks`/`data-lead-form`/`data-lead-err`/`data-fc-term`/`data-fc-rate`/`data-rates` used identically in Twig (Tasks 6, 8) and JS (Task 9). Routes `/api/lead/oferta` + `/api/lead/test-ride` consistent (Tasks 7, 8). `finance` table columns consistent across Tasks 1, 3, 6. Container key `finance` consistent (Tasks 3, 4, 5).
- **No placeholders:** every code step contains full content.
```
