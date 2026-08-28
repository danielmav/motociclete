<?php
/**
 * Generator de newsletter Brevo (Developer mode) pentru motociclete.com.ro + bikershop.ro.
 *
 * Ia un fișier JSON de input (știre + 2 modele + 6 produse BikerShop) și produce YAML-ul
 * complet al editorului Brevo, pornind de la fragmentele din documente/newsletter/parts/
 * (tăiate din template.txt: head / news / mid1 / model ×2 / mid2 / product_row ×2 / tail).
 * Output-ul se lipește direct în Brevo → Developer mode (Ctrl+A, paste).
 *
 *   php database/newsletter_brevo.php documente/newsletter/input.json > documente/newsletter/out.yml
 *
 * Rulează cu PHP 8.1 Laragon (are pdo_mysql). Datele modelelor vin din DB-ul local
 * (nume, preț EUR, cover, descriere, URL), ale produselor LIVE de pe BikerShop
 * (nume, preț RON brut, imagine, URL) — orice câmp poate fi suprascris din input
 * (vezi documente/newsletter/input.example.json).
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

use App\BikerShop\Client;
use App\Catalog\Repository as Catalog;
use App\Database;

const SITE  = 'https://www.motociclete.com.ro';
const PARTS = __DIR__ . '/../documente/newsletter/parts';

$inputFile = $argv[1] ?? __DIR__ . '/../documente/newsletter/input.json';
if (!is_file($inputFile)) {
    fwrite(STDERR, "Lipsește fișierul de input: $inputFile\n");
    exit(1);
}
$in = json_decode((string) file_get_contents($inputFile), true, 512, JSON_THROW_ON_ERROR);

$settings = require __DIR__ . '/../config/settings.php';
$db       = new Database($settings['db']);
$catalog  = new Catalog($db);
$bs       = new Client($db, $settings['db']['bikershop']);

// ---------------------------------------------------------------- helpers
function part(string $name): string
{
    $f = PARTS . "/$name.yml";
    if (!is_file($f)) {
        throw new RuntimeException("Lipsește fragmentul $f");
    }
    return rtrim((string) file_get_contents($f), "\n") . "\n";
}

/** Escapare pentru string YAML single-quoted (' → ''); fără newline-uri. */
function y(string $s): string
{
    return str_replace(["\r\n", "\r", "\n", "'"], [' ', ' ', ' ', "''"], $s);
}

/** Înlocuiește fiecare {{KEY}} cu valorile în ordine (a n-a apariție → a n-a valoare). */
function fill(string $tpl, array $vars): string
{
    foreach ($vars as $key => $vals) {
        $vals = is_array($vals) ? array_values($vals) : [$vals];
        $i = 0;
        $tpl = preg_replace_callback('/\{\{' . preg_quote($key, '/') . '\}\}/', function () use (&$i, $vals) {
            $v = $vals[$i] ?? end($vals);
            $i++;
            return y((string) $v);
        }, $tpl);
    }
    return $tpl;
}

/** Blocurile Brevo au id-uri UUID; duplicatele (model ×2, rând produse ×2) primesc id-uri noi. */
function freshIds(string $yml): string
{
    return preg_replace_callback('/^(\s+id: )[0-9a-f-]{36}$/m', fn ($m) => $m[1] . uuid(), $yml);
}

function uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function eur(int|float $v): string
{
    return number_format((float) $v, 0, ',', '.') . ' €';
}

function lei(int|float $v): string
{
    return number_format((float) $v, 0, ',', '.') . ' lei';
}

function excerpt(string $html, int $max = 260): string
{
    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if (mb_strlen($t) <= $max) {
        return $t;
    }
    $t = mb_substr($t, 0, $max);
    $dot = mb_strrpos($t, '. ');
    if ($dot !== false && $dot > $max / 2) {
        return mb_substr($t, 0, $dot + 1);
    }
    $sp = mb_strrpos($t, ' ');
    return rtrim(mb_substr($t, 0, $sp ?: $max), ' ,;:') . '…';
}

function paragraphs(array|string $p): string
{
    $p = is_array($p) ? $p : [$p];
    return implode('', array_map(fn ($x) => str_starts_with(trim((string) $x), '<') ? $x : '<p>' . $x . '</p>', $p));
}

// ---------------------------------------------------------------- modele
/** Acceptă "slug", "brand/slug", URL complet sau obiect {slug|url, brand, nume, pret, descriere, imagine, link}. */
function resolveModel(Catalog $catalog, Database $db, array|string $spec): array
{
    $o = is_array($spec) ? $spec : ['slug' => $spec];
    $ref = (string) ($o['url'] ?? $o['slug'] ?? '');
    $ref = preg_replace('#^https?://[^/]+#', '', $ref);
    $segs = array_values(array_filter(explode('/', trim($ref, '/'))));
    $brand = $o['brand'] ?? (count($segs) > 1 ? $segs[0] : 'yamaha');
    $slug  = end($segs) ?: '';

    $p = $catalog->product($brand, $slug);
    if (!$p && ($canon = $catalog->canonicalForSlugRedirect($brand, $slug))) {
        $p = $catalog->product($brand, basename($canon));
    }
    if (!$p) { // slug fără an (ex. "mt-09" → "mt-09-2026")
        $st = $db->local()->prepare("SELECT slug FROM products WHERE brand = :b AND is_active = 1 AND slug REGEXP :re ORDER BY year DESC LIMIT 1");
        $st->execute([':b' => $brand, ':re' => '^' . preg_quote($slug) . '-[0-9]{4}$']);
        if ($s = $st->fetchColumn()) {
            $p = $catalog->product($brand, (string) $s);
        }
    }
    if (!$p) {
        throw new RuntimeException("Model negăsit în catalog: $brand/$slug");
    }
    if ((int) $p['is_active'] === 0) {
        fwrite(STDERR, "AVERTISMENT: {$p['name']} e scos din ofertă (is_active=0)\n");
    }
    return [
        'IMAGE' => $o['imagine'] ?? (SITE . ($p['cover'] ?? '')),
        'NAME'  => $o['nume'] ?? $p['name'],
        'PRICE' => $o['pret'] ?? ((int) $p['price'] > 0 ? eur((int) $p['price']) : 'Preț la cerere'),
        'DESC'  => $o['descriere'] ?? excerpt((string) ($p['excerpt'] ?: $p['description'])),
        'URL'   => $o['link'] ?? (SITE . $p['url']),
    ];
}

// ---------------------------------------------------------------- produse BikerShop
/** Acceptă id, URL bikershop (…/27821-slug.html) sau obiect {id|url, nume, pret, pret_vechi, pret_html, imagine, link}. */
function productSpecs(array $list): array
{
    return array_map(function ($spec) {
        $o = is_array($spec) ? $spec : (is_numeric($spec) ? ['id' => (int) $spec] : ['url' => (string) $spec]);
        if (empty($o['id']) && !empty($o['url']) && preg_match('#/(\d+)-[^/]*\.html#', $o['url'], $m)) {
            $o['id'] = (int) $m[1];
        }
        if (empty($o['id'])) {
            throw new RuntimeException('Produs BikerShop fără id: ' . json_encode($spec, JSON_UNESCAPED_UNICODE));
        }
        return $o;
    }, $list);
}

function resolveProducts(Client $bs, array $specs): array
{
    $ids  = array_map(fn ($o) => (int) $o['id'], $specs);
    $live = [];
    foreach ($bs->productsByIds($ids, count($ids)) as $p) {
        $live[$p['id']] = $p;
    }
    if (!$live && !$bs->isAvailable()) {
        fwrite(STDERR, "AVERTISMENT: BikerShop DB indisponibil (IP whitelisted? vezi database/diagnose_db.php) — folosesc doar câmpurile din input\n");
    }
    $out = [];
    foreach ($specs as $o) {
        $p = $live[$o['id']] ?? null;
        if (!$p) {
            fwrite(STDERR, "AVERTISMENT: produsul {$o['id']} nu e activ / negăsit pe BikerShop\n");
        }
        $missing = [];
        foreach (['nume' => 'name', 'imagine' => 'image', 'link' => 'url'] as $k => $lk) {
            if (empty($o[$k]) && empty($p[$lk]) && !($k === 'link' && !empty($o['url']))) {
                $missing[] = $k;
            }
        }
        if ($missing) {
            throw new RuntimeException("Produsul {$o['id']}: lipsesc " . implode(', ', $missing) . " (completează-le în input)");
        }
        $priceHtml = $o['pret_html'] ?? null;
        if ($priceHtml === null) {
            $cur = isset($o['pret']) ? (float) $o['pret'] : (float) ($p['price'] ?? 0);
            $now = $cur > 0 ? lei($cur) : '';
            if (!empty($o['pret_vechi'])) {
                $old = (float) $o['pret_vechi'];
                $pct = $cur > 0 && $old > $cur ? ' -' . round((1 - $cur / $old) * 100) . '%' : '';
                $priceHtml = '<p><strong>' . $now . '</strong> <s>' . lei($old) . '</s>' . $pct . '</p>';
            } else {
                $priceHtml = '<p><strong>' . $now . '</strong></p>';
            }
        }
        $out[] = [
            'IMAGE'      => $o['imagine'] ?? $p['image'],
            'NAME'       => $o['nume'] ?? $p['name'],
            'PRICE_HTML' => $priceHtml,
            'URL'        => $o['link'] ?? $o['url'] ?? $p['url'],
        ];
    }
    return $out;
}

// ---------------------------------------------------------------- build
$models = array_map(fn ($m) => resolveModel($catalog, $db, $m), $in['modele'] ?? []);
if (count($models) !== 2) {
    throw new RuntimeException('Sunt necesare exact 2 modele în "modele"');
}
$products = resolveProducts($bs, productSpecs($in['produse'] ?? []));
if (count($products) !== 6) {
    throw new RuntimeException('Sunt necesare exact 6 produse în "produse"');
}
$news = $in['stire'] ?? [];
foreach (['titlu_html', 'imagine', 'link'] as $k) {
    if (empty($news[$k])) {
        throw new RuntimeException("Lipsește stire.$k");
    }
}

$out  = fill(part('head'), [
    'SUBJECT'         => $in['subiect'] ?? '',
    'NEWS_TITLE_HTML' => str_starts_with(trim($news['titlu_html']), '<')
        ? $news['titlu_html']
        : '<h1 class="default-heading1">' . $news['titlu_html'] . '</h1>',
]);
$out .= fill(part('news'), [
    'NEWS_LINK'      => $news['link'],
    'NEWS_IMAGE'     => $news['imagine'],
    'NEWS_BODY_HTML' => paragraphs($news['paragrafe'] ?? $news['text_html'] ?? ''),
    'NEWS_BUTTON'    => $news['buton'] ?? 'Detalii',
]);
$out .= part('mid1');
foreach ($models as $m) {
    $out .= freshIds(fill(part('model'), $m));
}
$out .= part('mid2');
foreach (array_chunk($products, 3) as $row) {
    $vars = [];
    foreach ($row as $p) {
        foreach ($p as $k => $v) {
            $vars[$k][] = $v;
        }
    }
    $out .= freshIds(fill(part('product_row'), $vars));
}
$out .= part('tail');

if (preg_match_all('/\{\{[A-Z_]+\}\}/', $out, $m)) {
    throw new RuntimeException('Placeholdere necompletate: ' . implode(', ', array_unique($m[0])));
}
echo $out;

fwrite(STDERR, sprintf("OK: %d linii, %d modele, %d produse\n", substr_count($out, "\n"), count($models), count($products)));
foreach ($models as $m) {
    fwrite(STDERR, "  model:  {$m['NAME']} — {$m['PRICE']} — {$m['URL']}\n");
}
foreach ($products as $p) {
    fwrite(STDERR, "  produs: {$p['NAME']} — " . strip_tags($p['PRICE_HTML']) . " — {$p['IMAGE']}\n");
}
