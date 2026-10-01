<?php

declare(strict_types=1);

/**
 * Sincronizare motociclete CFMOTO din feed-ul importatorului (ATVROM) — logica în
 * App\Cfmoto\FeedSync. Feed-ul e sursa de adevăr pentru prețuri.
 *
 *   --prices          actualizează prețul/reducerea/variantele produselor legate (cron zilnic, noaptea)
 *   --new             adaugă produsele noi din feed ca INACTIVE + email de notificare (cron săptămânal)
 *   --apply           scrie în DB (fără = dry-run, doar raport)
 *   --link=PID:FEED   leagă manual produsul PID de rândul (rândurile, „FEED,FEED" = variante) din feed;
 *                     FEED=0 → dezleagă
 *   --feed=FILE       citește feed-ul dintr-un fișier local (test) în loc de URL
 *   --no-mail         nu trimite email (doar raport în consolă)
 *
 * Ambele moduri leagă întâi automat produsele nelegate (nume + an) și completează codul
 * de produs lipsă (referința BikerShop sau generat). Produsele legate care lipsesc din
 * feed sunt doar raportate (în email la --new), nu dezactivate.
 * Destinatarii emailului: FEED_NOTIFY_EMAIL (listă separată prin virgulă; fallback MAIL_DEALER).
 *
 * Local: C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/sync_cfmoto_feed.php --prices
 * Cron (server, ea-php81):
 *   15 2 * * *  .../database/sync_cfmoto_feed.php --prices --apply
 *   30 2 * * 1  .../database/sync_cfmoto_feed.php --new --apply
 */

use App\BikerShop\Client;
use App\Cfmoto\FeedSync;
use App\Content\Repository as Content;
use App\Database;
use App\Support\Mailer;
use App\Support\Settings;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
$settings = require $root . '/config/settings.php';

$opt = getopt('', ['prices', 'new', 'apply', 'link:', 'feed:', 'no-mail']);
$apply = isset($opt['apply']);
$doPrices = isset($opt['prices']);
$doNew = isset($opt['new']);
if (!$doPrices && !$doNew && !isset($opt['link'])) {
    fwrite(STDERR, "Folosire: sync_cfmoto_feed.php --prices|--new|--link=PID:FEED[,FEED] [--apply] [--feed=FILE] [--no-mail]\n");
    exit(2);
}

$db = new Database($settings['db']);
$pdo = $db->local();
$sync = new FeedSync($pdo, $root . '/media');

echo '[' . date('Y-m-d H:i:s') . '] ' . ($apply ? 'APPLY' : 'DRY-RUN (fără --apply nu se scrie nimic)') . "\n";

// --- legare manuală -----------------------------------------------------------
if (isset($opt['link'])) {
    if (!preg_match('/^(\d+):([\d,]+)$/', (string) $opt['link'], $m)) {
        fwrite(STDERR, "--link așteaptă PID:FEED[,FEED] (ex. --link=173:186369,258880)\n");
        exit(2);
    }
    $ids = array_values(array_filter(FeedSync::ids($m[2])));
    echo "Leg produsul #{$m[1]} de feed " . ($ids ? '#' . implode(', #', $ids) : '(dezlegat)') . "\n";
    if ($apply) {
        $sync->link((int) $m[1], $ids);
    }
    if (!$doPrices && !$doNew) {
        exit(0);
    }
}

// --- feed -----------------------------------------------------------------------
try {
    $csv = isset($opt['feed']) ? (string) file_get_contents((string) $opt['feed']) : FeedSync::download();
    $feed = FeedSync::parse($csv);
} catch (Throwable $e) {
    fwrite(STDERR, 'EROARE feed: ' . $e->getMessage() . "\n");
    exit(1);
}
// Plasă de siguranță: un feed trunchiat/gol nu are voie să pară „toate modelele au dispărut".
if (count($feed) < 5) {
    fwrite(STDERR, 'EROARE: feed-ul are doar ' . count($feed) . " motociclete CFMOTO — opresc.\n");
    exit(1);
}
echo 'Feed: ' . count($feed) . " motociclete CFMOTO/CFLITE noi\n";

// Codurile de produs existente pe BikerShop (refolosite ca `sku`).
$bs = new Client($db, $settings['db']['bikershop']);
$bsRows = array_merge($bs->productsByReferencePrefix('cfmoto-'), $bs->productsByReferencePrefix('cflite-'));
$sync->setBikershopRefs($bsRows);
echo $bs->isAvailable() ? 'BikerShop: ' . count($bsRows) . " referințe cfmoto-*/cflite-*\n" : "AVERTISMENT: BikerShop indisponibil — codurile lipsă se generează (fără referința BikerShop)\n";

// --- legare automată ------------------------------------------------------------
$products = $sync->products();
$prop = $sync->proposeLinks($feed, $products);
foreach ($prop['links'] as $pid => $fids) {
    $titles = implode(' / ', array_map(static fn ($id) => "#{$id} {$feed[$id]['title']}", $fids));
    echo sprintf("  legat: #%d %-40s ↔ %s\n", $pid, nameOf($products, $pid), $titles);
    if ($apply) {
        $sync->link($pid, $fids);
    }
}
foreach ($prop['ambiguous'] as $pid => $cands) {
    echo sprintf("  AMBIGUU: #%d %s → %s (leagă manual cu --link)\n", $pid, nameOf($products, $pid), $cands);
}
// Re-citește (sau simulează legăturile în dry-run).
$products = $apply ? $sync->products() : array_map(
    static fn ($p) => isset($prop['links'][(int) $p['id']]) ? ['ids' => $prop['links'][(int) $p['id']]] + $p : $p,
    $products
);
$unlinked = array_filter($products, static fn ($p) => !$p['ids']);

// --- coduri de produs lipsă ------------------------------------------------------
$skus = 0;
// Coduri unice: cele existente + cele atribuite acum (produsele active vin primele → păstrează codul curat).
$used = array_flip(array_filter(array_map(static fn ($p) => strtolower(trim((string) $p['sku'])), $products)));
foreach ($products as $p) {
    if (trim((string) $p['sku']) === '') {
        // Anul lipsă pe site (ex. „450SR ABS") → anul din rândul de feed legat.
        if (!$p['year'] && $p['ids'] && isset($feed[$p['ids'][0]])) {
            $p['year'] = $feed[$p['ids'][0]]['year'];
        }
        $base = $sku = $sync->skuFor($p);
        for ($n = 2; isset($used[strtolower($sku)]); $n++) {
            $sku = $base . '-' . $n;
        }
        $used[strtolower($sku)] = true;
        echo sprintf("  cod: #%d %-40s → %s\n", $p['id'], mb_substr((string) $p['name'], 0, 40), $sku);
        $skus++;
        if ($apply) {
            $sync->setSku((int) $p['id'], $sku);
        }
    }
}

// --- prețuri --------------------------------------------------------------------
$diff = $sync->priceDiff($feed, $products);
$changed = false;
echo "\nPrețuri (EUR cu TVA):\n";
foreach ($diff['changes'] as $c) {
    $t = $c['t'];
    $what = [];
    if ((int) $c['price'] !== $t['price'] || abs((float) $c['discount_pct'] - $t['discount_pct']) > 0.009) {
        $what[] = sprintf('%d (−%s%%) → %d (−%s%%) [listă %d]', $c['price'], (float) $c['discount_pct'], $t['price'], $t['discount_pct'], $t['list']);
    }
    if ((string) $c['variants_json'] !== (string) $t['variants_json']) {
        $vs = json_decode((string) $t['variants_json'], true) ?: [];
        $what[] = 'variante: ' . implode(', ', array_map(static fn ($v) => $v['version'] . ' ' . $v['price'], $vs));
    }
    if ((string) $c['supplier_ref'] !== $t['supplier_ref']) {
        $what[] = 'ref. furnizor: ' . $t['supplier_ref'];
    }
    echo sprintf("  #%d %-40s %s\n", $c['id'], mb_substr((string) $c['name'], 0, 40), implode(' | ', $what));
    if ($apply) {
        $sync->applyTarget((int) $c['id'], $t);
        $changed = true;
    }
}
echo $diff['changes'] ? '  ' . count($diff['changes']) . " produse actualizate\n" : "  nicio modificare\n";
foreach ($diff['missing'] as $p) {
    echo sprintf("  LIPSĂ din feed: #%d %s (%s) — feed %s\n", $p['id'], $p['name'], $p['is_active'] ? 'activ' : 'inactiv', $p['feed_ids']);
}

// --- produse noi ----------------------------------------------------------------
$created = [];
if ($doNew) {
    $groups = FeedSync::groupRows(array_diff_key($sync->newRows($feed, $products), $prop['reserved']));
    echo "\nProduse noi în feed: " . count($groups) . "\n";
    foreach ($groups as $g) {
        $d = $sync->shapeProduct($g);
        $vars = count($g) > 1 ? ' — variante: ' . implode(', ', array_map(static fn ($f) => $f['variant'], $g)) : '';
        if ($apply) {
            $id = $sync->createProduct($g);
            $created[] = ['id' => $id] + $d + ['links' => array_column($g, 'link'), 'vars' => $vars];
            echo sprintf("  + #%d %s (%s, cod %s, %d EUR)%s — inactiv\n", $id, $d['name'], $d['slug'], $d['sku'], $d['price'], $vars);
            $changed = true;
        } else {
            $imgs = array_sum(array_map(static fn ($f) => count($f['images']), $g));
            echo sprintf(
                "  + %s → slug %s, cod %s, ref %s, %d EUR, permis %s, %d imagini [feed #%s]%s\n",
                $d['name'], $d['slug'], $d['sku'], $d['supplier_ref'], $d['price'], $d['licence'] ?? '-', $imgs, $d['feed_ids'], $vars
            );
        }
    }
    if ($apply) {
        // Rândurile rezervate (legare ambiguă) rămân „nevăzute" până se rezolvă manual.
        $sync->markSeen(array_diff_key($feed, $prop['reserved']), $sync->products());
    }
}

if ($changed) {
    @unlink($root . '/storage/cache/navv2.cache'); // cardurile din meniu au prețuri
}

// --- email (doar la --new --apply, când e ceva de raportat) -----------------------
if ($doNew && $apply && !isset($opt['no-mail']) && ($created || $diff['missing'] || $prop['ambiguous'] || $unlinked)) {
    $admin = $settings['app']['url'] . $settings['app']['base_path'] . $settings['admin']['path'];
    $lines = [];
    if ($created) {
        $lines[] = 'Modele noi adăugate din feed-ul ATVROM (INACTIVE — verifică și activează din admin):';
        foreach ($created as $c) {
            $lines[] = sprintf('- %s — %d EUR — cod %s%s — %s/produse/%d', $c['name'], $c['price'], $c['sku'], $c['vars'], $admin, $c['id']);
            foreach ($c['links'] as $l) {
                $lines[] = '  Sursa: ' . $l;
            }
        }
        $lines[] = '';
        $lines[] = 'De verificat la fiecare: categoria, permisul, descrierea (feed-ul e fără diacritice), imaginile și culorile.';
        $lines[] = '';
    }
    if ($diff['missing']) {
        $lines[] = 'Modele de pe site care NU mai apar în feed (nu am modificat nimic):';
        foreach ($diff['missing'] as $p) {
            $lines[] = sprintf('- %s (%s) — %s/produse/%d', $p['name'], $p['is_active'] ? 'activ' : 'inactiv', $admin, $p['id']);
        }
        $lines[] = '';
    }
    $orphans = array_filter($unlinked, static fn ($p) => (int) $p['is_active'] === 1);
    if ($orphans || $prop['ambiguous']) {
        $lines[] = 'Modele active fără corespondent în feed (prețul NU se actualizează automat — completează „ID feed ATVROM" în admin):';
        foreach ($orphans as $p) {
            $amb = $prop['ambiguous'][(int) $p['id']] ?? '';
            $lines[] = sprintf('- %s — %s/produse/%d%s', $p['name'], $admin, $p['id'], $amb !== '' ? ' (posibil: ' . $amb . ')' : '');
        }
    }
    if ($lines) {
        $store = new Settings($db);
        $mailer = new Mailer($settings['mail'], $root . '/storage/logs', ($settings['app']['env'] ?? 'prod') === 'dev', $pdo);
        $mailer->setBrand([
            'address'  => $store->get('address', ''),
            'schedule' => $store->get('schedule', ''),
            'phone'    => $store->get('phone_general', ''),
            'email'    => (string) ($settings['mail']['dealer'] ?? ''),
            'departments' => (new Content($db))->departments(),
            'social'   => [],
        ]);
        $subject = $created
            ? 'CFMOTO: ' . count($created) . ' modele noi de activat pe motociclete.com.ro'
            : 'CFMOTO: raport sincronizare feed';
        foreach (array_filter(array_map('trim', explode(',', (string) $settings['mail']['feed_notify']))) as $to) {
            $ok = $mailer->send($to, $subject, implode("\n", $lines), 'cfmoto_feed');
            echo "\nEmail către {$to}: " . ($ok ? 'trimis' : 'EȘUAT') . "\n";
        }
    }
}

echo "Gata.\n";

function nameOf(array $products, int $id): string
{
    foreach ($products as $p) {
        if ((int) $p['id'] === $id) {
            return mb_substr((string) $p['name'], 0, 40);
        }
    }
    return '?';
}
