# Pagina produs — îmbunătățiri (calculator rate, modale lead, tab scroll, finanțare)

Data: 2026-06-12

Șase modificări pe pagina de produs (`templates/catalog/product.twig`) + o pagină
nouă de condiții de finanțare + două tabele noi în DB.

## 1. Scroll/focus la click pe tab

În `assets/js/app.js`, handler-ul de click pe `[data-ptab]` (în jur de linia 429):
după `setPanel(id)`, derulează bara de taburi în vizor cu
`ptabs.scrollIntoView({ behavior: 'smooth', block: 'start' })`.

- Bara `.ptabs` e deja `position: sticky` sub header → conținutul panoului aterizează
  imediat sub taburi.
- **Doar la click real**, NU la deschiderea via deep-link `#accesorii`/`#piese-oem`
  (acolo `setPanel` se apelează fără scroll, ca să nu sară pagina la load).

## 2. Buton telefon / WhatsApp (0722354437)

În `.product__cta` (`templates/catalog/product.twig`, ~linia 76):

- Buton **WhatsApp**: `https://wa.me/40722354437?text=<mesaj url-encodat>`, unde mesajul
  precompletează numele modelului (ex. „Bună ziua, sunt interesat de Yamaha MT-09 2026.").
  Target `_blank`, `rel="noopener"`.
- Link secundar **Sună**: `tel:0722354437`.
- Stil: WhatsApp ca buton secundar verde discret; „Sună" ca link/butonaș alăturat.

## 3. Calculator de rate (`#unicredit-calculator`)

Card în `.product__summary`, sub bloc preț (afișat doar dacă produsul are preț).

- Două controale:
  - **Preț** read-only = prețul în lei cu TVA = `eur × curs` (rotunjit). Valoarea EUR
    e în `p.price`; cursul vine din `Settings::currency()['rate']`.
  - **`<select>` durată**: opțiunile din `finance.terms` (`12,18,24,36,48,60`),
    **default 60** (ultima, `selected`).
- **Formula** (server-side, în `FinanceController` sau un helper):
  `rata(n) = anuitate(pret_lei, calc_rate, n)`, unde
  `anuitate(P, annual, n) = P * (annual/12) / (1 - (1 + annual/12)^(-n))`.
  `calc_rate` = `finance.calc_rate` (default 0.145 = DAE 14,5%, alegerea clientului).
  Aproximativ vs. widgetul real Unicredit (~15–30 lei diferență), acceptat.
- **Server precomputează** harta `{12: rata, 18: rata, …}` (rotunjit la 2 zecimale) și o
  pune ca atribut `data-rates='{...}'` (JSON) pe card. JS-ul (în `app.js`) doar
  înlocuiește valoarea afișată la `change` pe select. Formula rămâne într-un singur loc (PHP).
- Sub calculator: link **„Detalii finanțare →"** → `/finantare`.
- Fără JS: cardul afișează rata pentru 60 luni (valoarea default randată server-side).

## 4. Pagină condiții finanțare + stocare în DB

- Rută nouă `GET /finantare` → `FinanceController::page` → `templates/finance.twig`.
- Tabel nou **`finance`** (un singur rând de config), `CREATE TABLE IF NOT EXISTS`,
  **NU se șterge la re-migrare** (ca `settings`):

  | coloană | tip | valoare seed |
  |---|---|---|
  | `id` | TINYINT PK | 1 |
  | `nominal_rate` | DECIMAL(5,2) | 13.00 |
  | `dae` | DECIMAL(5,2) | 14.50 |
  | `admin_fee` | DECIMAL(8,2) | 10.00 |
  | `calc_rate` | DECIMAL(5,2) | 14.50 |
  | `terms` | VARCHAR(64) | '12,18,24,36,48,60' |
  | `page_title` | VARCHAR(255) | 'Finanțare prin UniCredit Consumer Financing' |
  | `page_html` | TEXT (utf8mb4) | conținutul din `documente/conditii-unicredit.docx` |

  Motiv tabel dedicat (nu `settings`): `settings.svalue` e VARCHAR(255), prea mic pt HTML.
- `finance.twig`: randează `page_title`, `page_html|raw` + un tabel sumar cu parametrii
  financiari (Produs financiar, dobândă fixă, DAE, comision lunar administrare, perioadă).
- `FinanceController` citește rândul `finance` prin PDO (prepared, degradare grațioasă);
  expune `calc_rate`/`terms` și pentru calculatorul din pagina produs.
- Conținutul `page_html` provine din extragerea docx-ului (deja făcută în brainstorming):
  pași credit 100% online, documente necesare, eligibilitate, tabelul de finanțare,
  unde se plătesc ratele, contact UniCredit, datele juridice ale IFN-ului.
- Toate valorile sunt editabile mai târziu din viitorul sistem de administrare.

## 5. Fundal gri pe „Modele similare"

În `assets/css/app.css`: `.product-related { background: var(--surface); }` +
padding vertical ca să citească ca o bandă. Bump `?v=N` în `templates/layout.twig`.

## 6. Modale contact + test ride

Cele două butoane CTA (**Cere ofertă**, **Programează test ride**) deschid modale
în loc să ducă în altă pagină.

- **Modal Cere ofertă** — câmpuri: nume prenume, email, telefon, mesaj.
- **Modal Test ride** — câmpuri: nume prenume, email, telefon, categorie permis
  (select: A1, A2, A, B etc.).
- Markup în `templates/partials/lead-modals.twig`, inclus în `product.twig`.
  Butoanele primesc `data-modal="oferta|test-ride"`; JS-ul (în `app.js`) deschide/închide
  (overlay, focus trap minim, ESC, click pe fundal).
- Submit **AJAX POST** (fetch) →
  - `POST /api/lead/oferta`
  - `POST /api/lead/test-ride`
  gestionate de `ContactController`. Răspuns JSON `{ ok: true }`.
- La succes, modalul afișează mesajul:
  *„Mulțumim pentru mesaj, un reprezentant va intra în legătură cu dumneavoastră cât
  se poate de repede."*
- Fiecare submit:
  1. **Salvat în tabel nou `site_messages`** (`CREATE TABLE IF NOT EXISTS`):
     `id` BIGINT PK AI, `type` ENUM('oferta','test_ride'), `brand` VARCHAR,
     `product_slug` VARCHAR, `product_name` VARCHAR, `name` VARCHAR, `email` VARCHAR,
     `phone` VARCHAR, `message` TEXT NULL, `licence` VARCHAR NULL (categoria permis),
     `ip` VARCHAR(45), `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP.
  2. **Email** către `info@motociclete.com.ro` via `Support\Mailer` existent: subiect
     („Cerere ofertă: {model}" / „Programare test ride: {model}"), corp cu modelul
     (nume + brand + URL), nume, telefon, email, mesaj/permis, data trimiterii.
- **Spam guard:** câmp honeypot ascuns (dacă e completat → ignoră silențios cu `{ok:true}`)
  + validare server-side (nume/email/telefon obligatorii, email valid). IP din
  `$_SERVER['REMOTE_ADDR']` (sau `X-Forwarded-For` dacă în spatele proxy-ului).
- **Fără JS:** butoanele păstrează un fallback (`mailto:info@motociclete.com.ro` cu
  subiect precompletat) ca să nu fie moarte.
- Adresa destinatar e configurabilă: folosește `MAIL_DEALER`/`mail.dealer` dacă există,
  altfel hardcodat `info@motociclete.com.ro`.

## Fișiere

**Noi:**
- `src/Controllers/FinanceController.php`
- `src/Controllers/ContactController.php`
- `templates/finance.twig`
- `templates/partials/lead-modals.twig`
- `database/schema_messages.sql` (tabelele `site_messages` + `finance` + seed `finance`)
- `database/seed_finance.php` (opțional, dacă `page_html` e prea mare pt SQL inline —
  populează `finance.page_html` din docx/txt). Rulat cu binarul Laragon PHP 8.1.

**Modificate:**
- `templates/catalog/product.twig` (buton wa/tel, calculator, include modale, fallback CTA)
- `assets/js/app.js` (scroll pe tab, calculator change, deschidere modale + fetch submit)
- `assets/css/app.css` (calculator, modale, fundal `.product-related`, buton WhatsApp)
- `src/Routes.php` (`/finantare`, `/api/lead/oferta`, `/api/lead/test-ride`)
- `templates/layout.twig` (bump `?v=N`)

## Convenții respectate

- Toate query-urile = prepared statements; degradare grațioasă dacă DB indisponibilă.
- BikerShop rămâne read-only (neatins aici).
- Scriptul de seed rulează cu binarul Laragon PHP 8.1.10 (are pdo_mysql).
- Schema `IF NOT EXISTS` + nedistructivă la re-migrarea catalogului.
- Reveal/animații gated pe `.js`; conținutul vizibil fără JS.

## Out of scope (explicit)

- Sistemul de administrare pentru `finance` și `site_messages` (UI de editare) — vine
  mai târziu; acum doar stocăm datele și seed-uim valorile.
- Integrarea cu modulul `drivetest` (clientul a ales doar DB nou + email).
- Reproducerea exactă a widgetului Unicredit (aproximare DAE 14,5% acceptată).
