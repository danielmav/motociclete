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
- **Probe/diagnostic DB rapid:** scrie un `tmp_*.php` (cu `require vendor/autoload.php`) și rulează-l cu binarul Laragon (apoi șterge-l) — `php -r` inline crapă pe quoting (`$`, `"`, paranteze) în Bash/PowerShell ȘI **n-are autoloaderul Composer** (clasele `App\` dau „not found"). `database/diagnose_db.php` testează cele 3 conexiuni.
- **mysqldump / mysql client** (dump/import DB): `C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/`.
  Conectare la DB remote: pasează parola via env `MYSQL_PWD` (parolele au caractere speciale → evită `-p`). Dev IP whitelisted în Remote MySQL pe serverele remote.
  Extrage parola din `.env` la runtime ca să NU apară în transcript: `export MYSQL_PWD=$(grep -m1 '^DM_PASS=' .env | cut -d= -f2- | tr -d "'")`. NU seta `MYSQL_PWD` pentru `root` local (n-are parolă → „Access denied").
  PowerShell `>` scrie UTF-16 (strică importul) → `mysqldump --result-file=…` + `mysql -e "source …"`. Bash tool NU are cmdleturi PowerShell (`Select-Object`/`-String`).
  Sursarea unui `.sql` UTF-8 cu clientul mysql necesită `--default-character-set=utf8mb4` (altfel diacriticele devin mojibake la INSERT).
  ⚠️ **NU pasa literali UTF-8 cu diacritice direct în linia de comandă `mysql.exe`** (Bash/PowerShell) — pipe-ul trece prin codepage-ul consolei Windows (CP850/CP1252) și corupe byte-urii (dublă-encodare: „ș"→„╚Ö" sau „È™") chiar cu `--default-character-set=utf8mb4`. Scrie/repară date cu diacritice **prin PDO dintr-un `tmp_*.php` UTF-8** (`\u{...}` sau literali UTF-8). Verifică cu `HEX(col)` (ex. `C899`=ș, `C3A9`=é) — clientul `mysql.exe` oricum nu randează diacriticele în consolă.
- **Slim 4** (routing), **Twig** (templating), **PDO** (MySQL/MariaDB), **phpdotenv**.
- Frontend: **CSS + JS vanilla**, fără build step. Fonturi Google (Archivo Expanded / Archivo / Hanken Grotesk).
- **Laragon**, `http://motociclete.test`. Document root = rădăcina proiectului; `.htaccess` rutează tot prin `index.php` și **blochează din web (403)** `src/`, `templates/`, `config/`, `storage/`, `vendor/`, `documente/`, `node_modules/`, `database/`, `.git/` (+ `.env`, `*.md`). `tests/` NU e blocat → scripturile de test/POC rulabile din browser merg în `tests/`, nu în `documente/` (care e și gitignored).

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
- **Dual Motors content** `dualmotors_motociclete` + `dualmotors_cfmoto` @ `109.163.231.49` (user `dualmotors_test`). Site-ul Yamaha/CFMOTO actual: `products`, `detalii`, `culori`, `imagini`, `noutati`, `categories`, `clienti_2021`. Sursă pentru migrarea catalogului (M2) ȘI a blogului. Config în `.env` ca `DM_*` → `db.dm`, folosit **DOAR de scripturile de migrare** (`migrate_catalog.php`, `migrate_news.php`) — la runtime portalul nu mai atinge bazele legacy (vor fi șterse după development). `noutati` există în ambele DB-uri (cfmoto e gol momentan).
- **`motociclete`** (local, RW, user `root` fără parolă) — baza proprie a portalului (M2 livrat). Schemă unificată Yamaha+CFMOTO: `categories` (ierarhie 2 niveluri, `brand`), `products` (`brand`, `slug`, `legacy_url` pt. 301), `product_images` (tip `color|gallery|detail`). Vezi `database/schema.sql` + `database/migrate_catalog.php` (rulează cu PHP 8.1 Laragon; doar produse active).
- **`dualmotors_motociclete2026`** @ `109.163.231.49` (user `dualmotors_test`, **full access** — aceleași credențiale ca `DM_*`). Baza portalului pentru staging-ul `motociclete.com.ro/2026/`. Aceeași schemă ca `motociclete` (local); se populează prin **dump local `motociclete` → import aici**. Pe server aplicația o accesează prin `localhost` (`DB_LOCAL_*` în `.env`-ul de pe server). Vezi `DEPLOY.md`. ID-urile produselor local↔staging sunt aliniate (dump lineage) → sync pe `id` e sigur; DAR pentru tabele cu child ce referă `id` (ex. `yamaha_accessory_models`→`yamaha_accessories.id`), upsert-pe-`id` intră în conflict cu rânduri pre-existente cu altă mapare `id↔cheie-unică` → folosește **oglindă exactă** (delete + insert verbatim cu id explicit). Vezi `tests/sync_accessories_to_staging.php`.
  ⚠️ **Schema de staging rămâne în urma celei locale** dacă importul s-a făcut înaintea unui milestone (ex. a lipsit tot My Garage + `products.bs_product_id` + `clienti.email_norm/telefon_norm`). Sincronizează **chirurgical/non-distructiv**: `CREATE TABLE IF NOT EXISTS`, `ALTER ... ADD COLUMN/KEY IF NOT EXISTS` (MariaDB le suportă), date per-tabel cu `mysqldump --no-create-info --replace`. **Dump+import-ul ÎNTREGII baze e blocat de auto-classifier** (prea agresiv). `/2026/health = true` la `bikershop` = doar conectivitate, NU că `bs_product_id` e populat (lipsa lui = fără tab „Piese & accesorii"). Diff de schemă: `LC_ALL=C sort` + `comm` (colația MySQL ≠ sortare pe bytes); MySQL8→MariaDB: `sed 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g'`.

## Catalog (M2) — pagini categorie + produs

- `src/Catalog/Repository.php` = singurul loc care citește catalogul local (prepared statements, degradare grațioasă). `src/Controllers/CatalogController.php` randează `catalog/{brand,category,product}.twig`.
  - ⚠️ Query-urile de carduri publice (`productsInCategory` — folosit ȘI de meniu ȘI de pagina categorie —, `related`, `subcategories.product_count`) TREBUIE să filtreze `p.is_active = 1`; doar adminul (`adminProducts`) vede produse inactive.
- **URL curat, brand-first:** `/{brand}/{cat}` (categorie), `/{brand}/{cat}/{sub}` (subcategorie), produs `/{brand}/{cat}/{sub}/{slug}` (Yamaha, cat 2 niveluri) sau `/{brand}/{cat}/{slug}` (CFMOTO, cat plate). Ruta de 3 segmente rezolvă dinamic subcategorie-vs-produs.
- **Filtre categorie:** pagina categorie acceptă `?permis=` + `?an=` (facete calculate din setul complet, filtrare în PHP în `CatalogController::renderCategory`; UI = `<select>`-uri care auto-submit GET).
- **Comparație modele:** doar același brand + aceeași categorie principală (top). Toggle „Compară" pe carduri (JS în `app.js`, tava sticky) → `/compara?brand=&models=slug,slug` (`CompareController` + `catalog/compare.twig`); backstop server-side păstrează doar produsele cu același `top_slug` ca primul. `Repository::productsBySlugs()`.
- **Blog „Pe Două Roți":** `src/News/Repository.php` citește din tabelele proprii `news` + `news_images` (DB-ul portalului), populate de `database/migrate_news.php` din legacy `noutati`/`imagini_noutati`. Rute `/blog` + `/blog/{id}-{slug}` (`NewsController`). Imaginile în `/media/noutati-moto/` (gitignored); `news_images.is_cover` = cover. Curățare orfane: `database/prune_news_media.php` (`--apply`).
- **SEO:** `layout.twig` expune blocurile `og_type`/`meta_robots`/`head_extra` + variabila `canonical_path` (controllerele o pasează; default `/`; categoria o setează FĂRĂ facetele `?an/?permis`). JSON-LD: dealer+WebSite (layout), `Product`+`BreadcrumbList` (produs, `prices` în RON cu TVA), `BlogPosting`+`datePublished` (blog, `news.date_iso`). `gtag` GA4 `G-VTV11ZJ0P9` în layout (scriptul Cloudflare `email-decode.min.js` se injectează AUTOMAT de CF — NU-l adăuga manual). `noindex,follow` pe `/cauta`+`/compara`. Rute statice (`/`, `/api/*`, `/health`, `/blog`, `/compara`) au prioritate în FastRoute.
- **robots.txt + sitemap.xml** = `src/Controllers/SeoController.php` (rute statice prioritare în Routes.php), live din `Catalog`/`News`/`Event`/`Content` (metode `sitemap*()` în repos). robots.txt e onorat doar la docroot root (pe `/2026` nu).
- **301-uri legacy (păstrare rank la relansare):** produse `*.html` pe `products.legacy_url` via `Repository::canonicalForLegacyLoose()` — ⚠️ cfmoto e salvat FĂRĂ prefix `cfmoto/` (yamaha exact); fallback la pagina de categorie pt. modele scoase (`LEGACY_CATEGORY_MAP`). `/stiri.php?id=N`→`/blog/{id}-{slug}` (`news.pathForLegacyId` pe `legacy_id`); pagini `.php` statice + `/cont` în `$legacyMap` (Routes.php); `/2026/*`→`/*` în `.htaccess`. Sursa inventarului URL vechi = export GSC `documente/export GSC/Table.csv`.
- ⚠️ **Gotcha rutare:** URL-urile CFMOTO `.html` sunt brand-first (`/cfmoto/...`) → matchează ruta produs `/{brand}/{cat}/{sub}/{slug}` ÎNAINTEA rutei catch-all legacy `/{legacy:.+\.html}`. Redirectul lor e prins în `CatalogController::renderProduct` când slug-ul se termină în `.html` (`legacyRedirectFromPath`).
- **301 la schimbarea slug-ului din admin:** tabela `product_slug_redirects` (`brand`+`old_slug` → `product_id`; `schema_admin.sql`). La save, `ProductController` cheamă `Repository::recordSlugChange()` dacă slug-ul s-a schimbat; `renderProduct` face 301 la canonical-ul curent prin `canonicalForSlugRedirect()` când slug-ul cerut nu mai există (înainte de 404). Maparea pe `product_id` (nu pe noul slug) păstrează redirectul 1-hop după redenumiri în lanț. ⚠️ Golirea câmpului Slug + re-save regenerează slug-ul din NUME → dacă numele n-are anul, se pierde `-YYYY` (ex. „RayZR" → `rayzr`, nu `rayzr-2026`).
- **404 interactiv + scoatere produs din ofertă (anti-broken-links):** handler custom `src/Support/NotFoundHandler.php` (înregistrat pe error middleware în `Bootstrap`, prinde TOATE 404-urile) → `templates/errors/404.twig` = pagină de portal (meniu+footer) cu sugestii: derivă tokeni din ultimul segment al URL-ului (scoate `.html`+anul, păstrează tokenii cu litere) → `Repository::search()` cu reducere progresivă de tokeni, completat cu `latestProducts()`. `PageController` aruncă acum `HttpNotFoundException` (nu mai scrie text simplu). **Procedură:** modelul indisponibil se **dezactivează** (`is_active=0`), NU se șterge — admin `ProductController::deactivate` (rută `/produse/{id}/scoate`, buton „Scoate din ofertă"). `Repository::product()` NU filtrează `is_active` → `CatalogController::renderProduct` detectează `is_active=0` și randează `templates/catalog/discontinued.twig` („Acest model nu mai este în ofertă" + alternative din `related()`) cu **HTTP 410 Gone**. „Șterge definitiv" (hard `deleteProduct`) rămâne doar pentru greșeli reale. Sitemap-ul e dinamic (`is_active=1`) → se actualizează singur; resubmit GSC opțional, doar pt. recrawl mai rapid.
- **Imagini:** în `/media/{brand}/{culori|motociclete|detalii}/` (gitignored). Galeria legacy `imagini` e servită din folderul `.../motociclete/`. Numele fișierelor sunt url-encodate la afișare. Vezi `database/README.md` pentru maparea de copiere de pe server.
- Meniul mega e **type-first, live din catalog**: `src/Support/NavigationV2.php` (`build()` → panouri pe tip de produs Motociclete/Scutere/ATV/Marine cu filtru de brand + carduri-model live; `cached()` = file-cache `storage/cache/navv2.cache`, TTL 600s — șterge fișierul ca să-l bustezi). Înregistrat ca Twig global `navV2` în `Bootstrap` (după container, ca să aibă `catalog`), randat de `templates/partials/header.twig` (+ offcanvas mobil) cu macro-ul `templates/partials/_model_card_v2.twig`. Stiluri/JS: `assets/css/app-v2.css` + `assets/js/app-v2.js` (încărcate site-wide din `layout.twig`). Vechiul `Navigation.php`/global `nav` a fost retras.
- Home hero = **slider cu 4 slide-uri** (animat în `app-v2.js` pe `[data-hero-slider]`): showroom / scutere Yamaha / CFMOTO / accesorii originale Yamaha. **Conținutul vine din DB**, tabela `hero_slides` (`database/schema_hero.sql` + seed idempotent `database/seed_hero.php`), citită de `src/Hero/Repository.php` (`slides()`, cu fallback hardcodat dacă tabela e goală/DB pică). Coloana `title_html` permite markup (`<span class="herov2__accent">`), `stats_json` = lista de stat-uri (doar slide 1). Imaginile în `/media/hero/*.jpg` (gitignored → deploy prin FTP ca restul `media/`). **Va fi administrabil** din viitorul sistem de admin (CRUD pe `hero_slides`).
- **Imagini cover** separate: `cover_image` din `products` se servește din `/media/{brand}/cover/` (tip `cover` în `Repository::FOLDER`), restul din `culori/motociclete/detalii`.
- **Prețuri:** stocate în EUR în DB, **cu TVA inclus** (confirmat). Afișare duală EUR + RON (la curs) via funcția Twig `prices(eur, brand?)` → `{eur, ron}` (helper `price_dual(eur,cur,brand?)` din `helpers.php`). **Cursul e per-brand:** Yamaha = curs BNR (`eur_ron_rate_yamaha`), CFMOTO = curs BRD vânzare (`eur_ron_rate_cfmoto`); `Settings::rateForBrand()` alege rata, fallback `eur_ron_rate`. **TOATE** call-site-urile `prices()` pasează brand-ul (carduri meniu/categorie/home/căutare/compară + pagina produs). Setări în tabela `settings` (`vat_pct`, `price_includes_vat` editabile; cele 2 cursuri = **read-only**, auto). Reducere = badge `−X%` (doar pagina produs) + preț vechi tăiat.
- **Curs valutar automat (cron zilnic 07:00):** `database/update_currency.php` ia EUR din BNR (`https://curs.bnr.ro/nbrfxrates.xml`, `<Rate currency="EUR">`) → `eur_ron_rate_yamaha` și „Vânz. BRD (RON)" din pagina BRD (secțiunea `tabAccountExchangeRates`, primul = rândul EUR) → `eur_ron_rate_cfmoto`. Degradare grațioasă: la eșec NU suprascrie cursul vechi (exit 1 + warning). Read-only în admin Setări.
- **Ribon „Promoție":** pe cardurile de model (meniu/categorie/home/căutare/asociate) când `m.promo` = preț redus SAU `promo_html` necompletat (`shapeCard` calculează `promo` din `has_promo`). Înlocuiește micul badge `−X%` pe carduri (rămâne pe pagina produs). CSS `.ribbon-promo` (colț dreapta-sus, oblic). **Cardurile fără preț (POA, price 0) nu mai afișează `.price`.** Numele subcategoriei (chip) a fost scos DOAR din cardul de meniu (`_model_card_v2.twig`), rămâne pe categorie/home/căutare.
- **Admin** minimal: `/admin` (HTTP Basic, creds `ADMIN_USER`/`ADMIN_PASS` din `.env`) → `src/Controllers/AdminController.php` editează cursul valutar/TVA. Tabela `settings` NU e ștearsă la re-migrare.
- **Mentenanță media:** `database/prune_media.php` (`--apply`) șterge fișierele din `/media` nereferite în DB (păstrează doar produsele active).
- **Promoții + variante de preț (coloane noi pe `products`):** `promo_html` (LONGTEXT, WYSIWYG în admin) → casetă `.promo-box` „Nu rata această promoție" în tabul Descriere (apare chiar și fără descriere). `variants_json` (TEXT, JSON `[{version,transmission,price}]`, price=EUR) → tabul **„Preturi"** doar la modelele cu ≥2 variante; editor pe rânduri în admin (`var_version[]`/`var_transmission[]`/`var_price[]`, `buildVariantsJson`/`variantRows` în `ProductController`), randat EUR+RON via `prices()` în `CatalogController::renderProduct`. Importerul Yamaha pre-completează ambele: `ModelImporter::shapeVariants()` iterează TOATE variantele (`productMotorcyclePowerVersion`/`productMotorcycleTransmission`/`prices[].amount`). Coloanele se adaugă nedistructiv prin `migrate_admin.php` (`ensure_column`). Cardul de meniu arată prețul vechi tăiat (`.price__old-mini`) când există reducere; `NavigationV2::CARDS_PER_PANE = 16`.
- **Import Yamaha — imagini caracteristici:** `ModelImporter::fetchFeatures()` pune imaginile blocurilor INLINE în `details_html` (`<figure><img>`); `downloadImages()` le descarcă în `/media/yamaha/detalii/` și rescrie URL-ul remote cu calea locală (nu mai ajung în galerie).
- **Pagina produs — extra (calculator, finanțare, lead-uri):** calculator de rate UniCredit (`#unicredit-calculator`): rata e calculată **server-side** în `src/Finance/Repository.php` (helper `credit_annuity` în `helpers.php`) la `calc_rate` (DAE 14,5%), trimisă ca JSON în `data-rates`; JS-ul (`app.js`) doar schimbă afișajul la durată (12→60 luni, default 60). Config în tabela **`finance`** (un rând, editabilă din admin ulterior). Pagina condiții `/finantare` = `FinanceController` + `finance.twig`, conținut în `finance.page_html` (seed `database/seed_finance.php`, diacritice via PDO). Butoane **WhatsApp + telefon** (0722354437). Click pe tab derulează la conținut (`scrollIntoView`). **Modale lead** (Cere ofertă / Test ride) → `POST /api/lead/oferta|test-ride` (`ContactController`): validare + honeypot `website` → salvează în **`site_messages`** (cu IP + dată) ȘI trimite email la `mail.dealer` (`MAIL_DEALER`, info@motociclete.com.ro); succes = panou „Mulțumim" în modal. Tabelele în `database/schema_messages.sql` (CREATE IF NOT EXISTS, nedistructive). Prețul afișat în calculator = RON cu TVA (`price_dual(...).ron_raw`).

## Fit My Bike — fitment PartsEurope = modulul **LeoPartsFilter**

- `ps_leopartsfilter_make` (235) → `ps_leopartsfilter_model` (18.797) → `ps_leopartsfilter_year` (83.036); nume în tabelele `*_lang`. PK-uri: `id_leopartsfilter_make/model/year`.
- Link produs↔fitment: **`ps_leopartsfilter_product`** (~14.8M rânduri: `id_product` + make/model/year). Există și `ps_leopartsfilter_map_cache` (denormalizat, cu nume). Query compatibilitate ~30ms (indexat).
- `src/BikerShop/Client.php`: `makes()`, `models(makeId)`, `years(makeId,modelId)`, `compatibleProducts(modelId, yearId?, limit)`, `featuredProducts()`.
- **API JSON live:** `GET /api/fit/models?make=`, `/api/fit/years?make=&model=`, `/api/fit/products?model=&year=` (vezi `ApiController`). JS-ul home (`assets/js/app.js`, `[data-fit]`) face cascadarea + randează cardurile.

## Produse asociate (pagina produs + My Garage) — OEM vs Aftermarket

- **OEM = fabricat de același producător** (manufacturer Yamaha/CFMoto); **aftermarket = alți producători**. Split pe `manufacturer`.
- Sursa = relațiile curatate ale modulului **`advrider_related`** de pe BikerShop (NU `diagram_cache`, NU leoparts): `ps_advrider_related_{manual,partseurope,rvx}_cache`, keyed pe **id_product-ul produsului-motocicletă bikershop** (`RelatedSourcesHelper::getMergedRelatedIds`).
- Mapare model local → produs bikershop: coloana `products.bs_product_id`, populată de `database/migrate_bs_models.php` (referința bikershop a motocicletei ≈ `products.slug`, ex. `mt-09-2026`). CFMOTO n-are produse-motocicletă pe bikershop (degradare grațioasă).
- `BikerShop\Client::relatedBikeProductIds()` + `relatedForBike(bsId, brand)` → `{oem, aftermarket}`. Folosit de `CatalogController` (pagina produs, pe taburi) și `ClientController`.
- `documente/advrider_related/` = sursa modulului PrestaShop (gitignored, parole în clar). `sync_oem.php`→`oem_cache` = echivalențe piesă↔piesă (pt. pagina unei PIESE). `oem_product_map`/`migrate_oem_fitment.php` (abordarea veche diagram_cache) rămân dar **nefolosite la runtime**.
- Pagina produs e pe **taburi** (`[data-ptabs]` în `app.js`): Descriere / Caracteristici cheie (`products.details_html`) / Specificații / Galerie (imagini+video) / Piese & accesorii. Progressive enhancement (fără JS, panourile se stivuiesc).

## Accesorii originale Yamaha (portal-owned)

- **Relația accesoriu↔model = sursă de adevăr în portal** (`yamaha_accessories` + `yamaha_accessory_models`; `database/schema_accessories.sql` + `migrate_accessories.php`; coloana `products.yamaha_pid` per model). OEM Yamaha NU mai vine din adv_related.
- Sursa = endpointul public **hyperdrive Yamaha** (fără API/auth): URL cu **GUID categorie CONSTANT** (`1a517708-545a-4094-89e3-ca507def0af3`), doar `yamaha_pid`-ul variază (din pagina de accesorii Yamaha `?product=NNN`). `App\Accessories\Importer` (`importForModel`/`models`) = fetch paginat + match referință→bikershop (`Client::productIdsByReferences`) → `bs_product_id`.
- **Cumpărarea rămâne pe BikerShop:** preț/imagine/URL vin LIVE prin `bs_product_id` (`productsByIds`); portalul stochează doar relația. NEDISTRUCTIV: doar upsert, accesoriile rămân chiar dacă modelul dispare. Accesoriile „fără bikershop" = de importat în PrestaShop via CSV.
- Afișare: `App\Accessories\Repository::bsProductIdsForModel()` → `CatalogController::renderProduct` (OEM Yamaha din portal, fallback adv_related dacă lipsește maparea). „Vezi mai multe" = `[data-accmore]` în `app.js` (necesită `.card-acc[hidden]{display:none}` — `display:flex` suprascrie `[hidden]`).
- **Pagina publică `/accesorii`** (`AccessoriesController` + `accessories.twig`, layout hero ca `/service`): shop cu selector „Ce motocicletă ai?" (`<select onchange=this.form.submit()>` din `modelsWithAccessories()`) + filtre pe categorie (`accessory_type` din `types(?model)`) + paginare (`page(?model,?tip,page,perPage)` în `Repository`, 24/pagină). Prețul/imaginea vin LIVE prin `bikershop->productsByIds()` → numărul de carduri afișate poate fi < total (produse inactive pe bikershop). Stiluri `.acc-*` în `app.css`. Meniul mega „Accesorii" pointează la `/accesorii`.
- Import: `database/import_yamaha_accessories.php --apply` (toate modelele / cron). Admin: la save cu PID **nou/schimbat** → sync automat (protejat, nu strică salvarea) + buton „Resincronizează" (`ProductController::syncAccessories`). Reîmprospătare modele vechi = **cron** pe server. Setare PID în bloc: `tests/set_yamaha_pids.php` din CSV.

## Import model Yamaha (admin) — pre-completare formular

- Buton „+ Adaugă produs Yamaha" în lista de produse (modal în `templates/admin/products/index.twig`) → POST `{base}/produse/import-yamaha` (`ProductController::importYamaha`): preia un MODEL din hyperdrive, descarcă imaginile în `/media/yamaha/...`, pune un draft în `$_SESSION['yamaha_draft']`, redirect la `/produse/0?from_yamaha=1` unde `form()` îl fuzionează în formular. **NU scrie în DB** — operatorul verifică + salvează (reutilizează `save()`). Cod: `src/Yamaha/ModelImporter.php`, container `yamaha_model_importer`. POC: `tests/poc_yamaha_model.php`.
- Endpointuri (≠ cele de accesorii): **produs** `https://hyperdrive.yamaha-motor.eu/products/yme-prod-ro/slug=<slug>?locale=ro-RO` → `key`=PID accesorii, `variants[0].images` (label Studio/Static/Action/360/Detail → cover/color/gallery; 360 ignorat), `variants[0].attributes.techSpecifications` (grupuri specs multi-locale, citește `ro-RO` → mapate pe engine/chassis/dimensions/connectivity), `attributes.features` (chei text), `prices` gol = POA → **prețul se completează manual**. **Text** `.../custom-objects/yme-prod-ro/keys=<features join cu %7C>?locale=ro-RO` → blocuri `header/body/images` (→ `details_html`). Slug = ultimul segment din `/pdp/<slug>/`; `.model.json` al paginii (AEM) are `hyperdriveEndpoint` ca fallback.
- `products.bs_product_id` e acum în `PROD_COLS` (Repository) + câmp ascuns în formularul de produs → se păstrează la editări; maparea fină pe nume rămâne `database/migrate_bs_models.php`.

## My Garage — zonă privată clienți (`/garage`)

- Login **passwordless OTP pe email** (introdu email/telefon din tabela `clienti`). `ClientController` + `App\Client\Repository` + `App\Support\Mailer`. Sesiune PHP nativă pornită în `Bootstrap` (cookie `dm_garage`).
- **Model de date:** `clienti` e legacy **un rând per vehicul** (are `unitate`/`vin`/`an`), iar `client_bikes.uniq_bike_clienti UNIQUE(clienti_id)` impune **1 motocicletă per rând clienti** → un client cu N motociclete = N rânduri `clienti` cu **același email** (emailurile duplicate sunt normale, NU un bug de deduplicat). My Garage public unifică pe `email_norm`: `findByLogin` rezolvă la email, `bikesForEmail()` întoarce toate motoarele cu acel email. Adminul `/dm-control/garage` grupează lista pe proprietar (email, fallback telefon, cu `rowspan`).
- **Mailer** (`Support\Mailer`, folosește **PHPMailer** `phpmailer/phpmailer` via Composer): fără `SMTP_HOST` sau `APP_ENV=dev` scrie în `storage/logs/mail.log` (codul OTP apare și pe `/garage/verify`); altfel trimite SMTP (TLS/SSL după `SMTP_SECURE`). `send($to,$subj,$body,$context,$replyTo)` — `replyTo` = emailul clientului pe lead-uri/service (echipa dă Reply direct la client). Fiecare email se persistă în `email_log`; **la `failed` body-ul include `[SMTP ERROR] …`** (diagnostic: interoghează `email_log` din DB-ul de prod).
- **Mail pe prod = setup split-domain:** domeniul are **MX pe Outlook/M365** (info@/service@ se citesc acolo), dar e găzduit pe cPanel → trimitem de pe server. `MAIL_FROM=website@…` (cont cPanel send-only). **Destinatari per tip:** `MAIL_DEALER`=info@ (ofertă/test drive), `MAIL_SERVICE`=service@ (programare service + cerere garage; fallback la dealer). cPanel **Email Routing = Remote Mail Exchanger** (altfel Exim livrează local). **`mail.motociclete.com.ro` NU există în DNS** → cea mai simplă trimitere = `SMTP_HOST=localhost` `SMTP_PORT=25` `SMTP_SECURE=` (fără auth, fără cert); serverul `109.163.231.49` e deja în SPF.
- Datele moto (km/culoare/accidente/revizii) sunt întreținute de ADMIN: `/admin/garage`, `/admin/service-requests`. Tabele în `database/schema_garage.sql` (CREATE IF NOT EXISTS, soft-links la `products`): `client_bikes, service_records, incidents, service_requests, client_otp, oem_product_map`.
- Seed (PHP 8.1 Laragon): `schema_garage.sql` → `normalize_clienti.php` (`telefon_norm`/`email_norm`) → `seed_garage.php` (`unitate`→catalog) → `migrate_bs_models.php` (`bs_product_id`). Re-rulează seed + bs_models după re-migrarea catalogului. `normalize_phone()`/`normalize_email()` în helpers.

## Sistem de administrare (`src/Admin/`)

Back-office complet, multi-user, sub o **cale ascunsă configurabilă** (`ADMIN_PATH` în `.env`,
default `/dm-control`; citită în `config/settings.php` ca `admin.path`). NU mai există `/admin`.

- **Auth:** tabela `admin_users` (`password_hash`), login pe sesiune (cheie `admin_uid`), CSRF
  (token în sesiune `$_SESSION['csrf']`, global Twig… de fapt injectat ca `csrf` din `BaseController`).
  `App\Admin\BaseController` = guard + CSRF + `render()` + `to()` (redirect) + `bustMenuCache()`.
  Primul user: `database/seed_admin_user.php <user> <parola>`.
- **Rute:** grup în `src/Routes.php` sub `$adminBase` cu factory `$adminCtl('Class','method')`.
  Controllere: `Auth, Dashboard, Hero, Announcement, Category, Product, News, Event, About, History, Service, Settings, Message, Garage, Upload`.
- **Upload imagini:** `POST {base}/upload` (`UploadController` + `App\Admin\Upload`) — multipart, validare
  (jpg/png/webp, 12MB), context whitelist → subfolder `/media` (`hero`, `noutati-moto`, `evenimente`, `despre` (context `about`),
  `{brand}/{culori|motociclete|detalii|cover}`). JS dropzone + reorder + cover în `assets/js/admin.js`
  (`[data-imgmgr]`, `data-store=url|filename`).
- **WYSIWYG:** Quill vendorat local `assets/vendor/quill/` (fără build/CDN), pe `[data-editor="câmp"]` + `<textarea>`.
  Specs produs (motor/șasiu/dimensiuni/conectivitate) = editor structurat rânduri label→valoare
  (`[data-rows]`/`[data-row-add]`) → HTML `<table>` în `specs_*` (parse/build în `ProductController`).
- **Pop-up site-wide:** tabela `announcements` (titlu + `body_html` WYSIWYG + fereastră `starts_at`/`ends_at` + `is_active` + `position`). Admin la `{base}/anunturi` (`Admin\AnnouncementController`); `Announcement\Repository::current()` (activ ȘI în fereastră, cel mai mic `position` câștigă) → global Twig `announcement` în `Bootstrap` → `templates/partials/announcement.twig` (modal `.modal--announce`, inclus în `layout.twig`). JS în `app.js` (`[data-announce]`) îl arată o dată per vizitator (dismiss în `localStorage`, cheie = `id-updated_at` → reapare la editare). Degradare grațioasă (null dacă tabela lipsește).
- **Module → tabele:** Hero=`hero_slides`; Pop-up=`announcements`; Categorii/Produse=`categories`/`products`/`product_images`
  (salvarea **bustează `storage/cache/navv2.cache`**); Blog=`news`/`news_images`/`news_categories`;
  Evenimente=`events`/`event_images` (+ public `/evenimente`, `/evenimente/{slug}`);
  Despre=intro (`settings.about_heading/about_intro_html`)+galerie `about_images`+`team_members`+timeline `history_entries`/`history_images`
  (admin `AboutController`+`HistoryController` → `App\About\Repository`+`App\History\Repository`; public canonic **`/despre_dual_motors`** (SEO, oglindă a fișierului legacy)
  = `AboutController`+`about.twig`; `/despre` și `/despre_dual_motors.php` fac **301** către canonic — rute statice obligatorii: catch-all `/{slug}` e `[a-z0-9-]+`, fără underscore.
  Galeria showroom = carusel fade auto (`[data-carousel]` în `app.js`), iar **toate** imaginile din pagină au click-to-zoom (`[data-zoom]` + lightbox grupat pe `[data-zoom-group]`));
  Service=descriere/notă (`settings.service_heading/service_desc_html/service_note_html`)+`service_prices` (rânduri grupate, editor `[data-rows]` cu **array-uri paralele** `price_group[]`/`price_label[]`/`price_value[]`)
  (admin `ServiceController` → `App\Service\Repository`; public `/service`+`/service/programare` = `App\Controllers\ServiceController`+`service.twig`);
  Finanțare=`finance` (rând id=1: dobândă/DAE/comision/durate + titlu/`page_html` pt. `/finantare`) — **pagină admin proprie** `Admin\FinanceController` (`{base}/finantare`), mutată din Setări;
  Setări=`settings`+`contact_departments`+`pages` (curs valutar read-only + TVA + social/adresă; pagini legale publice la `/{slug}` via `PageController`,
  rută înregistrată ULTIMA); Mesaje=`site_messages`+`service_requests`+`service_bookings`+`email_log`; Garage reutilizează
  `Client\Repository` + calendar din `service_requests.preferred_date`.
- **Pagini de conținut (Despre / Service):** schema în `database/schema_pages.sql` (rulată de `migrate_admin.php`),
  seed din legacy: `seed_about.php` (intro+echipă+timeline, imagini deja în `media/despre/`, inclusiv subfoldere `2014/`,`2016/`
  → `History\Repository` encodează **per-segment** path-ul ca slash-ul să supraviețuiască) + `seed_service.php` (descriere+notă+prețuri).
  Formularul de programare service e **anonim** (NU `service_requests`, care cere `clienti_id`) → tabela proprie `service_bookings`,
  POST `/service/programare` (validare + honeypot `website` → DB + email dealer ca `ContactController`), vizibil în admin la
  Mesaje → „Programări service" (status nou/confirmat/închis). Form AJAX inline = handler `[data-ajax-form]` în `app.js`
  (ascunde formul, arată `[data-form-thanks]`). Linkurile „Diagrame piese" Yamaha/CFMOTO sunt **statice** în `service.twig`
  (imagini în `media/service/`, URL-uri fixe bikershop.ro).
- **Email → DB:** `Support\Mailer::send($to,$subj,$body,$context)` persistă **fiecare** email în `email_log`
  (PDO injectat din `Bootstrap`). Footer-ul folosește globalul Twig `site` (socials/adresă/departamente/pagini legale).
- **Schemă/migrare (cross-engine):** `database/schema_admin.sql` (CREATE IF NOT EXISTS) + `database/migrate_admin.php`
  (rulează schema split pe `;` via `_dbutil.php` + `ensure_column` prin information_schema — MySQL 8 n-are
  `ADD COLUMN IF NOT EXISTS`). Seedere: `seed_admin_user.php`, `seed_settings.php` (departamente + pagini legale),
  `seed_about.php`, `seed_service.php` (idempotente: umplu doar tabele goale; text din `settings` via INSERT IGNORE → editările din admin se păstrează).
  ⚠️ După ce adminul gestionează catalogul, **NU mai rula `migrate_catalog.php`** (DROP+RECREATE) în producție.
  Pe deploy: rulează `migrate_admin.php` + seederele pe server; folderele `/media/*` upload trebuie scriibile.
- **Fitment** (mapare produs↔BikerShop make/model/year) NU a fost portat în noul admin (vechiul `/admin/fitment`
  a fost retras); datele rămân populate de scripturile de migrare. De re-adăugat ca modul la nevoie.
- **Testare admin (curl):** login pe sesiune → cookie jar (`curl -c/-b`), ia CSRF din `window.CSRF="..."` (layout)
  sau câmpul `_csrf`, apoi POST. Pagina `/login` NU are `window.CSRF` (layout minimal) → ia tokenul din câmpul `_csrf`.
  Cookie-ul de sesiune `dm_garage` e `HttpOnly` → în jar apare ca linie `#HttpOnly_…` (NU o filtra cu `grep -v '^#'`).
  User de test: `database/seed_admin_user.php __tmp <pass>` (șterge-l după). Upload multipart: cale **relativă în repo** (`-F "files[]=@storage/..."`),
  NU `/tmp/...` (curl pe Git Bash dă „Failed to open/read local data").
- **Twig (gotchas):** funcția `namespace()` NU e disponibilă în acest build → pentru „primul element peste bucle
  imbricate" folosește `loop.parent.loop.first`. Macro-urile NU văd variabilele apelantului, dar VĂD globalele
  (`base`, `prices`, `navV2`, `site`) — pasează restul ca argumente. Sintaxa `{% for x in y if … %}` a fost scoasă
  în Twig 3 → folosește `|filter(x => …)`. Nu există filtrul `string` → stringifică prin concatenare (`(a ~ '') == b`).
  Compilare rapidă fără auth: încarcă template-ul cu `$twig->getEnvironment()->load('…')` (înregistrează `money`/`prices` ca stub-uri).

## Convenții

- Toate query-urile = prepared statements. BikerShop NU se scrie niciodată (read-only).
- **PDO native prepares (emulate=false): un placeholder numit NU se poate repeta.** `id_shop`/`id_lang` (int-uri din config, de încredere) sunt inline în SQL; doar inputul user rămâne bound. ⚠️ Helperele de repo `all()`/`one()` prind `Throwable` → întorc `[]`/`null`, deci un placeholder repetat (eroare `HY093`) apare ca **rezultat gol, fără eroare** (vezi bug-ul de căutare din garage). Pt. căutare multi-coloană generează placeholdere unice (ex. `:s{i}_{j}`).
- Cumpărarea rămâne pe BikerShop: produsele fac link la `bikershop.ro/{id}-{slug}.html`; imagini `bikershop.ro/{id_image}-large_default/{slug}.jpg` (servite public, 200).
- Prețuri BikerShop = **RON (Lei)** (moneda default a shopului, `PS_CURRENCY_DEFAULT=1`; EUR secundar la rate ~0.19). `ps_product_shop.price` e **fără TVA**; `Client::shapeProduct` aplică cota reală din `tax_rules_group` (de regulă 21%) → brut, afișat „Lei" cu 2 zecimale. Prețurile **motocicletelor** sunt în EUR (alt flux). Reducerile `specific_price` NU sunt citite (preț standard).
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
`http://motociclete.test`. `/health` raportează `bikershop` + `catalog` + `news`.
După editări `app.css`/`app.js`: bump `?v=N` în `layout.twig` (cache-bust).
Screenshot: Chrome NU e pe PATH în Bash → cale completă
`"/c/Program Files/Google/Chrome/Application/chrome.exe" --headless=new --screenshot=<CALE-ABSOLUTĂ>.png --window-size=1440,2600 <url>`
(calea relativă a fișierului dă „cannot find path"; cale absolută obligatorie).
Screenshot interactiv / pagini cu sesiune (taburi, login garage): `npm i --no-save puppeteer-core` (folosește Chrome-ul existent prin `executablePath`), injectează cookie-ul de sesiune, apoi `page.screenshot`. **`node_modules/` e gitignored.** `puppeteer-core` e **ESM** → script `.mjs` cu `import` (nu `require`), iar `page.goto` cere **URL absolut**.

## Deploy (LIVE la root; fostul staging `/2026/` retras)

Vezi `DEPLOY.md`. **Repo GitHub `danielmav/motociclete` e PUBLIC** → nu comite niciodată secrete;
**Deploy prin SSH** (alias `~/.ssh/config` `dualmotors` → `109.163.231.49:1187`, user `dualmotors`, cheie `id_ed25519_dualmotors`): `ssh dualmotors 'cd /home/dualmotors/public_html/motociclete.com.ro && git pull --ff-only origin main'` — Claude poate face pull-ul singur după push (nu mai e nevoie de „Update from Remote" din cPanel). **Un hook `PostToolUse` rulează automat acest pull după fiecare `git push` reușit pe acest proiect.** Warning-ul post-quantum pe stderr e inofensiv. Working tree-ul de pe server trebuie curat (`composer.phar` untracked e ok; `.htaccess` e skip-worktree). La dependențe noi pull-ul NU rulează composer (vezi mai jos).
`.env` (în orice folder) + `documente/` sunt gitignored. Clonat prin cPanel Git **direct în docroot live**
`/home/dualmotors/public_html/motociclete.com.ro` (cont cPanel **`dualmotors`**, fără underscore);
„Update from Remote" / `git pull` pe loc = suficient pentru **cod**, dar **NU instalează `vendor/`** (gitignored) → la **dependențe noi** (ex. PHPMailer) trebuie rulat composer manual, altfel apare `Class not found` la runtime.
Permisiuni post-clone: `.htaccess`=644, foldere=755.
- **PHP server = ea-php 8.1** (PHP-ul asignat domeniului în MultiPHP): binar `/usr/local/bin/ea-php81` (= `/opt/cpanel/ea-php81/root/usr/bin/php`), are pdo_mysql+curl. (Verifică binarul cu `ea-php81 -m | grep -Ei 'pdo_mysql|curl'`.)
- **Composer pe server:** `/opt/cpanel/composer/bin/composer` din `.cpanel.yml` **NU există** pe acest server. Bootstrap în docroot: `curl -sS https://getcomposer.org/installer -o composer-setup.php && php composer-setup.php` → apoi `php composer.phar install --no-dev --optimize-autoloader`. `.cpanel.yml` rulează composer **doar** la „Deploy HEAD Commit", care e blocat de `.htaccess` dirty → fă întâi `git update-index --skip-worktree .htaccess`.
- **`.htaccess` are un bloc PHP generat de cPanel** (MultiPHP) → apare mereu „modified" și blochează „Deploy" din cPanel. NU-l comite (strică PHP pe Laragon local). Pe server o singură dată: `git update-index --skip-worktree .htaccess`. `drive-test/` (app separată sub docroot) + `.duckversions/` (artefact File Manager) sunt gitignored.
- **Cron-uri (ea-php81, `/usr/local/bin/ea-php81`):** accesorii Yamaha lunar (`import_yamaha_accessories.php --apply`); curs valutar zilnic 07:00 (`update_currency.php`); retention GDPR zilnic 03:30 (`retention.php --apply` — anonimizare IP 30z / PII 12 luni, ștergere `email_log`/`client_otp`); loguri în `/home/dualmotors/`.
- **Post-deploy:** la coloane/schemă noi rulează `database/migrate_admin.php`; după schimbări catalog/meniu șterge `storage/cache/navv2.cache`.

**Hook pre-commit anti-secrete** (`.githooks/pre-commit`, gitleaks): blochează commit-urile cu secrete.
Activare după un clone nou: `git config core.hooksPath .githooks` + `scoop install gitleaks`.
**Fals pozitiv** pe biblioteci vendorate (ex. `quill.min.js` → „generic-api-key"): adaugă fingerprint-ul în `.gitleaksignore` (NU `--no-verify`).

## Module conexe

- **drivetest** (`c:\laragon\www\drivetest`, `drivetest.test`) — sistem „drive test"
  existent (PHP procedural + MySQLi, DB `dualmotors_testdrive`). Portalul face deocamdată
  link spre el (CTA „Programează drive test", URL din `app.testride_url` / `.env` `TESTRIDE_URL`,
  pe prod `https://www.motociclete.com.ro/drive-test/`); unificare ulterioară (Milestone 4).
  UI = „drive test" peste tot; rutele/id-urile interne rămân `test-ride`/`test_ride`.
