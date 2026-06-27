# GDPR Retention Cron + IP Anonymization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a daily retention job that anonymizes IPs after 30 days, anonymizes the rest of the PII on leads/bookings after 12 months (keeping the row for aggregate stats), and deletes old `email_log` / `client_otp` rows — leaving `clienti` / `service_requests` untouched.

**Architecture:** A single CLI script `database/retention.php` (PHP 8.1, mirrors `update_currency.php` bootstrap + the `--apply` dry-run pattern of `prune_media.php`). A new nullable `anonymized_at` column on `site_messages` + `service_bookings` makes the PII pass idempotent and auditable, added non-destructively via `ensure_column` in `migrate_admin.php` plus the schema files.

**Tech Stack:** PHP 8.1 (pdo_mysql), MySQL/MariaDB, plain PDO. No build step, no framework runtime (standalone CLI).

## Global Constraints

- **Run CLI/DB scripts with the Laragon PHP 8.1 binary** `C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe` (PATH `php` is 8.2, no pdo_mysql). (CLAUDE.md)
- **No automated test harness.** Verification = backdated-row probe scripts run with the Laragon binary (write `tmp_*.php`, run, then delete — `php -r` inline lacks the Composer autoloader).
- **Dry-run is the default; `--apply` executes.** (spec)
- **Retention thresholds (constants):** `IP_DAYS=30`, `PII_DAYS=365`, `EMAILLOG_DAYS=365`, `OTP_DAYS=7`. (spec)
- **`NOT NULL` columns are emptied with `''`; nullable columns with `NULL`.** (spec)
- **Never touch `clienti` or `service_requests`** (legal basis: contract). (spec)
- **PII pass is idempotent** via the `anonymized_at IS NULL` predicate. (spec)
- **`anonymized_at` added non-destructively** (ensure_column via information_schema + added to schema files for fresh installs). (spec)
- **Romanian** for output/log messages.
- **Commit directly on `main`** (no branches/PRs). (memory: workflow-commit-pe-main)

---

## Task 1: Add `anonymized_at` column (schema + migration)

**Files:**
- Modify: `database/schema_messages.sql` (the `site_messages` CREATE)
- Modify: `database/schema_pages.sql` (the `service_bookings` CREATE)
- Modify: `database/migrate_admin.php` (add two `ensure_column` calls)

**Interfaces:**
- Produces: column `anonymized_at DATETIME NULL` on both `site_messages` and `service_bookings`. Task 2 reads/writes it.

- [ ] **Step 1: Add the column to `site_messages` CREATE (fresh installs)**

In `database/schema_messages.sql`, in the `site_messages` table, add the column right after the `created_at` line. Change:

```sql
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
```
to:
```sql
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `anonymized_at`  DATETIME NULL,
    PRIMARY KEY (`id`),
```

- [ ] **Step 2: Add the column to `service_bookings` CREATE (fresh installs)**

In `database/schema_pages.sql`, in the `service_bookings` table, change:

```sql
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
```
to:
```sql
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `anonymized_at`  DATETIME NULL,
    PRIMARY KEY (`id`),
```

- [ ] **Step 3: Add non-destructive column adds to the migration**

In `database/migrate_admin.php`, after the existing `ensure_column` for `products`/`variants_json` (currently the last ensure_column line), add:

```php
ensure_column($pdo, 'site_messages', 'anonymized_at', 'ALTER TABLE `site_messages` ADD COLUMN `anonymized_at` DATETIME NULL');
ensure_column($pdo, 'service_bookings', 'anonymized_at', 'ALTER TABLE `service_bookings` ADD COLUMN `anonymized_at` DATETIME NULL');
```

- [ ] **Step 4: Run the migration**

Run: `C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/migrate_admin.php`
Expected: ends with `migrate_admin: done.` and no errors.

- [ ] **Step 5: Verify both columns exist**

Write `tmp_check_cols.php` at the repo root:

```php
<?php
require __DIR__ . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
$s = require __DIR__ . '/config/settings.php';
$pdo = (new App\Database($s['db']))->local();
foreach (['site_messages', 'service_bookings'] as $t) {
    $c = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' AND COLUMN_NAME = 'anonymized_at'"
    )->fetchColumn();
    echo "{$t}.anonymized_at: " . ($c ? 'OK' : 'MISSING') . "\n";
}
```

Run: `C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe tmp_check_cols.php`
Expected: both lines say `OK`. Then delete the probe: `rm tmp_check_cols.php`.

- [ ] **Step 6: Commit**

```bash
git add database/schema_messages.sql database/schema_pages.sql database/migrate_admin.php
git commit -m "feat(gdpr): add anonymized_at column for retention (non-destructive)"
```

---

## Task 2: The retention script (`database/retention.php`)

**Files:**
- Create: `database/retention.php`

**Interfaces:**
- Consumes: `anonymized_at` column from Task 1; `App\Database` (`->local()` returns the RW PDO); `config/settings.php` (`$settings['db']`); `Dotenv`.
- Produces: a CLI script with exit code 0 on success, 1 on any per-op error; dry-run default, `--apply` to write.

- [ ] **Step 1: Create the script**

Create `database/retention.php`:

```php
<?php

declare(strict_types=1);

/**
 * Retention GDPR: anonimizează IP-uri (30 zile) + restul PII (365 zile) pe tabelele
 * tranzitorii și șterge logurile vechi. Lead-urile/programările NU se șterg —
 * se golește PII-ul (se păstrează tip/brand/dată pentru statistici).
 *
 * Dry-run implicit (doar raportează counts). --apply execută modificările.
 *
 * Rulează cu PHP 8.1 (pdo_mysql). Pe server, cron zilnic (alt-php81):
 *   30 3 * * * /opt/alt/php81/usr/bin/php /home/dualmotors/public_html/motociclete.com.ro/database/retention.php --apply >> /home/dualmotors/retention.log 2>&1
 * Local:
 *   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/retention.php [--apply]
 *
 * NU atinge `clienti` / `service_requests` (bază legală: contract).
 */

use App\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
$settings = require $root . '/config/settings.php';

const IP_DAYS       = 30;   // anonimizare IP (Stage A)
const PII_DAYS      = 365;  // anonimizare restul PII (Stage B)
const EMAILLOG_DAYS = 365;  // ștergere email_log
const OTP_DAYS      = 7;    // ștergere coduri OTP

$apply = in_array('--apply', $argv, true);
$pdo   = (new Database($settings['db']))->local();

echo $apply
    ? "RETENTION (--apply): execut modificările\n"
    : "RETENTION (dry-run): doar raportez — folosește --apply pentru a executa\n";

$errors = [];

/**
 * O operație de retention: numără rândurile țintă cu $countSql; dacă --apply și
 * există rânduri, execută $writeSql (aceleași predicate). Raportează și prinde erorile.
 */
$op = static function (string $label, string $countSql, string $writeSql) use ($pdo, $apply, &$errors): void {
    try {
        $n = (int) $pdo->query($countSql)->fetchColumn();
        if ($apply && $n > 0) {
            $pdo->exec($writeSql);
        }
        $verb = $apply ? 'modificate' : 'ar fi modificate';
        echo "  {$label}: {$n} {$verb}\n";
    } catch (Throwable $e) {
        $errors[] = "{$label}: " . $e->getMessage();
        echo "  {$label}: EROARE — " . $e->getMessage() . "\n";
    }
};

// 1. site_messages — Stage A (IP, 30 zile)
$op(
    'site_messages IP (' . IP_DAYS . 'z)',
    'SELECT COUNT(*) FROM site_messages WHERE created_at < (NOW() - INTERVAL ' . IP_DAYS . ' DAY) AND ip IS NOT NULL',
    'UPDATE site_messages SET ip=NULL WHERE created_at < (NOW() - INTERVAL ' . IP_DAYS . ' DAY) AND ip IS NOT NULL'
);

// site_messages — Stage B (PII, 365 zile)
$op(
    'site_messages PII (' . PII_DAYS . 'z)',
    'SELECT COUNT(*) FROM site_messages WHERE created_at < (NOW() - INTERVAL ' . PII_DAYS . ' DAY) AND anonymized_at IS NULL',
    "UPDATE site_messages SET name='', email='', phone='', message=NULL, ip=NULL, anonymized_at=NOW() WHERE created_at < (NOW() - INTERVAL " . PII_DAYS . ' DAY) AND anonymized_at IS NULL'
);

// 2. service_bookings — Stage A (IP, 30 zile)
$op(
    'service_bookings IP (' . IP_DAYS . 'z)',
    'SELECT COUNT(*) FROM service_bookings WHERE created_at < (NOW() - INTERVAL ' . IP_DAYS . ' DAY) AND ip IS NOT NULL',
    'UPDATE service_bookings SET ip=NULL WHERE created_at < (NOW() - INTERVAL ' . IP_DAYS . ' DAY) AND ip IS NOT NULL'
);

// service_bookings — Stage B (PII, 365 zile)
$op(
    'service_bookings PII (' . PII_DAYS . 'z)',
    'SELECT COUNT(*) FROM service_bookings WHERE created_at < (NOW() - INTERVAL ' . PII_DAYS . ' DAY) AND anonymized_at IS NULL',
    "UPDATE service_bookings SET name='', email=NULL, phone=NULL, sasiu=NULL, lucrari=NULL, ip=NULL, anonymized_at=NOW() WHERE created_at < (NOW() - INTERVAL " . PII_DAYS . ' DAY) AND anonymized_at IS NULL'
);

// 3. email_log — ștergere (365 zile)
$op(
    'email_log delete (' . EMAILLOG_DAYS . 'z)',
    'SELECT COUNT(*) FROM email_log WHERE created_at < (NOW() - INTERVAL ' . EMAILLOG_DAYS . ' DAY)',
    'DELETE FROM email_log WHERE created_at < (NOW() - INTERVAL ' . EMAILLOG_DAYS . ' DAY)'
);

// 4. client_otp — ștergere (7 zile)
$op(
    'client_otp delete (' . OTP_DAYS . 'z)',
    'SELECT COUNT(*) FROM client_otp WHERE created_at < (NOW() - INTERVAL ' . OTP_DAYS . ' DAY)',
    'DELETE FROM client_otp WHERE created_at < (NOW() - INTERVAL ' . OTP_DAYS . ' DAY)'
);

if ($errors) {
    fwrite(STDERR, 'Erori: ' . implode('; ', $errors) . "\n");
    exit(1);
}
echo $apply ? "Retention aplicat.\n" : "Dry-run complet.\n";
exit(0);
```

- [ ] **Step 2: Lint the script**

Run: `C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe -l database/retention.php`
Expected: `No syntax errors detected in database/retention.php`.

- [ ] **Step 3: Seed backdated test rows**

Write `tmp_retention_seed.php` at the repo root (test rows are tagged with
`product_slug='__rettest__'` / `marca='__rettest__'` so cleanup still works
after PII is blanked):

```php
<?php
require __DIR__ . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
$s = require __DIR__ . '/config/settings.php';
$pdo = (new App\Database($s['db']))->local();

$pdo->exec("INSERT INTO site_messages (type,product_slug,name,email,phone,ip,created_at) VALUES
 ('oferta','__rettest__','Ret40','ret40@example.com','0700000040','9.9.9.40',  NOW() - INTERVAL 40 DAY),
 ('oferta','__rettest__','Ret400','ret400@example.com','0700000400','9.9.9.144', NOW() - INTERVAL 400 DAY),
 ('oferta','__rettest__','RetNew','retnew@example.com','0700000001','9.9.9.1',  NOW())");

$pdo->exec("INSERT INTO service_bookings (name,email,phone,marca,sasiu,lucrari,ip,created_at) VALUES
 ('Ret40','ret40@example.com','0700000040','__rettest__','VIN40','revizie','9.9.9.40',  NOW() - INTERVAL 40 DAY),
 ('Ret400','ret400@example.com','0700000400','__rettest__','VIN400','revizie','9.9.9.144', NOW() - INTERVAL 400 DAY),
 ('RetNew','retnew@example.com','0700000001','__rettest__','VINNEW','revizie','9.9.9.1', NOW())");

echo "seeded\n";
```

Run: `C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe tmp_retention_seed.php`
Expected: `seeded`.

- [ ] **Step 4: Run dry-run and confirm counts**

Run: `C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/retention.php`
Expected output includes (counts are ≥ these — real old data may add to them):
- `site_messages IP (30z): N ar fi modificate` with N ≥ 1 (the 40-day + 400-day rows have IPs)
- `site_messages PII (365z): N ar fi modificate` with N ≥ 1 (the 400-day row)
- `service_bookings IP (30z): N ar fi modificate` with N ≥ 1
- `service_bookings PII (365z): N ar fi modificate` with N ≥ 1
- ends with `Dry-run complet.`

Confirm NOTHING changed (dry-run): write `tmp_retention_verify.php` at the repo root:

```php
<?php
require __DIR__ . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
$s = require __DIR__ . '/config/settings.php';
$pdo = (new App\Database($s['db']))->local();
echo "-- site_messages --\n";
foreach ($pdo->query("SELECT DATEDIFF(NOW(),created_at) d, name, email, phone, ip, anonymized_at FROM site_messages WHERE product_slug='__rettest__' ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo sprintf("  %4dz name=%-8s email=%-20s phone=%-12s ip=%-9s anon=%s\n", $r['d'], var_export($r['name'],true), var_export($r['email'],true), var_export($r['phone'],true), var_export($r['ip'],true), var_export($r['anonymized_at'],true));
}
echo "-- service_bookings --\n";
foreach ($pdo->query("SELECT DATEDIFF(NOW(),created_at) d, name, email, phone, sasiu, lucrari, ip, anonymized_at FROM service_bookings WHERE marca='__rettest__' ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo sprintf("  %4dz name=%-8s email=%-20s phone=%-12s sasiu=%-8s lucrari=%-9s ip=%-9s anon=%s\n", $r['d'], var_export($r['name'],true), var_export($r['email'],true), var_export($r['phone'],true), var_export($r['sasiu'],true), var_export($r['lucrari'],true), var_export($r['ip'],true), var_export($r['anonymized_at'],true));
}
```

Run: `C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe tmp_retention_verify.php`
Expected: all three rows per table still have their original `name`/`email`/`phone`/`ip`, `anon=NULL` (dry-run wrote nothing).

- [ ] **Step 5: Apply and confirm behavior**

Run: `C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/retention.php --apply`
Expected: same counts, verb `modificate`, ends with `Retention aplicat.`

Run: `C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe tmp_retention_verify.php`
Expected:
- **site_messages** — 400-day row: `name=''`, `email=''`, `phone=''`, `ip=NULL`, `anon=` a timestamp; 40-day row: `ip=NULL` but `name`/`email`/`phone` intact, `anon=NULL`; new row: everything intact, `anon=NULL`.
- **service_bookings** — 400-day row: `name=''`, `email=NULL`, `phone=NULL`, `sasiu=NULL`, `lucrari=NULL`, `ip=NULL`, `anon=` a timestamp; 40-day row: `ip=NULL`, rest intact, `anon=NULL`; new row: intact.

- [ ] **Step 6: Confirm idempotency**

Run: `C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/retention.php --apply`
Expected: the PII counts (`site_messages PII`, `service_bookings PII`) for the test rows no longer include them (the 400-day rows are already `anonymized_at`-stamped). Re-running changes no test row (verify with the probe again if desired — the 400-day rows keep their existing `anon` timestamp).

- [ ] **Step 7: Clean up test rows and probes**

Write/run cleanup (or run inline via a tmp file):

```php
<?php
require __DIR__ . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
$s = require __DIR__ . '/config/settings.php';
$pdo = (new App\Database($s['db']))->local();
$pdo->exec("DELETE FROM site_messages WHERE product_slug='__rettest__'");
$pdo->exec("DELETE FROM service_bookings WHERE marca='__rettest__'");
echo "cleaned\n";
```

Save as `tmp_retention_clean.php`, run with the Laragon binary, expect `cleaned`, then delete all probes:
`rm tmp_retention_seed.php tmp_retention_verify.php tmp_retention_clean.php`

Confirm no `tmp_*.php` remain: `ls tmp_*.php` → expected "No such file" / empty.

- [ ] **Step 8: Commit**

```bash
git add database/retention.php
git commit -m "feat(gdpr): retention cron — IP/PII anonymization + log pruning"
```

---

## Task 3: Documentation

**Files:**
- Modify: `GDPR-AUDIT.md` (§3 status + §4 action list)
- Modify: `CLAUDE.md` (Deploy cron list)

**Interfaces:**
- Consumes: `database/retention.php` from Task 2.

- [ ] **Step 1: Update the audit status**

In `GDPR-AUDIT.md`, §3 table, change the row about IP retention from:

```markdown
| IP-uri stocate fără politică de ștergere | **Recomandat** — vezi §4 (retention cron) |
```
to:
```markdown
| IP-uri stocate fără politică de ștergere | **Remediat** — `database/retention.php` (cron zilnic): IP la 30 zile, restul PII la 12 luni, loguri șterse |
```

Then in §4, remove the now-done retention bullet (the item starting "**Politică de retention + ștergere automată**") and replace it with a one-line note:

```markdown
3. **Politică de retention** — implementată prin `database/retention.php` (cron zilnic 03:30). Praguri: IP 30 zile, PII 12 luni, `email_log`/`client_otp` șterse. Conturile My Garage inactive (`clienti`/`service_requests`) rămân de evaluat separat.
```

- [ ] **Step 2: Add the cron to CLAUDE.md**

In `CLAUDE.md`, in the Deploy section's cron bullet (the line listing "Cron-uri (alt-php81): accesorii Yamaha lunar ...; curs valutar zilnic 07:00 ..."), append the retention cron so the line reads:

```
- **Cron-uri (alt-php81):** accesorii Yamaha lunar (`import_yamaha_accessories.php --apply`); curs valutar zilnic 07:00 (`update_currency.php`); retention GDPR zilnic 03:30 (`retention.php --apply` — anonimizare IP 30z / PII 12 luni, ștergere `email_log`/`client_otp`); loguri în `/home/dualmotors/`.
```

(Match the exact surrounding wording in the file; insert the retention clause before "loguri în".)

- [ ] **Step 3: Commit**

```bash
git add GDPR-AUDIT.md CLAUDE.md
git commit -m "docs(gdpr): retention cron — audit status + CLAUDE cron list"
```

---

## Self-Review notes

- **Spec coverage:** retention.php (Task 2) covers all four operations + dry-run/--apply + graceful per-op error handling + exit codes; `anonymized_at` schema/migration (Task 1); docs incl. cron line (Task 3). All spec sections covered.
- **Column name consistency:** `anonymized_at` is identical in schema files, `ensure_column` calls (Task 1), and every Stage-B query (Task 2).
- **Threshold consistency:** constants `IP_DAYS=30`, `PII_DAYS=365`, `EMAILLOG_DAYS=365`, `OTP_DAYS=7` are defined once and concatenated into every SQL string — no divergent literals.
- **NULL vs '' rule:** `name`/`email`/`phone` on `site_messages` are `NOT NULL` → emptied with `''`; `service_bookings.name` `NOT NULL` → `''`; nullable columns (`message`, `email`/`phone`/`sasiu`/`lucrari` on bookings, `ip`) → `NULL`. Matches the schema verified in the spec.
- **Excluded tables:** no query references `clienti` or `service_requests`.
- **Idempotency:** Stage-B predicates include `anonymized_at IS NULL`; Stage-A predicates include `ip IS NOT NULL`. Re-runs are no-ops on already-processed rows.
