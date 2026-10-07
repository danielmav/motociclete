# Newsletter propriu — Proiectul 1: campanii

Data: 2026-10-07
Stare: design aprobat în discuție, în așteptarea revizuirii specificației

## Context

Newsletterul se trimitea din Brevo, cu modulul `sendinblue` din PrestaShop (BikerShop)
pentru sincronizarea contactelor și cu generatorul YAML din adminul portalului
(`App\Newsletter\Generator`) pentru conținut. Pe 4 octombrie 2026 modulul a fost
reconectat și contactele reimportate: lista a crescut de la ~2.500 la ~6.000 de adrese,
iar Brevo a suspendat trimiterea de campanii pentru „low metrics" pe contactele noi.

Analiza bazei BikerShop arată două surse pentru adresele noi: comenzile făcute ca
vizitator (guest), unde bifa de newsletter apare la ~90% din comenzi, deci a fost cel
mai probabil pusă implicit, și clienții fără bifă, printre care 263 de adrese fictive
`guest-emag-…@bikershop.ro` create pentru comenzile venite din eMAG (inexistente, deci
respinse la orice trimitere).

## Scop

Un modul propriu de newsletter în adminul portalului, care înlocuiește modulul Brevo
pentru campanii: liste de abonați, compunere, trimitere, dezabonare, statistici.
Singura componentă externă este un releu SMTP pentru livrare.

Succes înseamnă: o campanie în formatul celor trimise până acum pleacă din
`/dm-control/newsletter` către o listă curată, fără ca vreun destinatar să primească
mesajul de două ori, cu dezabonare funcțională și cu adresele invalide scoase automat.

## Decizii luate

| Subiect | Decizie |
|---|---|
| Unde stă modulul | Adminul portalului (`{ADMIN_PATH}/newsletter`); BikerShop e doar sursă de citire |
| Livrare | Releu SMTP extern; furnizorul se alege înainte de etapa 3 |
| Lista inițială | Conturi BikerShop cu bifa de newsletter + abonații din footerul BikerShop; **fără** comenzi guest |
| Portal | Formular de abonare nou + proprietarii din My Garage adăugați direct (decizia proprietarului firmei) |
| Expeditor | `noutati@news.motociclete.com.ro`, nume „Dual Motors", Reply-To `info@motociclete.com.ro` |
| Liste | Două: „BikerShop oferte" (`oferte`) și „Dual Motors știri" (`stiri`) |
| Statistici | Trimise, eșuate, respinse, reclamații, dezabonări, clicuri; fără urmărirea deschiderilor |
| Dezabonări | Ținute în portal; nu se scriu înapoi în PrestaShop |

Recomandări din documentul de strategie BikerShop 2027 **respinse** explicit:
sincronizarea tuturor celor ~6.300 de contacte și readucerea bifei de newsletter la 85%
(ar presupune bifă pusă implicit).

## În afara acestui proiect

- Proiectul 2: fluxuri automate (după cumpărare, consumabile la 6 luni, revenire,
  coș abandonat). Folosește infrastructura de trimitere de aici.
- Proiectul 3: modificări în BikerShop (bifa de la înregistrare, câmpul „ce motocicletă ai").
- Urmărirea deschiderilor, editor vizual, teste A/B, segmentare mai fină decât pe liste.

Modulul `sendinblue` rămâne instalat în PrestaShop până la proiectul 2 (urmărește
coșurile abandonate).

## Arhitectură

Cod nou în `src/Newsletter/`, controller de admin `Admin\NewsletterController` extins,
controller public `Controllers\NewsletterController`, scripturi cron în `database/`.

| Unitate | Rol | Depinde de |
|---|---|---|
| `Newsletter\Repository` | Singurul loc care citește/scrie tabelele `nl_*` | PDO local |
| `Newsletter\Sync` | Aduce abonații din BikerShop și My Garage | `Repository`, `BikerShop\Client`, PDO local |
| `Newsletter\Content` | Rezolvă datele unui mesaj (știre, modele, produse, prețuri) din formular | `Catalog\Repository`, `BikerShop\Client` |
| `Newsletter\Renderer` | Produce HTML-ul și varianta text din `Content` | Twig |
| `Newsletter\Links` | Adaugă UTM și rescrie linkurile pentru numărarea clicurilor | `Repository` |
| `Newsletter\Transport` | Trimite un mesaj prin releu (PHPMailer SMTP) | config `.env` |
| `Newsletter\Sender` | Procesează coada: tranșe, limite, pauză automată | `Repository`, `Transport` |
| `Newsletter\Webhook\Provider` | Interfață: traduce notificarea releului în evenimente | — |

`Newsletter\Content` se extrage din `Generator` existent. Generatorul YAML rămâne
funcțional până când prima campanie reală pleacă din modulul nou, apoi se retrage
împreună cu `storage/newsletter/parts/`.

Trimiterea de campanii **nu** trece prin `Support\Mailer`: acela salvează corpul
fiecărui email în `email_log`, ceea ce ar însemna mii de copii per campanie. `Mailer`
se folosește doar pentru emailul de confirmare a abonării.

## Date

Fișier nou `database/schema_newsletter.sql` (`CREATE TABLE IF NOT EXISTS`), rulat din
`migrate_admin.php`.

- **`nl_subscribers`** — o adresă pe rând: `email` (normalizat, unic), `name`, `token`
  (unic, pentru linkurile publice), `status` (`pending`, `active`, `bounced`,
  `complained`), `soft_bounces`, `created_at`, `confirmed_at`.
- **`nl_subscriptions`** — apartenența la liste: `subscriber_id`, `list` (`oferte`,
  `stiri`), `status` (`active`, `unsubscribed`), `source` (`bs_account`, `bs_footer`,
  `portal`, `garage`, `manual`, `brevo`), `subscribed_at`, `unsubscribed_at`. Cheie
  primară `(subscriber_id, list)`.
- **`nl_campaigns`** — `list`, `type` (`stiri`, `oferte`), `subject`, `preheader`,
  `slug`, `input_json` (formularul), `html`, `text`, `status` (`draft`, `queued`,
  `sending`, `paused`, `sent`), `pause_reason`, date de creare/pornire/terminare.
- **`nl_sends`** — un rând per destinatar per campanie: `status` (`queued`, `sent`,
  `failed`, `bounced`, `complained`), `attempts`, `message_id`, `error`, `sent_at`.
  `UNIQUE (campaign_id, subscriber_id)` garantează că nimeni nu primește de două ori.
- **`nl_links`** — linkurile unei campanii: `url` final (cu UTM), `block`, `position`.
- **`nl_clicks`** — `link_id`, `subscriber_id`, `clicked_at`.
- **`nl_events`** — jurnalul brut al notificărilor de la releu, pentru diagnostic.

Starea globală (`bounced`, `complained`) stă pe abonat și îl exclude din toate listele.
Dezabonarea stă pe abonament și privește o singură listă.

## Abonați

### Repartizarea pe liste

| Sursă | `oferte` | `stiri` |
|---|---|---|
| Cont BikerShop cu `newsletter=1` (`is_guest=0`, `active=1`, `deleted=0`, shop 1) | da | da |
| Footer BikerShop (`ps_emailsubscription`, `active=1`) | da | da |
| Proprietar My Garage (`clienti` cu `email_norm` completat) | nu | da |
| Abonare de pe portal | la alegerea abonatului | la alegerea abonatului |

### Sincronizare

`database/newsletter_sync.php` (dry-run implicit, `--apply`), rulat de cron noaptea și
de butonul „Actualizează lista" din admin. Reguli:

- Adaugă doar abonamente care lipsesc. Un abonament `unsubscribed` nu se reactivează.
- Un abonat `bounced` sau `complained` nu se reactivează.
- Dacă un cont BikerShop nu mai are `newsletter=1`, abonamentele lui cu sursa
  `bs_account` devin `unsubscribed`.
- Comenzile guest nu se importă deloc.
- Adresele fictive nu se importă, indiferent de bifă: cele generate pentru comenzile
  din marketplace (`guest-emag-…@bikershop.ro`, 263 la data scrierii), orice adresă pe
  domeniile proprii (`bikershop.ro`, `motociclete.com.ro`) sau pe `emag.ro`, și
  domeniile de test (`tfbnw.net`). Lista de tipare stă într-o constantă în
  `Newsletter\Sync`; aceeași verificare se aplică la abonarea de pe portal și la
  adăugarea manuală.
- Dacă BikerShop nu răspunde, sincronizarea se oprește fără modificări (nu
  interpretează lipsa datelor ca dezabonare în masă).

### Import unic din Brevo

Înainte de prima campanie se importă din Brevo (export CSV) contactele dezabonate,
blocate și cu hard bounce, ca excluderi (`source = brevo`). Fără acest pas am scrie
unor oameni care s-au dezabonat deja. Script `database/newsletter_import_brevo.php`.

### Abonare pe portal

- Formular în footer: email + alegerea listelor + textul de acord. `POST
  /api/newsletter/abonare`, cu honeypot și limită de cereri pe IP.
- Abonatul intră ca `pending` și primește un email de confirmare; linkul
  `/newsletter/confirmare/{token}` îl activează.
- Bifă opțională, nebifată implicit, pe formularele de ofertă/contact/service:
  declanșează același flux cu confirmare.
- În My Garage, utilizatorul autentificat își gestionează abonamentele direct
  (emailul e deja verificat prin OTP).

### Dezabonare

- `GET /newsletter/dezabonare/{token}`: pagină cu cele două liste și starea fiecăreia.
- `POST` pe aceeași adresă cu `?l=<listă>`: dezabonare cu un clic, cerută de
  Gmail/Yahoo (headerele `List-Unsubscribe` și `List-Unsubscribe-Post`).
- Linkul din mesaj dezabonează direct de la lista campaniei și oferă opțiunea de a
  renunța și la cealaltă.

### Admin

Pagina „Abonați": totaluri pe liste și surse, căutare după email, adăugare și
dezabonare manuală, istoricul unei adrese (sursă, campanii primite, evenimente).

## Compunere

Două șabloane Twig în `templates/email/newsletter/`, HTML pe tabele, stiluri inline,
lățime 600 px, lizibile la 390 px:

- **`stiri`** — formatul campaniilor de până acum (referință:
  `documente/newsletter/brevo-mirror-agv-k5.html`): antet cu linkuri de categorii,
  știre, 2 modele, 6 produse, blocuri fixe, footer cu datele firmei.
- **`oferte`** — centrat pe produse BikerShop: titlu scurt, grilă de produse cu preț
  redus, un buton către magazin, același footer.

Formularul din admin rămâne cel existent, cu alegerea tipului și a listei, subiect și
preheader. Funcții: previzualizare, trimitere de test la o adresă, pagină publică
„vezi în browser" la `/newsletter/{id}-{slug}` (`noindex`).

### Prețuri

Problemă existentă: generatorul afișează prețul de listă și ignoră reducerile.

- **Modele (portal):** preț curent + preț vechi tăiat + procent, calculate din
  `products.price` și `products.discount_pct`, la fel ca pe pagina produsului.
- **Produse BikerShop:** `BikerShop\Client::shapeProduct` primește în plus prețul vechi
  și procentul, citite din reducerile magazinului. Corecția se vede și pe cardurile de
  accesorii de pe portal. Cum sunt reprezentate reducerile în baza BikerShop (regulile
  din `ps_specific_price` față de câmpurile `special|rrp` ale modulului supplierpricing)
  se verifică pe date reale la începutul implementării; primul pas din plan.
- Suprascrierea manuală a prețului din formular rămâne.

### Imagini

Imaginile produselor BikerShop se copiază la generare în `/media/newsletter/`,
redimensionate la cel mult 600 px lățime, pentru că Cloudflare le poate bloca în
clienții de email.

### Linkuri

- UTM pe orice link către `motociclete.com.ro` și `bikershop.ro`:
  `utm_source=newsletter`, `utm_medium=email`, `utm_campaign=<slug campanie>`,
  `utm_content=<bloc>-<poziție>`.
- Linkurile sunt înlocuite cu `/nl/c/{link}/{token}`, care înregistrează clicul și
  redirecționează **doar** către URL-ul salvat în `nl_links` (fără parametru de
  destinație în URL, deci fără redirect deschis).
- HTML-ul campaniei se salvează o singură dată, cu marcaje pentru partea
  personalizată (linkul de dezabonare, adresa destinatarului, linkurile de clic),
  completate la trimitere.

## Trimitere

1. „Trimite" arată numărul de destinatari ai listei și cere confirmare. La confirmare
   se creează rândurile `nl_sends` și campania devine `queued`.
2. `database/newsletter_send.php`, cron la 5 minute, cu fișier de blocare: ia o tranșă
   de rânduri `queued`, trimite prin `Transport`, marchează fiecare rând.
3. Limite setabile din admin: mesaje pe rulare și mesaje pe zi. Limita pe zi servește
   la încălzirea subdomeniului nou (creștere treptată în primele campanii).
4. Erori temporare de SMTP: rândul rămâne `queued`, cu cel mult 3 încercări, apoi
   `failed`.
5. Pauză automată, cu motivul afișat în admin, când: 20 de eșecuri consecutive, rata de
   respingere peste 5% sau rata de reclamații peste 0,3% după cel puțin 200 de mesaje.
6. Pauză și reluare manuală din admin.

În `APP_ENV=dev` sau fără releu configurat, `Transport` scrie în
`storage/logs/newsletter.log` și nu trimite nimic.

Configurare în `.env`: `NL_SMTP_HOST`, `NL_SMTP_PORT`, `NL_SMTP_USER`, `NL_SMTP_PASS`,
`NL_SMTP_SECURE`, `NL_FROM`, `NL_FROM_NAME`, `NL_REPLY_TO`, `NL_WEBHOOK_SECRET`.

## Respingeri și reclamații

`POST /api/newsletter/webhook/{secret}` primește notificările releului. Interfața
`Webhook\Provider` le traduce în evenimente (`bounce_hard`, `bounce_soft`,
`complaint`); există o singură implementare, pentru furnizorul ales.

- Respingere definitivă: abonatul devine `bounced`.
- Reclamație de spam: abonatul devine `complained`.
- Respingere temporară: se numără; la 3 campanii consecutive devine `bounced`.
- Fiecare notificare se salvează în `nl_events`. Un secret greșit răspunde 404.

## Statistici

Pe pagina campaniei: destinatari, trimise, eșuate, respinse, reclamații, dezabonări,
clicuri unice, cele mai accesate linkuri. Calculate din `nl_sends` și `nl_clicks`.

## GDPR

- `retention.php` șterge: clicurile mai vechi de 12 luni, `nl_events` mai vechi de 90
  de zile, abonații `pending` neconfirmați după 30 de zile.
- Adresele dezabonate, respinse sau cu reclamație se păstrează ca listă de excluderi.
- Un paragraf despre newsletter în politica de confidențialitate (pagină editabilă din
  admin): ce date, de unde, cum te dezabonezi, releul ca împuternicit.
- Sursa și data fiecărui abonament rămân înregistrate ca dovadă.

## Condiții externe

- Cont la furnizorul de releu, cu drept de trimitere în producție.
- Înregistrări DNS pentru `news.motociclete.com.ro`: SPF, DKIM, DMARC și cele cerute de
  releu pentru domeniul de retur.
- Exportul din Brevo al contactelor dezabonate, blocate și respinse.

## Verificare

Scripturi în `tests/` și verificări manuale, după tiparul proiectului:

- Sincronizare în dry-run: totalurile pe surse corespund interogărilor directe; nicio
  adresă guest.
- Randare: ambele șabloane produc HTML valid pentru un set fix de date; capturi la
  600 px și 390 px; prețurile reduse apar corect pentru un model și un produs cu
  reducere cunoscută.
- Fluxul abonare → confirmare → dezabonare, cu `curl`, inclusiv dezabonarea cu un clic.
- Trimitere în modul dev către o listă de test: fiecare destinatar apare o singură dată
  în jurnal; pauza și reluarea nu dublează mesaje.
- Redirectul de clic nu acceptă destinații care nu sunt în `nl_links`.
- Webhook: notificări de test pentru fiecare tip de eveniment schimbă starea corectă.
- Prima campanie reală: întâi către adresele echipei, apoi cu limită zilnică mică.

## Etape

1. Schemă, abonați, sincronizare, import Brevo, abonare și dezabonare.
2. Compunere: `Content`, `Renderer`, prețuri cu reducere, linkuri cu UTM, test, pagină publică.
3. Coadă de trimitere, releu, webhook.
4. Statistici, clicuri, retenție.

Fiecare etapă se poate livra și verifica separat. Prima campanie reală pleacă după
etapa 3.
