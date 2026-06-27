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
| IP-uri stocate fără politică de ștergere | **Remediat** — `database/retention.php` (cron zilnic): IP la 30 zile, restul PII la 12 luni, loguri șterse |

## 4. Acțiuni pentru companie (non-tehnice / decizii de business)

1. **Conținut juridic** — finalizează textul paginilor `/confidentialitate` și `/termeni-si-conditii` (validare juridică umană).
2. **Responsabil prelucrare date** — desemnează persoana de contact pentru solicitări GDPR și, dacă e cazul, înregistrarea la ANSPDCP.
3. **Politică de retention** — implementată prin `database/retention.php` (cron zilnic 03:30). Praguri: IP 30 zile, PII 12 luni, `email_log`/`client_otp` șterse. Conturile My Garage inactive (`clienti`/`service_requests`) rămân de evaluat separat.
4. **Anonimizare IP** — evaluează stocarea trunchiată/anonimizată a IP-urilor existente (parte din runda de retention).
5. **Acorduri de prelucrare (DPA)** cu procesatorii: Google (Analytics), Cloudflare, furnizorul de găzduire cPanel + SMTP, BikerShop / PartsEurope.

## 5. Verificare consimțământ (regresie)

- Prima vizită: niciun cookie `_ga` înainte de accept (DevTools → Application → Cookies).
- Accept → apare `_ga` + `dm_consent=v1:analytics=1`. Refuz → fără `_ga`, `dm_consent=v1:analytics=0`.
- „Setări cookie-uri" din footer redeschide preferințele.
- Formularele de lead/service resping submit-ul fără bifa de consimțământ (HTTP 422).
