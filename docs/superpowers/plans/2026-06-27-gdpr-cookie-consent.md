# GDPR Audit + EU Cookie Consent Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a GDPR-compliant EU cookie consent banner (Google Consent Mode v2) that gates GA4, publish a cookie policy page, add consent checkboxes to public forms, and write a GDPR data-flow audit document.

**Architecture:** GA4 stays loaded but starts with Consent Mode v2 `default: denied` (cookieless ping mode); a custom vanilla banner (`consent.js` + Twig partial) writes a first-party `dm_consent` cookie and calls `gtag('consent','update')` on opt-in. Forms gain a required `consent` checkbox validated server-side (mirroring the existing honeypot pattern). The audit is a committed Markdown doc.

**Tech Stack:** PHP 8.1, Slim 4, Twig, vanilla CSS/JS (no build step, no CDN), Google Consent Mode v2.

## Global Constraints

- **No build step / no CDN:** all JS/CSS is vanilla and served locally. New JS goes in `assets/js/`, CSS in `assets/css/app-v2.css`. (CLAUDE.md "Stack")
- **Cache-bust on asset edits:** bump `?v=N` in `templates/layout.twig` for any edited `app.css`/`app-v2.css`/`app.js`/`app-v2.js`/new JS. (CLAUDE.md "Local development")
- **No automated test runner exists.** `tests/` holds runnable PHP probe scripts; verification is via browser DevTools + `curl` + the Laragon PHP binary, NOT phpunit.
- **PHP CLI scripts with PDO/curl** run with `C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe` (PATH `php` is 8.2, no `pdo_mysql`). (CLAUDE.md "Stack")
- **Seeders are idempotent** (`INSERT IGNORE`) — never overwrite admin-edited content. The server DB is the source of truth for page content; do NOT overwrite live `/confidentialitate` text. (memory: relaunch-2026-go-live)
- **Language is Romanian** for all user-facing copy.
- **`*.md` files are blocked from the web** by `.htaccess`, so `GDPR-AUDIT.md` at repo root is safe. (CLAUDE.md "Stack")
- **Commit directly on `main`** (no branches/PRs). (memory: workflow-commit-pe-main)
- **Consent categories:** only **Necesare** + **Analitice**. `ad_*` Consent Mode signals stay `denied` always (no ad products). (spec "Out of scope")

---

## Task 1: Consent Mode v2 default-denied + early grant in layout head

**Files:**
- Modify: `templates/layout.twig:8-15` (the GA4 block)

**Interfaces:**
- Produces: a `dm_consent` cookie contract — value format `v1:analytics=1` (granted) or `v1:analytics=0` (denied), read by this inline snippet via the substring `analytics=1`. Tasks 2 must write exactly this format.

- [ ] **Step 1: Replace the GA4 block with consent-aware ordering**

In `templates/layout.twig`, replace lines 8-15 (the comment + the two GA scripts) with:

```twig
    {# Google Analytics (GA4) with Consent Mode v2.
       Consent defaults to DENIED (EEA) so GA runs cookieless until the user opts in
       via the cookie banner (assets/js/consent.js, cookie `dm_consent`).
       Cloudflare injects email-decode.min.js automatically — not added here. #}
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('consent', 'default', {
        ad_storage: 'denied',
        ad_user_data: 'denied',
        ad_personalization: 'denied',
        analytics_storage: 'denied',
        wait_for_update: 500
      });
      // Returning visitor who already granted analytics: lift consent before GA loads.
      try {
        if (/(?:^|;\s*)dm_consent=[^;]*analytics=1/.test(document.cookie)) {
          gtag('consent', 'update', { analytics_storage: 'granted' });
        }
      } catch (e) {}
      gtag('js', new Date());
      gtag('config', 'G-VTV11ZJ0P9');
    </script>
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-VTV11ZJ0P9"></script>
```

- [ ] **Step 2: Verify GA4 sets no `_ga` cookie on a fresh visit**

Open `http://motociclete.test/` in a browser with no `dm_consent`/`_ga` cookies (DevTools → Application → Cookies → clear site data, then reload).
Expected: **no `_ga` or `_ga_*` cookie present** after load (GA is in denied/cookieless mode). The `gtag/js` request still fires (Network tab) but no analytics cookie is written.

- [ ] **Step 3: Commit**

```bash
git add templates/layout.twig
git commit -m "feat(gdpr): GA4 Consent Mode v2 default-denied + early grant from cookie"
```

---

## Task 2: Cookie consent banner — partial, JS, styles, wiring

**Files:**
- Create: `templates/partials/cookie-consent.twig`
- Create: `assets/js/consent.js`
- Modify: `assets/css/app-v2.css` (append `.cc-*` block)
- Modify: `templates/layout.twig` (include partial + load `consent.js`; bump `app-v2.css` version)

**Interfaces:**
- Consumes: cookie format `v1:analytics=1|0` from Task 1.
- Produces: global `window.openCookiePrefs()` (opens the preferences modal) — used by Task 3.

- [ ] **Step 1: Create the banner + preferences partial**

Create `templates/partials/cookie-consent.twig`:

```twig
{# EU cookie consent — banner + preferences modal. Logic in assets/js/consent.js.
   Hidden by default (shown by JS only when there is no stored decision). #}
<div class="cc" data-cc hidden>
  <div class="cc__banner" role="dialog" aria-modal="false" aria-label="Consimțământ cookie-uri" data-cc-banner>
    <div class="cc__text">
      <strong>Folosim cookie-uri</strong>
      <p>Folosim cookie-uri necesare pentru funcționarea site-ului și, cu acordul tău,
         cookie-uri de analiză (Google Analytics) pentru a înțelege cum este folosit site-ul.
         Detalii în <a href="{{ base }}/politica-cookies">Politica de cookie-uri</a>.</p>
    </div>
    <div class="cc__actions">
      <button type="button" class="btn btn--ghost cc__btn" data-cc-reject>Doar necesare</button>
      <button type="button" class="btn btn--ghost cc__btn" data-cc-prefs>Setări</button>
      <button type="button" class="btn btn--primary cc__btn" data-cc-accept>Accept toate</button>
    </div>
  </div>

  <div class="cc__modal" data-cc-modal hidden>
    <div class="cc__overlay" data-cc-modal-close></div>
    <div class="cc__dialog" role="dialog" aria-modal="true" aria-labelledby="cc-prefs-title">
      <button type="button" class="cc__x" data-cc-modal-close aria-label="Închide">&times;</button>
      <h2 class="cc__title" id="cc-prefs-title">Setări cookie-uri</h2>

      <div class="cc__cat">
        <label class="cc__cat-head">
          <input type="checkbox" checked disabled>
          <span><strong>Necesare</strong> — sesiune, securitate, preferința de consimțământ. Mereu active.</span>
        </label>
      </div>
      <div class="cc__cat">
        <label class="cc__cat-head">
          <input type="checkbox" data-cc-analytics>
          <span><strong>Analitice</strong> — Google Analytics 4, statistici anonime de utilizare.</span>
        </label>
      </div>

      <div class="cc__modal-actions">
        <button type="button" class="btn btn--ghost cc__btn" data-cc-reject>Refuz analitice</button>
        <button type="button" class="btn btn--primary cc__btn" data-cc-save>Salvează preferințele</button>
      </div>
    </div>
  </div>
</div>
```

- [ ] **Step 2: Create the consent JS**

Create `assets/js/consent.js`:

```javascript
(function () {
  'use strict';
  var NAME = 'dm_consent';
  var VERSION = 'v1';
  var MAX_AGE = 60 * 60 * 24 * 365; // 12 months

  function readCookie() {
    var m = document.cookie.match(/(?:^|;\s*)dm_consent=([^;]*)/);
    if (!m) { return null; }
    var val = decodeURIComponent(m[1]);
    if (val.indexOf(VERSION + ':') !== 0) { return null; } // version bump => re-prompt
    return { analytics: /analytics=1/.test(val) };
  }

  function writeCookie(analytics) {
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = NAME + '=' + encodeURIComponent(VERSION + ':analytics=' + (analytics ? '1' : '0')) +
      '; path=/; max-age=' + MAX_AGE + '; SameSite=Lax' + secure;
  }

  function applyConsent(analytics) {
    if (typeof window.gtag === 'function') {
      window.gtag('consent', 'update', { analytics_storage: analytics ? 'granted' : 'denied' });
    }
  }

  var root, banner, modal, analyticsToggle;

  function showBanner() { if (root) { root.hidden = false; if (banner) { banner.hidden = false; } if (modal) { modal.hidden = true; } } }
  function hideAll() { if (root) { root.hidden = true; } }
  function openPrefs() {
    var c = readCookie();
    if (analyticsToggle) { analyticsToggle.checked = !!(c && c.analytics); }
    if (root) { root.hidden = false; }
    if (banner) { banner.hidden = true; }
    if (modal) { modal.hidden = false; }
  }
  window.openCookiePrefs = openPrefs;

  function decide(analytics) {
    writeCookie(analytics);
    applyConsent(analytics);
    hideAll();
  }

  document.addEventListener('DOMContentLoaded', function () {
    root = document.querySelector('[data-cc]');
    if (!root) { return; }
    banner = root.querySelector('[data-cc-banner]');
    modal = root.querySelector('[data-cc-modal]');
    analyticsToggle = root.querySelector('[data-cc-analytics]');

    root.querySelectorAll('[data-cc-accept]').forEach(function (b) { b.addEventListener('click', function () { decide(true); }); });
    root.querySelectorAll('[data-cc-reject]').forEach(function (b) { b.addEventListener('click', function () { decide(false); }); });
    root.querySelectorAll('[data-cc-prefs]').forEach(function (b) { b.addEventListener('click', openPrefs); });
    root.querySelectorAll('[data-cc-modal-close]').forEach(function (b) { b.addEventListener('click', showBanner); });
    var save = root.querySelector('[data-cc-save]');
    if (save) { save.addEventListener('click', function () { decide(!!(analyticsToggle && analyticsToggle.checked)); }); }

    if (!readCookie()) { showBanner(); }
  });
})();
```

- [ ] **Step 3: Append styles to `assets/css/app-v2.css`**

Append at the end of `assets/css/app-v2.css`:

```css
/* ---- Cookie consent ---- */
.cc__banner{position:fixed;left:0;right:0;bottom:0;z-index:1000;display:flex;gap:1rem;
  align-items:center;justify-content:space-between;flex-wrap:wrap;
  padding:1rem clamp(1rem,4vw,2rem);background:#0E0E10;color:#fff;
  box-shadow:0 -6px 24px rgba(0,0,0,.25)}
.cc__text{max-width:62ch}
.cc__text strong{display:block;font-weight:800;margin-bottom:.25rem}
.cc__text p{margin:0;font-size:.9rem;line-height:1.5;color:#d6d6d8}
.cc__text a{color:#fff;text-decoration:underline}
.cc__actions{display:flex;gap:.5rem;flex-wrap:wrap}
.cc__btn{white-space:nowrap}
.cc__modal{position:fixed;inset:0;z-index:1001;display:flex;align-items:center;justify-content:center;padding:1rem}
.cc__overlay{position:absolute;inset:0;background:rgba(0,0,0,.55)}
.cc__dialog{position:relative;background:#fff;color:#0E0E10;max-width:560px;width:100%;
  border-radius:12px;padding:1.5rem clamp(1rem,4vw,2rem);max-height:90vh;overflow:auto}
.cc__x{position:absolute;top:.5rem;right:.75rem;background:none;border:0;font-size:1.6rem;line-height:1;cursor:pointer}
.cc__title{margin:0 0 1rem;font-size:1.25rem}
.cc__cat{padding:.75rem 0;border-top:1px solid #eee}
.cc__cat-head{display:flex;gap:.6rem;align-items:flex-start;font-size:.9rem;line-height:1.5;cursor:pointer}
.cc__cat-head input{margin-top:.2rem}
.cc__modal-actions{display:flex;gap:.5rem;justify-content:flex-end;margin-top:1.25rem;flex-wrap:wrap}
@media(max-width:640px){.cc__banner{flex-direction:column;align-items:stretch}.cc__actions{justify-content:stretch}.cc__actions .cc__btn{flex:1}}
```

- [ ] **Step 4: Wire partial + script into layout and bump CSS version**

In `templates/layout.twig`:
1. After the line `{% include 'partials/announcement.twig' %}` (currently line 90), add:

```twig
    {% include 'partials/cookie-consent.twig' %}
```

2. Before the existing `<script src="{{ base }}/assets/js/app.js?v=20" defer></script>` line, add (consent.js loads first, NOT deferred-after-others so prefs are wired early):

```twig
    <script src="{{ base }}/assets/js/consent.js?v=1" defer></script>
```

3. Bump the app-v2.css version from `?v=3` to `?v=4` on line 78.

- [ ] **Step 5: Verify banner behavior in the browser**

With site data cleared, load `http://motociclete.test/`:
- Banner appears at the bottom.
- Click **"Accept toate"** → banner disappears; DevTools → Cookies shows `dm_consent=v1:analytics=1` AND a `_ga` cookie now appears; reload → no banner.
- Clear cookies, reload, click **"Doar necesare"** → `dm_consent=v1:analytics=0`, **no** `_ga` cookie; reload → no banner.
- Clear cookies, reload, click **"Setări"** → modal opens with Necesare disabled/checked, Analitice unchecked.

- [ ] **Step 6: Commit**

```bash
git add templates/partials/cookie-consent.twig assets/js/consent.js assets/css/app-v2.css templates/layout.twig
git commit -m "feat(gdpr): custom cookie consent banner (Consent Mode v2 opt-in)"
```

---

## Task 3: Footer "Setări cookie-uri" link (consent withdrawal)

**Files:**
- Modify: `templates/partials/footer.twig:55-65` (the legal row)

**Interfaces:**
- Consumes: `window.openCookiePrefs()` from Task 2.

- [ ] **Step 1: Add the reopen-preferences link to the legal row**

In `templates/partials/footer.twig`, inside `<span class="site-footer__legal"> … </span>` (after the `{% endif %}` that closes the legal pages list, before `</span>` on line 64), add:

```twig
                <a href="#" onclick="if(window.openCookiePrefs){window.openCookiePrefs();}return false;">Setări cookie-uri</a>
```

- [ ] **Step 2: Verify**

Load any page, scroll to footer, click **"Setări cookie-uri"** → the cookie preferences modal opens (no page navigation).

- [ ] **Step 3: Commit**

```bash
git add templates/partials/footer.twig
git commit -m "feat(gdpr): footer link to reopen cookie preferences"
```

---

## Task 4: Cookie policy page (`/politica-cookies`)

**Files:**
- Modify: `database/seed_settings.php:32-37` (the legal-pages seed array + insert)

**Interfaces:**
- Produces: a `pages` row with slug `politica-cookies`, served by the existing `PageController` at `/{slug}`.

- [ ] **Step 1: Add the cookie policy row to the seed array**

In `database/seed_settings.php`, add a third entry to the legal-pages array (after the `confidentialitate` line, line 34):

```php
    ['politica-cookies', 'Politica de cookie-uri', '<h2>Ce sunt cookie-urile</h2>
<p>Cookie-urile sunt fișiere text mici stocate pe dispozitivul tău. Le folosim pentru funcționarea site-ului și, cu acordul tău, pentru analiză.</p>
<h2>Cookie-uri pe care le folosim</h2>
<table>
<thead><tr><th>Cookie</th><th>Categorie</th><th>Scop</th><th>Durată</th></tr></thead>
<tbody>
<tr><td>dm_garage</td><td>Necesar</td><td>Sesiunea de autentificare „Garajul meu"</td><td>Sesiune</td></tr>
<tr><td>dm_consent</td><td>Necesar</td><td>Reține preferința ta privind cookie-urile</td><td>12 luni</td></tr>
<tr><td>__cf_bm</td><td>Necesar</td><td>Securitate / anti-bot (Cloudflare)</td><td>30 min</td></tr>
<tr><td>_ga, _ga_*</td><td>Analitic</td><td>Google Analytics 4 — statistici anonime de utilizare</td><td>până la 13 luni</td></tr>
</tbody>
</table>
<h2>Gestionarea consimțământului</h2>
<p>Îți poți schimba oricând alegerea din linkul „Setări cookie-uri" din subsolul site-ului. Cookie-urile analitice se activează doar după acordul tău explicit.</p>
<p>Vezi și <a href="/confidentialitate">Politica de confidențialitate</a>.</p>'],
```

- [ ] **Step 2: Run the seeder locally**

Run: `C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/seed_settings.php`
Expected: completes without errors. (`INSERT IGNORE` — existing rows untouched, new `politica-cookies` row inserted.)

- [ ] **Step 3: Verify the page renders**

Run: `curl -s -o /dev/null -w "%{http_code}" http://motociclete.test/politica-cookies`
Expected: `200`. Then open `http://motociclete.test/politica-cookies` in a browser → the cookie table renders.

- [ ] **Step 4: Commit**

```bash
git add database/seed_settings.php
git commit -m "feat(gdpr): seed /politica-cookies page (cookie inventory)"
```

---

## Task 5: Consent checkbox on lead forms + server validation

**Files:**
- Create: `templates/partials/_consent-field.twig`
- Modify: `templates/partials/lead-modals.twig:46` (add the field before the error/submit)
- Modify: `src/Controllers/ContactController.php:49` (require `consent` after the honeypot check)

**Interfaces:**
- Produces: a reusable `partials/_consent-field.twig` include (renders `<input name="consent">`), reused by Task 6. Relies on the global Twig `base`.

- [ ] **Step 1: Create the shared consent field partial**

Create `templates/partials/_consent-field.twig`:

```twig
{# Reusable GDPR consent checkbox for public forms. Required; also validated server-side. #}
<label class="consent-field">
    <input type="checkbox" name="consent" value="1" required>
    <span>Sunt de acord cu prelucrarea datelor mele conform <a href="{{ base }}/confidentialitate" target="_blank" rel="noopener">Politicii de confidențialitate</a>.</span>
</label>
```

- [ ] **Step 2: Add a style for the consent field**

Append to `assets/css/app-v2.css`:

```css
.consent-field{display:flex;gap:.5rem;align-items:flex-start;font-size:.82rem;line-height:1.45;margin:.25rem 0 .75rem}
.consent-field input{margin-top:.15rem}
```

- [ ] **Step 3: Include the field in the lead modals**

In `templates/partials/lead-modals.twig`, immediately **before** line 46 (`<p class="lead-form__err" data-lead-err hidden></p>`), add:

```twig
                {% include 'partials/_consent-field.twig' %}
```

- [ ] **Step 4: Require consent server-side in ContactController**

In `src/Controllers/ContactController.php`, immediately after the honeypot block (after line 49, the `}` closing the `website` check), add:

```php

        // GDPR: explicit consent is required to process the lead.
        if (trim((string) ($data['consent'] ?? '')) === '') {
            return $this->json($response->withStatus(422), ['ok' => false, 'error' => 'Bifează acordul privind prelucrarea datelor personale.']);
        }
```

- [ ] **Step 5: Verify server rejects missing consent and accepts with it**

Run (missing consent → 422):
```bash
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://motociclete.test/api/lead/oferta \
  -d "name=Test&email=t@example.com&phone=0700000000&brand=yamaha"
```
Expected: `422`.

Run (with consent → 200, `{"ok":true}`):
```bash
curl -s -X POST http://motociclete.test/api/lead/oferta \
  -d "name=Test&email=t@example.com&phone=0700000000&brand=yamaha&consent=1"
```
Expected: body `{"ok":true}`.

- [ ] **Step 6: Bump app-v2.css version and verify the checkbox renders**

Bump `app-v2.css` version in `templates/layout.twig` from `?v=4` to `?v=5`. Open a product page, click "Cere ofertă" → the consent checkbox appears above the submit button and the browser blocks submit until checked.

- [ ] **Step 7: Commit**

```bash
git add templates/partials/_consent-field.twig templates/partials/lead-modals.twig src/Controllers/ContactController.php assets/css/app-v2.css templates/layout.twig
git commit -m "feat(gdpr): consent checkbox + server validation on lead forms"
```

---

## Task 6: Consent checkbox on service booking + server validation

**Files:**
- Modify: `templates/service.twig:69` (add the consent field before the error line)
- Modify: `src/Controllers/ServiceController.php:56` (require `consent` after the honeypot check)

**Interfaces:**
- Consumes: `partials/_consent-field.twig` from Task 5.

- [ ] **Step 1: Include the consent field in the booking form**

In `templates/service.twig`, immediately **before** line 69 (`<p class="booking-form__err" data-form-err hidden></p>`), add:

```twig
                        {% include 'partials/_consent-field.twig' %}
```

- [ ] **Step 2: Require consent server-side in ServiceController**

In `src/Controllers/ServiceController.php`, immediately after the honeypot block (after line 56, the `}` closing the `website` check), add:

```php

        // GDPR: explicit consent is required to process the booking.
        if (trim((string) ($d['consent'] ?? '')) === '') {
            return $this->json($response->withStatus(422), ['ok' => false, 'error' => 'Bifează acordul privind prelucrarea datelor personale.']);
        }
```

- [ ] **Step 3: Verify server rejects missing consent and accepts with it**

Run (missing consent → 422):
```bash
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://motociclete.test/service/programare \
  -d "name=Test&phone=0700000000"
```
Expected: `422`.

Run (with consent → 200, `{"ok":true}`):
```bash
curl -s -X POST http://motociclete.test/service/programare \
  -d "name=Test&phone=0700000000&consent=1"
```
Expected: body `{"ok":true}`.

- [ ] **Step 4: Verify the checkbox renders**

Open `http://motociclete.test/service`, scroll to "Programare service" → the consent checkbox appears above the submit button.

- [ ] **Step 5: Commit**

```bash
git add templates/service.twig src/Controllers/ServiceController.php
git commit -m "feat(gdpr): consent checkbox + server validation on service booking"
```

---

## Task 7: Privacy note on My Garage login

**Files:**
- Modify: `templates/client/login.twig:25` (add a privacy note after the existing note)

- [ ] **Step 1: Add the privacy link note**

In `templates/client/login.twig`, immediately after line 25 (the `auth__note` paragraph), add:

```twig
        <p class="auth__note auth__note--privacy">Datele tale sunt prelucrate conform <a href="{{ base }}/confidentialitate">Politicii de confidențialitate</a>.</p>
```

- [ ] **Step 2: Verify**

Open `http://motociclete.test/garage` (login page) → the privacy note with the link appears below the contact note.

- [ ] **Step 3: Commit**

```bash
git add templates/client/login.twig
git commit -m "feat(gdpr): privacy policy note on My Garage login"
```

---

## Task 8: GDPR audit document

**Files:**
- Create: `GDPR-AUDIT.md` (repo root)

- [ ] **Step 1: Write the audit document**

Create `GDPR-AUDIT.md` with the following content:

```markdown
# Audit GDPR — motociclete.com.ro

**Operator:** Dual Tours SRL (Dual Motors), Șos. Pipera 48, București.
**Data auditului:** 2026-06-27.
**Domeniu:** portalul public motociclete.com.ro (Slim/Twig/PHP), exclusiv BikerShop (PrestaShop separat).

## 1. Inventar de date personale

| Date | Locație (tabelă) | Sursă | Bază legală | Retention recomandat |
|------|------------------|-------|-------------|----------------------|
| Email, telefon, nume clienți | `clienti` | import + My Garage | Executarea contractului (art. 6(1)(b)) | Durata relației + 3 ani garanție |
| Lead-uri ofertă/drive test (nume, email, telefon, mesaj, **IP**) | `site_messages` | formulare publice | Consimțământ (art. 6(1)(a)) | 12 luni de la ultimul contact |
| Programări service (nume, email, telefon, date moto, **IP**) | `service_bookings` | formular public | Consimțământ / pași precontractuali | 12 luni |
| Cereri service | `service_requests` | My Garage | Executarea contractului | Durata relației |
| Jurnal email-uri trimise | `email_log` | sistem | Interes legitim (audit livrare) | 6–12 luni |
| Coduri OTP | `client_otp` | login | Executarea contractului / securitate | Minute (expiră); curățare periodică |

> Notă: `clienti_2021` pe BikerShop este legacy și nu este atins la runtime.

## 2. Inventar de cookie-uri

| Cookie | Categorie | Scop | Durată |
|--------|-----------|------|--------|
| `dm_garage` | Necesar | Sesiune autentificare My Garage | Sesiune |
| `dm_consent` | Necesar | Preferința de consimțământ cookie | 12 luni |
| `__cf_bm` | Necesar | Securitate/anti-bot (Cloudflare) | ~30 min |
| `_ga`, `_ga_*` | Analitic | Google Analytics 4 | până la 13 luni |

GA4 rulează în **Consent Mode v2**, implicit `denied`; cookie-urile `_ga` se setează doar după consimțământ explicit.

## 3. Lacune și status remediere

| Constatare | Status |
|-----------|--------|
| GA4 se încărca fără consimțământ | **Remediat** — Consent Mode v2 + banner opt-in |
| Lipsea bannerul de cookie consent | **Remediat** — banner custom + retragere din footer |
| Lipsea politica de cookie-uri | **Remediat** — pagina `/politica-cookies` |
| Formularele nu cereau consimțământ explicit | **Remediat** — checkbox obligatoriu + validare server (lead + service) |
| Login My Garage fără link la confidențialitate | **Remediat** — notă + link |
| Drepturile GDPR în pagina de confidențialitate | **Parțial** — drepturile sunt prezente pe `/confidentialitate` (prod); de completat cu detalii de prelucrare/retention |
| IP-uri stocate fără politică de ștergere | **Recomandat** — vezi §4 (retention cron) |

## 4. Acțiuni pentru companie (non-tehnice / decizii de business)

1. **Conținut juridic** — finalizează textul paginilor `/confidentialitate` și `/termeni-si-conditii` (validare juridică umană).
2. **Responsabil prelucrare date** — desemnează persoana de contact pentru solicitări GDPR și, dacă e cazul, înregistrarea la ANSPDCP.
3. **Politică de retention + ștergere automată** — implementează un cron care șterge/anonimizează `site_messages`, `service_bookings`, `email_log` conform termenelor din §1 (neimplementat în această rundă).
4. **Anonimizare IP** — evaluează stocarea trunchiată/anonimizată a IP-urilor existente (parte din runda de retention).
5. **Acorduri de prelucrare (DPA)** cu procesatorii: Google (Analytics), Cloudflare, furnizorul de găzduire cPanel + SMTP, BikerShop / PartsEurope.

## 5. Verificare consimțământ (regresie)

- Prima vizită: niciun cookie `_ga` înainte de accept (DevTools → Application → Cookies).
- Accept → apare `_ga` + `dm_consent=v1:analytics=1`. Refuz → fără `_ga`, `dm_consent=v1:analytics=0`.
- „Setări cookie-uri" din footer redeschide preferințele.
- Formularele de lead/service resping submit-ul fără bifa de consimțământ (HTTP 422).
```

- [ ] **Step 2: Verify the file is web-blocked and committed**

Run: `curl -s -o /dev/null -w "%{http_code}\n" http://motociclete.test/GDPR-AUDIT.md`
Expected: `403` (`.htaccess` blocks `*.md`).

- [ ] **Step 3: Commit**

```bash
git add GDPR-AUDIT.md
git commit -m "docs(gdpr): data-flow audit + remediation status"
```

---

## Self-Review notes

- **Spec coverage:** Part A → Tasks 1–3; Part B → Task 4; Part C → Tasks 5–7; Part D → Task 8. All covered.
- **Cookie format consistency:** `v1:analytics=1|0` is written by `consent.js` (Task 2) and read by both the layout snippet (Task 1, regex `analytics=1`) and `consent.js` (Task 2, `analytics=1`). Consistent.
- **`window.openCookiePrefs`** is defined in Task 2 and consumed in Task 3. Consistent.
- **`consent` field name** is emitted by `_consent-field.twig` (Task 5) and validated in both controllers (Tasks 5, 6) as `consent`. Consistent.
- **CSS version bumps:** `app-v2.css` goes `?v=3`→`v=4` (Task 2) →`v=5` (Task 5). Sequential, no conflict.
```
