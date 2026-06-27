# Audit GDPR + EU Cookie Consent — Design

**Data:** 2026-06-27
**Proiect:** motociclete.com.ro (Dual Motors / Dual Tours SRL)
**Status:** aprobat, gata de planificare

## Context

Portalul colectează date personale prin mai multe fluxuri și încarcă Google
Analytics 4 (`gtag`, `G-VTV11ZJ0P9`) **necondiționat** în `<head>`
([templates/layout.twig:9-15](../../../templates/layout.twig#L9-L15)) — fără
niciun mecanism de consimțământ. Site-ul deservește trafic din EEA (România) →
e nevoie de cookie consent conform ePrivacy + GDPR și de un audit al fluxurilor
de date.

### Stare curentă relevantă

**Date personale colectate:**
- `clienti` — clienți My Garage (email, telefon, nume); login passwordless OTP.
- `site_messages` — lead-uri „Cere ofertă" / „Drive test"; conține **IP + dată**.
- `service_bookings` — programări service anonime; conține **IP**.
- `service_requests` — cereri service legate de `clienti_id`.
- `email_log` — fiecare email trimis (destinatar, subiect, corp).
- `client_otp` — coduri OTP.
- (Legacy `clienti_2021` pe BikerShop — nefolosit la runtime.)

**Cookie-uri / tracking:**
- GA4 `gtag` — încărcat necondiționat (problema principală).
- Cloudflare injectează `email-decode.min.js` automat (+ `__cf_bm` — strict necesar/securitate).
- `dm_garage` — cookie de sesiune PHP (HttpOnly, strict necesar).
- `localStorage` — dismiss anunț site-wide ([assets/js/app.js:722-726](../../../assets/js/app.js#L722-L726)).
- Embed-uri YouTube deja pe `youtube-nocookie` ([assets/js/app.js:598](../../../assets/js/app.js#L598)).

**Pagini legale:** placeholdere `confidentialitate` + `termeni-si-conditii` în
tabela `pages` ([database/seed_settings.php:33-34](../../../database/seed_settings.php#L33-L34)).
**Lipsesc:** politica de cookie-uri și bannerul de consimțământ.

## Decizii (din brainstorming)

- **Livrabil:** audit GDPR (document) + banner cookie consent (implementare).
- **Abordare banner:** custom vanilla (CSS+JS, fără build/CDN, ca restul
  proiectului) cu **Google Consent Mode v2**.
- **Remedieri în această rundă:** banner + checkbox consimțământ/link
  confidențialitate pe formularele de lead și programare service + pagina
  politică de cookie-uri. Restul (retention, conținut juridic) = recomandări în audit.

## Partea A — Banner cookie consent (Consent Mode v2)

### Fluxul de consimțământ

1. În `<head>`, **înainte** de încărcarea `gtag.js`, script inline:
   - `gtag('consent','default', { analytics_storage:'denied', ad_storage:'denied', ad_user_data:'denied', ad_personalization:'denied', wait_for_update:500 })`.
   - GA4 rulează în mod „cookieless ping" (fără cookie `_ga`) până la accept.
   - Tot inline: citește cookie-ul `dm_consent`; dacă `analytics=granted`, face
     `gtag('consent','update',{analytics_storage:'granted'})` imediat → vizitatorii
     care au acceptat nu văd bannerul și nu pierd analytics (fără flicker).
   - `gtag('config', 'G-VTV11ZJ0P9')` rămâne; respectă starea de consimțământ.

2. **Stocare consimțământ:** cookie first-party `dm_consent`, versionat
   (ex. `v1:analytics=1`), expirare **12 luni**, + oglindă în `localStorage`.
   La schimbarea versiunii politicii (`v1`→`v2`) → re-prompt automat.

3. **UI** — `templates/partials/cookie-consent.twig` (inclus în `layout.twig`),
   două niveluri:
   - **Banner:** text scurt + butoane „Accept toate" / „Doar necesare" / „Setări".
     Butoanele Accept și Refuz au **prominență egală** (cerință legală).
   - **Modal preferințe:** categorii
     - **Necesare** — toggle dezactivat, mereu ON (sesiune, consimțământ, securitate CF).
     - **Analitice** — GA4, togglabil (default OFF la prima vizită).
     Buton „Salvează preferințele".
   - **Fără** categorie „Marketing" (nu există pixel Facebook/Google Ads). `ad_*`
     rămân mereu `denied`.

4. **JS** — `assets/js/consent.js`, încărcat **devreme** (ne-deferred, în `<head>`
   sau imediat după gtag): citește cookie, arată bannerul dacă nu există decizie,
   scrie cookie + `gtag('consent','update',...)`, expune `window.openCookiePrefs()`.

5. **Footer:** link „Setări cookie-uri" în rândul legal
   ([templates/partials/footer.twig:55-65](../../../templates/partials/footer.twig#L55-L65))
   → `window.openCookiePrefs()` (mecanism de retragere a consimțământului, obligatoriu).

6. **Stiluri** `.cc-*` în `assets/css/app-v2.css`; bump `?v=` în `layout.twig`.

### Module / interfețe

| Unitate | Rol | Depinde de |
|---------|-----|-----------|
| Inline head snippet (în `layout.twig`) | default consent denied + grant timpuriu din cookie | — |
| `partials/cookie-consent.twig` | markup banner + modal preferințe | globalul `base`, link `politica-cookies` |
| `assets/js/consent.js` | logica de citire/scriere consimțământ + gtag update | cookie `dm_consent`, `gtag` |
| `assets/css/app-v2.css` (`.cc-*`) | stiluri banner/modal | design system existent |

## Partea B — Pagina politică de cookie-uri

- Rând nou în tabela `pages`: slug `politica-cookies`, titlu „Politica de
  cookie-uri", `body_html` cu inventarul real:
  - `dm_garage` (sesiune, necesar), `dm_consent` (preferințe, necesar),
    `_ga` / `_ga_*` (GA4, analitic, durată), `__cf_bm` (Cloudflare, securitate).
- Adăugat în seed (`seed_settings.php`, `INSERT IGNORE` — nu suprascrie editările
  din admin), editabil din admin prin `PageController` existent.
- Link în footer (rândul legal).

## Partea C — Remedieri formulare

Checkbox **obligatoriu** „Sunt de acord cu prelucrarea datelor conform
[Politicii de confidențialitate](/confidentialitate)" pe:

- **Lead modals** (oferta + test-ride) — [templates/partials/lead-modals.twig](../../../templates/partials/lead-modals.twig).
- **Programare service** — `templates/service.twig`.

Validare **server-side** în `ContactController` (`/api/lead/*`) și
`ServiceController` (`/service/programare`): respinge dacă lipsește câmpul
`consent` (același tipar defensiv ca honeypot-ul `website` existent).

**My Garage OTP login** (`templates/client/login.twig`): notă scurtă + link la
confidențialitate (login = bază legală contract/interes legitim → fără checkbox
blocant).

## Partea D — Documentul de audit

`GDPR-AUDIT.md` la rădăcina repo (lângă `DEPLOY.md`; `*.md` blocat din web prin
`.htaccess`). Conține:

1. **Inventar de date** — tabel: dată / locație (tabelă) / bază legală /
   retention recomandat. Acoperă `clienti`, `site_messages` (+IP),
   `service_bookings` (+IP), `service_requests`, `email_log`, `client_otp`.
2. **Inventar de cookie-uri** (oglindă a paginii publice).
3. **Lacune găsite** + status remediere (rezolvat în această rundă vs recomandat).
4. **Acțiuni pentru companie** (non-tehnice / decizii de business):
   - Conținut juridic real pentru pagina de confidențialitate + termeni.
   - Responsabil prelucrare date / contact ANSPDCP.
   - Politică de retention + **cron de ștergere** date vechi (recomandat, neimplementat acum).
   - DPA-uri cu procesatori: Google (Analytics), Cloudflare, host cPanel + SMTP,
     BikerShop / PartsEurope.

## Out of scope (recomandări, nu se implementează acum)

- Cron de retention / ștergere automată date vechi.
- Conținutul juridic final al politicilor (necesită validare umană).
- Categorie „Marketing" / pixeli de advertising (nu există).
- Anonimizarea IP-urilor deja stocate în `site_messages` / `service_bookings`
  (de evaluat în runda de retention).

## Criterii de succes

- GA4 NU setează cookie `_ga` înainte de accept (verificabil în DevTools →
  Application → Cookies pe prima vizită).
- Bannerul apare o singură dată; decizia persistă 12 luni; „Setări cookie-uri"
  din footer redeschide preferințele.
- Accept → `_ga` apare + `gtag consent update granted`. Refuz → fără `_ga`.
- Formularele de lead/service resping submit-ul fără bifa de consimțământ
  (client + server).
- Pagina `/politica-cookies` se randează și e editabilă din admin.
- `GDPR-AUDIT.md` există și acoperă toate fluxurile de date inventariate.
