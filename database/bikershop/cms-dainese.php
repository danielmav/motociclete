<?php
declare(strict_types=1);

/**
 * Paginile de prezentare Dainese D-air® pe BikerShop (pagini CMS, după modelul paginilor dainese.com):
 *   smart-go   → pagină nouă, „Smart GO — everyday motorcycle airbag"
 *   smart-air  → pagina CMS #6 existentă (URL-ul indexat se păstrează), „Smart Air"
 *
 * Șablonul: database/bikershop/cms/<pagină>.html, cu marcajele
 *   {{IMG}}         → https://bikershop.ro/img/cms/<pagină> (preview: storage/<pagină>/img)
 *   {{URL:<ref>}}   → pagina produsului cu referința <ref> dacă e ACTIV pe BikerShop, altfel categoria Dainese de protecții (FALLBACK_URL)
 *   {{PRICE:<ref>}} → prețul cu TVA de pe BikerShop (dacă produsul există, chiar inactiv) sau prețul de rezervă
 *   {{CARD:<ref>}}  → cardul produsului (imagine, nume, preț, link)
 * Imaginile/clipurile (de pe dainese.com, NU sunt în git — repo public) stau în storage/<pagină>/img/
 * și se urcă o dată pe server:
 *   ssh bikershop 'mkdir -p ~/public_html/img/cms/<pagină>'
 *   scp storage/<pagină>/img/* bikershop:public_html/img/cms/<pagină>/
 *
 * Rulare (local, PHP 8.1 Laragon; BikerShop din .env):
 *   php database/bikershop/cms-dainese.php --page=smart-go             → dry-run: storage/<pagină>/preview.html
 *   php database/bikershop/cms-dainese.php --page=smart-go --apply     → creează / actualizează pagina CMS
 *   … --apply --activate                                               → și o activează
 * Pagina se caută după link_rewrite; una nouă se creează INACTIVĂ (fără --activate). Re-rulează cu --apply
 * după importul/activarea produselor: linkurile trec de pe categorie pe paginile produselor.
 * Se scrie identic pe shop 1/2 × limba 1/2 (ps_cms_lang are 4 rânduri per pagină); conținutul vechi se
 * salvează în storage/<pagină>/backup-cms-<id>-<ts>.json. ⚠️ Editarea din BO (TinyMCE + HTMLPurifier)
 * poate scoate <style>/<video> — modificările se fac în șablon și se re-aplică din script.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

const SHOP_URL = 'https://bikershop.ro';
const VAT = 1.21;
// produs inactiv / lipsă → categoria Dainese de protecții piept/spate (căutarea după cod nu găsește produse inactive)
const FALLBACK_URL = SHOP_URL . '/777-protectii-pentru-piept-si-spate?producatorii=dainese';
// produse: referință → card; `price` = preț de rezervă (lei cu TVA) când produsul nu există pe BikerShop
const PAGES = [
    'smart-go' => [
        'link_rewrite' => 'dainese-smart-go-airbag-moto',
        'og_image' => 'og-smart-go.jpg',   // 1200×630 în img/social/ (ets_seo) — sursa storage/smart-go/
        'title' => 'Dainese Smart GO – vestă airbag moto pentru fiecare zi',
        'desc' => 'Dainese Smart GO: airbag moto D-air® de 780 g, protecție Nivel 2 piept și spate, fără conectare la motocicletă, alimentat de la telefon sau Powerbank. Pe BikerShop.',
        'products' => [
            '1D400022001' => ['name' => 'Vestă airbag Dainese Smart GO', 'note' => 'Airbag D-air® Nivel 2, mărime unică', 'img' => 'vesta.webp', 'price' => 1832.25],
            '19800023001' => ['name' => 'Rucsac Dainese Smart GO Backpack 15 L', 'note' => 'Se prinde magnetic de vestă', 'img' => 'rucsac-p.webp', 'price' => 677.25],
            '1D000041001' => ['name' => 'Dainese Smart GO Powerbank 5000 mAh', 'note' => 'Până la 72 h de autonomie', 'img' => 'powerbank.webp', 'price' => 204.75],
            '1D000026999' => ['name' => 'Generator de gaz Smart GO', 'note' => 'Pentru reactivare după declanșare', 'img' => 'generator.webp', 'price' => 362.25],
        ],
    ],
    'smart-air' => [
        'link_rewrite' => 'vesta-inteligenta-cu-airbag-dainese-d-air-in-romania-prin-bikershop',
        'og_image' => 'og-smart-air.jpg',  // 1200×630 în img/social/ (ets_seo) — sursa storage/smart-air/
        'title' => 'Vesta inteligentă cu airbag Dainese Smart Air D-air®',
        'desc' => 'Dainese Smart Air: vestă airbag D-air® Nivel 2 pentru piept și spate, moduri ROAD, OFFROAD și EV, până la 18 ore autonomie, fără conectare la motocicletă. Pe BikerShop.',
        'products' => [
            '1D400021001' => ['name' => 'Vestă airbag Dainese Smart Air', 'note' => 'D-air® Nivel 2 · XS/S, M/L, X/2X', 'img' => 'vesta.webp', 'price' => 2645.00],
            '1D000008999' => ['name' => 'Generator de gaz D-air® Road Shield', 'note' => 'Pentru reactivare după declanșare', 'img' => 'generator.webp', 'price' => 625.00],
            '19100049629' => ['name' => 'Vestă anti-vânt pentru Smart Air', 'note' => 'Strat exterior peste airbag', 'img' => 'antivant.webp', 'price' => 472.00],
        ],
    ],
];

$opt = ['page' => '', 'apply' => false, 'activate' => false];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--page=')) {
        $opt['page'] = substr($a, 7);
    } elseif ($a === '--apply' || $a === '--activate') {
        $opt[substr($a, 2)] = true;
    } else {
        fwrite(STDERR, "Opțiune necunoscută: {$a}\n");
        exit(2);
    }
}
if (!isset(PAGES[$opt['page']])) {
    fwrite(STDERR, '--page=' . implode('|', array_keys(PAGES)) . "\n");
    exit(2);
}
$page = $opt['page'];
$cfg = PAGES[$page];
$products = $cfg['products'];
$imgUrl = SHOP_URL . '/img/cms/' . $page;

$root = dirname(__DIR__, 2);
$out = "{$root}/storage/{$page}";
@mkdir($out, 0775, true);

function env_file(string $root): array
{
    $e = [];
    foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        if (str_contains($l, '=') && $l[0] !== '#') {
            [$k, $v] = explode('=', $l, 2);
            $e[trim($k)] = trim(trim($v), "\"'");
        }
    }
    return $e;
}

$e = env_file($root);
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $e['BIKERSHOP_HOST'], (int) ($e['BIKERSHOP_PORT'] ?? 3306), $e['BIKERSHOP_NAME']),
    $e['BIKERSHOP_USER'], $e['BIKERSHOP_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

/* ---------- produsele: link + preț ---------- */
$refs = array_map('strval', array_keys($products));
$in = implode(',', array_fill(0, count($refs), '?'));
// URL-ul canonic (PS_ROUTE_product_rule) = /<categoria implicită>/<id>-<slug>[-<ean13>].html; altfel 302 spre el
$st = $pdo->prepare("SELECT p.id_product, p.reference, p.ean13, ps.active, ps.price, pl.link_rewrite, cl.link_rewrite AS cat_rewrite
    FROM ps_product p
    JOIN ps_product_shop ps ON ps.id_product = p.id_product AND ps.id_shop = 1
    JOIN ps_product_lang pl ON pl.id_product = p.id_product AND pl.id_shop = 1 AND pl.id_lang = 1
    LEFT JOIN ps_category_lang cl ON cl.id_category = ps.id_category_default AND cl.id_shop = 1 AND cl.id_lang = 1
    WHERE p.reference IN ($in)");
$st->execute($refs);
$found = [];
foreach ($st as $r) {
    $found[strtoupper((string) $r['reference'])] = $r;
}

$items = [];
foreach ($products as $ref => $c) {
    $ref = (string) $ref;
    $p = $found[strtoupper($ref)] ?? null;
    $live = $p && (int) $p['active'] === 1;
    $items[$ref] = $c + [
        'url' => $live ? SHOP_URL . '/' . ($p['cat_rewrite'] ? $p['cat_rewrite'] . '/' : '') . (int) $p['id_product'] . '-' . $p['link_rewrite'] . (trim((string) $p['ean13']) !== '' ? '-' . trim((string) $p['ean13']) : '') . '.html' : FALLBACK_URL,
        'lei' => $p && (float) $p['price'] > 0 ? (float) $p['price'] * VAT : $c['price'],
        'state' => $p ? ($live ? "activ #{$p['id_product']}" : "INACTIV #{$p['id_product']}") : 'lipsește',
    ];
}

function lei(float $v): string
{
    return number_format(round($v), 0, ',', '.') . ' lei';
}

function render(string $tpl, array $items, string $img): string
{
    $h = fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $html = str_replace('{{IMG}}', $img, $tpl);
    return preg_replace_callback('/\{\{(URL|PRICE|CARD):([A-Z0-9]+)\}\}/', function ($m) use ($items, $img, $h) {
        $it = $items[$m[2]] ?? null;
        if (!$it) {
            throw new RuntimeException("Referință necunoscută în șablon: {$m[2]}");
        }
        if ($m[1] === 'URL') {
            return $h($it['url']);
        }
        if ($m[1] === 'PRICE') {
            return $h(lei($it['lei']));
        }
        return '<a class="sg-card" href="' . $h($it['url']) . '">'
            . '<div class="sg-card__img"><img src="' . $h($img . '/' . $it['img']) . '" width="600" height="600" loading="lazy" alt="' . $h($it['name']) . '"></div>'
            . '<div class="sg-card__body"><span class="sg-card__name">' . $h($it['name']) . '</span>'
            . '<span class="sg-card__note">' . $h($it['note']) . '</span>'
            . '<span class="sg-card__price">' . $h(lei($it['lei'])) . '</span>'
            . '<span class="sg-card__cta">Vezi produsul →</span></div></a>';
    }, $html);
}

$tpl = (string) file_get_contents(__DIR__ . "/cms/{$page}.html");

echo "Produse ({$page}):\n";
foreach ($items as $ref => $it) {
    printf("  %-12s %-16s %10s  %s\n", $ref, $it['state'], lei($it['lei']), $it['url']);
}

/* ---------- preview local ---------- */
$preview = '<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>Preview ' . htmlspecialchars($page) . '</title><style>body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#fff}'
    . '.pv-h1{max-width:1280px;margin:24px auto;padding:0 16px;font-size:28px}</style></head><body>'
    . '<h1 class="pv-h1">' . htmlspecialchars($cfg['title']) . '</h1>' . render($tpl, $items, 'img') . '</body></html>';
file_put_contents("{$out}/preview.html", $preview);
echo "\nPreview: {$out}/preview.html\n";

$content = render($tpl, $items, $imgUrl);

if (!$opt['apply']) {
    echo "Dry-run (fără scrieri). --apply ca să scrii pagina CMS.\n";
    exit(0);
}

/* ---------- scriere CMS ---------- */
$find = $pdo->prepare("SELECT id_cms FROM ps_cms_lang WHERE link_rewrite = ? LIMIT 1");
$find->execute([$cfg['link_rewrite']]);
$id = (int) $find->fetchColumn();

$pdo->beginTransaction();
try {
    if ($id) {
        $bk = $pdo->prepare("SELECT c.active, l.* FROM ps_cms c JOIN ps_cms_lang l USING (id_cms) WHERE c.id_cms = ?");
        $bk->execute([$id]);
        $file = "{$out}/backup-cms-{$id}-" . date('Ymd-His') . '.json';
        file_put_contents($file, json_encode($bk->fetchAll(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "Backup: {$file}\n";
        $pdo->prepare("UPDATE ps_cms_lang SET meta_title = ?, head_seo_title = ?, meta_description = ?, content = ? WHERE id_cms = ?")
            ->execute([$cfg['title'], $cfg['title'], $cfg['desc'], $content, $id]);
        if ($opt['activate']) {
            $pdo->prepare("UPDATE ps_cms SET active = 1 WHERE id_cms = ?")->execute([$id]);
        }
        echo "Pagina CMS #{$id} actualizată" . ($opt['activate'] ? ' și activată' : '') . ".\n";
    } else {
        $pos = (int) $pdo->query("SELECT COALESCE(MAX(position), -1) + 1 FROM ps_cms WHERE id_cms_category = 1")->fetchColumn();
        $pdo->prepare("INSERT INTO ps_cms (id_cms_category, position, active, indexation) VALUES (1, ?, ?, 1)")
            ->execute([$pos, $opt['activate'] ? 1 : 0]);
        $id = (int) $pdo->lastInsertId();
        $shop = $pdo->prepare("INSERT INTO ps_cms_shop (id_cms, id_shop) VALUES (?, ?)");
        $lang = $pdo->prepare("INSERT INTO ps_cms_lang (id_cms, id_lang, id_shop, meta_title, head_seo_title, meta_description, content, link_rewrite)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ([1, 2] as $s) {
            $shop->execute([$id, $s]);
            foreach ([1, 2] as $l) {
                $lang->execute([$id, $l, $s, $cfg['title'], $cfg['title'], $cfg['desc'], $content, $cfg['link_rewrite']]);
            }
        }
        echo "Pagina CMS #{$id} creată " . ($opt['activate'] ? 'ACTIVĂ' : 'INACTIVĂ (activeaz-o din BO sau cu --apply --activate)') . ".\n";
    }
    // imaginea Open Graph: modulul ets_seo o ia din ps_ets_seo_cms.social_img (fișier în img/social/);
    // fără ea, og:image al temei e logo-ul (prea mic) și Facebook alege o imagine oarecare din pagină
    $has = $pdo->prepare("SELECT id_ets_seo_cms FROM ps_ets_seo_cms WHERE id_cms = ? AND id_shop = ? AND id_lang = ?");
    $upd = $pdo->prepare("UPDATE ps_ets_seo_cms SET social_img = ? WHERE id_ets_seo_cms = ?");
    $ins = $pdo->prepare("INSERT INTO ps_ets_seo_cms (id_cms, id_shop, id_lang, social_img) VALUES (?, ?, ?, ?)");
    foreach ([1, 2] as $s) {
        foreach ([1, 2] as $l) {
            $has->execute([$id, $s, $l]);
            ($row = $has->fetchColumn()) ? $upd->execute([$cfg['og_image'], $row]) : $ins->execute([$id, $s, $l, $cfg['og_image']]);
        }
    }
    echo "og:image: " . SHOP_URL . "/img/social/{$cfg['og_image']}\n";
    $pdo->commit();
} catch (Throwable $ex) {
    $pdo->rollBack();
    fwrite(STDERR, 'Eroare: ' . $ex->getMessage() . "\n");
    exit(1);
}
echo 'URL: ' . SHOP_URL . "/content/{$id}-" . $cfg['link_rewrite'] . "\n";
