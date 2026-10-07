# Newsletter propriu — Etapa 1: abonați — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Portalul ține propria listă de abonați la newsletter (două liste), alimentată din BikerShop, My Garage și un formular public cu confirmare, cu dezabonare funcțională și pagină de admin.

**Architecture:** Cod nou în `src/Newsletter/` (`Address`, `Repository`, `Sync`, `BrevoImport`), un controller public și unul de admin, două scripturi CLI. Tabelele `nl_*` stau în baza portalului; BikerShop se citește doar prin `BikerShop\Client`. Etapa nu trimite campanii: singurul email este cel de confirmare a abonării, prin `Support\Mailer` existent.

**Tech Stack:** PHP 8.1, Slim 4, Twig 3, PDO (MySQL 8 local / MariaDB pe server), PHPMailer (deja instalat), CSS/JS vanilla.

**Spec:** `docs/superpowers/specs/2026-10-07-newsletter-campanii-design.md` (secțiunile „Date", „Abonați", „GDPR"). Etapele 2–4 au planuri separate.

**În afara acestei etape (trec în planul etapei 2, care atinge aceleași șabloane publice):** bifa opțională de newsletter de pe formularele de ofertă/contact/service și comutatoarele din My Garage.

## Global Constraints

- PHP-ul de rulat local este `C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe` (în comenzi: `$PHP`). `php` din PATH nu are `pdo_mysql`.
- Toate interogările sunt prepared statements. Cu `PDO::ATTR_EMULATE_PREPARES = false` un placeholder numit **nu se poate repeta** în același SQL.
- PDO întoarce coloanele numerice ca string: convertește cu `(int)` înainte de comparații stricte.
- Codul portalului **nu scrie** în baza BikerShop. Orice citire din BikerShop stă în `src/BikerShop/Client.php`.
- Listele sunt exact `oferte` („BikerShop oferte") și `stiri` („Dual Motors știri").
- Surse valide: `bs_account`, `bs_footer`, `portal`, `garage`, `manual`, `brevo`.
- Comenzile guest (`is_guest = 1`) nu se importă.
- Adrese blocate: prefixul local `guest-emag-`; domeniile `bikershop.ro`, `emag.ro`, `tfbnw.net` (și subdomeniile lor).
- Un abonament `unsubscribed` și un abonat `bounced`/`complained` nu sunt reactivați de sincronizare.
- Repo-ul GitHub este public: niciun secret, nicio adresă reală de client în cod, teste sau commit-uri. Adresele de test folosesc domeniul `nl-test.invalid`.
- Fără `tmp_*.php` în commit-uri; adaugă fișierele explicit (`git add <cale>`), nu `git add -A`.
- Commit direct pe `main`, **fără push** în timpul implementării (push-ul declanșează deploy pe live).
- După editarea `assets/css/app.css` crește `?v=N` din `templates/layout.twig`.
- Texte publice în română, cu diacritice.

## Review Focus

1. **Aceeași adresă din mai multe surse sau scrisă diferit** (`Ion@Gmail.com `, cont + footer + garage): trebuie să rezulte un singur abonat și cel mult un abonament pe listă. Test în Task 3.
2. **BikerShop indisponibil sau răspuns parțial la sincronizare**: nu trebuie să dezaboneze pe nimeni. Test în Task 3 (sursă `null` și garda de 50%).
3. **Un om dezabonat reapare în datele BikerShop la sincronizarea următoare**: rămâne dezabonat. Test în Task 3.
4. **Filtrele de email deschid automat linkul de dezabonare (GET)**: starea nu se schimbă la GET. Test în Task 5.
5. **Cineva trimite repetat formularul cu adresa altcuiva**: victima primește cel mult un email de confirmare la 15 minute, iar un IP creează cel mult 5 abonați noi pe oră. Test în Task 5.

---

## Structura fișierelor

| Fișier | Rol |
|---|---|
| `database/schema_newsletter.sql` (nou) | Tabelele `nl_subscribers`, `nl_subscriptions` |
| `database/migrate_admin.php` (modificat) | Rulează schema nouă |
| `src/Newsletter/Address.php` (nou) | Normalizare + adrese blocate; fără dependențe |
| `src/Newsletter/Repository.php` (nou) | Singurul loc care atinge tabelele `nl_*` |
| `src/Newsletter/Sync.php` (nou) | Regulile de sincronizare din surse |
| `src/Newsletter/BrevoImport.php` (nou) | Import de excluderi din CSV Brevo |
| `src/BikerShop/Client.php` (modificat) | `newsletterAccounts()`, `newsletterFooter()` |
| `src/Client/Repository.php` (modificat) | `ownersWithEmail()` |
| `src/Bootstrap.php` (modificat) | Serviciul `newsletter` în container |
| `database/newsletter_sync.php` (nou) | CLI sincronizare |
| `database/newsletter_import_brevo.php` (nou) | CLI import Brevo |
| `src/Controllers/NewsletterController.php` (nou) | Abonare, confirmare, preferințe/dezabonare |
| `templates/newsletter/{status,prefs}.twig` (noi) | Pagini publice |
| `templates/partials/newsletter-signup.twig` (nou) | Formularul din footer |
| `src/Support/EmailTemplate.php` (modificat) | Linkurile din emailurile text devin butoane |
| `src/Admin/SubscriberController.php` (nou) | Pagina „Abonați" |
| `templates/admin/newsletter/subscribers.twig` (nou) | Șablonul paginii |
| `src/Routes.php` (modificat) | Rute publice + admin |
| `database/retention.php` (modificat) | Retenție pentru `nl_subscribers` |
| `tests/_nl.php` + `tests/Newsletter*Test.php` (noi) | Teste plain-PHP |

Testele rulează cu runner-ul plain-PHP al proiectului (fără PHPUnit), după modelul `tests/FitmentMatcherTest.php`. Testele cu bază de date rulează într-o tranzacție pe baza locală `motociclete` și fac rollback la final, deci nu lasă urme.

---

### Task 1: Schema și clasa `Address`

**Files:**
- Create: `database/schema_newsletter.sql`
- Create: `src/Newsletter/Address.php`
- Create: `tests/NewsletterAddressTest.php`
- Modify: `database/migrate_admin.php` (după linia `run_sql_file($pdo, __DIR__ . '/schema_cfmoto_feed.sql');`)

**Interfaces:**
- Consumes: nimic.
- Produces:
  - `App\Newsletter\Address::normalize(?string $raw): ?string` — adresa în litere mici, fără spații, sau `null` dacă e invalidă ori mai lungă de 190 de caractere.
  - `App\Newsletter\Address::isBlocked(string $email): bool` — primește o adresă deja normalizată.
  - `App\Newsletter\Address::clean(?string $raw): ?string` — `normalize` + `null` dacă e blocată.
  - Tabelele `nl_subscribers` și `nl_subscriptions` (coloanele de mai jos).

- [ ] **Step 1: Scrie testul**

`tests/NewsletterAddressTest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterAddressTest.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Newsletter\Address;

$failures = 0;
$count    = 0;

function check(string $label, bool $ok): void
{
    global $failures, $count;
    $count++;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . "\n";
}

// --- normalize() -------------------------------------------------------------
check('normalize: litere mici + fără spații', Address::normalize("  Ion.Pop@Gmail.COM \n") === 'ion.pop@gmail.com');
check('normalize: null rămâne null', Address::normalize(null) === null);
check('normalize: șir gol', Address::normalize('   ') === null);
check('normalize: fără @', Address::normalize('ion.gmail.com') === null);
check('normalize: fără domeniu', Address::normalize('ion@') === null);
check('normalize: spațiu în interior', Address::normalize('ion pop@gmail.com') === null);
check('normalize: peste 190 de caractere', Address::normalize(str_repeat('a', 185) . '@x.ro') === null);

// --- isBlocked() -------------------------------------------------------------
check('blocked: adresă fictivă eMAG', Address::isBlocked('guest-emag-533289276@bikershop.ro'));
check('blocked: orice adresă pe bikershop.ro', Address::isBlocked('comenzi@bikershop.ro'));
check('blocked: subdomeniu bikershop.ro', Address::isBlocked('x@mail.bikershop.ro'));
check('blocked: emag.ro', Address::isBlocked('cineva@emag.ro'));
check('blocked: domeniu de test tfbnw.net', Address::isBlocked('open_abc@tfbnw.net'));
check('blocked: prefix guest-emag pe alt domeniu', Address::isBlocked('guest-emag-1@altceva.ro'));
check('permis: gmail', !Address::isBlocked('ion@gmail.com'));
check('permis: echipa pe motociclete.com.ro', !Address::isBlocked('info@motociclete.com.ro'));
check('permis: domeniu care doar se termină la fel', !Address::isBlocked('ion@notbikershop.ro'));

// --- clean() -----------------------------------------------------------------
check('clean: adresă bună', Address::clean(' Ion@Yahoo.com') === 'ion@yahoo.com');
check('clean: adresă blocată → null', Address::clean('Guest-Emag-1@BikerShop.ro') === null);
check('clean: adresă invalidă → null', Address::clean('nu-e-email') === null);

echo "\n{$count} verificări, {$failures} eșecuri\n";
exit($failures ? 1 : 0);
```

- [ ] **Step 2: Rulează testul și verifică eșecul**

```bash
PHP="C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe"
"$PHP" /c/laragon/www/motociclete/tests/NewsletterAddressTest.php
```

Expected: eroare fatală `Class "App\Newsletter\Address" not found`.

- [ ] **Step 3: Scrie `Address`**

`src/Newsletter/Address.php`:

```php
<?php

declare(strict_types=1);

namespace App\Newsletter;

/**
 * Adrese de email pentru newsletter: normalizare + lista de adrese la care nu
 * trimitem niciodată (fictive, de marketplace, de test). Fără dependențe.
 */
final class Address
{
    private const MAX_LEN = 190;

    /** Domenii (și subdomeniile lor) la care nu trimitem. */
    private const BLOCKED_DOMAINS = ['bikershop.ro', 'emag.ro', 'tfbnw.net'];

    /** Prefixe ale părții locale generate automat (comenzi din marketplace). */
    private const BLOCKED_PREFIXES = ['guest-emag-'];

    /** Adresa în litere mici, fără spații la capete; null dacă nu e validă. */
    public static function normalize(?string $raw): ?string
    {
        $email = strtolower(trim((string) $raw));
        if ($email === '' || strlen($email) > self::MAX_LEN) {
            return null;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** @param string $email adresă deja normalizată */
    public static function isBlocked(string $email): bool
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return true;
        }
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        foreach (self::BLOCKED_PREFIXES as $prefix) {
            if (str_starts_with($local, $prefix)) {
                return true;
            }
        }
        foreach (self::BLOCKED_DOMAINS as $blocked) {
            if ($domain === $blocked || str_ends_with($domain, '.' . $blocked)) {
                return true;
            }
        }
        return false;
    }

    /** normalize() + null pentru adresele blocate. */
    public static function clean(?string $raw): ?string
    {
        $email = self::normalize($raw);
        return ($email === null || self::isBlocked($email)) ? null : $email;
    }
}
```

- [ ] **Step 4: Rulează testul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterAddressTest.php
```

Expected: `19 verificări, 0 eșecuri`.

- [ ] **Step 5: Scrie schema**

`database/schema_newsletter.sql`:

```sql
-- Newsletter propriu: abonati + apartenenta la liste. Idempotent (CREATE IF NOT EXISTS).
-- Rulat de database/migrate_admin.php.

CREATE TABLE IF NOT EXISTS `nl_subscribers` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`           VARCHAR(190) NOT NULL,
    `name`            VARCHAR(160) NULL,
    `token`           CHAR(32) NOT NULL,
    `status`          ENUM('pending','active','bounced','complained') NOT NULL DEFAULT 'active',
    `soft_bounces`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `signup_ip`       VARCHAR(45) NULL,
    `confirm_sent_at` DATETIME NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `confirmed_at`    DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_nl_email` (`email`),
    UNIQUE KEY `uniq_nl_token` (`token`),
    KEY `idx_nl_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nl_subscriptions` (
    `subscriber_id`   INT UNSIGNED NOT NULL,
    `list_key`        ENUM('oferte','stiri') NOT NULL,
    `status`          ENUM('active','unsubscribed') NOT NULL DEFAULT 'active',
    `source`          ENUM('bs_account','bs_footer','portal','garage','manual','brevo') NOT NULL,
    `subscribed_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `unsubscribed_at` DATETIME NULL,
    PRIMARY KEY (`subscriber_id`, `list_key`),
    KEY `idx_nl_list_status` (`list_key`, `status`),
    KEY `idx_nl_source` (`source`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Comentariile nu conțin `;` (scriptul de migrare împarte fișierul pe `;`).

- [ ] **Step 6: Leagă schema în migrare**

În `database/migrate_admin.php`, imediat după `run_sql_file($pdo, __DIR__ . '/schema_cfmoto_feed.sql');` adaugă:

```php
run_sql_file($pdo, __DIR__ . '/schema_newsletter.sql');
```

- [ ] **Step 7: Rulează migrarea și verifică tabelele**

```bash
cd /c/laragon/www/motociclete && "$PHP" database/migrate_admin.php
"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe" -uroot motociclete -e "SHOW TABLES LIKE 'nl\_%'; SHOW COLUMNS FROM nl_subscriptions;" | tr -d '\r'
```

Expected: `migrate_admin: done.`, apoi tabelele `nl_subscribers` și `nl_subscriptions`, cu coloana `list_key`. Rulează migrarea a doua oară: trebuie să treacă fără erori.

- [ ] **Step 8: Commit**

```bash
cd /c/laragon/www/motociclete
git add database/schema_newsletter.sql database/migrate_admin.php src/Newsletter/Address.php tests/NewsletterAddressTest.php
git commit -m "feat(newsletter): schema abonati + normalizarea si blocarea adreselor"
```

---

### Task 2: `Newsletter\Repository`

**Files:**
- Create: `src/Newsletter/Repository.php`
- Create: `tests/_nl.php`
- Create: `tests/NewsletterRepositoryTest.php`
- Modify: `src/Bootstrap.php` (în array-ul `$container`, după linia `'client'    => new Client\Repository($db),`)

**Interfaces:**
- Consumes: tabelele din Task 1; `App\Database::local(): PDO`.
- Produces (toate pe `App\Newsletter\Repository`, construit cu `new Repository(App\Database $db)`; în container: `$container['newsletter']`):
  - `const LISTS = ['oferte' => 'BikerShop oferte', 'stiri' => 'Dual Motors știri']`
  - `find(int $id): ?array`, `findByEmail(string $email): ?array`, `findByToken(string $token): ?array` — rândul din `nl_subscribers`.
  - `ensureSubscriber(string $email, ?string $name, string $status, ?string $ip = null): array` — întoarce rândul existent **neschimbat** sau creează unul nou.
  - `activate(int $id): void` — `status = active`, `confirmed_at` completat, `soft_bounces = 0`.
  - `setStatus(int $id, string $status): void`
  - `subscriptions(int $id): array` — indexat pe `list_key`.
  - `addSubscription(int $id, string $list, string $source): bool` — inserează doar dacă nu există rând; `true` dacă a inserat.
  - `setSubscription(int $id, string $list, string $source): void` — acțiune explicită: creează sau reactivează.
  - `unsubscribe(int $id, string $list): bool` — doar rânduri existente și active.
  - `suppress(int $id, string $list, string $source): void` — creează sau trece rândul pe `unsubscribed`.
  - `unsubscribeSource(int $id, string $source): int`
  - `activeEmailsBySource(string $source): array` — listă de adrese.
  - `counts(): array` — `['subscribers' => [status => n], 'lists' => [list => ['active' => n, 'unsubscribed' => n, 'by_source' => [source => n]]]]`.
  - `search(string $q, int $limit = 50): array` — abonați cu cheia suplimentară `subs`.
  - `recentSignupsFromIp(string $ip, int $minutes): int`
  - `confirmRecentlySent(int $id, int $minutes): bool`, `markConfirmSent(int $id): void`
  - `tests/_nl.php`: `check()`, `nl_db()`, `nl_isolate()`, `nl_done()` pentru testele următoare.

- [ ] **Step 1: Scrie helperul de test**

`tests/_nl.php`:

```php
<?php

declare(strict_types=1);

/**
 * Helper comun pentru testele de newsletter (plain PHP, fără PHPUnit).
 * Testele cu DB rulează într-o tranzacție pe baza locală și fac rollback la final.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$GLOBALS['nl_settings'] = require dirname(__DIR__) . '/config/settings.php';
$GLOBALS['nl_failures'] = 0;
$GLOBALS['nl_count']    = 0;

function check(string $label, bool $ok): void
{
    $GLOBALS['nl_count']++;
    if (!$ok) {
        $GLOBALS['nl_failures']++;
    }
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . "\n";
}

function nl_db(): App\Database
{
    static $db = null;
    return $db ??= new App\Database($GLOBALS['nl_settings']['db']);
}

/**
 * Pornește o tranzacție, golește tabelele nl_* în interiorul ei și programează
 * rollback la ieșire: testul vede tabele goale, iar datele locale rămân neatinse.
 */
function nl_isolate(): PDO
{
    $pdo = nl_db()->local();
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM nl_subscriptions');
    $pdo->exec('DELETE FROM nl_subscribers');
    register_shutdown_function(static function () use ($pdo): void {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    });
    return $pdo;
}

function nl_done(): void
{
    echo "\n{$GLOBALS['nl_count']} verificări, {$GLOBALS['nl_failures']} eșecuri\n";
    exit($GLOBALS['nl_failures'] ? 1 : 0);
}
```

- [ ] **Step 2: Scrie testul**

`tests/NewsletterRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterRepositoryTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Repository;

$pdo  = nl_isolate();
$repo = new Repository(nl_db());

// --- abonați -----------------------------------------------------------------
$a = $repo->ensureSubscriber('ana@nl-test.invalid', 'Ana', 'active');
check('ensureSubscriber: creează rândul', (int) $a['id'] > 0 && $a['email'] === 'ana@nl-test.invalid');
check('ensureSubscriber: token de 32 de caractere hex', (bool) preg_match('/^[a-f0-9]{32}$/', (string) $a['token']));
check('ensureSubscriber: activ → confirmed_at completat', $a['confirmed_at'] !== null);

$again = $repo->ensureSubscriber('ana@nl-test.invalid', 'Alt nume', 'pending');
check('ensureSubscriber: rândul existent rămâne neschimbat',
    (int) $again['id'] === (int) $a['id'] && $again['status'] === 'active' && $again['name'] === 'Ana');

$p = $repo->ensureSubscriber('paul@nl-test.invalid', null, 'pending', '10.0.0.1');
check('ensureSubscriber: pending fără confirmed_at', $p['status'] === 'pending' && $p['confirmed_at'] === null);
check('findByToken: găsește după token', (int) ($repo->findByToken((string) $p['token'])['id'] ?? 0) === (int) $p['id']);
check('findByToken: token necunoscut → null', $repo->findByToken(str_repeat('0', 32)) === null);
check('find: după id', ($repo->find((int) $a['id'])['email'] ?? '') === 'ana@nl-test.invalid');

$repo->activate((int) $p['id']);
$p2 = $repo->findByEmail('paul@nl-test.invalid');
check('activate: pending → active cu confirmed_at', $p2['status'] === 'active' && $p2['confirmed_at'] !== null);

$repo->setStatus((int) $p['id'], 'bounced');
check('setStatus: bounced', $repo->findByEmail('paul@nl-test.invalid')['status'] === 'bounced');

// --- abonamente --------------------------------------------------------------
$id = (int) $a['id'];
check('addSubscription: inserează', $repo->addSubscription($id, 'oferte', 'bs_account') === true);
check('addSubscription: a doua oară nu inserează', $repo->addSubscription($id, 'oferte', 'garage') === false);
check('subscriptions: sursa inițială rămâne', $repo->subscriptions($id)['oferte']['source'] === 'bs_account');

check('unsubscribe: rând activ → true', $repo->unsubscribe($id, 'oferte') === true);
check('unsubscribe: listă fără rând → false', $repo->unsubscribe($id, 'stiri') === false);
check('unsubscribe: nu creează rând', !isset($repo->subscriptions($id)['stiri']));
check('addSubscription: nu reactivează un dezabonat',
    $repo->addSubscription($id, 'oferte', 'bs_account') === false
    && $repo->subscriptions($id)['oferte']['status'] === 'unsubscribed');

$repo->setSubscription($id, 'oferte', 'portal');
$s = $repo->subscriptions($id)['oferte'];
check('setSubscription: reactivează și schimbă sursa',
    $s['status'] === 'active' && $s['source'] === 'portal' && $s['unsubscribed_at'] === null);

$repo->suppress($id, 'stiri', 'brevo');
check('suppress: creează rând dezabonat', $repo->subscriptions($id)['stiri']['status'] === 'unsubscribed');
$repo->suppress($id, 'oferte', 'brevo');
check('suppress: trece un rând activ pe dezabonat', $repo->subscriptions($id)['oferte']['status'] === 'unsubscribed');

// --- pe surse ----------------------------------------------------------------
$b = $repo->ensureSubscriber('bogdan@nl-test.invalid', null, 'active');
$repo->addSubscription((int) $b['id'], 'oferte', 'bs_account');
$repo->addSubscription((int) $b['id'], 'stiri', 'bs_account');
check('activeEmailsBySource: o adresă o singură dată',
    $repo->activeEmailsBySource('bs_account') === ['bogdan@nl-test.invalid']);
check('unsubscribeSource: dezabonează ambele liste', $repo->unsubscribeSource((int) $b['id'], 'bs_account') === 2);
check('activeEmailsBySource: gol după dezabonare', $repo->activeEmailsBySource('bs_account') === []);

// --- totaluri și căutare -----------------------------------------------------
$c = $repo->ensureSubscriber('carmen@nl-test.invalid', 'Carmen', 'active');
$repo->addSubscription((int) $c['id'], 'stiri', 'garage');
$counts = $repo->counts();
check('counts: abonați pe stare', $counts['subscribers']['active'] === 3 && $counts['subscribers']['bounced'] === 1);
check('counts: stiri activ = 1, din garage', $counts['lists']['stiri']['active'] === 1 && $counts['lists']['stiri']['by_source']['garage'] === 1);
check('counts: oferte activ = 0', $counts['lists']['oferte']['active'] === 0);

$found = $repo->search('carmen');
check('search: găsește după fragment', count($found) === 1 && $found[0]['subs']['stiri']['source'] === 'garage');
check('search: % nu e wildcard', $repo->search('%') === []);

// --- limite de abonare -------------------------------------------------------
check('recentSignupsFromIp: numără pe IP', $repo->recentSignupsFromIp('10.0.0.1', 60) === 1);
check('recentSignupsFromIp: alt IP = 0', $repo->recentSignupsFromIp('10.0.0.2', 60) === 0);
check('confirmRecentlySent: fals înainte de trimitere', $repo->confirmRecentlySent($id, 15) === false);
$repo->markConfirmSent($id);
check('confirmRecentlySent: adevărat după trimitere', $repo->confirmRecentlySent($id, 15) === true);

nl_done();
```

- [ ] **Step 3: Rulează testul și verifică eșecul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterRepositoryTest.php
```

Expected: eroare fatală `Class "App\Newsletter\Repository" not found`.

- [ ] **Step 4: Scrie `Repository`**

`src/Newsletter/Repository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\Database;
use PDO;

/**
 * Singurul loc care citește/scrie tabelele de newsletter (`nl_subscribers`,
 * `nl_subscriptions`). Starea globală (pending/active/bounced/complained) stă pe
 * abonat; dezabonarea stă pe abonament și privește o singură listă.
 *
 * Erorile de DB NU sunt înghițite aici: apelanții (controllere, CLI) le prind.
 */
final class Repository
{
    /** Cheie listă → nume afișat. */
    public const LISTS = ['oferte' => 'BikerShop oferte', 'stiri' => 'Dual Motors știri'];

    public function __construct(private Database $db) {}

    private function pdo(): PDO
    {
        return $this->db->local();
    }

    // -- Abonați --------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $s = $this->pdo()->prepare('SELECT * FROM nl_subscribers WHERE id = :id');
        $s->execute([':id' => $id]);
        return $s->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $s = $this->pdo()->prepare('SELECT * FROM nl_subscribers WHERE email = :e');
        $s->execute([':e' => $email]);
        return $s->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findByToken(string $token): ?array
    {
        $s = $this->pdo()->prepare('SELECT * FROM nl_subscribers WHERE token = :t');
        $s->execute([':t' => $token]);
        return $s->fetch() ?: null;
    }

    /**
     * Rândul existent (NESCHIMBAT) sau unul nou cu starea dată.
     * @return array<string,mixed>
     */
    public function ensureSubscriber(string $email, ?string $name, string $status, ?string $ip = null): array
    {
        $row = $this->findByEmail($email);
        if ($row !== null) {
            return $row;
        }
        $this->pdo()->prepare(
            'INSERT INTO nl_subscribers (email, name, token, status, signup_ip) VALUES (:e, :n, :t, :s, :ip)'
        )->execute([
            ':e' => $email, ':n' => $name, ':t' => bin2hex(random_bytes(16)), ':s' => $status, ':ip' => $ip,
        ]);
        $id = (int) $this->pdo()->lastInsertId();
        if ($status === 'active') {
            $this->pdo()->prepare('UPDATE nl_subscribers SET confirmed_at = NOW() WHERE id = :id')->execute([':id' => $id]);
        }
        return (array) $this->find($id);
    }

    public function activate(int $id): void
    {
        $this->pdo()->prepare(
            "UPDATE nl_subscribers
             SET status = 'active', soft_bounces = 0, confirmed_at = COALESCE(confirmed_at, NOW())
             WHERE id = :id"
        )->execute([':id' => $id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->pdo()->prepare('UPDATE nl_subscribers SET status = :s WHERE id = :id')
            ->execute([':s' => $status, ':id' => $id]);
    }

    // -- Abonamente -----------------------------------------------------------

    /** @return array<string,array<string,mixed>> indexat pe list_key */
    public function subscriptions(int $id): array
    {
        $s = $this->pdo()->prepare(
            'SELECT list_key, status, source, subscribed_at, unsubscribed_at
             FROM nl_subscriptions WHERE subscriber_id = :id'
        );
        $s->execute([':id' => $id]);
        $out = [];
        foreach ($s->fetchAll() as $row) {
            $out[(string) $row['list_key']] = $row;
        }
        return $out;
    }

    /** Inserează doar dacă nu există rând (nu reactivează un dezabonat). */
    public function addSubscription(int $id, string $list, string $source): bool
    {
        $s = $this->pdo()->prepare(
            'INSERT IGNORE INTO nl_subscriptions (subscriber_id, list_key, source) VALUES (:id, :l, :s)'
        );
        $s->execute([':id' => $id, ':l' => $list, ':s' => $source]);
        return $s->rowCount() === 1;
    }

    /** Acțiune explicită a omului sau a adminului: creează sau reactivează. */
    public function setSubscription(int $id, string $list, string $source): void
    {
        $this->pdo()->prepare(
            "INSERT INTO nl_subscriptions (subscriber_id, list_key, status, source)
             VALUES (:id, :l, 'active', :s)
             ON DUPLICATE KEY UPDATE status = 'active', source = VALUES(source),
                                     subscribed_at = NOW(), unsubscribed_at = NULL"
        )->execute([':id' => $id, ':l' => $list, ':s' => $source]);
    }

    /** Dezabonează un abonament existent și activ. */
    public function unsubscribe(int $id, string $list): bool
    {
        $s = $this->pdo()->prepare(
            "UPDATE nl_subscriptions SET status = 'unsubscribed', unsubscribed_at = NOW()
             WHERE subscriber_id = :id AND list_key = :l AND status = 'active'"
        );
        $s->execute([':id' => $id, ':l' => $list]);
        return $s->rowCount() > 0;
    }

    /** Excludere: creează rândul ca dezabonat sau îl trece pe dezabonat. */
    public function suppress(int $id, string $list, string $source): void
    {
        $this->pdo()->prepare(
            "INSERT INTO nl_subscriptions (subscriber_id, list_key, status, source, unsubscribed_at)
             VALUES (:id, :l, 'unsubscribed', :s, NOW())
             ON DUPLICATE KEY UPDATE status = 'unsubscribed',
                                     unsubscribed_at = COALESCE(unsubscribed_at, NOW())"
        )->execute([':id' => $id, ':l' => $list, ':s' => $source]);
    }

    /** Dezabonează toate abonamentele active ale unui abonat venite dintr-o sursă. */
    public function unsubscribeSource(int $id, string $source): int
    {
        $s = $this->pdo()->prepare(
            "UPDATE nl_subscriptions SET status = 'unsubscribed', unsubscribed_at = NOW()
             WHERE subscriber_id = :id AND source = :s AND status = 'active'"
        );
        $s->execute([':id' => $id, ':s' => $source]);
        return $s->rowCount();
    }

    /** @return array<int,string> adresele cu cel puțin un abonament activ din sursa dată */
    public function activeEmailsBySource(string $source): array
    {
        $s = $this->pdo()->prepare(
            "SELECT DISTINCT u.email
             FROM nl_subscriptions s JOIN nl_subscribers u ON u.id = s.subscriber_id
             WHERE s.source = :s AND s.status = 'active'
             ORDER BY u.email"
        );
        $s->execute([':s' => $source]);
        return array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN));
    }

    // -- Admin ----------------------------------------------------------------

    /**
     * @return array{subscribers:array<string,int>,lists:array<string,array{active:int,unsubscribed:int,by_source:array<string,int>}>}
     */
    public function counts(): array
    {
        $out = [
            'subscribers' => ['pending' => 0, 'active' => 0, 'bounced' => 0, 'complained' => 0],
            'lists'       => [],
        ];
        foreach (array_keys(self::LISTS) as $list) {
            $out['lists'][$list] = ['active' => 0, 'unsubscribed' => 0, 'by_source' => []];
        }
        foreach ($this->pdo()->query('SELECT status, COUNT(*) n FROM nl_subscribers GROUP BY status') as $r) {
            $out['subscribers'][(string) $r['status']] = (int) $r['n'];
        }
        // Doar abonații activi contează ca destinatari.
        $rows = $this->pdo()->query(
            "SELECT s.list_key, s.status, s.source, COUNT(*) n
             FROM nl_subscriptions s JOIN nl_subscribers u ON u.id = s.subscriber_id
             WHERE u.status = 'active'
             GROUP BY s.list_key, s.status, s.source"
        );
        foreach ($rows as $r) {
            $list = (string) $r['list_key'];
            $n    = (int) $r['n'];
            $out['lists'][$list][(string) $r['status']] += $n;
            if ($r['status'] === 'active') {
                $src = (string) $r['source'];
                $out['lists'][$list]['by_source'][$src] = ($out['lists'][$list]['by_source'][$src] ?? 0) + $n;
            }
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> abonați + cheia `subs` (abonamentele lor) */
    public function search(string $q, int $limit = 50): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $s = $this->pdo()->prepare(
            "SELECT * FROM nl_subscribers WHERE email LIKE :q ORDER BY email LIMIT {$limit}"
        );
        $s->execute([':q' => '%' . addcslashes($q, '%_\\') . '%']);
        $rows = $s->fetchAll();
        foreach ($rows as &$row) {
            $row['subs'] = $this->subscriptions((int) $row['id']);
        }
        unset($row);
        return $rows;
    }

    // -- Limite la abonarea publică ------------------------------------------

    public function recentSignupsFromIp(string $ip, int $minutes): int
    {
        $minutes = max(1, $minutes);
        $s = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM nl_subscribers
             WHERE signup_ip = :ip AND created_at > (NOW() - INTERVAL {$minutes} MINUTE)"
        );
        $s->execute([':ip' => $ip]);
        return (int) $s->fetchColumn();
    }

    public function confirmRecentlySent(int $id, int $minutes): bool
    {
        $minutes = max(1, $minutes);
        $s = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM nl_subscribers
             WHERE id = :id AND confirm_sent_at > (NOW() - INTERVAL {$minutes} MINUTE)"
        );
        $s->execute([':id' => $id]);
        return (int) $s->fetchColumn() > 0;
    }

    public function markConfirmSent(int $id): void
    {
        $this->pdo()->prepare('UPDATE nl_subscribers SET confirm_sent_at = NOW() WHERE id = :id')
            ->execute([':id' => $id]);
    }
}
```

- [ ] **Step 5: Rulează testul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterRepositoryTest.php
```

Expected: `32 verificări, 0 eșecuri`. Apoi verifică faptul că testul nu a lăsat urme:

```bash
"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe" -uroot motociclete -N -e "SELECT COUNT(*) FROM nl_subscribers WHERE email LIKE '%nl-test.invalid'" | tr -d '\r'
```

Expected: `0`.

- [ ] **Step 6: Înregistrează serviciul în container**

În `src/Bootstrap.php`, în array-ul `$container`, după linia `'client'    => new Client\Repository($db),` adaugă:

```php
            'newsletter' => new Newsletter\Repository($db),
```

- [ ] **Step 7: Verifică faptul că situl pornește**

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://motociclete.test/health
```

Expected: `200`.

- [ ] **Step 8: Commit**

```bash
cd /c/laragon/www/motociclete
git add src/Newsletter/Repository.php src/Bootstrap.php tests/_nl.php tests/NewsletterRepositoryTest.php
git commit -m "feat(newsletter): repository pentru abonati si abonamente"
```

---

### Task 3: Sincronizarea din BikerShop și My Garage

**Files:**
- Create: `src/Newsletter/Sync.php`
- Create: `database/newsletter_sync.php`
- Create: `tests/NewsletterSyncTest.php`
- Modify: `src/BikerShop/Client.php` (două metode publice noi, puse înaintea metodei private `shapeProduct`)
- Modify: `src/Client/Repository.php` (o metodă publică nouă, pusă înaintea metodei private `shapeBike`)

**Interfaces:**
- Consumes: `Repository` din Task 2 (`findByEmail`, `ensureSubscriber`, `activate`, `subscriptions`, `addSubscription`, `activeEmailsBySource`, `unsubscribeSource`); `Address::normalize`, `Address::isBlocked`.
- Produces:
  - `App\BikerShop\Client::newsletterAccounts(): ?array` și `newsletterFooter(): ?array` — liste de `['email' => string, 'name' => string]`; `null` când BikerShop nu răspunde.
  - `App\Client\Repository::ownersWithEmail(): array` — același format, din `clienti`.
  - `App\Newsletter\Sync::gather(BikerShop\Client $bs, Client\Repository $garage): array` — `['bs_account' => ?array, 'bs_footer' => ?array, 'garage' => array]`.
  - `App\Newsletter\Sync::run(array $sources, bool $apply): array` — raport: `['aborted' => ?string, 'new_subscribers' => int, 'activated' => int, 'invalid' => int, 'blocked' => int, 'unsubscribed' => int, 'added' => ['bs_account' => int, 'bs_footer' => int, 'garage' => int]]`.

- [ ] **Step 1: Scrie testul**

`tests/NewsletterSyncTest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterSyncTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Repository;
use App\Newsletter\Sync;

$pdo  = nl_isolate();
$repo = new Repository(nl_db());
$sync = new Sync($repo);

$row = static fn (string $email, string $name = ''): array => ['email' => $email, 'name' => $name];
$sources = static fn (?array $acc, ?array $foot, array $garage): array =>
    ['bs_account' => $acc, 'bs_footer' => $foot, 'garage' => $garage];

// --- dry-run nu scrie nimic --------------------------------------------------
$r = $sync->run($sources([$row('ana@nl-test.invalid', 'Ana')], [], []), false);
check('dry-run: raportează 1 abonat nou', $r['new_subscribers'] === 1 && $r['added']['bs_account'] === 2);
check('dry-run: nu scrie în DB', $repo->findByEmail('ana@nl-test.invalid') === null);

// --- prima rulare ------------------------------------------------------------
$r = $sync->run($sources(
    [$row(' Ana@NL-Test.invalid ', 'Ana'), $row('guest-emag-1@bikershop.ro'), $row('nu-e-email')],
    [$row('ana@nl-test.invalid'), $row('florin@nl-test.invalid')],
    [$row('ana@nl-test.invalid', 'Ana P'), $row('gabi@nl-test.invalid', 'Gabi')]
), true);
check('run: fără abandon', $r['aborted'] === null);
check('run: 3 abonați noi (ana o singură dată)', $r['new_subscribers'] === 3);
check('run: adresa fictivă numărată ca blocată', $r['blocked'] === 1);
check('run: adresa invalidă numărată', $r['invalid'] === 1);
check('run: adresa blocată nu ajunge în DB', $repo->findByEmail('guest-emag-1@bikershop.ro') === null);

$ana = $repo->findByEmail('ana@nl-test.invalid');
$subs = $repo->subscriptions((int) $ana['id']);
check('cont BikerShop: ambele liste, sursa bs_account',
    ($subs['oferte']['source'] ?? '') === 'bs_account' && ($subs['stiri']['source'] ?? '') === 'bs_account');
check('nume luat din prima sursă', $ana['name'] === 'Ana');

$gabi = $repo->findByEmail('gabi@nl-test.invalid');
$gsubs = $repo->subscriptions((int) $gabi['id']);
check('garage: doar lista stiri', isset($gsubs['stiri']) && !isset($gsubs['oferte']) && $gsubs['stiri']['source'] === 'garage');

$florin = $repo->findByEmail('florin@nl-test.invalid');
check('footer: ambele liste, sursa bs_footer',
    ($repo->subscriptions((int) $florin['id'])['oferte']['source'] ?? '') === 'bs_footer');

// --- a doua rulare identică nu schimbă nimic ---------------------------------
$r = $sync->run($sources(
    [$row('ana@nl-test.invalid')], [$row('florin@nl-test.invalid')], [$row('gabi@nl-test.invalid')]
), true);
check('re-rulare: nimic nou', $r['new_subscribers'] === 0 && array_sum($r['added']) === 0 && $r['unsubscribed'] === 0);

// --- un dezabonat rămâne dezabonat -------------------------------------------
$repo->unsubscribe((int) $florin['id'], 'oferte');
$sync->run($sources([$row('ana@nl-test.invalid')], [$row('florin@nl-test.invalid')], []), true);
check('dezabonat: sincronizarea nu îl reactivează',
    $repo->subscriptions((int) $florin['id'])['oferte']['status'] === 'unsubscribed');

// --- bounced nu primește abonamente noi --------------------------------------
$bad = $repo->ensureSubscriber('respins@nl-test.invalid', null, 'active');
$repo->setStatus((int) $bad['id'], 'bounced');
$sync->run($sources([$row('ana@nl-test.invalid'), $row('respins@nl-test.invalid')], [$row('florin@nl-test.invalid')], []), true);
check('bounced: rămâne bounced, fără abonamente',
    $repo->findByEmail('respins@nl-test.invalid')['status'] === 'bounced'
    && $repo->subscriptions((int) $bad['id']) === []);

// --- pending devine activ când apare într-o sursă de încredere ---------------
$pend = $repo->ensureSubscriber('nou@nl-test.invalid', null, 'pending');
$r = $sync->run($sources([$row('ana@nl-test.invalid'), $row('nou@nl-test.invalid')], [$row('florin@nl-test.invalid')], []), true);
check('pending: activat de sincronizare',
    $r['activated'] === 1 && $repo->findByEmail('nou@nl-test.invalid')['status'] === 'active');

// --- contul nu mai are bifa în BikerShop -------------------------------------
$r = $sync->run($sources([$row('nou@nl-test.invalid')], [$row('florin@nl-test.invalid')], []), true);
check('bifă scoasă: raportat 1 dezabonat', $r['unsubscribed'] === 1);
check('bifă scoasă: abonamentele bs_account devin dezabonate',
    $repo->subscriptions((int) $ana['id'])['oferte']['status'] === 'unsubscribed'
    && $repo->subscriptions((int) $ana['id'])['stiri']['status'] === 'unsubscribed');
check('bifă scoasă: alte surse rămân neatinse',
    $repo->subscriptions((int) $gabi['id'])['stiri']['status'] === 'active');

// --- BikerShop indisponibil --------------------------------------------------
$before = $repo->activeEmailsBySource('bs_account');
$r = $sync->run($sources(null, [], []), true);
check('sursă null: abandon cu motiv', is_string($r['aborted']) && $r['aborted'] !== '');
check('sursă null: nimeni dezabonat', $repo->activeEmailsBySource('bs_account') === $before);

// --- garda împotriva unui răspuns parțial ------------------------------------
$many = [];
for ($i = 1; $i <= 30; $i++) {
    $many[] = $row("client{$i}@nl-test.invalid");
}
$sync->run($sources($many, [], []), true);
$active = count($repo->activeEmailsBySource('bs_account'));
$r = $sync->run($sources(array_slice($many, 0, 5), [], []), true);
check('gardă: abandon când sursa scade sub jumătate', is_string($r['aborted']));
check('gardă: nimeni dezabonat', count($repo->activeEmailsBySource('bs_account')) === $active);

nl_done();
```

- [ ] **Step 2: Rulează testul și verifică eșecul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterSyncTest.php
```

Expected: eroare fatală `Class "App\Newsletter\Sync" not found`.

- [ ] **Step 3: Scrie `Sync`**

`src/Newsletter/Sync.php`:

```php
<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\BikerShop\Client as BikerShop;
use App\Client\Repository as Garage;

/**
 * Aduce abonații din sursele de încredere (conturi + footer BikerShop, proprietari
 * My Garage) în tabelele nl_*. Reguli:
 *   - adaugă doar abonamente care lipsesc; un dezabonat nu se reactivează;
 *   - un abonat bounced/complained nu primește nimic;
 *   - un cont BikerShop care nu mai are bifa pierde abonamentele cu sursa bs_account;
 *   - dacă BikerShop nu răspunde sau răspunde parțial, nu se modifică nimic.
 */
final class Sync
{
    /** Sursă → listele în care intră. */
    public const LISTS_BY_SOURCE = [
        'bs_account' => ['oferte', 'stiri'],
        'bs_footer'  => ['oferte', 'stiri'],
        'garage'     => ['stiri'],
    ];

    /** Garda de răspuns parțial se aplică de la acest număr de conturi active în sus. */
    private const GUARD_MIN = 20;

    public function __construct(private Repository $repo) {}

    /**
     * Citește sursele. BikerShop întoarce null când e indisponibil.
     * @return array{bs_account:?array,bs_footer:?array,garage:array}
     */
    public static function gather(BikerShop $bs, Garage $garage): array
    {
        return [
            'bs_account' => $bs->newsletterAccounts(),
            'bs_footer'  => $bs->newsletterFooter(),
            'garage'     => $garage->ownersWithEmail(),
        ];
    }

    /**
     * @param array{bs_account:?array,bs_footer:?array,garage:array} $sources
     * @return array{aborted:?string,new_subscribers:int,activated:int,invalid:int,blocked:int,unsubscribed:int,added:array<string,int>}
     */
    public function run(array $sources, bool $apply): array
    {
        $report = [
            'aborted' => null, 'new_subscribers' => 0, 'activated' => 0,
            'invalid' => 0, 'blocked' => 0, 'unsubscribed' => 0,
            'added' => ['bs_account' => 0, 'bs_footer' => 0, 'garage' => 0],
        ];

        foreach (['bs_account', 'bs_footer'] as $key) {
            if (!is_array($sources[$key] ?? null)) {
                $report['aborted'] = "BikerShop indisponibil (sursa {$key}); nu s-a modificat nimic.";
                return $report;
            }
        }

        // 1. Curăță: email normalizat → nume, pe fiecare sursă.
        $clean = [];
        foreach (array_keys(self::LISTS_BY_SOURCE) as $source) {
            $clean[$source] = [];
            foreach ($sources[$source] ?? [] as $row) {
                $email = Address::normalize(isset($row['email']) ? (string) $row['email'] : null);
                if ($email === null) {
                    $report['invalid']++;
                    continue;
                }
                if (Address::isBlocked($email)) {
                    $report['blocked']++;
                    continue;
                }
                $name = trim((string) ($row['name'] ?? ''));
                $clean[$source][$email] ??= ($name !== '' ? $name : null);
            }
        }

        // 2. Gardă: un răspuns mult mai mic decât ce avem deja = date parțiale.
        $current = $this->repo->activeEmailsBySource('bs_account');
        if (count($current) >= self::GUARD_MIN && count($clean['bs_account']) < count($current) / 2) {
            $report['aborted'] = sprintf(
                'BikerShop a întors %d conturi față de %d active; pare un răspuns parțial. Nu s-a modificat nimic.',
                count($clean['bs_account']),
                count($current)
            );
            return $report;
        }

        // 3. Adaugă ce lipsește.
        $seenNew = [];
        foreach (self::LISTS_BY_SOURCE as $source => $lists) {
            foreach ($clean[$source] as $email => $name) {
                $sub = $this->repo->findByEmail($email);
                if ($sub === null) {
                    if (!isset($seenNew[$email])) {
                        $seenNew[$email] = [];
                        $report['new_subscribers']++;
                    }
                    if (!$apply) {
                        // Dry-run: numără listele pe care le-ar primi, o singură dată fiecare.
                        foreach ($lists as $list) {
                            if (!isset($seenNew[$email][$list])) {
                                $seenNew[$email][$list] = true;
                                $report['added'][$source]++;
                            }
                        }
                        continue;
                    }
                    $sub = $this->repo->ensureSubscriber($email, $name, 'active');
                } elseif ($sub['status'] === 'pending') {
                    $report['activated']++;
                    if ($apply) {
                        $this->repo->activate((int) $sub['id']);
                    }
                } elseif ($sub['status'] !== 'active') {
                    continue; // bounced / complained: exclus definitiv
                }

                $id = (int) $sub['id'];
                $existing = $apply ? [] : $this->repo->subscriptions($id);
                foreach ($lists as $list) {
                    if ($apply) {
                        if ($this->repo->addSubscription($id, $list, $source)) {
                            $report['added'][$source]++;
                        }
                    } elseif (!isset($existing[$list])) {
                        $report['added'][$source]++;
                    }
                }
            }
        }

        // 4. Conturile care nu mai au bifa în BikerShop.
        foreach ($current as $email) {
            if (array_key_exists($email, $clean['bs_account'])) {
                continue;
            }
            $report['unsubscribed']++;
            if ($apply) {
                $sub = $this->repo->findByEmail($email);
                if ($sub !== null) {
                    $this->repo->unsubscribeSource((int) $sub['id'], 'bs_account');
                }
            }
        }

        return $report;
    }
}
```

- [ ] **Step 4: Rulează testul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterSyncTest.php
```

Expected: `22 verificări, 0 eșecuri`.

- [ ] **Step 5: Adaugă citirile din BikerShop**

În `src/BikerShop/Client.php`, înaintea metodei `private function shapeProduct(array $r): array`, adaugă:

```php
    /**
     * Conturile înregistrate care au bifa de newsletter (fără comenzi guest).
     * Null = BikerShop indisponibil; apelantul NU trebuie să-l trateze ca listă goală.
     * @return array<int,array{email:string,name:string}>|null
     */
    public function newsletterAccounts(): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $p = $this->prefix;
        $shop = $this->shopId; // trusted config int, inlined
        try {
            return $this->pdo->query(
                "SELECT email, TRIM(CONCAT(firstname, ' ', lastname)) AS name
                 FROM {$p}customer
                 WHERE newsletter = 1 AND is_guest = 0 AND active = 1 AND deleted = 0 AND id_shop = {$shop}"
            )->fetchAll();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Abonații din formularul de newsletter din footerul magazinului.
     * @return array<int,array{email:string,name:string}>|null
     */
    public function newsletterFooter(): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $p = $this->prefix;
        $shop = $this->shopId;
        try {
            return $this->pdo->query(
                "SELECT email, '' AS name FROM {$p}emailsubscription WHERE active = 1 AND id_shop = {$shop}"
            )->fetchAll();
        } catch (Throwable) {
            return null;
        }
    }
```

- [ ] **Step 6: Adaugă citirea proprietarilor din My Garage**

În `src/Client/Repository.php`, înaintea metodei `private function shapeBike(array $r): array`, adaugă:

```php
    /**
     * Proprietarii cu email (o adresă o singură dată), pentru lista de newsletter.
     * @return array<int,array{email:string,name:string}>
     */
    public function ownersWithEmail(): array
    {
        return $this->all(
            "SELECT email_norm AS email, MAX(client) AS name
             FROM clienti
             WHERE email_norm IS NOT NULL AND email_norm <> ''
             GROUP BY email_norm"
        );
    }
```

- [ ] **Step 7: Scrie CLI-ul**

`database/newsletter_sync.php`:

```php
<?php

declare(strict_types=1);

/**
 * Sincronizează abonații de newsletter din BikerShop (conturi + footer) și My Garage.
 * Dry-run implicit (doar raportează). --apply execută.
 *
 * Local:
 *   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/newsletter_sync.php [--apply]
 * Server (cron noaptea):
 *   30 1 * * * /usr/local/bin/ea-php81 /home/dualmotors/public_html/motociclete.com.ro/database/newsletter_sync.php --apply >> /home/dualmotors/newsletter_sync.log 2>&1
 */

use App\BikerShop\Client as BikerShop;
use App\Client\Repository as Garage;
use App\Database;
use App\Newsletter\Repository;
use App\Newsletter\Sync;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
$settings = require $root . '/config/settings.php';

$apply = in_array('--apply', $argv, true);
$db    = new Database($settings['db']);

echo date('Y-m-d H:i:s') . ($apply ? " SYNC (--apply)\n" : " SYNC (dry-run): folosește --apply pentru a executa\n");

try {
    $sources = Sync::gather(new BikerShop($db, $settings['db']['bikershop']), new Garage($db));
    foreach ($sources as $name => $rows) {
        echo "  sursa {$name}: " . (is_array($rows) ? count($rows) . ' rânduri' : 'INDISPONIBILĂ') . "\n";
    }
    $r = (new Sync(new Repository($db)))->run($sources, $apply);
} catch (Throwable $e) {
    fwrite(STDERR, 'Eroare: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($r['aborted'] !== null) {
    fwrite(STDERR, 'ABANDONAT: ' . $r['aborted'] . "\n");
    exit(1);
}

$verb = $apply ? '' : ' (ar fi)';
echo "  abonați noi{$verb}: {$r['new_subscribers']}\n";
echo "  activați din pending{$verb}: {$r['activated']}\n";
foreach ($r['added'] as $source => $n) {
    echo "  abonamente adăugate din {$source}{$verb}: {$n}\n";
}
echo "  dezabonați (bifă scoasă în BikerShop){$verb}: {$r['unsubscribed']}\n";
echo "  sărite: {$r['blocked']} blocate, {$r['invalid']} invalide\n";
exit(0);
```

- [ ] **Step 8: Rulează dry-run pe date reale și compară cu interogarea directă**

```bash
cd /c/laragon/www/motociclete && "$PHP" database/newsletter_sync.php
```

Expected (cifrele exacte variază de la o zi la alta): `sursa bs_account` în jur de 3.230 de rânduri, `sursa bs_footer` în jur de 816, `sursa garage` în jur de 426; „abonați noi (ar fi)" mai mic sau egal cu suma lor; `sărite: … blocate` include adresa de pe `bikershop.ro` și cele de pe `tfbnw.net`. Dacă IP-ul de dezvoltare nu e autorizat pe BikerShop, scriptul iese cu `ABANDONAT: BikerShop indisponibil` și cod 1: acesta este comportamentul corect, nu un bug.

Verifică și că dry-run-ul nu a scris nimic:

```bash
"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe" -uroot motociclete -N -e "SELECT COUNT(*) FROM nl_subscribers" | tr -d '\r'
```

Expected: `0`.

- [ ] **Step 9: Aplică local și verifică regulile pe date reale**

```bash
cd /c/laragon/www/motociclete && "$PHP" database/newsletter_sync.php --apply
"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe" -uroot motociclete -e "
SELECT list_key, source, status, COUNT(*) n FROM nl_subscriptions GROUP BY 1,2,3;
SELECT COUNT(*) fictive FROM nl_subscribers WHERE email LIKE 'guest-emag-%' OR email LIKE '%@bikershop.ro' OR email LIKE '%@tfbnw.net';
SELECT COUNT(*) doar_garage_in_oferte FROM nl_subscriptions WHERE source='garage' AND list_key='oferte';" | tr -d '\r'
```

Expected: `fictive = 0`, `doar_garage_in_oferte = 0`, iar sursa `garage` apare doar la `stiri`. Rulează `--apply` a doua oară: „abonați noi: 0" și toate „abonamente adăugate: 0".

- [ ] **Step 10: Commit**

```bash
cd /c/laragon/www/motociclete
git add src/Newsletter/Sync.php src/BikerShop/Client.php src/Client/Repository.php database/newsletter_sync.php tests/NewsletterSyncTest.php
git commit -m "feat(newsletter): sincronizarea abonatilor din BikerShop si My Garage"
```

---

### Task 4: Importul excluderilor din Brevo

**Files:**
- Create: `src/Newsletter/BrevoImport.php`
- Create: `database/newsletter_import_brevo.php`
- Create: `tests/NewsletterBrevoImportTest.php`

**Interfaces:**
- Consumes: `Repository` (`findByEmail`, `ensureSubscriber`, `setStatus`, `suppress`), `Repository::LISTS`, `Address::normalize`.
- Produces:
  - `App\Newsletter\BrevoImport::emailsFromCsv(string $path): array` — adresele normalizate, unice, din coloana `EMAIL` a unui export Brevo.
  - `App\Newsletter\BrevoImport::run(array $emails, string $as, bool $apply): array` — `$as` este `unsubscribed` sau `bounced`; raport `['total' => int, 'created' => int, 'updated' => int]`.

Exporturile Brevo sunt CSV cu antet, separator `;` sau `,`, uneori cu BOM UTF-8; coloana de email se numește `EMAIL` (orice combinație de litere mari/mici).

- [ ] **Step 1: Scrie testul**

`tests/NewsletterBrevoImportTest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterBrevoImportTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\BrevoImport;
use App\Newsletter\Repository;
use App\Newsletter\Sync;

$pdo    = nl_isolate();
$repo   = new Repository(nl_db());
$import = new BrevoImport($repo);

$tmp = static function (string $content): string {
    $path = tempnam(sys_get_temp_dir(), 'nlcsv');
    file_put_contents($path, $content);
    return $path;
};

// --- citirea CSV-ului --------------------------------------------------------
$semi = $tmp("\xEF\xBB\xBFCONTACT ID;EMAIL;NUME\n1; Dan@NL-Test.invalid ;Dan\n2;eva@nl-test.invalid;Eva\n3;dan@nl-test.invalid;Dublura\n4;nu-e-email;X\n");
check('csv: separator ; + BOM + dubluri + invalide',
    BrevoImport::emailsFromCsv($semi) === ['dan@nl-test.invalid', 'eva@nl-test.invalid']);

$comma = $tmp("email,added\n\"fane@nl-test.invalid\",2026-01-01\n");
check('csv: separator , + antet cu litere mici + ghilimele',
    BrevoImport::emailsFromCsv($comma) === ['fane@nl-test.invalid']);

$noCol = $tmp("NUME;TELEFON\nDan;0700\n");
$threw = false;
try {
    BrevoImport::emailsFromCsv($noCol);
} catch (RuntimeException) {
    $threw = true;
}
check('csv: fără coloana EMAIL → excepție', $threw);

// --- dezabonați --------------------------------------------------------------
$r = $import->run(['dan@nl-test.invalid'], 'unsubscribed', false);
check('dry-run: raportează fără să scrie', $r['total'] === 1 && $repo->findByEmail('dan@nl-test.invalid') === null);

$existing = $repo->ensureSubscriber('eva@nl-test.invalid', 'Eva', 'active');
$repo->addSubscription((int) $existing['id'], 'oferte', 'bs_account');

$r = $import->run(['dan@nl-test.invalid', 'eva@nl-test.invalid'], 'unsubscribed', true);
check('unsubscribed: 1 creat, 1 actualizat', $r['created'] === 1 && $r['updated'] === 1);
$dan = $repo->findByEmail('dan@nl-test.invalid');
$dsubs = $repo->subscriptions((int) $dan['id']);
check('unsubscribed: ambele liste excluse, sursa brevo',
    $dsubs['oferte']['status'] === 'unsubscribed' && $dsubs['stiri']['status'] === 'unsubscribed' && $dsubs['stiri']['source'] === 'brevo');
check('unsubscribed: abonamentul existent devine dezabonat',
    $repo->subscriptions((int) $existing['id'])['oferte']['status'] === 'unsubscribed');

// Sincronizarea de după import nu îi readuce.
(new Sync($repo))->run([
    'bs_account' => [['email' => 'dan@nl-test.invalid', 'name' => 'Dan']],
    'bs_footer'  => [],
    'garage'     => [['email' => 'eva@nl-test.invalid', 'name' => 'Eva']],
], true);
check('după sync: dezabonatul din Brevo rămâne dezabonat',
    $repo->subscriptions((int) $dan['id'])['oferte']['status'] === 'unsubscribed'
    && $repo->subscriptions((int) $existing['id'])['stiri']['status'] === 'unsubscribed');

// --- respinși ----------------------------------------------------------------
$import->run(['fane@nl-test.invalid', 'eva@nl-test.invalid'], 'bounced', true);
check('bounced: adresă nouă creată ca bounced', $repo->findByEmail('fane@nl-test.invalid')['status'] === 'bounced');
check('bounced: adresă existentă trecută pe bounced', $repo->findByEmail('eva@nl-test.invalid')['status'] === 'bounced');

$threw = false;
try {
    $import->run(['x@nl-test.invalid'], 'altceva', true);
} catch (InvalidArgumentException) {
    $threw = true;
}
check('tip necunoscut → excepție', $threw);

nl_done();
```

- [ ] **Step 2: Rulează testul și verifică eșecul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterBrevoImportTest.php
```

Expected: eroare fatală `Class "App\Newsletter\BrevoImport" not found`.

- [ ] **Step 3: Scrie `BrevoImport`**

`src/Newsletter/BrevoImport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Newsletter;

use InvalidArgumentException;
use RuntimeException;

/**
 * Import unic al excluderilor din Brevo (exporturi CSV): contactele dezabonate
 * devin dezabonate pe ambele liste, cele respinse devin `bounced`. Fără acest pas
 * am scrie unor oameni care s-au dezabonat deja în Brevo.
 */
final class BrevoImport
{
    public function __construct(private Repository $repo) {}

    /**
     * Adresele normalizate și unice din coloana EMAIL.
     * @return array<int,string>
     */
    public static function emailsFromCsv(string $path): array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException("Nu pot citi fișierul: {$path}");
        }
        $header = fgets($fh);
        if ($header === false) {
            fclose($fh);
            throw new RuntimeException('Fișier gol.');
        }
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
        $sep = substr_count($header, ';') >= substr_count($header, ',') ? ';' : ',';
        $cols = array_map(
            static fn ($c): string => strtoupper(trim((string) $c)),
            str_getcsv(rtrim($header, "\r\n"), $sep)
        );
        $idx = array_search('EMAIL', $cols, true);
        if ($idx === false) {
            fclose($fh);
            throw new RuntimeException('Nu găsesc coloana EMAIL în antet: ' . implode(' | ', $cols));
        }
        $out = [];
        while (($row = fgetcsv($fh, 0, $sep)) !== false) {
            $email = Address::normalize(isset($row[$idx]) ? (string) $row[$idx] : null);
            if ($email !== null) {
                $out[$email] = true;
            }
        }
        fclose($fh);
        return array_keys($out);
    }

    /**
     * @param array<int,string> $emails adrese deja normalizate
     * @param string $as 'unsubscribed' | 'bounced'
     * @return array{total:int,created:int,updated:int}
     */
    public function run(array $emails, string $as, bool $apply): array
    {
        if (!in_array($as, ['unsubscribed', 'bounced'], true)) {
            throw new InvalidArgumentException("Tip necunoscut: {$as}");
        }
        $report = ['total' => count($emails), 'created' => 0, 'updated' => 0];
        foreach ($emails as $email) {
            $existing = $this->repo->findByEmail($email);
            $existing === null ? $report['created']++ : $report['updated']++;
            if (!$apply) {
                continue;
            }
            $sub = $existing ?? $this->repo->ensureSubscriber($email, null, 'active');
            $id  = (int) $sub['id'];
            if ($as === 'bounced') {
                $this->repo->setStatus($id, 'bounced');
                continue;
            }
            foreach (array_keys(Repository::LISTS) as $list) {
                $this->repo->suppress($id, $list, 'brevo');
            }
        }
        return $report;
    }
}
```

- [ ] **Step 4: Rulează testul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterBrevoImportTest.php
```

Expected: `11 verificări, 0 eșecuri`.

- [ ] **Step 5: Scrie CLI-ul**

`database/newsletter_import_brevo.php`:

```php
<?php

declare(strict_types=1);

/**
 * Import unic al excluderilor din Brevo. Dry-run implicit; --apply execută.
 *
 *   php database/newsletter_import_brevo.php <fisier.csv> --as=unsubscribed [--apply]
 *   php database/newsletter_import_brevo.php <fisier.csv> --as=bounced      [--apply]
 *
 * Exporturile se fac din Brevo → Contacts, filtrate pe „Unsubscribed" / „Blocklisted"
 * (→ --as=unsubscribed) și pe „Hard bounced" (→ --as=bounced). CSV-urile conțin date
 * personale: ține-le în storage/newsletter/ (gitignored) și șterge-le după import.
 */

use App\Database;
use App\Newsletter\BrevoImport;
use App\Newsletter\Repository;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
$settings = require $root . '/config/settings.php';

$file = null;
$as   = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--as=')) {
        $as = substr($arg, 5);
    } elseif (!str_starts_with($arg, '--')) {
        $file = $arg;
    }
}
$apply = in_array('--apply', $argv, true);

if ($file === null || !in_array($as, ['unsubscribed', 'bounced'], true)) {
    fwrite(STDERR, "Utilizare: newsletter_import_brevo.php <fisier.csv> --as=unsubscribed|bounced [--apply]\n");
    exit(2);
}

try {
    $emails = BrevoImport::emailsFromCsv($file);
    $r = (new BrevoImport(new Repository(new Database($settings['db']))))->run($emails, $as, $apply);
} catch (Throwable $e) {
    fwrite(STDERR, 'Eroare: ' . $e->getMessage() . "\n");
    exit(1);
}

$verb = $apply ? '' : ' (ar fi)';
echo ($apply ? "IMPORT BREVO (--apply)\n" : "IMPORT BREVO (dry-run): folosește --apply pentru a executa\n");
echo "  adrese valide în fișier: {$r['total']}\n";
echo "  marcate {$as}{$verb}: {$r['total']} ({$r['created']} noi, {$r['updated']} existente)\n";
exit(0);
```

- [ ] **Step 6: Verifică CLI-ul cu un fișier de probă**

```bash
cd /c/laragon/www/motociclete
printf 'EMAIL;NUME\nproba-brevo@nl-test.invalid;Proba\n' > storage/newsletter/proba-brevo.csv
"$PHP" database/newsletter_import_brevo.php storage/newsletter/proba-brevo.csv --as=unsubscribed
"$PHP" database/newsletter_import_brevo.php storage/newsletter/proba-brevo.csv
rm storage/newsletter/proba-brevo.csv
```

Expected: prima comandă afișează `adrese valide în fișier: 1` și `marcate unsubscribed (ar fi): 1 (1 noi, 0 existente)`; a doua afișează `Utilizare: …` și iese cu cod 2. `storage/newsletter/` este gitignored, deci fișierul de probă nu apare în `git status`.

- [ ] **Step 7: Commit**

```bash
cd /c/laragon/www/motociclete
git add src/Newsletter/BrevoImport.php database/newsletter_import_brevo.php tests/NewsletterBrevoImportTest.php
git commit -m "feat(newsletter): import al excluderilor din exporturile Brevo"
```

---

### Task 5: Abonare, confirmare și dezabonare pe portal

**Files:**
- Create: `src/Controllers/NewsletterController.php`
- Create: `templates/newsletter/status.twig`
- Create: `templates/newsletter/prefs.twig`
- Create: `templates/partials/newsletter-signup.twig`
- Create: `tests/NewsletterPublicTest.php`
- Create: `tests/EmailTemplateLinkTest.php`
- Modify: `src/Support/EmailTemplate.php` (metoda `textToHtml`)
- Modify: `src/Routes.php` (rute noi, puse înaintea blocului `// --- Catalog (Yamaha + CFMOTO), backed by the local DB ---`)
- Modify: `templates/partials/footer.twig` (include formularul)
- Modify: `assets/css/app.css` (stiluri `.nl-*`, la finalul fișierului)
- Modify: `templates/layout.twig` (crește `app.css?v=`)

**Interfaces:**
- Consumes: `$container['newsletter']` (`Repository`), `$container['mailer']` (`Support\Mailer::send($to, $subject, $body, $context)`), `Address::clean`, `Repository::LISTS`.
- Produces — rute publice:
  - `POST /api/newsletter/abonare` — câmpuri `email`, `lists[]`, `consent=1`, honeypot `website`. JSON `{ok:true}` sau `{ok:false,error}` (422/429/500). Fără header AJAX: 303 către `/newsletter/abonare?ok=1` sau `?eroare=…`.
  - `GET /newsletter/abonare` — pagina de stare după un POST fără JavaScript.
  - `GET /newsletter/confirmare/{token}?l=oferte,stiri` — activează abonatul și abonamentele alese.
  - `GET /newsletter/dezabonare/{token}[?l=<listă>]` — pagina de preferințe; **nu schimbă starea**.
  - `POST /newsletter/dezabonare/{token}[?l=<listă>]` — salvează preferințele; cu `List-Unsubscribe=One-Click` în corp dezabonează direct și răspunde `200 OK` text simplu.
  - `{token}` este `[a-f0-9]{32}`; orice altceva nu potrivește ruta.

- [ ] **Step 1: Testul pentru linkurile din emailuri**

`Support\EmailTemplate::textToHtml()` transformă textul controllerelor în HTML, dar un URL rămâne text simplu, deci linkul de confirmare nu ar fi clicabil.

`tests/EmailTemplateLinkTest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/EmailTemplateLinkTest.php
 */

require __DIR__ . '/_nl.php';

use App\Support\EmailTemplate;

$url  = 'https://www.motociclete.com.ro/newsletter/confirmare/0123456789abcdef0123456789abcdef?l=oferte,stiri';
$html = EmailTemplate::textToHtml("Salut,\n\nConfirmă abonarea:\n{$url}\n\nMulțumim.");

check('linia cu URL devine link', str_contains($html, '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'));
check('restul textului rămâne paragraf', str_contains($html, '<p style="margin:0 0 12px">Salut,</p>'));
check('URL-ul nu e tratat ca rând „Cheie: valoare"', !str_contains($html, '<td style="padding:6px 10px;border:1px solid #E4E4E7;background:#FAFAFA'));

$otp = EmailTemplate::textToHtml('Codul tău este 123456');
check('codul OTP rămâne evidențiat', str_contains($otp, 'letter-spacing:4px') && str_contains($otp, '123456'));

$mixed = EmailTemplate::textToHtml('Vezi https://example.com/x pentru detalii');
check('URL în mijlocul unei fraze rămâne text', !str_contains($mixed, '<a href="https://example.com/x"'));

$xss = EmailTemplate::textToHtml('https://example.com/?a="><script>');
check('URL-ul e escapat', !str_contains($xss, '<script>'));

nl_done();
```

- [ ] **Step 2: Rulează și verifică eșecul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/EmailTemplateLinkTest.php
```

Expected: prima verificare (`linia cu URL devine link`) eșuează; `6 verificări, 1 eșecuri`.

- [ ] **Step 3: Adaugă linkificarea în `textToHtml`**

În `src/Support/EmailTemplate.php`, în `textToHtml()`, înlocuiește:

```php
            if ($line === '') { $flushRows(); continue; }
```

cu:

```php
            if ($line === '') { $flushRows(); continue; }
            // O linie care e doar un URL devine buton (ex. linkul de confirmare a abonării).
            if (preg_match('~^https?://\S+$~', $line)) {
                $flushRows();
                $html .= '<p style="margin:0 0 16px"><a href="' . $e($line) . '" style="display:inline-block;padding:12px 22px;background:' . self::RED . ';color:#ffffff;text-decoration:none;font-weight:700;border-radius:6px">Deschide linkul</a></p>'
                    . '<p style="margin:0 0 12px;font-size:12px;color:#71717A;word-break:break-all">' . $e($line) . '</p>';
                continue;
            }
```

- [ ] **Step 4: Rulează testul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/EmailTemplateLinkTest.php
```

Expected: `6 verificări, 0 eșecuri`.

- [ ] **Step 5: Scrie testul HTTP al fluxului public**

Testul lovește situl local (`http://motociclete.test`), deci scrie în baza locală; își șterge singur rândurile `@nl-test.invalid` la început și la sfârșit. În `APP_ENV=dev` emailul de confirmare se scrie în `storage/logs/mail.log`, nu se trimite.

`tests/NewsletterPublicTest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Test HTTP al fluxului abonare → confirmare → preferințe → dezabonare.
 * Necesită situl local pornit (Laragon) pe APP_URL.
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterPublicTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Repository;

$base = rtrim((string) $GLOBALS['nl_settings']['app']['url'], '/') . (string) $GLOBALS['nl_settings']['app']['base_path'];
$pdo  = nl_db()->local();
$repo = new Repository(nl_db());

$cleanup = static function () use ($pdo): void {
    $pdo->exec("DELETE s FROM nl_subscriptions s JOIN nl_subscribers u ON u.id = s.subscriber_id WHERE u.email LIKE '%@nl-test.invalid'");
    $pdo->exec("DELETE FROM nl_subscribers WHERE email LIKE '%@nl-test.invalid'");
    $pdo->exec("DELETE FROM email_log WHERE to_addr LIKE '%@nl-test.invalid'");
};
$cleanup();
register_shutdown_function($cleanup);

/** @return array{0:int,1:string} [status, body] */
$http = static function (string $method, string $url, array $fields = [], bool $ajax = true): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $ajax ? ['X-Requested-With: XMLHttpRequest'] : [],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
};
$mails = static fn (string $to): int => (int) $pdo->query(
    'SELECT COUNT(*) FROM email_log WHERE to_addr = ' . $pdo->quote($to)
)->fetchColumn();

$email = 'vizitator@nl-test.invalid';
$api   = $base . '/api/newsletter/abonare';

// --- validare ----------------------------------------------------------------
[$c, $b] = $http('POST', $api, ['email' => $email, 'lists' => ['stiri']]);
check('fără acord → 422', $c === 422 && str_contains($b, '"ok":false'));
[$c] = $http('POST', $api, ['email' => $email, 'consent' => '1']);
check('fără nicio listă → 422', $c === 422);
[$c] = $http('POST', $api, ['email' => 'nu-e-email', 'lists' => ['stiri'], 'consent' => '1']);
check('email invalid → 422', $c === 422);
[$c] = $http('POST', $api, ['email' => 'guest-emag-9@bikershop.ro', 'lists' => ['stiri'], 'consent' => '1']);
check('adresă blocată → 422', $c === 422);
[$c, $b] = $http('POST', $api, ['email' => 'bot@nl-test.invalid', 'lists' => ['stiri'], 'consent' => '1', 'website' => 'spam']);
check('honeypot → succes aparent, fără rând', $c === 200 && $repo->findByEmail('bot@nl-test.invalid') === null);

// --- abonare -----------------------------------------------------------------
[$c, $b] = $http('POST', $api, ['email' => ' Vizitator@NL-Test.invalid ', 'lists' => ['stiri', 'altceva'], 'consent' => '1']);
$sub = $repo->findByEmail($email);
check('abonare validă → 200 ok', $c === 200 && str_contains($b, '"ok":true'));
check('abonatul e creat ca pending, fără abonamente',
    $sub !== null && $sub['status'] === 'pending' && $repo->subscriptions((int) $sub['id']) === []);
check('un email de confirmare', $mails($email) === 1);

// Review Focus 5: retrimiterea imediată nu inundă adresa.
$http('POST', $api, ['email' => $email, 'lists' => ['stiri'], 'consent' => '1']);
$http('POST', $api, ['email' => $email, 'lists' => ['oferte'], 'consent' => '1']);
check('retrimitere în 15 minute → tot un singur email', $mails($email) === 1);

$token = (string) $sub['token'];

// --- confirmare --------------------------------------------------------------
[$c] = $http('GET', $base . '/newsletter/confirmare/' . str_repeat('0', 32) . '?l=stiri', [], false);
check('token necunoscut → 404', $c === 404);
[$c] = $http('GET', $base . '/newsletter/confirmare/nu-e-token', [], false);
check('token cu format greșit → 404', $c === 404);

[$c, $b] = $http('GET', $base . '/newsletter/confirmare/' . $token . '?l=stiri,altceva', [], false);
$sub  = $repo->findByEmail($email);
$subs = $repo->subscriptions((int) $sub['id']);
check('confirmare → 200 + abonat activ', $c === 200 && $sub['status'] === 'active');
check('confirmare → doar lista validă cerută, sursa portal',
    isset($subs['stiri']) && !isset($subs['oferte']) && $subs['stiri']['source'] === 'portal');
check('pagina de confirmare e noindex', str_contains($b, 'noindex'));

// --- preferințe: GET nu schimbă starea (Review Focus 4) ----------------------
[$c, $b] = $http('GET', $base . '/newsletter/dezabonare/' . $token . '?l=stiri', [], false);
check('pagina de preferințe → 200', $c === 200 && str_contains($b, 'Dual Motors știri'));
check('GET pe linkul de dezabonare NU dezabonează',
    $repo->subscriptions((int) $sub['id'])['stiri']['status'] === 'active');

// --- dezabonare cu un clic (Gmail/Yahoo) -------------------------------------
[$c, $b] = $http('POST', $base . '/newsletter/dezabonare/' . $token . '?l=stiri', ['List-Unsubscribe' => 'One-Click'], false);
check('one-click → 200', $c === 200);
check('one-click → dezabonat de la lista din link',
    $repo->subscriptions((int) $sub['id'])['stiri']['status'] === 'unsubscribed');

// --- formularul de preferințe ------------------------------------------------
[$c] = $http('POST', $base . '/newsletter/dezabonare/' . $token, ['keep' => ['oferte', 'stiri']], false);
$subs = $repo->subscriptions((int) $sub['id']);
check('salvare preferințe → 303', $c === 303);
check('bifat → ambele liste active', $subs['oferte']['status'] === 'active' && $subs['stiri']['status'] === 'active');

$http('POST', $base . '/newsletter/dezabonare/' . $token, ['keep' => ['oferte']], false);
$subs = $repo->subscriptions((int) $sub['id']);
check('debifat → doar lista debifată e dezabonată',
    $subs['oferte']['status'] === 'active' && $subs['stiri']['status'] === 'unsubscribed');

$http('POST', $base . '/newsletter/dezabonare/' . $token, ['unsub_all' => '1', 'keep' => ['oferte']], false);
$subs = $repo->subscriptions((int) $sub['id']);
check('„dezabonează-mă de la tot" → ambele dezabonate',
    $subs['oferte']['status'] === 'unsubscribed' && $subs['stiri']['status'] === 'unsubscribed');

[$c] = $http('POST', $base . '/newsletter/dezabonare/' . str_repeat('0', 32), ['unsub_all' => '1'], false);
check('POST cu token necunoscut → 404', $c === 404);

// --- limita pe IP (Review Focus 5) -------------------------------------------
$codes = [];
for ($i = 1; $i <= 6; $i++) {
    [$codes[]] = $http('POST', $api, ['email' => "ip{$i}@nl-test.invalid", 'lists' => ['stiri'], 'consent' => '1']);
}
check('peste 5 abonați noi pe oră de pe același IP → 429', $codes[0] === 200 && end($codes) === 429);
check('adresa peste limită nu e creată', $repo->findByEmail('ip6@nl-test.invalid') === null);

// --- fallback fără JavaScript ------------------------------------------------
[$c] = $http('POST', $api, ['email' => 'nu-e-email', 'lists' => ['stiri'], 'consent' => '1'], false);
check('fără AJAX → redirect 303', $c === 303);
[$c, $b] = $http('GET', $base . '/newsletter/abonare?ok=1', [], false);
check('pagina de stare → 200', $c === 200 && str_contains($b, 'Verifică-ți emailul'));

nl_done();
```

- [ ] **Step 6: Rulează și verifică eșecul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterPublicTest.php
```

Expected: majoritatea verificărilor eșuează (rutele nu există; `POST /api/newsletter/abonare` răspunde 404 sau 405).

- [ ] **Step 7: Scrie controllerul**

`src/Controllers/NewsletterController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Newsletter\Address;
use App\Newsletter\Repository;
use App\Support\Mailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;
use Throwable;

/**
 * Partea publică a newsletterului: abonare cu confirmare prin email, pagina de
 * preferințe/dezabonare și dezabonarea cu un clic cerută de Gmail/Yahoo.
 *
 * Linkurile publice poartă tokenul abonatului (secret, trimis doar pe email).
 * GET nu schimbă niciodată abonamentele: filtrele de email deschid automat linkurile.
 */
final class NewsletterController
{
    private const SIGNUPS_PER_IP_PER_HOUR = 5;
    private const CONFIRM_RESEND_MINUTES  = 15;

    private Repository $repo;
    private Mailer $mailer;
    private string $base;
    private string $siteUrl;

    /** @param array<string,mixed> $container */
    public function __construct(private Twig $twig, array $container)
    {
        $this->repo    = $container['newsletter'];
        $this->mailer  = $container['mailer'];
        $this->base    = (string) ($container['settings']['app']['base_path'] ?? '');
        $this->siteUrl = rtrim((string) ($container['settings']['app']['url'] ?? ''), '/') . $this->base;
    }

    /** POST /api/newsletter/abonare */
    public function subscribe(Request $request, Response $response): Response
    {
        $d = (array) $request->getParsedBody();

        // Honeypot: succes aparent pentru boți.
        if (trim((string) ($d['website'] ?? '')) !== '') {
            return $this->ok($request, $response);
        }
        if (trim((string) ($d['consent'] ?? '')) !== '1') {
            return $this->err($request, $response, 'Bifează acordul pentru a primi newsletterul.');
        }
        $lists = $this->validLists((array) ($d['lists'] ?? []));
        if (!$lists) {
            return $this->err($request, $response, 'Alege cel puțin o listă.');
        }
        $email = Address::clean(isset($d['email']) ? (string) $d['email'] : null);
        if ($email === null) {
            return $this->err($request, $response, 'Introdu o adresă de email validă.');
        }

        $ip = $this->clientIp($request);
        try {
            $sub = $this->repo->findByEmail($email);
            if ($sub === null) {
                if ($ip !== '' && $this->repo->recentSignupsFromIp($ip, 60) >= self::SIGNUPS_PER_IP_PER_HOUR) {
                    return $this->err($request, $response, 'Prea multe cereri. Încearcă din nou mai târziu.', 429);
                }
                $sub = $this->repo->ensureSubscriber($email, null, 'pending', $ip !== '' ? $ip : null);
            }
            // Același răspuns indiferent dacă adresa exista: nu dezvăluim cine e abonat.
            // Abonamentele se schimbă abia la confirmare, din linkul primit pe email.
            $id = (int) $sub['id'];
            if (!$this->repo->confirmRecentlySent($id, self::CONFIRM_RESEND_MINUTES)) {
                $this->repo->markConfirmSent($id);
                $this->mailer->send(
                    $email,
                    'Confirmă abonarea la newsletterul Dual Motors',
                    $this->confirmBody((string) $sub['token'], $lists),
                    'newsletter-confirm'
                );
            }
        } catch (Throwable) {
            return $this->err($request, $response, 'A apărut o eroare. Încearcă din nou.', 500);
        }

        return $this->ok($request, $response);
    }

    /** GET /newsletter/abonare — pagina de stare după un POST fără JavaScript. */
    public function signupStatus(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $error = trim((string) ($q['eroare'] ?? ''));
        return $this->status(
            $response,
            $error !== '' ? 'Abonarea nu a reușit' : 'Verifică-ți emailul',
            $error !== '' ? $error : 'Ți-am trimis un email cu un link de confirmare. Abonarea devine activă după ce apeși pe link.',
            '/newsletter/abonare'
        );
    }

    /** GET /newsletter/confirmare/{token}?l=oferte,stiri */
    public function confirm(Request $request, Response $response, array $args): Response
    {
        $sub   = $this->subscriberOr404($request, $args);
        $id    = (int) $sub['id'];
        $lists = $this->validLists(explode(',', (string) ($request->getQueryParams()['l'] ?? '')));

        $this->repo->activate($id);
        foreach ($lists as $list) {
            $this->repo->setSubscription($id, $list, 'portal');
        }

        $names = array_map(static fn (string $l): string => Repository::LISTS[$l], $lists);
        return $this->status(
            $response,
            'Abonare confirmată',
            $names
                ? 'Mulțumim! De acum primești: ' . implode(' și ', $names) . '.'
                : 'Adresa ta este confirmată.',
            '/newsletter/confirmare',
            $this->base . '/newsletter/dezabonare/' . $sub['token']
        );
    }

    /** GET /newsletter/dezabonare/{token}[?l=lista] — doar afișează. */
    public function prefs(Request $request, Response $response, array $args): Response
    {
        $sub   = $this->subscriberOr404($request, $args);
        $q     = $request->getQueryParams();
        $focus = $this->validLists([(string) ($q['l'] ?? '')])[0] ?? null;

        return $this->twig->render($response, 'newsletter/prefs.twig', [
            'email'          => (string) $sub['email'],
            'token'          => (string) $sub['token'],
            'lists'          => Repository::LISTS,
            'subs'           => $this->repo->subscriptions((int) $sub['id']),
            'focus'          => $focus,
            'saved'          => isset($q['salvat']),
            'excluded'       => in_array($sub['status'], ['bounced', 'complained'], true),
            'canonical_path' => '/newsletter/dezabonare',
        ]);
    }

    /** POST /newsletter/dezabonare/{token}[?l=lista] */
    public function prefsSave(Request $request, Response $response, array $args): Response
    {
        $sub   = $this->subscriberOr404($request, $args);
        $id    = (int) $sub['id'];
        $d     = (array) $request->getParsedBody();
        $all   = array_keys(Repository::LISTS);
        $focus = $this->validLists([(string) ($request->getQueryParams()['l'] ?? '')]);

        // Dezabonare cu un clic (RFC 8058): clientul de email trimite acest POST singur.
        if ((string) ($d['List-Unsubscribe'] ?? '') === 'One-Click') {
            foreach ($focus ?: $all as $list) {
                $this->repo->unsubscribe($id, $list);
            }
            $response->getBody()->write('OK');
            return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $keep = isset($d['unsub_all']) ? [] : $this->validLists((array) ($d['keep'] ?? []));
        foreach ($all as $list) {
            if (in_array($list, $keep, true)) {
                $this->repo->setSubscription($id, $list, 'portal');
            } else {
                $this->repo->unsubscribe($id, $list);
            }
        }
        // Cine ține tokenul a primit emailul, deci adresa e a lui: o putem activa.
        if ($keep && $sub['status'] === 'pending') {
            $this->repo->activate($id);
        }

        return $response
            ->withHeader('Location', $this->base . '/newsletter/dezabonare/' . $sub['token'] . '?salvat=1')
            ->withStatus(303);
    }

    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function subscriberOr404(Request $request, array $args): array
    {
        $sub = $this->repo->findByToken((string) ($args['token'] ?? ''));
        if ($sub === null) {
            throw new HttpNotFoundException($request);
        }
        return $sub;
    }

    /** @param array<int|string,mixed> $raw @return array<int,string> cheile de listă valide, în ordinea oficială */
    private function validLists(array $raw): array
    {
        $raw = array_map(static fn ($v): string => trim((string) $v), $raw);
        return array_values(array_filter(
            array_keys(Repository::LISTS),
            static fn (string $key): bool => in_array($key, $raw, true)
        ));
    }

    /** @param array<int,string> $lists */
    private function confirmBody(string $token, array $lists): string
    {
        $names = array_map(static fn (string $l): string => Repository::LISTS[$l], $lists);
        return implode("\n", [
            'Salut,',
            '',
            'Ai cerut să primești pe email: ' . implode(' și ', $names) . '.',
            'Confirmă abonarea apăsând pe butonul de mai jos:',
            '',
            $this->siteUrl . '/newsletter/confirmare/' . $token . '?l=' . implode(',', $lists),
            '',
            'Dacă nu ai cerut tu această abonare, ignoră mesajul: nu vei primi nimic.',
        ]);
    }

    private function status(Response $response, string $title, string $message, string $canonical, ?string $prefsUrl = null): Response
    {
        return $this->twig->render($response, 'newsletter/status.twig', [
            'title'          => $title,
            'message'        => $message,
            'prefs_url'      => $prefsUrl,
            'canonical_path' => $canonical,
        ]);
    }

    private function isAjax(Request $request): bool
    {
        return strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
    }

    private function ok(Request $request, Response $response): Response
    {
        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true]);
        }
        return $response->withHeader('Location', $this->base . '/newsletter/abonare?ok=1')->withStatus(303);
    }

    private function err(Request $request, Response $response, string $msg, int $status = 422): Response
    {
        if ($this->isAjax($request)) {
            return $this->json($response->withStatus($status), ['ok' => false, 'error' => $msg]);
        }
        return $response
            ->withHeader('Location', $this->base . '/newsletter/abonare?eroare=' . rawurlencode($msg))
            ->withStatus(303);
    }

    private function clientIp(Request $request): string
    {
        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ($xff !== '') {
            return trim(explode(',', $xff)[0]);
        }
        return (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
    }

    /** @param array<string,mixed> $payload */
    private function json(Response $response, array $payload): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

- [ ] **Step 8: Adaugă rutele**

În `src/Routes.php`, înaintea comentariului `// --- Catalog (Yamaha + CFMOTO), backed by the local DB ---`, adaugă:

```php
    // --- Newsletter propriu: abonare cu confirmare + preferințe/dezabonare ---
    $nl = function (string $method) use ($twig, $container) {
        return function ($request, $response, $args) use ($twig, $container, $method) {
            return (new \App\Controllers\NewsletterController($twig, $container))->{$method}($request, $response, $args);
        };
    };
    $app->post('/api/newsletter/abonare', $nl('subscribe'));
    $app->get('/newsletter/abonare', $nl('signupStatus'));
    $app->get('/newsletter/confirmare/{token:[a-f0-9]{32}}', $nl('confirm'));
    $app->get('/newsletter/dezabonare/{token:[a-f0-9]{32}}', $nl('prefs'));
    $app->post('/newsletter/dezabonare/{token:[a-f0-9]{32}}', $nl('prefsSave'));

```

`subscribe` și `signupStatus` primesc și ele `$args` (gol) de la closure; PHP ignoră argumentul în plus.

- [ ] **Step 9: Scrie șabloanele paginilor**

`templates/newsletter/status.twig`:

```twig
{% extends 'layout.twig' %}

{% block title %}{{ title }} | Newsletter Dual Motors{% endblock %}
{% block description %}Newsletterul Dual Motors: noutăți despre motociclete Yamaha și CFMOTO și oferte BikerShop.{% endblock %}
{% block meta_robots %}noindex,nofollow{% endblock %}

{% block content %}
<article class="section">
    <div class="container container--narrow">
        <header class="page-head">
            <span class="kicker">Newsletter</span>
            <h1 class="page-title">{{ title }}</h1>
        </header>
        <p class="nl-lead">{{ message }}</p>
        {% if prefs_url %}
            <p><a class="link-arrow" href="{{ prefs_url }}">Schimbă preferințele sau dezabonează-te</a></p>
        {% endif %}
        <p class="finance-back"><a class="link-arrow" href="{{ base }}/">← Înapoi la showroom</a></p>
    </div>
</article>
{% endblock %}
```

`templates/newsletter/prefs.twig`:

```twig
{% extends 'layout.twig' %}

{% block title %}Preferințe newsletter | Dual Motors{% endblock %}
{% block description %}Alege ce newslettere Dual Motors primești sau dezabonează-te.{% endblock %}
{% block meta_robots %}noindex,nofollow{% endblock %}

{% block content %}
<article class="section">
    <div class="container container--narrow">
        <header class="page-head">
            <span class="kicker">Newsletter</span>
            <h1 class="page-title">Preferințe newsletter</h1>
        </header>

        <p class="nl-lead">Setări pentru adresa <strong>{{ email }}</strong>.</p>

        {% if saved %}
            <p class="nl-note nl-note--ok">Preferințele au fost salvate.</p>
        {% endif %}
        {% if excluded %}
            <p class="nl-note">Nu mai trimitem mesaje la această adresă. Dacă vrei să primești din nou newsletterul, abonează-te din nou din subsolul sitului.</p>
        {% endif %}

        <form class="nl-prefs" method="post" action="{{ base }}/newsletter/dezabonare/{{ token }}">
            <fieldset class="nl-prefs__lists">
                <legend>Vreau să primesc:</legend>
                {% for key, name in lists %}
                    {# La venirea din linkul unui mesaj (?l=…), lista acelui mesaj apare debifată. #}
                    {% set is_on = subs[key] is defined and subs[key].status == 'active' %}
                    <label class="nl-prefs__item">
                        <input type="checkbox" name="keep[]" value="{{ key }}" {{ is_on and focus != key ? 'checked' }}>
                        <span>
                            <strong>{{ name }}</strong>
                            {% if key == 'oferte' %}— oferte și reduceri la echipament, piese și accesorii{% else %}— noutăți, modele noi și evenimente Dual Motors{% endif %}
                        </span>
                    </label>
                {% endfor %}
            </fieldset>
            <div class="nl-prefs__actions">
                <button class="btn btn--primary" type="submit">Salvează preferințele</button>
                <button class="btn btn--ghost" type="submit" name="unsub_all" value="1">Dezabonează-mă de la tot</button>
            </div>
        </form>

        <p class="finance-back"><a class="link-arrow" href="{{ base }}/">← Înapoi la showroom</a></p>
    </div>
</article>
{% endblock %}
```

- [ ] **Step 10: Formularul din footer**

`templates/partials/newsletter-signup.twig`:

```twig
{# Abonare la newsletter (footer). Confirmare prin email; logica în NewsletterController. #}
<div class="container nl-signup">
    <div class="nl-signup__text">
        <h4>Noutăți și oferte pe email</h4>
        <p>Modele noi, evenimente și reduceri la echipament. Te poți dezabona oricând.</p>
    </div>
    <div class="nl-signup__box">
        <form class="nl-signup__form" method="post" action="{{ base }}/api/newsletter/abonare" data-ajax-form>
            <input type="text" name="website" tabindex="-1" autocomplete="off" class="hp" aria-hidden="true">
            <div class="nl-signup__row">
                <label class="sr-only" for="nl-email">Adresa ta de email</label>
                <input id="nl-email" type="email" name="email" placeholder="Adresa ta de email" autocomplete="email" required>
                <button class="btn btn--primary" type="submit">Abonează-mă</button>
            </div>
            <div class="nl-signup__lists">
                <label><input type="checkbox" name="lists[]" value="stiri" checked> Dual Motors știri</label>
                <label><input type="checkbox" name="lists[]" value="oferte" checked> BikerShop oferte</label>
            </div>
            <label class="nl-signup__consent">
                <input type="checkbox" name="consent" value="1" required>
                <span>Sunt de acord să primesc newsletterul, conform <a href="{{ base }}/confidentialitate" target="_blank" rel="noopener">Politicii de confidențialitate</a>.</span>
            </label>
            <p class="nl-signup__err" data-form-err hidden></p>
        </form>
        <p class="nl-signup__thanks" data-form-thanks hidden>Verifică-ți emailul: ți-am trimis un link de confirmare.</p>
    </div>
</div>
```

Bifele pentru liste sunt alegerea conținutului, nu acordul; acordul (`consent`) este nebifat implicit.

În `templates/partials/footer.twig`, înaintea comentariului `{# Contact data on its own line below the menus. #}`, adaugă:

```twig
    {% include 'partials/newsletter-signup.twig' %}

```

Handlerul `[data-ajax-form]` din `assets/js/app.js` caută `[data-form-thanks]` în părintele formularului, de aceea paragraful de mulțumire stă lângă `<form>`, în `.nl-signup__box`.

- [ ] **Step 11: Stiluri**

La finalul `assets/css/app.css` adaugă:

```css
/* ---- Newsletter: formularul din footer (fundal întunecat) + paginile de preferințe ---- */
.nl-signup { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr); gap: 1.5rem 2.5rem; align-items: start; border-top: 1px solid rgba(255,255,255,.08); padding: 1.8rem 0; }
.nl-signup > * { min-width: 0; }
.nl-signup__text h4 { margin: 0 0 .4rem; color: #fff; }
.nl-signup__text p { margin: 0; font-size: .92rem; }
.nl-signup__row { display: flex; gap: .6rem; }
.nl-signup__row input[type="email"] { flex: 1 1 auto; min-width: 0; padding: .75rem .9rem; border: 1px solid rgba(255,255,255,.22); border-radius: 6px; background: rgba(255,255,255,.06); color: #fff; font: inherit; }
.nl-signup__row input[type="email"]::placeholder { color: #9a9aa3; }
.nl-signup__lists { display: flex; flex-wrap: wrap; gap: .4rem 1.4rem; margin-top: .8rem; font-size: .88rem; }
.nl-signup__lists label, .nl-signup__consent { display: flex; align-items: flex-start; gap: .5rem; cursor: pointer; }
.nl-signup__consent { margin-top: .6rem; font-size: .8rem; }
.nl-signup__consent a { color: inherit; text-decoration: underline; }
.nl-signup__lists input, .nl-signup__consent input { flex: none; margin-top: .2rem; accent-color: var(--red); }
.nl-signup__err { margin: .7rem 0 0; color: #ff8a84; font-size: .88rem; }
.nl-signup__thanks { margin: 0; color: #fff; font-weight: 600; }
@media (max-width: 760px) {
    .nl-signup { grid-template-columns: minmax(0, 1fr); }
    .nl-signup__row { flex-direction: column; }
}

.nl-lead { font-size: 1.05rem; margin: 0 0 1.4rem; }
.nl-note { margin: 0 0 1.2rem; padding: .8rem 1rem; border-left: 3px solid var(--red); background: #fafafa; }
.nl-note--ok { border-left-color: #1a7f37; }
.nl-prefs__lists { border: 0; padding: 0; margin: 0 0 1.4rem; }
.nl-prefs__lists legend { font-weight: 700; margin-bottom: .6rem; padding: 0; }
.nl-prefs__item { display: flex; align-items: flex-start; gap: .7rem; padding: .8rem 0; border-bottom: 1px solid #e4e4e7; cursor: pointer; }
.nl-prefs__item input { flex: none; margin-top: .3rem; accent-color: var(--red); }
.nl-prefs__actions { display: flex; flex-wrap: wrap; gap: .8rem; margin-bottom: 2rem; }
```

În `templates/layout.twig` schimbă `app.css?v=49` în `app.css?v=50`.

Verifică în `assets/css/app.css` că există deja clasele `.hp` (honeypot) și `.sr-only`:

```bash
grep -n "^\.hp\b\|\.hp {\|\.sr-only" /c/laragon/www/motociclete/assets/css/app.css | head
```

Expected: cel puțin câte o regulă pentru fiecare. Dacă `.sr-only` lipsește, adaugă la blocul de mai sus:

```css
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
```

- [ ] **Step 12: Rulează testul HTTP**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterPublicTest.php
```

Expected: `27 verificări, 0 eșecuri`. Dacă verificarea limitei pe IP pică la o a doua rulare în aceeași oră, nu e un bug: curățarea de la început șterge rândurile de test, deci contorul pornește de la zero la fiecare rulare.

- [ ] **Step 13: Verifică vizual**

```bash
cd /c/laragon/www/motociclete
"/c/Program Files/Google/Chrome/Application/chrome.exe" --headless=new --screenshot="C:/laragon/www/motociclete/storage/shots/nl-footer.png" --window-size=1440,3600 http://motociclete.test/contact
```

Deschide `storage/shots/nl-footer.png` și verifică: formularul apare în footer între coloanele de meniu și datele de contact, textul e lizibil pe fundalul întunecat, bifa de acord e nebifată. Pentru mobil folosește puppeteer cu `setViewport({width: 390})` (Chrome headless nu coboară sub ~500 px) și verifică `document.documentElement.scrollWidth === clientWidth`. Verifică și pagina de preferințe: abonează o adresă `@nl-test.invalid` din formular, ia tokenul din DB și deschide `http://motociclete.test/newsletter/dezabonare/<token>`; la final șterge rândul.

- [ ] **Step 14: Verifică emailul de confirmare**

```bash
tail -n 20 /c/laragon/www/motociclete/storage/logs/mail.log
```

Expected: ultimul mesaj are subiectul „Confirmă abonarea la newsletterul Dual Motors" și conține un URL `…/newsletter/confirmare/<32 hex>?l=…` pe o linie proprie.

- [ ] **Step 15: Commit**

```bash
cd /c/laragon/www/motociclete
git add src/Controllers/NewsletterController.php src/Routes.php src/Support/EmailTemplate.php \
  templates/newsletter/status.twig templates/newsletter/prefs.twig templates/partials/newsletter-signup.twig \
  templates/partials/footer.twig templates/layout.twig assets/css/app.css \
  tests/NewsletterPublicTest.php tests/EmailTemplateLinkTest.php
git commit -m "feat(newsletter): abonare cu confirmare, preferinte si dezabonare pe portal"
```

---

### Task 6: Pagina de admin „Abonați"

**Files:**
- Create: `src/Admin/SubscriberController.php`
- Create: `templates/admin/newsletter/subscribers.twig`
- Modify: `src/Routes.php` (după cele două rute existente `/newsletter` din grupul de admin)
- Modify: `templates/admin/newsletter/index.twig` (link către pagina nouă)

**Interfaces:**
- Consumes: `Admin\BaseController` (`requireAuth`, `csrfOk`, `render`, `to`, `body`), `$this->container['newsletter']`, `$this->container['bikershop']`, `$this->container['client']`, `Sync::gather`, `Sync::run`, `Address::clean`, `Repository::LISTS`.
- Produces — rute de admin (sub `{ADMIN_PATH}`):
  - `GET /newsletter/abonati[?q=…]` — totaluri + căutare.
  - `POST /newsletter/abonati/adauga` — `email`, `name`, `lists[]`.
  - `POST /newsletter/abonati/{id}/dezabonare` — `list`.
  - `POST /newsletter/abonati/sync` — rulează sincronizarea cu `apply = true`.
  - Mesajele de rezultat circulă prin `?msg=…` sau `?err=…` în URL.

- [ ] **Step 1: Scrie controllerul**

`src/Admin/SubscriberController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Admin;

use App\Newsletter\Address;
use App\Newsletter\Repository;
use App\Newsletter\Sync;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Admin Newsletter → Abonați: totaluri pe liste și surse, căutare după email,
 * adăugare și dezabonare manuală, sincronizare la cerere din BikerShop + My Garage.
 */
final class SubscriberController extends BaseController
{
    private const PATH = '/newsletter/abonati';

    /** GET {base}/newsletter/abonati */
    public function index(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $q    = $request->getQueryParams();
        $term = trim((string) ($q['q'] ?? ''));
        $repo = $this->repo();
        try {
            $counts  = $repo->counts();
            $results = $term !== '' ? $repo->search($term) : [];
            $dbError = null;
        } catch (Throwable) {
            $counts  = null;
            $results = [];
            $dbError = 'Tabelele de newsletter lipsesc sau baza de date nu răspunde. Rulează database/migrate_admin.php.';
        }
        return $this->render($response, 'admin/newsletter/subscribers.twig', [
            'active'  => 'newsletter',
            'lists'   => Repository::LISTS,
            'counts'  => $counts,
            'q'       => $term,
            'results' => $results,
            'msg'     => (string) ($q['msg'] ?? ''),
            'err'     => $dbError ?? (string) ($q['err'] ?? ''),
        ]);
    }

    /** POST {base}/newsletter/abonati/adauga */
    public function add(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->back($response, 'err', 'Sesiune expirată. Reîncarcă pagina.');
        }
        $email = Address::clean(isset($body['email']) ? (string) $body['email'] : null);
        if ($email === null) {
            return $this->back($response, 'err', 'Adresă invalidă sau blocată (fictivă / de marketplace).');
        }
        $lists = array_values(array_intersect(array_keys(Repository::LISTS), (array) ($body['lists'] ?? [])));
        if (!$lists) {
            return $this->back($response, 'err', 'Alege cel puțin o listă.');
        }
        $name = trim((string) ($body['name'] ?? ''));
        try {
            $repo = $this->repo();
            $sub  = $repo->ensureSubscriber($email, $name !== '' ? $name : null, 'active');
            if (in_array($sub['status'], ['bounced', 'complained'], true)) {
                return $this->back($response, 'err', "Adresa {$email} este exclusă (mesaje respinse sau reclamație de spam) și nu poate fi adăugată.", $email);
            }
            $id = (int) $sub['id'];
            if ($sub['status'] === 'pending') {
                $repo->activate($id);
            }
            foreach ($lists as $list) {
                $repo->setSubscription($id, $list, 'manual');
            }
        } catch (Throwable) {
            return $this->back($response, 'err', 'Eroare la salvare.');
        }
        return $this->back($response, 'msg', "Adresa {$email} a fost abonată.", $email);
    }

    /** POST {base}/newsletter/abonati/{id}/dezabonare */
    public function unsubscribe(Request $request, Response $response, array $args): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->back($response, 'err', 'Sesiune expirată. Reîncarcă pagina.');
        }
        $list = (string) ($body['list'] ?? '');
        if (!isset(Repository::LISTS[$list])) {
            return $this->back($response, 'err', 'Listă necunoscută.');
        }
        try {
            $repo = $this->repo();
            $sub  = $repo->find((int) ($args['id'] ?? 0));
            if ($sub === null) {
                return $this->back($response, 'err', 'Abonatul nu există.');
            }
            $repo->unsubscribe((int) $sub['id'], $list);
        } catch (Throwable) {
            return $this->back($response, 'err', 'Eroare la salvare.');
        }
        return $this->back($response, 'msg', $sub['email'] . ' a fost dezabonat de la ' . Repository::LISTS[$list] . '.', (string) $sub['email']);
    }

    /** POST {base}/newsletter/abonati/sync */
    public function sync(Request $request, Response $response): Response
    {
        if ($d = $this->requireAuth($response)) {
            return $d;
        }
        if (!$this->csrfOk($this->body($request))) {
            return $this->back($response, 'err', 'Sesiune expirată. Reîncarcă pagina.');
        }
        try {
            $sources = Sync::gather($this->container['bikershop'], $this->container['client']);
            $r = (new Sync($this->repo()))->run($sources, true);
        } catch (Throwable $e) {
            return $this->back($response, 'err', 'Sincronizarea a eșuat: ' . $e->getMessage());
        }
        if ($r['aborted'] !== null) {
            return $this->back($response, 'err', $r['aborted']);
        }
        return $this->back($response, 'msg', sprintf(
            'Listă actualizată: %d abonați noi, %d abonamente adăugate, %d dezabonați (bifă scoasă în BikerShop), %d adrese sărite.',
            $r['new_subscribers'],
            array_sum($r['added']),
            $r['unsubscribed'],
            $r['blocked'] + $r['invalid']
        ));
    }

    // ------------------------------------------------------------------

    private function repo(): Repository
    {
        return $this->container['newsletter'];
    }

    /** Redirect înapoi la pagină cu un mesaj (și, opțional, căutarea păstrată). */
    private function back(Response $response, string $kind, string $text, string $q = ''): Response
    {
        $query = http_build_query(array_filter([$kind => $text, 'q' => $q], static fn ($v) => $v !== ''));
        return $this->to($response, self::PATH . '?' . $query);
    }
}
```

- [ ] **Step 2: Scrie șablonul**

`templates/admin/newsletter/subscribers.twig`:

```twig
{% extends 'admin/layout.twig' %}
{% set active = 'newsletter' %}
{% block title %}Abonați newsletter{% endblock %}
{% block actions %}
    <a class="adm-btn" href="{{ admin_base }}/newsletter">Generator</a>
    <form method="post" action="{{ admin_base }}/newsletter/abonati/sync" style="display:inline">
        <input type="hidden" name="_csrf" value="{{ csrf }}">
        <button class="adm-btn adm-btn--primary" type="submit">Actualizează lista</button>
    </form>
{% endblock %}

{% block content %}
    {% if msg %}<div class="adm-flash adm-flash--ok">{{ msg }}</div>{% endif %}
    {% if err %}<div class="adm-flash adm-flash--err">{{ err }}</div>{% endif %}

    {% set source_names = {bs_account: 'cont BikerShop', bs_footer: 'footer BikerShop', portal: 'abonare portal', garage: 'My Garage', manual: 'adăugat manual', brevo: 'import Brevo'} %}
    {% set status_names = {pending: 'neconfirmat', active: 'activ', bounced: 'respins', complained: 'reclamație spam'} %}

    {% if counts %}
    <div class="adm-grid2" style="max-width:980px;margin-bottom:1.2rem">
        {% for key, name in lists %}
            <div class="adm-fieldset">
                <span class="adm-fieldset__t">{{ name }}</span>
                <p style="margin:.6rem 0 .3rem;font-size:1.6rem;font-weight:700">{{ counts.lists[key].active }}</p>
                <p class="adm-muted" style="margin:0 0 .5rem">destinatari activi · {{ counts.lists[key].unsubscribed }} dezabonați</p>
                <ul class="adm-help" style="margin:0 0 0 1rem">
                    {% for src, n in counts.lists[key].by_source %}
                        <li>{{ source_names[src] ?? src }}: {{ n }}</li>
                    {% else %}
                        <li>niciun abonat încă</li>
                    {% endfor %}
                </ul>
            </div>
        {% endfor %}
    </div>
    <p class="adm-muted" style="margin-bottom:1.4rem">
        Adrese în total: {{ counts.subscribers.active }} active · {{ counts.subscribers.pending }} neconfirmate ·
        {{ counts.subscribers.bounced }} respinse · {{ counts.subscribers.complained }} cu reclamație de spam.
        „Actualizează lista" aduce conturile și abonații din footerul BikerShop și proprietarii din My Garage; nu reactivează pe nimeni dezabonat.
    </p>
    {% endif %}

    <div class="adm-grid2" style="max-width:980px;margin-bottom:1.4rem">
        <form class="adm-fieldset" method="get" action="{{ admin_base }}/newsletter/abonati">
            <span class="adm-fieldset__t">Caută o adresă</span>
            <label style="margin-top:.6rem">Email (sau o parte din el) <input type="text" name="q" value="{{ q }}" required></label>
            <div class="adm-actions" style="margin:.8rem 0 0"><button class="adm-btn" type="submit">Caută</button></div>
        </form>

        <form class="adm-fieldset" method="post" action="{{ admin_base }}/newsletter/abonati/adauga">
            <input type="hidden" name="_csrf" value="{{ csrf }}">
            <span class="adm-fieldset__t">Adaugă manual</span>
            <label style="margin-top:.6rem">Email <input type="email" name="email" required></label>
            <label style="margin-top:.6rem">Nume (opțional) <input type="text" name="name"></label>
            <div style="margin-top:.6rem">
                {% for key, name in lists %}
                    <label style="display:inline-flex;gap:.4rem;align-items:center;margin-right:1rem"><input type="checkbox" name="lists[]" value="{{ key }}" checked> {{ name }}</label>
                {% endfor %}
            </div>
            <p class="adm-help" style="margin:.6rem 0 0">Adaugă doar persoane care au cerut explicit newsletterul.</p>
            <div class="adm-actions" style="margin:.8rem 0 0"><button class="adm-btn adm-btn--primary" type="submit">Abonează</button></div>
        </form>
    </div>

    {% if q %}
    <table class="adm-table">
        <thead><tr><th>Email</th><th>Nume</th><th>Stare</th>{% for key, name in lists %}<th>{{ name }}</th>{% endfor %}<th>Adăugat</th></tr></thead>
        <tbody>
        {% for s in results %}
            <tr>
                <td><strong>{{ s.email }}</strong></td>
                <td class="adm-muted">{{ s.name ?: '—' }}</td>
                <td>
                    {% if s.status == 'active' %}<span class="adm-badge adm-badge--on">activ</span>
                    {% else %}<span class="adm-badge adm-badge--off">{{ status_names[s.status] ?? s.status }}</span>{% endif %}
                </td>
                {% for key, name in lists %}
                    <td style="white-space:nowrap">
                        {% if s.subs[key] is defined %}
                            {% set sub = s.subs[key] %}
                            {% if sub.status == 'active' %}
                                <span class="adm-badge adm-badge--on">abonat</span>
                                <span class="adm-muted">{{ source_names[sub.source] ?? sub.source }}</span>
                                <form method="post" action="{{ admin_base }}/newsletter/abonati/{{ s.id }}/dezabonare" style="display:inline" onsubmit="return confirm('Dezabonezi {{ s.email|e('js') }} de la {{ name|e('js') }}?')">
                                    <input type="hidden" name="_csrf" value="{{ csrf }}">
                                    <input type="hidden" name="list" value="{{ key }}">
                                    <button class="adm-btn adm-btn--sm adm-btn--danger" type="submit">Dezabonează</button>
                                </form>
                            {% else %}
                                <span class="adm-badge adm-badge--off">dezabonat</span>
                                <span class="adm-muted">{{ sub.unsubscribed_at ? sub.unsubscribed_at|date('d.m.Y') : '' }}</span>
                            {% endif %}
                        {% else %}
                            <span class="adm-muted">—</span>
                        {% endif %}
                    </td>
                {% endfor %}
                <td class="adm-muted">{{ s.created_at|date('d.m.Y') }}</td>
            </tr>
        {% else %}
            <tr><td colspan="{{ 4 + lists|length }}" class="adm-muted">Nicio adresă nu conține „{{ q }}".</td></tr>
        {% endfor %}
        </tbody>
    </table>
    {% endif %}
{% endblock %}
```

- [ ] **Step 3: Adaugă rutele și linkul**

În `src/Routes.php`, imediat după linia `$app->post($adminBase . '/newsletter', $adminCtl('NewsletterController', 'generate'));`, adaugă:

```php
    // Newsletter — abonați (liste proprii, sincronizate din BikerShop + My Garage)
    $app->get($adminBase . '/newsletter/abonati',                          $adminCtl('SubscriberController', 'index'));
    $app->post($adminBase . '/newsletter/abonati/adauga',                  $adminCtl('SubscriberController', 'add'));
    $app->post($adminBase . '/newsletter/abonati/sync',                    $adminCtl('SubscriberController', 'sync'));
    $app->post($adminBase . '/newsletter/abonati/{id:[0-9]+}/dezabonare',  $adminCtl('SubscriberController', 'unsubscribe'));
```

În `templates/admin/newsletter/index.twig`, după linia `{% block title %}Newsletter Brevo{% endblock %}`, adaugă:

```twig
{% block actions %}<a class="adm-btn" href="{{ admin_base }}/newsletter/abonati">Abonați</a>{% endblock %}
```

- [ ] **Step 4: Verifică șablonul și paginile cu un utilizator de test**

Creează un utilizator de admin temporar, autentifică-te cu `curl` și parcurge pagina. Tokenul CSRF se ia din câmpul `_csrf` al paginii de login, apoi din `window.CSRF` al paginilor de admin. Cookie-ul de sesiune e `HttpOnly`: în jar apare ca linie `#HttpOnly_…`, nu o filtra.

```bash
cd /c/laragon/www/motociclete
TMPPASS=$(openssl rand -hex 12)   # parolă de unică folosință, generată la rulare
"$PHP" database/seed_admin_user.php __tmp_nl "$TMPPASS"
J=storage/shots/nl-admin.jar; B=http://motociclete.test/dm-control
T=$(curl -s -c $J "$B/login" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -w "login=%{http_code}\n" -b $J -c $J --data-urlencode "_csrf=$T" -d "username=__tmp_nl" --data-urlencode "password=$TMPPASS" "$B/login"
curl -s -b $J "$B/newsletter/abonati" -o storage/shots/nl-admin.html -w "pagina=%{http_code}\n"
grep -c "destinatari activi" storage/shots/nl-admin.html
C=$(grep -o 'window.CSRF="[^"]*"' storage/shots/nl-admin.html | sed 's/.*="//;s/"//')
curl -s -o /dev/null -w "adauga=%{http_code} %{redirect_url}\n" -b $J --data-urlencode "_csrf=$C" --data-urlencode "email=admin-test@nl-test.invalid" -d "lists[]=stiri" "$B/newsletter/abonati/adauga"
curl -s -o /dev/null -w "blocata=%{http_code} %{redirect_url}\n" -b $J --data-urlencode "_csrf=$C" --data-urlencode "email=guest-emag-1@bikershop.ro" -d "lists[]=stiri" "$B/newsletter/abonati/adauga"
curl -s -o /dev/null -w "fara_csrf=%{http_code} %{redirect_url}\n" -b $J --data-urlencode "email=x@nl-test.invalid" -d "lists[]=stiri" "$B/newsletter/abonati/adauga"
curl -s -b $J "$B/newsletter/abonati?q=admin-test" | grep -c "admin-test@nl-test.invalid"
```

Dacă `ADMIN_PATH` din `.env` nu este `dm-control`, înlocuiește în `B`. Numele câmpurilor de login (`username`, `password`) se verifică în `templates/admin/login.twig`.

Expected: `login=303`, `pagina=200`, `grep -c "destinatari activi"` = `2`, `adauga=303` cu `msg=` în URL, `blocata=303` cu `err=` în URL, `fara_csrf=303` cu `err=` în URL, iar ultima comandă cel puțin `1`.

Verifică în DB apoi curăță:

```bash
"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe" -uroot motociclete -e "
SELECT u.email, s.list_key, s.source, s.status FROM nl_subscribers u JOIN nl_subscriptions s ON s.subscriber_id=u.id WHERE u.email LIKE '%@nl-test.invalid';
DELETE s FROM nl_subscriptions s JOIN nl_subscribers u ON u.id=s.subscriber_id WHERE u.email LIKE '%@nl-test.invalid';
DELETE FROM nl_subscribers WHERE email LIKE '%@nl-test.invalid';
DELETE FROM admin_users WHERE username='__tmp_nl';" | tr -d '\r'
rm -f storage/shots/nl-admin.jar storage/shots/nl-admin.html
```

Expected: un rând `admin-test@nl-test.invalid | stiri | manual | active`; niciun rând pentru adresa blocată sau pentru `x@nl-test.invalid`.

- [ ] **Step 5: Testează butonul de dezabonare și sincronizarea din browser**

Autentifică-te în admin cu contul propriu, deschide `Newsletter → Abonați`:
1. Apasă „Actualizează lista": apare mesajul verde cu cifrele, iar totalurile corespund cu rularea CLI din Task 3.
2. Caută o adresă cunoscută, apasă „Dezabonează" pe o listă, confirmă: rândul arată „dezabonat" cu data de azi.
3. Apasă din nou „Actualizează lista" și caută aceeași adresă: rămâne dezabonată.

- [ ] **Step 6: Commit**

```bash
cd /c/laragon/www/motociclete
git add src/Admin/SubscriberController.php templates/admin/newsletter/subscribers.twig templates/admin/newsletter/index.twig src/Routes.php
git commit -m "feat(newsletter): pagina de admin Abonati (totaluri, cautare, adaugare, sincronizare)"
```

---

### Task 7: Retenție, documentație și pașii de livrare

**Files:**
- Modify: `database/retention.php` (constante noi + două operații, înaintea blocului `if ($errors) {`)
- Modify: `CLAUDE.md` (secțiune nouă după „Newsletter Brevo (generator YAML)")
- Create: `tests/NewsletterRetentionTest.php`

**Interfaces:**
- Consumes: tabelele `nl_subscribers`, `nl_subscriptions`; closure-ul `$op(string $label, string $countSql, string $writeSql)` din `retention.php`.
- Produces: `retention.php` șterge abonații `pending` neconfirmați după 30 de zile și golește `signup_ip` după 30 de zile.

- [ ] **Step 1: Scrie testul**

Testul rulează `retention.php --apply` ca proces separat, deci nu poate folosi tranzacția: lucrează pe rânduri `@nl-test.invalid` pe care le șterge la final.

`tests/NewsletterRetentionTest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterRetentionTest.php
 */

require __DIR__ . '/_nl.php';

$pdo = nl_db()->local();
$cleanup = static function () use ($pdo): void {
    $pdo->exec("DELETE s FROM nl_subscriptions s JOIN nl_subscribers u ON u.id = s.subscriber_id WHERE u.email LIKE '%@nl-test.invalid'");
    $pdo->exec("DELETE FROM nl_subscribers WHERE email LIKE '%@nl-test.invalid'");
};
$cleanup();
register_shutdown_function($cleanup);

$insert = $pdo->prepare(
    "INSERT INTO nl_subscribers (email, token, status, signup_ip, created_at)
     VALUES (:e, :t, :s, '10.9.9.9', NOW() - INTERVAL :d DAY)"
);
$add = static function (string $email, string $status, int $daysAgo) use ($insert): void {
    $insert->execute([':e' => $email, ':t' => bin2hex(random_bytes(16)), ':s' => $status, ':d' => $daysAgo]);
};
$add('pending-vechi@nl-test.invalid', 'pending', 45);
$add('pending-nou@nl-test.invalid', 'pending', 5);
$add('activ-vechi@nl-test.invalid', 'active', 45);
$add('activ-nou@nl-test.invalid', 'active', 5);

$php = PHP_BINARY;
$script = dirname(__DIR__) . '/database/retention.php';

$row = static function (string $email) use ($pdo): ?array {
    $s = $pdo->prepare('SELECT status, signup_ip FROM nl_subscribers WHERE email = :e');
    $s->execute([':e' => $email]);
    return $s->fetch() ?: null;
};

// --- dry-run -----------------------------------------------------------------
exec(escapeshellarg($php) . ' ' . escapeshellarg($script), $out, $code);
check('dry-run: cod de ieșire 0', $code === 0);
check('dry-run: raportează operațiile de newsletter', str_contains(implode("\n", $out), 'nl_subscribers'));
check('dry-run: nu șterge nimic', $row('pending-vechi@nl-test.invalid') !== null);

// --- apply -------------------------------------------------------------------
exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --apply', $out2, $code2);
check('apply: cod de ieșire 0', $code2 === 0);
check('pending de 45 de zile: șters', $row('pending-vechi@nl-test.invalid') === null);
check('pending de 5 zile: păstrat', $row('pending-nou@nl-test.invalid') !== null);
check('activ de 45 de zile: păstrat, IP golit',
    ($row('activ-vechi@nl-test.invalid')['status'] ?? '') === 'active' && $row('activ-vechi@nl-test.invalid')['signup_ip'] === null);
check('activ de 5 zile: IP păstrat', ($row('activ-nou@nl-test.invalid')['signup_ip'] ?? null) === '10.9.9.9');

nl_done();
```

`--apply` aplică aici și celelalte reguli de retenție pe baza **locală** (anonimizarea mesajelor vechi etc.). Este comportamentul obișnuit al scriptului; nu rula testul împotriva bazei de pe server.

- [ ] **Step 2: Rulează și verifică eșecul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterRetentionTest.php
```

Expected: eșuează `dry-run: raportează operațiile de newsletter`, `pending de 45 de zile: șters` și `activ de 45 de zile: păstrat, IP golit`.

- [ ] **Step 3: Adaugă regulile în `retention.php`**

După linia `const OTP_DAYS      = 7;    // ștergere coduri OTP` adaugă:

```php
const NL_PENDING_DAYS = 30; // ștergere abonați newsletter neconfirmați
```

Înaintea blocului `if ($errors) {` adaugă:

```php
// 5. newsletter — abonați care n-au confirmat abonarea (30 zile). Nu au abonamente
//    (se creează abia la confirmare), dar ștergem defensiv și eventualele rânduri orfane.
$op(
    'nl_subscriptions orfane pending (' . NL_PENDING_DAYS . 'z)',
    "SELECT COUNT(*) FROM nl_subscriptions s JOIN nl_subscribers u ON u.id = s.subscriber_id
     WHERE u.status = 'pending' AND u.created_at < (NOW() - INTERVAL " . NL_PENDING_DAYS . ' DAY)',
    "DELETE s FROM nl_subscriptions s JOIN nl_subscribers u ON u.id = s.subscriber_id
     WHERE u.status = 'pending' AND u.created_at < (NOW() - INTERVAL " . NL_PENDING_DAYS . ' DAY)'
);
$op(
    'nl_subscribers pending delete (' . NL_PENDING_DAYS . 'z)',
    "SELECT COUNT(*) FROM nl_subscribers WHERE status = 'pending' AND created_at < (NOW() - INTERVAL " . NL_PENDING_DAYS . ' DAY)',
    "DELETE FROM nl_subscribers WHERE status = 'pending' AND created_at < (NOW() - INTERVAL " . NL_PENDING_DAYS . ' DAY)'
);

// newsletter — IP-ul de la abonare (30 zile)
$op(
    'nl_subscribers IP (' . IP_DAYS . 'z)',
    'SELECT COUNT(*) FROM nl_subscribers WHERE created_at < (NOW() - INTERVAL ' . IP_DAYS . ' DAY) AND signup_ip IS NOT NULL',
    'UPDATE nl_subscribers SET signup_ip = NULL WHERE created_at < (NOW() - INTERVAL ' . IP_DAYS . ' DAY) AND signup_ip IS NOT NULL'
);
```

Actualizează și comentariul de la începutul fișierului: după rândul `* NU atinge \`clienti\` / \`service_requests\` (bază legală: contract).` adaugă:

```php
 * Newsletter: șterge abonații neconfirmați după 30 de zile și golește IP-ul de la
 * abonare; adresele dezabonate/respinse se PĂSTREAZĂ (lista de excluderi).
```

- [ ] **Step 4: Rulează testul**

```bash
"$PHP" /c/laragon/www/motociclete/tests/NewsletterRetentionTest.php
```

Expected: `8 verificări, 0 eșecuri`.

- [ ] **Step 5: Rulează toată suita de newsletter**

```bash
cd /c/laragon/www/motociclete
for t in NewsletterAddressTest NewsletterRepositoryTest NewsletterSyncTest NewsletterBrevoImportTest EmailTemplateLinkTest NewsletterPublicTest NewsletterRetentionTest; do
  echo "== $t"; "$PHP" tests/$t.php | tail -1
done
```

Expected: fiecare test se încheie cu `0 eșecuri`.

- [ ] **Step 6: Documentează în `CLAUDE.md`**

În `CLAUDE.md`, după secțiunea „## Newsletter Brevo (generator YAML)" (înaintea secțiunii „## Fit My Bike"), adaugă:

```markdown
## Newsletter propriu (înlocuiește Brevo pentru campanii)

Specificație: `docs/superpowers/specs/2026-10-07-newsletter-campanii-design.md`. Etapa 1 (abonați) livrată; compunerea HTML, trimiterea prin releu SMTP și statisticile sunt etape separate.

- **Două liste:** `oferte` („BikerShop oferte") și `stiri` („Dual Motors știri"). Tabele `nl_subscribers` (o adresă pe rând; stare globală `pending|active|bounced|complained`) + `nl_subscriptions` (`list_key`, `status active|unsubscribed`, `source`). Schema `database/schema_newsletter.sql`, rulată din `migrate_admin.php`. `App\Newsletter\Repository` = singurul loc care le atinge (NU înghite erorile de DB; apelanții le prind).
- **Sincronizare** `database/newsletter_sync.php` (dry-run implicit, `--apply`; cron pe server 01:30) + butonul „Actualizează lista" din admin: conturi BikerShop cu `newsletter=1` și `is_guest=0` + footer (`ps_emailsubscription`) → ambele liste; proprietari My Garage → doar `stiri`. Doar adaugă ce lipsește; un dezabonat sau un `bounced`/`complained` nu e reactivat; contul fără bifă pierde abonamentele cu sursa `bs_account`. BikerShop indisponibil sau răspuns sub jumătate din conturile active → abandon fără scrieri.
- ⚠️ **Comenzile guest și adresele fictive NU intră în liste** (`App\Newsletter\Address`): `guest-emag-…@bikershop.ro` (comenzi din eMAG), orice adresă pe `bikershop.ro`/`emag.ro`/`tfbnw.net`. Reimportul lor în Brevo (4 oct. 2026) a dus la suspendarea contului.
- **Import excluderi Brevo** (o singură dată, înainte de prima campanie): `database/newsletter_import_brevo.php <csv> --as=unsubscribed|bounced [--apply]`. CSV-urile conțin date personale → `storage/newsletter/` (gitignored), șterse după import.
- **Public:** formular în footer (`partials/newsletter-signup.twig`) → `POST /api/newsletter/abonare` → email de confirmare (prin `Support\Mailer`) → `/newsletter/confirmare/{token}?l=…`. Abonamentele se creează abia la confirmare. Preferințe/dezabonare: `/newsletter/dezabonare/{token}`; **GET nu schimbă starea** (filtrele de email deschid linkurile), dezabonarea cu un clic = POST cu `List-Unsubscribe=One-Click`. Limite: 5 abonați noi/IP/oră, un email de confirmare la 15 minute per adresă.
- **Admin:** `{base}/newsletter/abonati` (`Admin\SubscriberController`): totaluri pe liste și surse, căutare, adăugare și dezabonare manuală.
- **Emailuri text:** o linie care e doar un URL devine buton în `EmailTemplate::textToHtml()`.
- **Teste:** `tests/Newsletter*Test.php` + `tests/EmailTemplateLinkTest.php` (plain PHP, helper `tests/_nl.php`). Cele cu DB rulează într-o tranzacție pe baza locală și fac rollback; `NewsletterPublicTest` și `NewsletterRetentionTest` lucrează pe rânduri `@nl-test.invalid` pe care le șterg singure.
```

- [ ] **Step 7: Commit**

```bash
cd /c/laragon/www/motociclete
git add database/retention.php tests/NewsletterRetentionTest.php CLAUDE.md
git commit -m "feat(newsletter): retentie pentru abonati neconfirmati + documentatie"
```

- [ ] **Step 8: Livrare pe server (doar cu acordul lui Daniel; push-ul declanșează deploy pe live)**

Acești pași se fac după ce Daniel confirmă explicit livrarea. Până la etapa 3 modulul nu trimite campanii, deci livrarea etapei 1 doar populează listele și afișează formularul din footer.

1. `git push origin main`, apoi verifică pe server: `ssh dualmotors 'cd /home/dualmotors/public_html/motociclete.com.ro && git log --oneline -1'` (fă `git pull --ff-only origin main` manual dacă hook-ul nu a rulat).
2. Schema: `ssh dualmotors 'cd /home/dualmotors/public_html/motociclete.com.ro && /usr/local/bin/ea-php81 database/migrate_admin.php'`. Expected: `migrate_admin: done.`
3. Dry-run: `ssh dualmotors 'cd /home/dualmotors/public_html/motociclete.com.ro && /usr/local/bin/ea-php81 database/newsletter_sync.php'`. Verifică cifrele cu Daniel înainte de `--apply`.
4. Importul din Brevo, când Daniel furnizează exporturile CSV (dezabonați/blocați și respinși): copiază-le cu `scp` în `storage/newsletter/` pe server, rulează întâi dry-run, apoi `--apply`, apoi șterge fișierele.
5. `--apply` pentru sincronizare, apoi verifică totalurile în admin.
6. Cron de sincronizare, cu procedura sigură de editare (nu prin pipe în `crontab -`): `crontab -l > ~/crontab.now`, adaugă linia din antetul `database/newsletter_sync.php`, `crontab ~/crontab.now`, apoi compară cu o copie de rezervă.
7. Verifică pe live formularul din footer cu o adresă proprie: emailul de confirmare sosește, linkul activează abonarea, pagina de preferințe funcționează.
8. Paragraful despre newsletter din politica de confidențialitate se adaugă din admin (`Setări → Pagini`), cu textul agreat cu Daniel: ce date (email, nume, sursa și data abonării), de unde provin (cont BikerShop, formularul din footer, My Garage), cum te dezabonezi (linkul din fiecare mesaj), cât se păstrează (până la dezabonare; adresa rămâne în lista de excluderi).
