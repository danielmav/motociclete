<?php
declare(strict_types=1);

/**
 * Pagina de prezentare Dainese Smart GO pe BikerShop (pagină CMS, după modelul paginii Dainese
 * „Smart GO — everyday motorcycle airbag").
 *
 * Șablonul: database/bikershop/cms/smart-go.html, cu marcajele
 *   {{IMG}}         → https://bikershop.ro/img/cms/smart-go (preview: storage/smart-go/img)
 *   {{URL:<ref>}}   → pagina produsului cu referința <ref> dacă e ACTIV pe BikerShop, altfel căutarea după cod
 *   {{PRICE:<ref>}} → prețul cu TVA de pe BikerShop (produs activ) sau prețul de rezervă din PRODUCTS
 *   {{CARD:<ref>}}  → cardul produsului (imagine, nume, preț, link)
 * Imaginile/clipurile (de pe dainese.com, NU sunt în git — repo public) stau în storage/smart-go/img/
 * și se urcă o dată pe server:
 *   ssh bikershop 'mkdir -p ~/public_html/img/cms/smart-go'
 *   scp storage/smart-go/img/* bikershop:public_html/img/cms/smart-go/
 *
 * Rulare (local, PHP 8.1 Laragon; BikerShop din .env):
 *   php database/bikershop/cms-smart-go.php              → dry-run: storage/smart-go/preview.html + starea linkurilor
 *   php database/bikershop/cms-smart-go.php --apply      → creează / actualizează pagina CMS (inactivă la creare)
 *   php database/bikershop/cms-smart-go.php --apply --activate
 * Re-rulează cu --apply după importul produselor (CSV din generare-csv-dainese.php --tag=smartgo) și
 * activarea lor: linkurile trec automat de pe căutare pe paginile produselor, iar prețurile pe cele din magazin.
 * Pagina se scrie identic pe shop 1/2 × limba 1/2 (ps_cms_lang are 4 rânduri per pagină); conținutul vechi
 * se salvează în storage/smart-go/backup-cms-<ts>.json. ⚠️ Editarea paginii din BO (TinyMCE + HTMLPurifier)
 * poate scoate <style>/<video> — modificările se fac în șablon și se re-aplică din script.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

const LINK_REWRITE = 'dainese-smart-go-airbag-moto';
const META_TITLE = 'Dainese Smart GO – vestă airbag moto pentru fiecare zi';
const META_DESC = 'Dainese Smart GO: airbag moto D-air® de 780 g, protecție Nivel 2 piept și spate, fără conectare la motocicletă, alimentat de la telefon sau Powerbank. Pe BikerShop.';
const SHOP_URL = 'https://bikershop.ro';
const IMG_URL = SHOP_URL . '/img/cms/smart-go';
const VAT = 1.21;
// referință → card; `price` = prețul de rezervă (lei cu TVA) până când produsul e activ pe BikerShop
const PRODUCTS = [
    '1D400022001' => ['name' => 'Vestă airbag Dainese Smart GO', 'note' => 'Airbag D-air® Nivel 2, mărime unică', 'img' => 'vesta.webp', 'price' => 1832.25],
    '19800023001' => ['name' => 'Rucsac Dainese Smart GO Backpack 15 L', 'note' => 'Se prinde magnetic de vestă', 'img' => 'rucsac-p.webp', 'price' => 677.25],
    '1D000041001' => ['name' => 'Dainese Smart GO Powerbank 5000 mAh', 'note' => 'Până la 72 h de autonomie', 'img' => 'powerbank.webp', 'price' => 204.75],
    '1D000026999' => ['name' => 'Generator de gaz Smart GO', 'note' => 'Pentru reactivare după declanșare', 'img' => 'generator.webp', 'price' => 362.25],
];

$root = dirname(__DIR__, 2);
$out = $root . '/storage/smart-go';
@mkdir($out, 0775, true);
$apply = in_array('--apply', $argv, true);
$activate = in_array('--activate', $argv, true);

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
$refs = array_keys(PRODUCTS);
$in = implode(',', array_fill(0, count($refs), '?'));
$st = $pdo->prepare("SELECT p.id_product, p.reference, ps.active, ps.price, pl.link_rewrite
    FROM ps_product p
    JOIN ps_product_shop ps ON ps.id_product = p.id_product AND ps.id_shop = 1
    JOIN ps_product_lang pl ON pl.id_product = p.id_product AND pl.id_shop = 1 AND pl.id_lang = 1
    WHERE p.reference IN ($in)");
$st->execute($refs);
$found = [];
foreach ($st as $r) {
    $found[strtoupper((string) $r['reference'])] = $r;
}

$items = [];
foreach (PRODUCTS as $ref => $cfg) {
    $ref = (string) $ref;
    $p = $found[strtoupper($ref)] ?? null;
    $live = $p && (int) $p['active'] === 1;
    $items[$ref] = [
        'url' => $live ? SHOP_URL . '/' . (int) $p['id_product'] . '-' . $p['link_rewrite'] . '.html' : SHOP_URL . '/cautare?s=' . rawurlencode($ref),
        'price' => $live && (float) $p['price'] > 0 ? (float) $p['price'] * VAT : $cfg['price'],
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
            return $h(lei($it['price']));
        }
        $c = PRODUCTS[$m[2]];
        return '<a class="sg-card" href="' . $h($it['url']) . '">'
            . '<div class="sg-card__img"><img src="' . $h($img . '/' . $c['img']) . '" width="600" height="600" loading="lazy" alt="' . $h($c['name']) . '"></div>'
            . '<div class="sg-card__body"><span class="sg-card__name">' . $h($c['name']) . '</span>'
            . '<span class="sg-card__note">' . $h($c['note']) . '</span>'
            . '<span class="sg-card__price">' . $h(lei($it['price'])) . '</span>'
            . '<span class="sg-card__cta">Vezi produsul →</span></div></a>';
    }, $html);
}

$tpl = (string) file_get_contents(__DIR__ . '/cms/smart-go.html');

echo "Produse:\n";
foreach ($items as $ref => $it) {
    printf("  %-12s %-16s %10s  %s\n", $ref, $it['state'], lei($it['price']), $it['url']);
}

/* ---------- preview local ---------- */
$preview = '<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>Preview Smart GO</title><style>body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#fff}'
    . '.pv-h1{max-width:1280px;margin:24px auto;padding:0 16px;font-size:28px}</style></head><body>'
    . '<h1 class="pv-h1">' . htmlspecialchars(META_TITLE) . '</h1>' . render($tpl, $items, 'img') . '</body></html>';
file_put_contents("{$out}/preview.html", $preview);
echo "\nPreview: {$out}/preview.html\n";

$content = render($tpl, $items, IMG_URL);

if (!$apply) {
    echo "Dry-run (fără scrieri). --apply ca să scrii pagina CMS.\n";
    exit(0);
}

/* ---------- scriere CMS ---------- */
$find = $pdo->prepare("SELECT id_cms FROM ps_cms_lang WHERE link_rewrite = ? LIMIT 1");
$find->execute([LINK_REWRITE]);
$id = (int) $find->fetchColumn();

$pdo->beginTransaction();
try {
    if ($id) {
        $bk = $pdo->prepare("SELECT * FROM ps_cms_lang WHERE id_cms = ?");
        $bk->execute([$id]);
        $file = "{$out}/backup-cms-{$id}-" . date('Ymd-His') . '.json';
        file_put_contents($file, json_encode($bk->fetchAll(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "Backup: {$file}\n";
        $pdo->prepare("UPDATE ps_cms_lang SET meta_title = ?, head_seo_title = ?, meta_description = ?, content = ? WHERE id_cms = ?")
            ->execute([META_TITLE, META_TITLE, META_DESC, $content, $id]);
        if ($activate) {
            $pdo->prepare("UPDATE ps_cms SET active = 1 WHERE id_cms = ?")->execute([$id]);
        }
        echo "Pagina CMS #{$id} actualizată" . ($activate ? ' și activată' : '') . ".\n";
    } else {
        $pos = (int) $pdo->query("SELECT COALESCE(MAX(position), -1) + 1 FROM ps_cms WHERE id_cms_category = 1")->fetchColumn();
        $pdo->prepare("INSERT INTO ps_cms (id_cms_category, position, active, indexation) VALUES (1, ?, ?, 1)")
            ->execute([$pos, $activate ? 1 : 0]);
        $id = (int) $pdo->lastInsertId();
        $shop = $pdo->prepare("INSERT INTO ps_cms_shop (id_cms, id_shop) VALUES (?, ?)");
        $lang = $pdo->prepare("INSERT INTO ps_cms_lang (id_cms, id_lang, id_shop, meta_title, head_seo_title, meta_description, content, link_rewrite)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ([1, 2] as $s) {
            $shop->execute([$id, $s]);
            foreach ([1, 2] as $l) {
                $lang->execute([$id, $l, $s, META_TITLE, META_TITLE, META_DESC, $content, LINK_REWRITE]);
            }
        }
        echo "Pagina CMS #{$id} creată " . ($activate ? 'ACTIVĂ' : 'INACTIVĂ (activeaz-o din BO sau cu --apply --activate)') . ".\n";
    }
    $pdo->commit();
} catch (Throwable $ex) {
    $pdo->rollBack();
    fwrite(STDERR, 'Eroare: ' . $ex->getMessage() . "\n");
    exit(1);
}
echo 'URL: ' . SHOP_URL . "/content/{$id}-" . LINK_REWRITE . "\n";
