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
- **Probe/diagnostic DB rapid:** scrie un `tmp_*.php` și rulează-l cu binarul Laragon (apoi șterge-l) — `php -r` inline crapă pe quoting (`$`, `"`, paranteze) în Bash/PowerShell. `database/diagnose_db.php` testează cele 3 conexiuni.
- **mysqldump / mysql client** (dump/import DB): `C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/`.
  Conectare la DB remote: pasează parola via env `MYSQL_PWD` (parolele au caractere speciale → evită `-p`). Dev IP whitelisted în Remote MySQL pe serverele remote.
  Extrage parola din `.env` la runtime ca să NU apară în transcript: `export MYSQL_PWD=$(grep -m1 '^DM_PASS=' .env | cut -d= -f2- | tr -d "'")`. NU seta `MYSQL_PWD` pentru `root` local (n-are parolă → „Access denied").
  PowerShell `>` scrie UTF-16 (strică importul) → `mysqldump --result-file=…` + `mysql -e "source …"`. Bash tool NU are cmdleturi PowerShell (`Select-Object`/`-String`).
  Sursarea unui `.sql` UTF-8 cu clientul mysql necesită `--default-character-set=utf8mb4` (altfel diacriticele devin mojibake la INSERT).
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
- **Dual Motors content** `dualmotors_motociclete` + `dualmotors_cfmoto` @ `109.163.231.49` (user `dualmotors_test`). Site-ul Yamaha/CFMOTO actual: `products`, `detalii`, `culori`, `imagini`, `noutati`, `categories`, `clienti_2021`. Sursă pentru migrarea catalogului (M2) ȘI a blogului. Config în `.env` ca `DM_*` → `db.dm`, folosit **DOAR de scripturile de migrare** (`migrate_catalog.php`, `migrate_news.php`) — la runtime portalul nu mai atinge bazele legacy (vor fi șterse după development). `noutati` există în ambele DB-uri (cfmoto e gol momentan).
- **`motociclete`** (local, RW, user `root` fără parolă) — baza proprie a portalului (M2 livrat). Schemă unificată Yamaha+CFMOTO: `categories` (ierarhie 2 niveluri, `brand`), `products` (`brand`, `slug`, `legacy_url` pt. 301), `product_images` (tip `color|gallery|detail`). Vezi `database/schema.sql` + `database/migrate_catalog.php` (rulează cu PHP 8.1 Laragon; doar produse active).
- **`dualmotors_motociclete2026`** @ `109.163.231.49` (user `dualmotors_test`, **full access** — aceleași credențiale ca `DM_*`). Baza portalului pentru staging-ul `motociclete.com.ro/2026/`. Aceeași schemă ca `motociclete` (local); se populează prin **dump local `motociclete` → import aici**. Pe server aplicația o accesează prin `localhost` (`DB_LOCAL_*` în `.env`-ul de pe server). Vezi `DEPLOY.md`.
  ⚠️ **Schema de staging rămâne în urma celei locale** dacă importul s-a făcut înaintea unui milestone (ex. a lipsit tot My Garage + `products.bs_product_id` + `clienti.email_norm/telefon_norm`). Sincronizează **chirurgical/non-distructiv**: `CREATE TABLE IF NOT EXISTS`, `ALTER ... ADD COLUMN/KEY IF NOT EXISTS` (MariaDB le suportă), date per-tabel cu `mysqldump --no-create-info --replace`. **Dump+import-ul ÎNTREGII baze e blocat de auto-classifier** (prea agresiv). `/2026/health = true` la `bikershop` = doar conectivitate, NU că `bs_product_id` e populat (lipsa lui = fără tab „Piese & accesorii"). Diff de schemă: `LC_ALL=C sort` + `comm` (colația MySQL ≠ sortare pe bytes); MySQL8→MariaDB: `sed 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g'`.

## Catalog (M2) — pagini categorie + produs

- `src/Catalog/Repository.php` = singurul loc care citește catalogul local (prepared statements, degradare grațioasă). `src/Controllers/CatalogController.php` randează `catalog/{brand,category,product}.twig`.
- **URL curat, brand-first:** `/{brand}/{cat}` (categorie), `/{brand}/{cat}/{sub}` (subcategorie), produs `/{brand}/{cat}/{sub}/{slug}` (Yamaha, cat 2 niveluri) sau `/{brand}/{cat}/{slug}` (CFMOTO, cat plate). Ruta de 3 segmente rezolvă dinamic subcategorie-vs-produs.
- **Filtre categorie:** pagina categorie acceptă `?permis=` + `?an=` (facete calculate din setul complet, filtrare în PHP în `CatalogController::renderCategory`; UI = `<select>`-uri care auto-submit GET).
- **Comparație modele:** doar același brand + aceeași categorie principală (top). Toggle „Compară" pe carduri (JS în `app.js`, tava sticky) → `/compara?brand=&models=slug,slug` (`CompareController` + `catalog/compare.twig`); backstop server-side păstrează doar produsele cu același `top_slug` ca primul. `Repository::productsBySlugs()`.
- **Blog „Pe Două Roți":** `src/News/Repository.php` citește din tabelele proprii `news` + `news_images` (DB-ul portalului), populate de `database/migrate_news.php` din legacy `noutati`/`imagini_noutati`. Rute `/blog` + `/blog/{id}-{slug}` (`NewsController`). Imaginile în `/media/noutati-moto/` (gitignored); `news_images.is_cover` = cover. Curățare orfane: `database/prune_news_media.php` (`--apply`).
- **SEO:** URL-urile vechi `*.html` fac **301** către canonic (match pe `products.legacy_url`). Rute statice (`/`, `/api/*`, `/health`, `/blog`, `/compara`) au prioritate în FastRoute.
- **Imagini:** în `/media/{brand}/{culori|motociclete|detalii}/` (gitignored). Galeria legacy `imagini` e servită din folderul `.../motociclete/`. Numele fișierelor sunt url-encodate la afișare. Vezi `database/README.md` pentru maparea de copiere de pe server.
- Meniul mega e **type-first, live din catalog**: `src/Support/NavigationV2.php` (`build()` → panouri pe tip de produs Motociclete/Scutere/ATV/Marine cu filtru de brand + carduri-model live; `cached()` = file-cache `storage/cache/navv2.cache`, TTL 600s — șterge fișierul ca să-l bustezi). Înregistrat ca Twig global `navV2` în `Bootstrap` (după container, ca să aibă `catalog`), randat de `templates/partials/header.twig` (+ offcanvas mobil) cu macro-ul `templates/partials/_model_card_v2.twig`. Stiluri/JS: `assets/css/app-v2.css` + `assets/js/app-v2.js` (încărcate site-wide din `layout.twig`). Vechiul `Navigation.php`/global `nav` a fost retras.
- Home hero = **slider cu 4 slide-uri** (animat în `app-v2.js` pe `[data-hero-slider]`): showroom / scutere Yamaha / CFMOTO / accesorii originale Yamaha. **Conținutul vine din DB**, tabela `hero_slides` (`database/schema_hero.sql` + seed idempotent `database/seed_hero.php`), citită de `src/Hero/Repository.php` (`slides()`, cu fallback hardcodat dacă tabela e goală/DB pică). Coloana `title_html` permite markup (`<span class="herov2__accent">`), `stats_json` = lista de stat-uri (doar slide 1). Imaginile în `/media/hero/*.jpg` (gitignored → deploy prin FTP ca restul `media/`). **Va fi administrabil** din viitorul sistem de admin (CRUD pe `hero_slides`).
- **Imagini cover** separate: `cover_image` din `products` se servește din `/media/{brand}/cover/` (tip `cover` în `Repository::FOLDER`), restul din `culori/motociclete/detalii`.
- **Prețuri:** stocate în EUR în DB, **cu TVA inclus** (confirmat). Afișare duală EUR + RON (la curs) via funcția Twig `prices(eur)` → `{eur, ron}` (helper `price_dual` din `helpers.php`). Setări în tabela `settings` (`eur_ron_rate`, `vat_pct`, `price_includes_vat`) prin `src/Support/Settings.php`. Reducere = badge `−X%` + preț vechi tăiat.
- **Admin** minimal: `/admin` (HTTP Basic, creds `ADMIN_USER`/`ADMIN_PASS` din `.env`) → `src/Controllers/AdminController.php` editează cursul valutar/TVA. Tabela `settings` NU e ștearsă la re-migrare.
- **Mentenanță media:** `database/prune_media.php` (`--apply`) șterge fișierele din `/media` nereferite în DB (păstrează doar produsele active).
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

## My Garage — zonă privată clienți (`/garage`)

- Login **passwordless OTP pe email** (introdu email/telefon din tabela `clienti`). `ClientController` + `App\Client\Repository` + `App\Support\Mailer`. Sesiune PHP nativă pornită în `Bootstrap` (cookie `dm_garage`).
- **Mailer**: fără SMTP (dev) scrie în `storage/logs/mail.log`; `APP_ENV=dev` arată codul și pe `/garage/verify`. Producție = SMTP (`.env`: `SMTP_*`, `MAIL_FROM`, `MAIL_DEALER`).
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
  Controllere: `Auth, Dashboard, Hero, Category, Product, News, Event, Settings, Message, Garage, Upload`.
- **Upload imagini:** `POST {base}/upload` (`UploadController` + `App\Admin\Upload`) — multipart, validare
  (jpg/png/webp, 12MB), context whitelist → subfolder `/media` (`hero`, `noutati-moto`, `evenimente`,
  `{brand}/{culori|motociclete|detalii|cover}`). JS dropzone + reorder + cover în `assets/js/admin.js`
  (`[data-imgmgr]`, `data-store=url|filename`).
- **WYSIWYG:** Quill vendorat local `assets/vendor/quill/` (fără build/CDN), pe `[data-editor="câmp"]` + `<textarea>`.
  Specs produs (motor/șasiu/dimensiuni/conectivitate) = editor structurat rânduri label→valoare
  (`[data-rows]`/`[data-row-add]`) → HTML `<table>` în `specs_*` (parse/build în `ProductController`).
- **Module → tabele:** Hero=`hero_slides`; Categorii/Produse=`categories`/`products`/`product_images`
  (salvarea **bustează `storage/cache/navv2.cache`**); Blog=`news`/`news_images`/`news_categories`;
  Evenimente=`events`/`event_images` (+ public `/evenimente`, `/evenimente/{slug}`);
  Setări=`settings`+`finance`+`contact_departments`+`pages` (pagini legale publice la `/{slug}` via `PageController`,
  rută înregistrată ULTIMA); Mesaje=`site_messages`+`service_requests`+`email_log`; Garage reutilizează
  `Client\Repository` + calendar din `service_requests.preferred_date`.
- **Email → DB:** `Support\Mailer::send($to,$subj,$body,$context)` persistă **fiecare** email în `email_log`
  (PDO injectat din `Bootstrap`). Footer-ul folosește globalul Twig `site` (socials/adresă/departamente/pagini legale).
- **Schemă/migrare (cross-engine):** `database/schema_admin.sql` (CREATE IF NOT EXISTS) + `database/migrate_admin.php`
  (rulează schema split pe `;` via `_dbutil.php` + `ensure_column` prin information_schema — MySQL 8 n-are
  `ADD COLUMN IF NOT EXISTS`). Seedere: `seed_admin_user.php`, `seed_settings.php` (departamente + pagini legale).
  ⚠️ După ce adminul gestionează catalogul, **NU mai rula `migrate_catalog.php`** (DROP+RECREATE) în producție.
  Pe deploy: rulează `migrate_admin.php` + seederele pe server; folderele `/media/*` upload trebuie scriibile.
- **Fitment** (mapare produs↔BikerShop make/model/year) NU a fost portat în noul admin (vechiul `/admin/fitment`
  a fost retras); datele rămân populate de scripturile de migrare. De re-adăugat ca modul la nevoie.

## Convenții

- Toate query-urile = prepared statements. BikerShop NU se scrie niciodată (read-only).
- **PDO native prepares (emulate=false): un placeholder numit NU se poate repeta.** `id_shop`/`id_lang` (int-uri din config, de încredere) sunt inline în SQL; doar inputul user rămâne bound.
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
Screenshot interactiv / pagini cu sesiune (taburi, login garage): `npm i --no-save puppeteer-core` (folosește Chrome-ul existent prin `executablePath`), injectează cookie-ul de sesiune, apoi `page.screenshot`. **`node_modules/` e gitignored.**

## Deploy (staging `/2026/`)

Vezi `DEPLOY.md`. **Repo GitHub `danielmav/motociclete` e PUBLIC** → nu comite niciodată secrete;
`.env` (în orice folder) + `documente/` sunt gitignored. Clonat prin cPanel Git **direct în docroot**
`public_html/motociclete.com.ro/2026` → „Update from Remote" = `git pull` pe loc (suficient pentru cod).
`vendor/` (gitignored) se pune via `vendor.zip` + FTP + Extract. `.env` creat manual pe server (`BASE_PATH=/2026`).
Permisiuni post-clone: `.htaccess`=644, foldere=755.

**Hook pre-commit anti-secrete** (`.githooks/pre-commit`, gitleaks): blochează commit-urile cu secrete.
Activare după un clone nou: `git config core.hooksPath .githooks` + `scoop install gitleaks`.

## Module conexe

- **drivetest** (`c:\laragon\www\drivetest`, `drivetest.test`) — sistem test-ride
  existent (PHP procedural + MySQLi, DB `dualmotors_testdrive`). Portalul face deocamdată
  link spre el (CTA „Programează test ride"); unificare ulterioară (Milestone 4).
