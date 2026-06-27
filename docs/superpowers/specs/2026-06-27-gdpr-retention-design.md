# Retention GDPR (cron) + anonimizare IP — Design

**Data:** 2026-06-27
**Proiect:** motociclete.com.ro (Dual Motors / Dual Tours SRL)
**Status:** aprobat, gata de planificare
**Context anterior:** runda GDPR + cookie consent (vezi
`2026-06-27-gdpr-cookie-consent-design.md`); `GDPR-AUDIT.md` §4 marca retention-ul
ca cea mai mare expunere rămasă.

## Context

Tabelele cu date personale stochează IP-uri și PII fără nicio politică de
ștergere. Această rundă adaugă un cron de retention care minimizează datele:
anonimizare IP devreme + anonimizare/ștergere PII după fereastra de retention.

### Tabele și coloane relevante (verificate în schema)

- `site_messages` — `name`/`email`/`phone` (toate `NOT NULL`), `message` (NULL),
  `licence` (NULL), `ip` (NULL), `created_at` DATETIME. Plus `type`, `brand`,
  `product_slug`, `product_name`. (`database/schema_messages.sql`)
- `service_bookings` — `name` (`NOT NULL`), `email`/`phone` (NULL), `marca`,
  `model`, `an_fabricatie`, `sasiu` (NULL), `kilometri`, `lucrari` (NULL, text liber),
  `status`, `ip` (NULL), `created_at` TIMESTAMP. (`database/schema_pages.sql`)
- `email_log` — `to_addr`, `subject`, `body` (poate conține PII), `context`,
  `created_at` DATETIME. (`database/schema_admin.sql`)
- `client_otp` — `code_hash`, `expires_at`, `used_at`, `ip`, `created_at`
  TIMESTAMP. (`database/schema_garage.sql`)

### Decizii (din brainstorming)

- Lead-uri/programări vechi: **păstrăm rândul, golim PII** (statistici agregate
  rămân: tip/brand/dată).
- IP: anonimizat **devreme (30 zile)**, separat de restul PII (12 luni).
- Domeniu: **doar tabelele tranzitorii** (`site_messages`, `service_bookings`,
  `email_log`, `client_otp`). `clienti` + `service_requests` rămân neatinse
  (bază legală: contract).

## Componenta principală — `database/retention.php`

Un singur script CLI, pe modelul `database/update_currency.php` (autoload +
`Dotenv::safeLoad()` + `config/settings.php` + `App\Database`) și pattern-ul
`--apply` din `prune_media.php`:

- **Dry-run implicit:** raportează ce ar schimba (counts per tabel/stage), fără
  scriere.
- **`--apply`:** execută efectiv.
- Praguri ca **constante** la începutul fișierului, comentate (fără UI de configurare — YAGNI).
- Rulează cu PHP 8.1 Laragon local / alt-php81 pe server.

### Praguri (constante)

```php
const IP_DAYS        = 30;   // anonimizare IP (Stage A)
const PII_DAYS       = 365;  // anonimizare restul PII (Stage B)
const EMAILLOG_DAYS  = 365;  // ștergere email_log
const OTP_DAYS       = 7;    // ștergere coduri OTP
```

## Operațiuni

### 1. `site_messages` — two-tier, păstrează rândul

- **Stage A (IP, 30 zile):**
  `UPDATE site_messages SET ip=NULL WHERE created_at < (NOW() - INTERVAL 30 DAY) AND ip IS NOT NULL`
- **Stage B (PII, 365 zile):**
  `UPDATE site_messages SET name='', email='', phone='', message=NULL, ip=NULL, anonymized_at=NOW() WHERE created_at < (NOW() - INTERVAL 365 DAY) AND anonymized_at IS NULL`
  - Golite: `name`, `email`, `phone` → `''` (coloane `NOT NULL`); `message`, `ip` → `NULL`.
  - Păstrate: `type`, `brand`, `product_slug`, `product_name`, `licence`, `created_at`.

### 2. `service_bookings` — two-tier, păstrează rândul

- **Stage A (IP, 30 zile):** `UPDATE ... SET ip=NULL WHERE created_at < (NOW() - INTERVAL 30 DAY) AND ip IS NOT NULL`
- **Stage B (PII, 365 zile):**
  `UPDATE service_bookings SET name='', email=NULL, phone=NULL, sasiu=NULL, lucrari=NULL, ip=NULL, anonymized_at=NOW() WHERE created_at < (NOW() - INTERVAL 365 DAY) AND anonymized_at IS NULL`
  - Golite: `name` → `''` (`NOT NULL`); `email`, `phone`, `sasiu` (serie șasiu = identificator), `lucrari` (text liber), `ip` → `NULL`.
  - Păstrate: `marca`, `model`, `an_fabricatie`, `kilometri`, `status`, `created_at`.

### 3. `email_log` — ștergere

`DELETE FROM email_log WHERE created_at < (NOW() - INTERVAL 365 DAY)` (log intern,
corpul poate conține PII; fără valoare statistică).

### 4. `client_otp` — ștergere

`DELETE FROM client_otp WHERE created_at < (NOW() - INTERVAL 7 DAY)` (coduri
single-use, expiră în minute).

## Schema — coloană nouă `anonymized_at`

`anonymized_at DATETIME NULL` pe `site_messages` + `service_bookings`, ca Stage B
să fie **idempotent** (nu re-procesează rânduri deja anonimizate) și auditabil.

- Adăugată nedistructiv prin `ensure_column` în `database/migrate_admin.php`
  (pattern existent prin `information_schema` — MySQL 8 n-are `ADD COLUMN IF NOT EXISTS`).
- Adăugată și în `schema_messages.sql` / `schema_pages.sql` pentru instalări noi.

## Degradare grațioasă + raportare

- Fiecare operație în `try/catch`; o eroare pe un tabel nu oprește restul.
- Raportează counts (rânduri afectate / care ar fi afectate) per tabel și stage.
- `exit(1)` + mesaj pe STDERR dacă vreo interogare pică; altfel `exit(0)`.
- Output spre stdout (cron → fișier log, ca `update_currency.php`).
- În dry-run, counts se obțin cu `SELECT COUNT(*)` pe aceleași predicate `WHERE`.

## Cron (server, alt-php81)

```
30 3 * * * /opt/alt/php81/usr/bin/php /home/dualmotors/public_html/motociclete.com.ro/database/retention.php --apply >> /home/dualmotors/retention.log 2>&1
```

## Testare (fără unit harness)

Probă temporară (script `tmp_*.php` cu binarul Laragon, șters după, sau SQL prin
clientul mysql):
1. Inserează rânduri de test cu `created_at` backdatat: ~40 zile și ~400 zile, în
   `site_messages` și `service_bookings` (+ rânduri recente de control).
2. Rulează dry-run → verifică counts raportate (Stage A prinde rândul de 40 zile;
   Stage B prinde rândul de 400 zile; rândurile recente neatinse).
3. Rulează `--apply` → confirmă: IP golit pe rândul de 40 zile; PII golit +
   `anonymized_at` setat pe rândul de 400 zile; rândul recent intact.
4. Re-rulează `--apply` → 0 rânduri modificate la Stage B (idempotent).
5. Curăță rândurile de test.

## Documentație

- `GDPR-AUDIT.md` §3: „IP-uri stocate fără politică de ștergere" → **Remediat**;
  §4: elimină acțiunea de retention din lista de recomandări.
- `CLAUDE.md`: adaugă `retention.php` la lista de cron-uri (secțiunea Deploy).
- Memorie de proiect `gdpr-cookie-consent.md`: marchează retention-ul ca livrat.

## Out of scope

- Curățarea conturilor My Garage inactive (`clienti`/`service_requests`) — reguli
  de business (relanție comercială, garanții) → altă rundă.
- UI de configurare a pragurilor în admin (constante în cod sunt suficiente).
- Anonimizarea retroactivă la deploy se face natural la prima rulare a cron-ului.

## Criterii de succes

- `retention.php` fără `--apply` nu modifică nimic, doar raportează counts.
- Cu `--apply`: IP-urile > 30 zile devin NULL; PII-ul > 365 zile e golit +
  `anonymized_at` setat; `email_log`/`client_otp` vechi șterse; rândurile recente
  neatinse; `clienti`/`service_requests` neatinse.
- Re-rularea e idempotentă (Stage B nu re-procesează).
- Coloana `anonymized_at` există după `migrate_admin.php` (nedistructiv).
