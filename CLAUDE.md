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
- **Scripturi CLI cu DB/curl** (migrare, prune, orice PDO): rulează cu binarul Laragon
  `C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe` — `php` din PATH (8.2) NU are `pdo_mysql`/`curl`.
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
- **Dual Motors content** `dualmotors_motociclete` + `dualmotors_cfmoto` @ `109.163.231.49` (user `dualmotors_test`). Site-ul Yamaha/CFMOTO actual: `products`, `detalii`, `culori`, `imagini`, `noutati`, `categories`, `clienti_2021`. Sursă pentru migrarea catalogului (M2). Config în `.env` ca `DM_*` → `db.dm` (folosit DOAR de scriptul de migrare).
- **`motociclete`** (local, RW, user `root` fără parolă) — baza proprie a portalului (M2 livrat). Schemă unificată Yamaha+CFMOTO: `categories` (ierarhie 2 niveluri, `brand`), `products` (`brand`, `slug`, `legacy_url` pt. 301), `product_images` (tip `color|gallery|detail`). Vezi `database/schema.sql` + `database/migrate_catalog.php` (rulează cu PHP 8.1 Laragon; doar produse active).
- **`dualmotors_motociclete2026`** @ `109.163.231.49` (user `dualmotors_test`, **full access** — aceleași credențiale ca `DM_*`). Baza portalului pentru staging-ul `motociclete.com.ro/2026/`. Aceeași schemă ca `motociclete` (local); se populează prin **dump local `motociclete` → import aici**. Pe server aplicația o accesează prin `localhost` (`DB_LOCAL_*` în `.env`-ul de pe server). Vezi `DEPLOY.md`.

## Catalog (M2) — pagini categorie + produs

- `src/Catalog/Repository.php` = singurul loc care citește catalogul local (prepared statements, degradare grațioasă). `src/Controllers/CatalogController.php` randează `catalog/{brand,category,product}.twig`.
- **URL curat, brand-first:** `/{brand}/{cat}` (categorie), `/{brand}/{cat}/{sub}` (subcategorie), produs `/{brand}/{cat}/{sub}/{slug}` (Yamaha, cat 2 niveluri) sau `/{brand}/{cat}/{slug}` (CFMOTO, cat plate). Ruta de 3 segmente rezolvă dinamic subcategorie-vs-produs.
- **SEO:** URL-urile vechi `*.html` fac **301** către canonic (match pe `products.legacy_url`). Rute statice (`/`, `/api/*`, `/health`) au prioritate în FastRoute.
- **Imagini:** în `/media/{brand}/{culori|motociclete|detalii}/` (gitignored). Galeria legacy `imagini` e servită din folderul `.../motociclete/`. Numele fișierelor sunt url-encodate la afișare. Vezi `database/README.md` pentru maparea de copiere de pe server.
- Meniul mega e în `src/Support/Navigation.php` (Twig global `nav`), nu în HomeController.
- **Imagini cover** separate: `cover_image` din `products` se servește din `/media/{brand}/cover/` (tip `cover` în `Repository::FOLDER`), restul din `culori/motociclete/detalii`.
- **Prețuri:** stocate în EUR în DB, **cu TVA inclus** (confirmat). Afișare duală EUR + RON (la curs) via funcția Twig `prices(eur)` → `{eur, ron}` (helper `price_dual` din `helpers.php`). Setări în tabela `settings` (`eur_ron_rate`, `vat_pct`, `price_includes_vat`) prin `src/Support/Settings.php`. Reducere = badge `−X%` + preț vechi tăiat.
- **Admin** minimal: `/admin` (HTTP Basic, creds `ADMIN_USER`/`ADMIN_PASS` din `.env`) → `src/Controllers/AdminController.php` editează cursul valutar/TVA. Tabela `settings` NU e ștearsă la re-migrare.
- **Mentenanță media:** `database/prune_media.php` (`--apply`) șterge fișierele din `/media` nereferite în DB (păstrează doar produsele active).

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

- Mobile-first: copiii de grid/flex primesc `min-width:0` (altfel lățimea intrinsecă a
  imaginilor produce overflow orizontal). Grilele catalog: pași 4→3→2→1 coloane; `--container: 1600px`.

## Local development

Rulează direct cu Laragon — fără build step. `composer install`, apoi vizitează
`http://motociclete.test`. `/health` raportează disponibilitatea BikerShop.
Screenshot: `chrome --headless=new --screenshot=<CALE-ABSOLUTĂ>.png --window-size=1440,2600 <url>`
(calea relativă dă „cannot find path"; cale absolută obligatorie).

## Module conexe

- **drivetest** (`c:\laragon\www\drivetest`, `drivetest.test`) — sistem test-ride
  existent (PHP procedural + MySQLi, DB `dualmotors_testdrive`). Portalul face deocamdată
  link spre el (CTA „Programează test ride"); unificare ulterioară (Milestone 4).
