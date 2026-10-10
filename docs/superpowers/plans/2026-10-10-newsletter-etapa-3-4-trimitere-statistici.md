# Newsletter etapele 3–4: coadă de trimitere, webhook, clicuri, statistici

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O campanie salvată poate fi pusă la trimis către lista ei, procesată în tranșe de un cron, cu pauză automată, respingeri/reclamații primite prin webhook (Amazon SES), clicuri numărate și statistici în admin — totul verificabil local, fără releul real.

**Architecture:** `Newsletter\Sends` e singurul loc care atinge `nl_sends` și tranzițiile de stare ale campaniei; `Newsletter\Sender` procesează coada printr-un callable de trimitere (în producție `Transport`, în teste un fals). `Newsletter\Tracking` ține `nl_links`/`nl_clicks`; rescrierea linkurilor se face la trimitere, HTML-ul salvat rămâne neschimbat. `Webhook\Ses` traduce notificările SNS în evenimente, `Newsletter\Feedback` le aplică.

**Tech Stack:** PHP 8.1, Slim 4, PDO (MySQL 8 local / MariaDB live), PHPMailer, Twig. Teste plain PHP (`tests/_nl.php`).

**Spec:** `docs/superpowers/specs/2026-10-07-newsletter-campanii-design.md` (secțiunile Trimitere, Respingeri și reclamații, Statistici, GDPR).

## Global Constraints

- Nimeni nu primește o campanie de două ori: `UNIQUE (campaign_id, subscriber_id)` + trimitere „cel mult o dată” (rândul trece în `sending` ÎNAINTE de SMTP; un rând rămas în `sending` nu se retrimite).
- Niciun GET nu schimbă starea abonamentului. (Clicul înregistrează doar un rând în `nl_clicks`.)
- Trimiterea către listă e oprită cât timp `NL_SEND_ENABLED` nu e `1` (în `APP_ENV=dev` e permisă: `Transport` scrie doar în jurnal). Fără gardă, configurarea de rezervă `SMTP_*` ar trimite campania prin serverul sitului.
- Schema: `CREATE TABLE IF NOT EXISTS` în `database/schema_newsletter.sql` + `ensure_column` în `migrate_admin.php` (MySQL 8 nu are `ADD COLUMN IF NOT EXISTS`). Colație `utf8mb4_unicode_ci`.
- PDO fără emulare: un placeholder numit nu se repetă; coloanele numerice vin ca string → `(int)`.
- Erorile de DB nu se înghit în clasele `Newsletter\*`; le prind controllerele/CLI.
- Scripturile CLI: dry-run implicit, `--apply` execută. Testele: gardă `PHP_SAPI !== 'cli'` (prin `_nl.php`).
- Texte de interfață în română, cu diacritice.

## Review Focus

1. Abonat dezabonat / respins între punerea la trimis și trimiterea efectivă → nu primește mesajul (rând `skipped`). Test în `NewsletterSenderTest`.
2. Proces întrerupt în timpul trimiterii → la rularea următoare rândul rămas `sending` devine `failed`, nu se retrimite. Test în `NewsletterSenderTest`.
3. „Pune la trimis” apăsat de două ori (sau două cereri simultane) → a doua e refuzată, fără rânduri duble. Test în `NewsletterSendsTest`.
4. Webhook cu JSON stricat, tip necunoscut sau adresă care nu e abonat → răspuns 200, eveniment jurnalizat, nicio stare schimbată; secret greșit sau gol → 404. Test în `NewsletterWebhookTest`.
5. Link de clic cu token necunoscut → redirecționează, dar nu înregistrează clic; link inexistent → 404; `&amp;` din `href` se potrivește corect la rescriere. Test în `NewsletterTrackingTest`.

---

## File Structure

| Fișier | Rol |
|---|---|
| `database/schema_newsletter.sql` (modific) | Tabele noi: `nl_sends`, `nl_links`, `nl_clicks`, `nl_events` |
| `database/migrate_admin.php` (modific) | `nl_campaigns.fail_streak`, `nl_subscriptions.unsub_campaign_id` |
| `src/Newsletter/Sends.php` (nou) | Coada: punere la trimis, tranșe, marcaje, pauză/reluare/anulare, sănătate, statistici, istoric |
| `src/Newsletter/Sender.php` (nou) | O rulare a cozii: limite, personalizare, headere, pauză automată |
| `src/Newsletter/Links.php` (modific) | + `hrefs()` și `tracked()` (funcții pure) |
| `src/Newsletter/Tracking.php` (nou) | `nl_links` + `nl_clicks` |
| `src/Newsletter/Webhook/Provider.php`, `Webhook/Ses.php` (noi) | Notificare releu → evenimente |
| `src/Newsletter/Feedback.php` (nou) | Aplică evenimentele (abonat, rând de trimitere, `nl_events`) |
| `src/Newsletter/Transport.php` (modific) | + `lastMessageId()` |
| `src/Newsletter/Repository.php` (modific) | `unsubscribe()` primește campania |
| `src/Controllers/NewsletterTrackController.php` (nou) | `GET /nl/c/{link}/{token}`, `POST /api/newsletter/webhook/{secret}` |
| `src/Controllers/NewsletterController.php` (modific) | `?c=` la dezabonare |
| `src/Admin/CampaignSendController.php` (nou) | Pune la trimis, pauză, reluare, anulare, limite |
| `src/Admin/CampaignController.php`, `SubscriberController.php` (modific) | Statistici pe pagina campaniei; istoricul unei adrese |
| `templates/admin/newsletter/*.twig` (modific) | Panou de trimitere + statistici, limite, istoric |
| `database/newsletter_send.php` (nou) | Cron la 5 minute, cu fișier de blocare |
| `database/retention.php` (modific) | Clicuri > 12 luni, `nl_events` > 90 de zile |
| `config/settings.php`, `src/Bootstrap.php`, `src/Routes.php` (modific) | Config, container, rute |
| `tests/Newsletter{Sends,Sender,Tracking,Webhook}Test.php` (noi) | |

## Interfețe

```php
// Stări nl_sends: queued, sending, sent, failed, skipped, soft_bounced, bounced, complained
final class Sends {
    public const MAX_ATTEMPTS = 3;
    public const FAIL_STREAK_PAUSE = 20;
    public const HEALTH_MIN_SENT = 200; // rate verificate abia de aici
    public const MAX_BOUNCE_RATE = 0.05;
    public const MAX_COMPLAINT_RATE = 0.003;
    public function __construct(Database $db);
    public function recipientCount(string $list): int;
    public function enqueue(int $campaignId): int;               // RuntimeException dacă nu e ciornă / fără HTML / 0 destinatari
    public function pause(int $campaignId, string $reason): bool;
    public function resume(int $campaignId): bool;
    public function cancel(int $campaignId): int;                // rândurile queued → skipped; campania → sent
    public function activeCampaigns(): array;                    // queued|sending, cele mai vechi primele, cu html/body_text
    public function batch(int $campaignId, int $limit, array $excludeIds = []): array; // + email, token, sub_status, list_status
    public function claim(int $sendId): bool;                    // queued → sending, attempts+1
    public function markSent(int $sendId, ?string $messageId): void;
    public function markRetry(int $sendId, string $error): string; // 'queued' | 'failed'
    public function markSkipped(int $sendId, string $reason): void;
    public function failStreak(int $campaignId, bool $failed): int;
    public function releaseStale(int $minutes): int;             // sending vechi → failed
    public function finishIfDone(int $campaignId): bool;
    public function sentLast24h(): int;
    public function health(int $campaignId): ?string;            // motivul de pauză sau null
    public function stats(int $campaignId): array;
    public function findForFeedback(?string $messageId, ?int $subscriberId): ?array;
    public function markFeedback(int $sendId, string $status): void; // soft_bounced|bounced|complained
    public function lastOutcomes(int $subscriberId, int $n): array;  // ultimele stări livrate
    public function history(int $subscriberId, int $limit = 20): array;
}

final class Sender {
    /** @param callable(string $to,string $subject,string $html,string $text,array $headers): array{ok:bool,error:string,message_id:?string} $send */
    public function __construct(Sends $sends, Tracking $tracking, callable $send, string $siteUrl, ?callable $pause = null);
    /** @return array{sent:int,failed:int,skipped:int,retry:int,paused:array<int,string>,finished:array<int,int>,stale:int} */
    public function run(int $perRun, int $perDay): array;
    public static function headers(string $unsubUrl): array;     // List-Unsubscribe + List-Unsubscribe-Post
}

final class Links { // adăugiri
    public static function hrefs(string $html): array;                               // URL-urile http(s) distincte, decodate
    public static function tracked(string $html, array $map, string $prefix): string; // href → {prefix}/{id}/%%TOKEN%%
}

final class Tracking {
    public function __construct(Database $db);
    public function register(int $campaignId, string $html): array; // url => link id (idempotent)
    public function link(int $linkId): ?array;
    public function click(int $linkId, ?int $subscriberId): void;
    public function uniqueClicks(int $campaignId): int;
    public function topLinks(int $campaignId, int $limit = 15): array;
}

interface Webhook\Provider {
    /** @return array{confirm_url:?string, events:array<int,array{type:string,email:string,message_id:?string,detail:string}>} */
    public function parse(string $body): array; // type: bounce_hard|bounce_soft|complaint|delivery
}

final class Feedback {
    public const SOFT_LIMIT = 3;
    public function __construct(Database $db, Repository $repo, Sends $sends);
    public function log(string $provider, string $type, ?string $email, ?string $messageId, string $payload): void;
    public function apply(array $event): string; // ce s-a întâmplat (pentru jurnal/teste)
}
```

Setări (`settings`): `nl_batch_size` (implicit 50), `nl_daily_limit` (implicit 200). `.env`: `NL_SEND_ENABLED`, `NL_WEBHOOK_SECRET`, `NL_SEND_DELAY_MS` (implicit 100).

---

### Task 1: Schemă + `Sends` (coada)

**Files:** `database/schema_newsletter.sql`, `database/migrate_admin.php`, `src/Newsletter/Sends.php`, `tests/_nl.php` (golește și tabelele noi), `tests/NewsletterSendsTest.php`

- [ ] Test: `enqueue` ia doar abonații `active` cu abonament `active` pe lista campaniei (nu pending/bounced/complained/dezabonați/altă listă); a doua chemare aruncă și nu dublează; campanie fără HTML sau cu 0 destinatari aruncă și rămâne ciornă.
- [ ] Test: `claim` reușește o singură dată; `markRetry` → `queued` de două ori, a treia oară `failed`; `releaseStale`; `finishIfDone`; `pause`/`resume`/`cancel`; `failStreak`; `health` (sub 200 trimise → null; 6% respinse → motiv; 0,5% reclamații → motiv); `stats`; `sentLast24h`.
- [ ] Schema + `ensure_column`, rulează `migrate_admin.php` local, implementează, rulează testul, commit.

### Task 2: Linkuri urmărite (`Links`, `Tracking`) + ruta de clic

**Files:** `src/Newsletter/Links.php`, `src/Newsletter/Tracking.php`, `src/Controllers/NewsletterTrackController.php`, `src/Routes.php`, `src/Bootstrap.php`, `tests/NewsletterTrackingTest.php`, `tests/NewsletterLinksTest.php`

- [ ] Test: `hrefs` ignoră `mailto:`/`tel:`/marcajele `%%…%%`, decodează `&amp;`, elimină dublurile; `tracked` rescrie doar URL-urile din hartă; `register` e idempotent și scoate `block` din `utm_content`; `click` + `uniqueClicks` + `topLinks`.
- [ ] Ruta `GET /nl/c/{link:[0-9]+}/{token:[a-f0-9]{32}}`: 302 către URL-ul din `nl_links`, `X-Robots-Tag: noindex`; token necunoscut → redirecționează fără clic; link inexistent → 404. Verificare cu `curl` pe situl local.
- [ ] Commit.

### Task 3: `Sender` + `Transport::lastMessageId()` + CLI

**Files:** `src/Newsletter/Sender.php`, `src/Newsletter/Transport.php`, `database/newsletter_send.php`, `config/settings.php`, `tests/NewsletterSenderTest.php`

- [ ] Test (transport fals care înregistrează apelurile): fiecare destinatar o singură dată pe două rulări consecutive; limita pe rulare și cea pe 24 h; headerele `List-Unsubscribe` (cu `?l=<listă>&c=<id>`) și `List-Unsubscribe-Post`; marcajele personalizate și linkurile rescrise cu tokenul destinatarului; eșec → reîncercare la rularea următoare, `failed` după 3; 20 de eșecuri la rând → campanie `paused` cu motiv; dezabonat între timp → `skipped`; rând `sending` vechi → `failed`, fără retrimitere; campania terminată → `sent` + `finished_at`; campanie `paused` nu trimite; reluarea nu dublează.
- [ ] CLI: fișier de blocare (`flock` pe `storage/cache/newsletter_send.lock`), refuz dacă trimiterea nu e activată, dry-run implicit, raport pe o linie cu dată.
- [ ] Commit.

### Task 4: Webhook (`Webhook\Ses`, `Feedback`) + rută

**Files:** `src/Newsletter/Webhook/Provider.php`, `src/Newsletter/Webhook/Ses.php`, `src/Newsletter/Feedback.php`, `src/Controllers/NewsletterTrackController.php`, `src/Routes.php`, `tests/NewsletterWebhookTest.php`

- [ ] Test `Ses::parse`: `SubscriptionConfirmation` (URL acceptat doar pe `https://sns.<regiune>.amazonaws.com`), `Notification` cu Bounce Permanent / Transient, Complaint, Delivery, formatul „event publishing” (`eventType`), mai mulți destinatari într-o notificare, JSON stricat → fără evenimente.
- [ ] Test `Feedback::apply`: respingere definitivă → abonat `bounced` + rând `bounced`; reclamație → `complained`; temporară → rând `soft_bounced`, abonatul devine `bounced` abia la a treia campanie consecutivă; adresă necunoscută → nimic; potrivire după `message_id`, apoi după adresă; depășirea ratei pune campania în pauză.
- [ ] Ruta `POST /api/newsletter/webhook/{secret}`: `hash_equals` cu `NL_WEBHOOK_SECRET`, secret gol/greșit → 404; orice corp → 200 + rând în `nl_events`. Verificare cu `curl`.
- [ ] Commit.

### Task 5: Admin — trimitere, limite, statistici, istoric + dezabonări pe campanie

**Files:** `src/Admin/CampaignSendController.php`, `src/Admin/CampaignController.php`, `src/Admin/SubscriberController.php`, `src/Controllers/NewsletterController.php`, `src/Newsletter/Repository.php`, `templates/admin/newsletter/{campaign_form,campaigns,subscribers}.twig`, `templates/newsletter/prefs.twig`, `src/Routes.php`

- [ ] `Repository::unsubscribe($id, $list, ?int $campaignId = null)`; `prefs`/`prefsSave` duc `c` mai departe (inclusiv la dezabonarea cu un clic). Test în `NewsletterRepositoryTest`.
- [ ] Pagina campaniei: pentru ciornă — numărul de destinatari + „Pune la trimis” (bifă de confirmare; dezactivat cu explicație dacă trimiterea nu e activată); pentru celelalte — statistici, cele mai accesate linkuri, pauză/reluare/oprire definitivă, motivul pauzei.
- [ ] Lista de campanii: limitele (mesaje pe rulare / pe 24 h), câte au plecat în ultimele 24 h, starea trimiterii (activată / doar jurnal / oprită).
- [ ] Abonați: sub fiecare rezultat al căutării, campaniile primite și starea lor.
- [ ] Verificare în browser (puppeteer/curl cu sesiune) pe situl local: flux complet ciornă → la trimis → cron local → statistici. Commit.

### Task 6: Retenție, documentație, verificare finală

**Files:** `database/retention.php`, `tests/NewsletterRetentionTest.php`, `tests/run_newsletter_suite.sh`, `CLAUDE.md`, `.env.example` (dacă există)

- [ ] Retenție: `nl_clicks` mai vechi de 12 luni, `nl_events` mai vechi de 90 de zile. Test.
- [ ] Toată suita trece; verificare independentă a diff-ului (un singur recenzent), corecturi, commit, push.

## Ce rămâne pentru după contul SES (nu face parte din plan)

- `NL_SMTP_*`, `NL_FROM`, `NL_WEBHOOK_SECRET`, `NL_SEND_ENABLED=1` în `.env`-ul de pe server; DNS pentru `news.motociclete.com.ro`.
- Abonamentul SNS → webhook; verificat că `Transport::lastMessageId()` întoarce ID-ul SES.
- Cronul `*/5 * * * * … newsletter_send.php --apply` pe server.
- Prima campanie: întâi către adresele echipei, apoi cu limită zilnică mică.
