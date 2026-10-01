<?php

declare(strict_types=1);

/**
 * Sincronizare motociclete CFMOTO din feed-ul importatorului (ATVROM) — logica în
 * App\Cfmoto\FeedSync. Feed-ul e sursa de adevăr pentru prețuri.
 *
 *   --prices        actualizează prețul/reducerea produselor legate (cron zilnic, noaptea)
 *   --new           adaugă produsele noi din feed ca INACTIVE + email de notificare (cron săptămânal)
 *   --apply         scrie în DB (fără = dry-run, doar raport)
 *   --link=PID:FEED leagă manual produsul PID de rândul de feed FEED (FEED=0 → dezleagă)
 *   --feed=FILE     citește feed-ul dintr-un fișier local (test) în loc de URL
 *   --no-mail       nu trimite email (doar raport în consolă)
 *
 * Ambele moduri leagă întâi automat produsele nelegate (nume + an). Produsele legate care
 * lipsesc din feed sunt doar raportate (în email la --new), nu dezactivate.
 *
 * Local: C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/sync_cfmoto_feed.php --prices
 * Cron (server, ea-php81):
 *   15 2 * * *  .../database/sync_cfmoto_feed.php --prices --apply
 *   30 2 * * 1  .../database/sync_cfmoto_feed.php --new --apply
 */

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
    fwrite(STDERR, "Folosire: sync_cfmoto_feed.php --prices|--new|--link=PID:FEED [--apply] [--feed=FILE] [--no-mail]\n");
    exit(2);
}

$db = new Database($settings['db']);
$pdo = $db->local();
$sync = new FeedSync($pdo, $root . '/media');

echo '[' . date('Y-m-d H:i:s') . '] ' . ($apply ? 'APPLY' : 'DRY-RUN (fără --apply nu se scrie nimic)') . "\n";

// --- legare manuală -----------------------------------------------------------
if (isset($opt['link'])) {
    if (!preg_match('/^(\d+):(\d+)$/', (string) $opt['link'], $m)) {
        fwrite(STDERR, "--link așteaptă PID:FEED (ex. --link=173:186369)\n");
        exit(2);
    }
    echo "Leg produsul #{$m[1]} de feed #{$m[2]}\n";
    if ($apply) {
        $sync->link((int) $m[1], ((int) $m[2]) ?: null);
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

// --- legare automată ------------------------------------------------------------
$products = $sync->products();
$prop = $sync->proposeLinks($feed, $products);
foreach ($prop['links'] as $pid => $fid) {
    echo sprintf("  legat: #%d %-40s ↔ feed #%d %s\n", $pid, nameOf($products, $pid), $fid, $feed[$fid]['title']);
    if ($apply) {
        $sync->link($pid, $fid);
    }
}
foreach ($prop['ambiguous'] as $pid => $cands) {
    echo sprintf("  AMBIGUU: #%d %s → %s (leagă manual cu --link)\n", $pid, nameOf($products, $pid), $cands);
}
// Re-citește (sau simulează legăturile în dry-run).
$products = $apply ? $sync->products() : array_map(
    static fn ($p) => isset($prop['links'][(int) $p['id']]) ? ['feed_id' => $prop['links'][(int) $p['id']]] + $p : $p,
    $products
);
$unlinked = array_filter($products, static fn ($p) => !$p['feed_id']);

// --- prețuri --------------------------------------------------------------------
$diff = $sync->priceDiff($feed, $products);
$changed = false;
if ($doPrices || $doNew) {
    echo "\nPrețuri (EUR cu TVA):\n";
    foreach ($diff['changes'] as $c) {
        echo sprintf(
            "  #%d %-40s %6d (−%s%%) → %6d (−%s%%)  [listă %d]\n",
            $c['id'], mb_substr($c['name'], 0, 40), $c['price'], (float) $c['discount_pct'], $c['new_price'], $c['new_pct'], $c['list']
        );
        if ($apply) {
            $sync->applyPrice((int) $c['id'], (int) $c['new_price'], (float) $c['new_pct']);
            $changed = true;
        }
    }
    echo $diff['changes'] ? '  ' . count($diff['changes']) . " modificări\n" : "  nicio modificare\n";
    foreach ($diff['missing'] as $p) {
        echo sprintf("  LIPSĂ din feed: #%d %s (%s) — feed #%d\n", $p['id'], $p['name'], $p['is_active'] ? 'activ' : 'inactiv', $p['feed_id']);
    }
}

// --- produse noi ----------------------------------------------------------------
$created = [];
if ($doNew) {
    $new = array_diff_key($sync->newRows($feed, $products), $prop['reserved']);
    echo "\nProduse noi în feed: " . count($new) . "\n";
    foreach ($new as $f) {
        $d = $sync->shapeProduct($f);
        $pr = FeedSync::pricing($f);
        if ($apply) {
            $id = $sync->createProduct($f);
            $created[] = ['id' => $id] + $d + ['feed' => $f];
            echo sprintf("  + #%d %s (%s, %d EUR) — inactiv\n", $id, $d['name'], $d['slug'], $pr['price']);
            $changed = true;
        } else {
            echo sprintf("  + %s → slug %s, %d EUR, permis %s, %d imagini [feed #%d]\n", $d['name'], $d['slug'], $pr['price'], $d['licence'] ?? '-', count($f['images']), $f['id']);
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
            $lines[] = sprintf('- %s (%s) — %d EUR — %s/produse/%d', $c['name'], $c['year'] ?: '-', $c['price'], $admin, $c['id']);
            $lines[] = '  Sursa: ' . $c['feed']['link'];
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
        $to = $settings['mail']['feed_notify'];
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
        $ok = $mailer->send($to, $subject, implode("\n", $lines), 'cfmoto_feed');
        echo "\nEmail către {$to}: " . ($ok ? 'trimis' : 'EȘUAT') . "\n";
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
