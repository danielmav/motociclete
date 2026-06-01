# CLAUDE.md

Guidance for working in this repository.

## Project Overview

`motociclete.com.ro` — portal pentru **Dual Motors / Dual Tours SRL** (dealer
oficial Yamaha & CFMOTO, showroom Pipera, București). Showroom moto + integrare
cu **BikerShop** (PrestaShop 9) pentru accesorii compatibile și echipament.
Aplicația e în limba română. Vezi `documente/` pentru raportul strategic + logo.

Construit pe milestone-uri (vezi planul aprobat). **Milestone 1 livrat = home page
+ fundație tehnică + identitate vizuală.**

## Stack

- **PHP 8.1** (Apache Laragon servește cu 8.1.10 — CLI e 8.2; `composer.json`
  pinează `platform.php = 8.1.10`).
- **Slim 4** (routing), **Twig** (templating), **PDO** (MySQL/MariaDB), **phpdotenv**.
- Frontend: **CSS + JS vanilla**, fără build step. Fonturi Google (Archivo Expanded / Archivo / Hanken Grotesk).
- **Laragon**, `http://motociclete.test`. Document root = rădăcina proiectului; `.htaccess` rutează tot prin `index.php` și protejează `src/`, `templates/`, `vendor/`, `.env`.

## Arhitectură

| Cale | Rol |
|------|-----|
| `index.php` | Front controller (require autoload + `App\Bootstrap::create()->run()`) |
| `src/Bootstrap.php` | Init env, settings, Slim, Twig, error handling, container; încarcă `src/Routes.php` |
| `src/Routes.php` | Tabel de rute (closure cu `$app`, `$twig`, `$container`) |
| `src/Controllers/` | Controllere (ex. `HomeController`) |
| `src/Database.php` | Factory PDO: `local()` (RW) + `bikershop()` (READ ONLY, null dacă neconfigurat) |
| `src/BikerShop/Client.php` | **Singurul** loc care atinge DB-ul PrestaShop. Read-only. Degradează grațios |
| `src/Support/helpers.php` | Helpers globale (`e`, `money_ron`, `slugify`) — autoload via composer `files` |
| `config/settings.php` | Config citită din `.env` |
| `templates/` | Twig: `layout.twig`, `home.twig`, `partials/{header,footer}` |
| `assets/{css,js,img,fonts}/` | Static |
| `storage/{cache,logs,shots}/` | Twig cache, logs, screenshot-uri de verificare |

## Baze de date (acces remote configurat în `.env`)

- **BikerShop** `bikershop_ps9` @ `5.254.67.10` (read-only, user `bikershop_test`). PrestaShop 9 / MariaDB 10.11, **~334.801 produse**, prefix `ps_`, multistore (filtrează `id_shop=1` ȘI `id_lang=1` pe `product_lang`). Behind Cloudflare → conectare doar la IP origine (3306 nu trece prin Cloudflare); dev IP whitelisted în Remote MySQL.
- **Dual Motors content** `dualmotors_motociclete` + `dualmotors_cfmoto` @ `109.163.231.49` (user `dualmotors_test`). Site-ul Yamaha/CFMOTO actual: `products`, `detalii`, `culori`, `imagini`, `noutati`, `categories`, `clienti_2021`. Sursă pentru migrarea catalogului (M2).
- `dualmotors_portal` (local, RW) — baza proprie a portalului, de creat în M2.

## Fit My Bike — fitment PartsEurope = modulul **LeoPartsFilter**

- `ps_leopartsfilter_make` (235) → `ps_leopartsfilter_model` (18.797) → `ps_leopartsfilter_year` (83.036); nume în tabelele `*_lang`. PK-uri: `id_leopartsfilter_make/model/year`.
- Link produs↔fitment: **`ps_leopartsfilter_product`** (~14.8M rânduri: `id_product` + make/model/year). Există și `ps_leopartsfilter_map_cache` (denormalizat, cu nume). Query compatibilitate ~30ms (indexat).
- `src/BikerShop/Client.php`: `makes()`, `models(makeId)`, `years(makeId,modelId)`, `compatibleProducts(modelId, yearId?, limit)`, `featuredProducts()`.
- **API JSON live:** `GET /api/fit/models?make=`, `/api/fit/years?make=&model=`, `/api/fit/products?model=&year=` (vezi `ApiController`). JS-ul home (`assets/js/app.js`, `[data-fit]`) face cascadarea + randează cardurile.

## Convenții

- Toate query-urile = prepared statements. BikerShop NU se scrie niciodată (read-only).
- **PDO native prepares (emulate=false): un placeholder numit NU se poate repeta.** `id_shop`/`id_lang` (int-uri din config, de încredere) sunt inline în SQL; doar inputul user rămâne bound.
- Cumpărarea rămâne pe BikerShop: produsele fac link la `bikershop.ro/{id}-{slug}.html`; imagini `bikershop.ro/{id_image}-large_default/{slug}.jpg` (servite public, 200).
- Prețuri BikerShop: stocate fără TVA în PrestaShop; afișate `*1.19` (orientativ) — de revizuit.
- Reveal animations: gated pe `.js` (setat inline în `<head>`) → conținut vizibil fără JS.
- Roșu brand: logo e `#FF0000`; pe fundal alb folosim `--red:#E10600` (rafinat). `--red-dark` la hover.

## Design system

`assets/css/app.css` — "Refined Motorsport Showroom": fundal alb, mult spațiu,
imagini mari, un singur accent roșu. Variabile CSS în `:root`. Display = Archivo
Expanded; UI/headings = Archivo; body = Hanken Grotesk. `.media-ph` = placeholder
elegant pentru fotografii (până vin imaginile reale).

## Local development

Rulează direct cu Laragon — fără build step. `composer install`, apoi vizitează
`http://motociclete.test`. `/health` raportează disponibilitatea BikerShop.
Screenshot rapid: `chrome --headless --screenshot=... --window-size=1440,2600 http://motociclete.test/`.

## Module conexe

- **drivetest** (`c:\laragon\www\drivetest`, `drivetest.test`) — sistem test-ride
  existent (PHP procedural + MySQLi, DB `dualmotors_testdrive`). Portalul face deocamdată
  link spre el (CTA „Programează test ride"); unificare ulterioară (Milestone 4).
