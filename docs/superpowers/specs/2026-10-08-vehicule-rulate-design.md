# Secțiunea „Rulate" (vehicule second hand) — design

Data: 2026-10-08. Aprobat de Daniel în conversație.

## Scop

Dealerul vinde și vehicule rulate, de orice marcă. Portalul primește o secțiune
publică „Rulate" cu anunțuri administrate din back-office, pe tiparul blogului.
Un cumpărător vede lista, deschide un anunț și scrie departamentului Vânzări moto
din formularul aflat în coloana din dreapta.

În afara scopului: filtrare avansată (an, preț, km), stare „Vândut", comparație,
calculator de rate, legături cu BikerShop sau cu catalogul de modele noi.

## Decizii

| Subiect | Decizie |
|---|---|
| Structură | Modul propriu (`used_*`), separat de catalog |
| Date vehicul | An, km, cilindree, toate opționale |
| Vehicul vândut | Se dezactivează; nu există stare „Vândut" |
| Preț | EUR cu TVA, afișat EUR + RON la cursul BNR; gol = „la cerere" |
| Expirare | 30 de zile, verificată la citire, fără cron |
| Mărci și categorii | Liste gestionate din admin |
| Meniu | Link simplu „Rulate", fără panou mega |

## Date

Fișier nou `database/schema_used.sql` (doar `CREATE TABLE IF NOT EXISTS`), rulat
din `database/migrate_admin.php`. Seed idempotent `database/seed_used.php`:
umple `used_brands` (Yamaha, CFMOTO, Honda) și `used_categories` (Motociclete,
Scutere, ATV) doar dacă tabelele sunt goale.

**`used_brands`**, **`used_categories`** — `id`, `name` VARCHAR(120), `slug`
VARCHAR(140) UNIQUE, `position` INT.

**`used_vehicles`**

| Coloană | Tip | Note |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `title` | VARCHAR(255) | obligatoriu |
| `slug` | VARCHAR(255) | din titlu, la creare; nu e unic (URL-ul conține id-ul) |
| `brand_id` | INT UNSIGNED | obligatoriu, FK `used_brands` (RESTRICT) |
| `category_id` | INT UNSIGNED | obligatoriu, FK `used_categories` (RESTRICT) |
| `price_eur` | DECIMAL(10,2) NULL | NULL = „la cerere" |
| `year` | SMALLINT UNSIGNED NULL | |
| `km` | INT UNSIGNED NULL | |
| `cc` | SMALLINT UNSIGNED NULL | |
| `description_html` | MEDIUMTEXT NULL | WYSIWYG |
| `video` | VARCHAR(255) NULL | link YouTube |
| `is_active` | TINYINT(1) DEFAULT 1 | 0 = dezactivat manual |
| `expires_at` | DATETIME NOT NULL | |
| `created_at`, `updated_at` | TIMESTAMP | |

Index: `(is_active, expires_at)`, `(category_id)`, `(brand_id)`.

**`used_images`** — `id`, `vehicle_id` (FK, CASCADE), `filename`, `is_cover`,
`position`. Fișierele stau în `/media/rulate/` (gitignored, ca restul `media/`).

## Expirare

- **Public** = `is_active = 1` și `expires_at > acum`. Regula stă într-un singur
  loc, în `Used\Repository`, și e folosită de toate interogările publice.
- „Acum" se calculează în PHP cu ora `Europe/Bucharest` și se trimite ca
  parametru; nu se folosește `NOW()` (serverul rulează pe UTC).
- La creare și la „Reactivează": `expires_at = acum + 30 de zile`, `is_active = 1`.
- Editarea unui anunț nu schimbă `expires_at`.
- Stări afișate în admin, deduse din cele două coloane:
  - **Activ** — public; se arată „expiră în N zile".
  - **Expirat** — `is_active = 1`, `expires_at <= acum`.
  - **Dezactivat** — `is_active = 0`.
- „Dezactivează" pune `is_active = 0` și lasă `expires_at` neschimbat.
- Durata (30) e o constantă în repo.

## Componente

### `App\Used\Repository`

Singurul loc care atinge tabelele `used_*`. Prepared statements. Metodele
publice de citire degradează grațios (listă goală / `null` la eroare de DB), ca
în celelalte repo-uri; cele de scriere lasă excepția să urce.

Citire publică:
- `page(?int $categoryId, ?int $brandId, int $page, int $perPage): array` →
  `{items, total}`; ordonate după `created_at` descrescător.
- `find(int $id): ?array` — orice stare, cu imagini; controllerul decide 200/410.
- `isPublic(array $vehicle): bool`.
- `latest(int $limit, ?int $excludeId): array` — pentru „Alte rulate".
- `categoriesWithCounts()`, `brandsWithCounts()` — doar cele cu anunțuri publice.
- `categoryBySlug()`, `brandBySlug()`.
- `sitemapEntries(): array`.

Admin:
- `adminList(?string $state): array`, `expired(): array`, `expiredCount(): int`.
- `save(?int $id, array $data, array $images): int`, `deactivate(int $id)`,
  `reactivate(int $id)`, `delete(int $id)` (șterge și fișierele din
  `/media/rulate/`).
- CRUD pentru mărci și categorii; ștergerea e refuzată dacă există anunțuri
  care le folosesc.

Înregistrat în container ca `used`.

### `App\Controllers\UsedController` (public)

| Rută | Pagină |
|---|---|
| `GET /rulate` | toate anunțurile |
| `GET /rulate/{categorie}` | filtrat pe categorie |
| `GET /rulate/marca/{marca}` | filtrat pe marcă |
| `GET /rulate/{id}-{slug}` | anunț |

Rutele se înregistrează ca rute statice/prioritare, înaintea rutelor de catalog
`/{brand}/{cat}` și a catch-all-ului `/{slug}`. Ruta de anunț are tiparul
`{id:[0-9]+}-{slug}` și se declară înaintea celei de categorie.

Slug-ul `marca` e rezervat: o categorie nu îl poate primi (adminul refuză
salvarea).

Comportament:
- Paginare `?p=N`, 12 pe pagină. Pagină peste total → 404.
- Categorie sau marcă inexistentă → 404. Existentă, dar fără anunțuri publice →
  200 cu mesaj „Nu avem anunțuri în această categorie" și `noindex,follow`.
- Anunț cu slug diferit de cel curent → 301 la URL-ul canonic.
- Anunț expirat sau dezactivat → **410** cu `used/gone.twig`: „Anunțul nu mai
  este disponibil" + până la 6 alte rulate publice.
- Anunț inexistent → `HttpNotFoundException` (pagina 404 a portalului).

Șabloane: `templates/used/index.twig`, `show.twig`, `gone.twig`,
`partials/_used_card.twig`, `partials/_used_sidebar.twig`.

Layout: două coloane, conținut în stânga și coloana fixă în dreapta; sub 992 px
coloana coboară sub conținut. Coloana conține, în ordine:
1. Formular de contact (titlu „Întreabă despre acest vehicul" pe anunț,
   „Cauți un vehicul rulat?" pe liste), cu telefonul și emailul Vânzări moto
   afișate deasupra.
2. Categorii, cu numărul de anunțuri; cea curentă e evidențiată.
3. Mărci, la fel.

Card: copertă (`loading="lazy"`), titlu, marcă și categorie, linia de date
(„2021 · 12.400 km · 689 cc", doar câmpurile completate), preț EUR + RON sau
„Preț la cerere".

Pagina de anunț: galerie cu click-to-zoom (`[data-zoom]` existent), video
YouTube încărcat la clic, tabel cu datele vehiculului, descriere, „Alte rulate".

Prețul RON folosește `price_dual()` cu cursul Yamaha (BNR), indiferent de marca
anunțului.

### Formular de contact

`POST /api/lead/rulate`, metodă nouă în `ContactController`, pe tiparul
lead-urilor existente:
- Câmpuri: nume, telefon, email, mesaj, acord GDPR, `vehicle_id` (opțional),
  câmp-capcană `website`.
- Validare: nume + (telefon sau email) + acord. Răspuns JSON; formularul e
  AJAX prin handlerul `[data-ajax-form]` existent și arată panoul „Mulțumim".
- Salvează în `site_messages` cu `type = 'rulate'`, IP și dată; titlul și
  linkul anunțului intră în corpul mesajului. Apare în admin la Mesaje →
  „Cereri site".
- Trimite email la adresa departamentului „Vânzări moto" din
  `contact_departments` (căutat după etichetă; rezervă `MAIL_DEALER`), cu
  Reply-To = emailul clientului și subiect „Rulate: <titlul anunțului>".
- Un `vehicle_id` care nu există sau nu mai e public nu blochează trimiterea;
  mesajul pleacă fără referința la anunț.

### `App\Admin\UsedController`

Rute sub `{base}/rulate`:

| Rută | Acțiune |
|---|---|
| `GET /rulate` | listă + filtre pe stare + mărci și categorii |
| `GET /rulate/{id}` | formular (`0` = anunț nou) |
| `POST /rulate/{id}` | salvare |
| `POST /rulate/{id}/dezactiveaza` | dezactivare |
| `POST /rulate/{id}/reactiveaza` | reactivare 30 de zile |
| `POST /rulate/{id}/delete` | ștergere definitivă |
| `POST /rulate/marca`, `/rulate/marca/{id}/delete` | mărci |
| `POST /rulate/categorie`, `/rulate/categorie/{id}/delete` | categorii |

Toate POST-urile cer CSRF, ca restul adminului. Formularul folosește editorul
Quill și managerul de imagini existent (`[data-imgmgr]`, `data-store=filename`).
Contextul de upload `rulate` se adaugă în whitelist-ul din `App\Admin\Upload`.

Validare la salvare: titlu, marcă, categorie obligatorii; an între 1950 și anul
viitor; km, cc, preț ≥ 0. La eroare, formularul se reafișează cu datele
introduse.

Lista arată coperta, titlul, marca, categoria, prețul, starea și termenul.
Meniul lateral al adminului primește intrarea „Rulate".

### Dashboard

`DashboardController` primește lista `used->expired()`. Șablonul afișează
caseta „Anunțuri rulate expirate (N)" doar când N > 0, cu titlul, data
expirării și butonul „Reactivează 30 de zile" pe fiecare rând (același POST ca
în listă, cu întoarcere la dashboard). Statisticile primesc și numărul de
rulate active.

### Navigare

„Rulate" → `/rulate` în `partials/header.twig` (bara principală și offcanvas-ul
de mobil) și în footer.

## SEO și Open Graph

| Pagină | `<title>` | Descriere |
|---|---|---|
| `/rulate` | Motociclete rulate și second hand — Dual Motors | text fix |
| categorie | „<Categorie> rulate — Dual Motors" | text cu numele categoriei |
| marcă | „<Marcă> rulate, second hand — Dual Motors" | text cu numele mărcii |
| anunț | „<Titlu> (<an>) — rulat, <preț> EUR" | an, km, cc + primele ~140 de caractere din descriere, fără HTML |

- `canonical_path` pe fiecare pagină; listele îl setează fără `?p` pentru
  prima pagină și cu `?p=N` pentru celelalte.
- OG: `og:title`, `og:description`, `og:url`, `og:image` (coperta anunțului;
  imaginea implicită a sitului pe liste), `og:type` = `product` pe anunț și
  `website` pe liste; `twitter:card = summary_large_image`.
- Un singur `h1` pe pagină.
- JSON-LD pe anunț: `Vehicle` (name, brand, `vehicleModelDate`,
  `mileageFromOdometer` în km, `vehicleEngine.engineDisplacement`, imagini) cu
  `Offer` (`price`, `priceCurrency: EUR`, `itemCondition: UsedCondition`,
  `availability: InStock`, vânzător = dealerul) + `BreadcrumbList`. Fără preț →
  fără `Offer`.
- JSON-LD pe liste: `ItemList` cu anunțurile paginii + `BreadcrumbList`.
- Pagina 410 are `noindex`.
- Sitemap (`SeoController`): `/rulate`, categoriile și mărcile cu anunțuri
  publice, anunțurile publice cu `lastmod = updated_at`.

## Erori

- Tabele lipsă sau DB indisponibilă: paginile publice afișează lista goală cu
  mesajul „Momentan nu avem vehicule rulate", fără 500; coloana din dreapta
  păstrează formularul.
- Eșecul emailului nu strică salvarea mesajului; rămâne în `email_log` ca
  `failed`.
- Upload respins (tip sau dimensiune): mesajul managerului de imagini existent.

## Teste

- `tests/UsedRepositoryTest.php` (PHP simplu, gardă CLI, tranzacție cu rollback
  pe baza locală, tiparul din `tests/_nl.php`):
  - anunț nou e public; cu `expires_at` în trecut nu mai e;
  - limita exactă: `expires_at = acum` nu e public;
  - `reactivate()` readuce anunțul public pentru 30 de zile;
  - `deactivate()` îl scoate și nu-l trece în lista `expired()`;
  - `expired()` întoarce doar `is_active = 1` cu termen depășit;
  - numărătorile pe categorii și mărci ignoră anunțurile nepublice;
  - ștergerea unei mărci folosite e refuzată.
- Verificare manuală: curl pe 200 / 301 (slug greșit) / 404 / 410; formularul
  trimis local (apare în `site_messages` și `mail.log`); captură la 390 px cu
  `scrollWidth == clientWidth`; validarea JSON-LD.

## Livrare

1. `git pull` pe server, apoi `ea-php81 database/migrate_admin.php` și
   `ea-php81 database/seed_used.php`.
2. Folderul `media/rulate/` creat cu drept de scriere.
3. Bump `?v=N` pentru `app.css` / `app.js` și pentru cele de admin.
4. CLAUDE.md primește o secțiune scurtă despre modul.
