<?php

declare(strict_types=1);

/**
 * Raport: referințe trunchiate în categoria 473 de pe BikerShop.
 *
 *   http://motociclete.test/tests/dup_ref_473.php
 *
 * Read-only. Compară, pentru fiecare produs din categoria 473:
 *   Yamaha (sursa de adevăr: cod complet + preț EUR)
 *   ↔ produsul actual din 473 (cod trunchiat, preț posibil greșit)
 *   ↔ geamănul cu codul complet (de regulă gol, în categoria 2545)
 *
 * Bifele salvează o selecție în storage/dup473_selection.json, consumată de
 * database/apply_dup_ref_473.php (singurul care scrie în BikerShop).
 *
 * ATENȚIE: tests/ NU e blocat de .htaccess (spre deosebire de database/) →
 * pagina ar fi publică pe producție. De aceea guard-ul de mai jos.
 */

use App\Accessories\RefAudit;
use App\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$settings = require $root . '/config/settings.php';

// ---- Guard: doar în dev, sau cu token explicit ---------------------------
$env   = (string) ($_ENV['APP_ENV'] ?? 'prod');
$token = (string) ($_ENV['TOOLS_TOKEN'] ?? '');
$given = (string) ($_GET['token'] ?? '');
if ($env !== 'dev' && ($token === '' || !hash_equals($token, $given))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("403 — disponibil doar în dev (APP_ENV=dev) sau cu ?token=… (TOOLS_TOKEN).\n");
}

$audit = new RefAudit(new Database($settings['db']), $settings['db']['bikershop']);
if (!$audit->isAvailable()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit("BikerShop sau baza locală indisponibilă.\n");
}

// ---- POST: salvează selecția --------------------------------------------
$saved = null;
$selectionFile = $root . '/storage/dup473_selection.json';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $picks = [];
    foreach (($_POST['pick'] ?? []) as $id => $sku) {
        $sku = (string) $sku;
        if ($sku !== '') {
            $picks[] = ['id_product' => (int) $id, 'sku' => $sku];
        }
    }
    $deactivate = array_values(array_unique(array_map('intval', $_POST['deact'] ?? [])));
    $payload = [
        'created_at' => date('c'),
        'rate'       => (float) ($_POST['rate'] ?? RefAudit::RATE),
        'picks'      => $picks,
        'deactivate' => $deactivate,
    ];
    @mkdir(dirname($selectionFile), 0775, true);
    file_put_contents($selectionFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $saved = ['picks' => count($picks), 'deact' => count($deactivate)];
}

$dedupe = $audit->dedupe();

// ?rate=5.20 permite simularea altui curs fără a modifica codul.
$rateOverride = isset($_GET['rate']) ? (float) $_GET['rate'] : null;

$data   = $audit->build($rateOverride);
$rows   = $data['rows'];
$counts = $data['counts'];
$rate   = $data['rate'];

$byClass = ['A1' => [], 'A2' => [], 'A3' => [], 'B' => [], 'C' => [], 'OK' => []];
foreach ($rows as $r) {
    $byClass[$r['class']][] = $r;
}

/** Câte rânduri din auditul de preț sunt efectiv greșite. */
$okDrift = 0;
foreach ($byClass['OK'] as $r) {
    if ($r['price_delta'] !== null && abs($r['price_delta']) >= 0.01) {
        $okDrift++;
    }
}

$SECTIONS = [
    'A1' => ['Corectare + retragere geamăn', 'Un singur cod Yamaha, iar geamănul cu codul complet e gol (fără imagini) în categoria ' . RefAudit::CAT_EMPTY . '. Se pune codul și prețul corect pe produsul din 473, iar geamănul se retrage (reversibil).', true],
    'A3' => ['Doar corectare cod + preț', 'Un singur cod Yamaha și nu există niciun produs BikerShop cu acel cod. Nu se retrage nimic.', true],
    'B'  => ['Ambiguu — alege codul', 'Mai multe coduri Yamaha încep cu aceeași referință trunchiată. Candidații sunt ordonați după cât de aproape e prețul lor de cel actual, dar alegerea e a ta.', false],
    'A2' => ['Conflict — geamănul are imagini', 'Codul Yamaha e clar, dar produsul care poartă deja codul complet are imagini proprii, deci nu e un simplu duplicat gol. Verifică manual înainte.', false],
    'C'  => ['Negăsit la Yamaha', 'Referința trunchiată nu are corespondent în catalogul Yamaha descărcat. Posibil produs scos din ofertă sau dintr-o altă categorie hyperdrive.', false],
    'OK' => ['Audit de preț (cod deja corect)', 'Produse care au deja codul complet de 12 caractere. Se verifică doar prețul față de Yamaha.', false],
];

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function lei(?float $v): string
{
    return $v === null ? '—' : number_format($v, 2, ',', '.') . ' lei';
}
?>
<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Audit referințe cat. 473 — BikerShop</title>
<style>
:root{--ink:#16181d;--muted:#6b7280;--line:#e5e7eb;--bg:#fff;--soft:#f7f8fa;--red:#e10600;--green:#0a7d33;--amber:#a15c00;}
*{box-sizing:border-box}
body{margin:0;font:14px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:var(--ink);background:var(--soft)}
header{position:sticky;top:0;z-index:5;background:#0e0e10;color:#fff;padding:14px 20px;box-shadow:0 1px 6px rgba(0,0,0,.25)}
header h1{margin:0 0 6px;font-size:17px;letter-spacing:.2px}
.sum{display:flex;flex-wrap:wrap;gap:8px;font-size:12.5px}
.sum b{display:inline-block;background:#22242a;border-radius:5px;padding:3px 9px;font-weight:600}
main{padding:18px 20px 120px;max-width:1700px;margin:0 auto}
section{margin:0 0 26px;background:var(--bg);border:1px solid var(--line);border-radius:10px;overflow:hidden}
section>h2{margin:0;padding:12px 16px;font-size:15px;background:#fbfbfc;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:10px}
section>h2 .n{background:var(--ink);color:#fff;border-radius:20px;padding:1px 10px;font-size:12px}
section>p.desc{margin:0;padding:10px 16px;color:var(--muted);font-size:13px;border-bottom:1px solid var(--line);background:#fff}
table{width:100%;border-collapse:collapse}
th,td{padding:9px 12px;border-bottom:1px solid var(--line);vertical-align:top;text-align:left}
th{background:#fcfcfd;font-size:12px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:600;position:sticky;top:64px}
tr:hover td{background:#fcfdff}
code{font:12.5px/1.4 ui-monospace,Consolas,monospace;background:#f2f3f5;padding:1px 5px;border-radius:4px}
code.new{background:#e6f6ec;color:#08682b;font-weight:700}
code.old{background:#fdecec;color:#a11}
.thumb{width:54px;height:54px;object-fit:contain;background:#fff;border:1px solid var(--line);border-radius:6px;flex:0 0 auto}
.prod{display:flex;gap:9px;align-items:flex-start}
.prod .nm{font-weight:600;line-height:1.3}
.meta{color:var(--muted);font-size:12px;margin-top:2px}
a{color:#0b5cd5;text-decoration:none}
a:hover{text-decoration:underline}
.warn{display:inline-block;background:#fff4e0;color:var(--amber);border:1px solid #f0dcb4;border-radius:4px;padding:1px 6px;font-size:11.5px;margin:2px 3px 0 0}
.up{color:var(--green);font-weight:600}
.down{color:var(--red);font-weight:600}
.pick{width:34px}
input[type=checkbox]{width:17px;height:17px;cursor:pointer}
.bar{position:fixed;left:0;right:0;bottom:0;background:#0e0e10;color:#fff;padding:11px 20px;display:flex;gap:14px;align-items:center;box-shadow:0 -2px 10px rgba(0,0,0,.3);z-index:9}
.bar button{background:var(--red);color:#fff;border:0;border-radius:6px;padding:9px 20px;font-size:14px;font-weight:600;cursor:pointer}
.bar button:hover{background:#b70500}
.ok{background:#e6f6ec;color:#08682b;border:1px solid #b9e3c8;padding:9px 14px;border-radius:8px;margin:0 0 16px}
.cands{margin:0;padding:0;list-style:none}
.cands li{padding:3px 0;border-top:1px dotted var(--line)}
.cands li:first-child{border-top:0}
.cands label{display:flex;gap:7px;align-items:flex-start;cursor:pointer}
details summary{cursor:pointer;padding:10px 16px;font-weight:600;background:#fbfbfc}
</style>
</head>
<body>
<header>
  <h1>Audit referințe — categoria <?= RefAudit::CAT_MAIN ?> BikerShop <span style="opacity:.6;font-weight:400">(sursa de adevăr: catalogul Yamaha)</span></h1>
  <div class="sum">
    <b>Curs aplicat: <?= number_format($rate, 2, ',', '.') ?></b>
    <b>mediana din catalog: <?= number_format((float) $data['rate_derived'], 4, ',', '.') ?> (<?= (int) $data['rate_samples'] ?> produse)</b>
    <b>TVA <?= number_format((RefAudit::VAT - 1) * 100, 0) ?>%</b>
    <?php foreach ($SECTIONS as $k => $s): ?>
      <b><?= h($k) ?>: <?= (int) ($counts[$k] ?? 0) ?></b>
    <?php endforeach; ?>
    <b>preț greșit la cod corect: <?= $okDrift ?></b>
    <b>grupuri duplicate: <?= count($dedupe['groups']) ?></b>
    <b>fără stoc + inexistent la Yamaha: <?= count($dedupe['orphans']) ?></b>
  </div>
</header>
<main>

<?php if ($saved !== null): ?>
  <p class="ok"><strong>Selecție salvată</strong> — <?= (int) $saved['picks'] ?> de corectat + <?= (int) $saved['deact'] ?> de dezactivat, în <code>storage/dup473_selection.json</code>.
  Rulează apoi:<br>
  <code>C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/apply_dup_ref_473.php</code> (dry-run), apoi cu <code>--apply</code>.</p>
<?php endif; ?>

<form method="post">
<input type="hidden" name="rate" value="<?= h((string) $rate) ?>">

<?php foreach ($SECTIONS as $class => [$title, $desc, $preselect]):
    $list = $byClass[$class];
    if (!$list) { continue; }
    $selectable = in_array($class, ['A1', 'A3', 'B'], true);
?>
<section id="s-<?= h($class) ?>">
  <h2><?= h($title) ?> <span class="n"><?= count($list) ?></span> <span style="font-size:12px;color:var(--muted);font-weight:400">clasa <?= h($class) ?></span></h2>
  <p class="desc"><?= h($desc) ?></p>
  <table>
    <thead>
      <tr>
        <?php if ($selectable): ?><th class="pick"></th><?php endif; ?>
        <th style="width:29%">Produs actual (cat. <?= RefAudit::CAT_MAIN ?>)</th>
        <th style="width:26%"><?= $class === 'B' ? 'Candidați Yamaha — alege' : 'Yamaha (sursa de adevăr)' ?></th>
        <th style="width:20%">Geamăn cu codul complet</th>
        <th style="width:25%">Cod &amp; preț propus</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($list as $r):
        $y = $r['yamaha'];
        $t = $r['twin'];
        $delta = $r['price_delta'];
    ?>
      <tr>
        <?php if ($selectable): ?>
        <td class="pick">
          <?php if ($class !== 'B' && $y && $y['expected'] !== null): ?>
            <input type="checkbox" name="pick[<?= (int) $r['id'] ?>]" value="<?= h($y['sku']) ?>" <?= $preselect ? 'checked' : '' ?>>
          <?php endif; ?>
        </td>
        <?php endif; ?>

        <td>
          <div class="prod">
            <?php if ($r['image']): ?><img class="thumb" src="<?= h($r['image']) ?>" alt="" loading="lazy"><?php endif; ?>
            <div>
              <div class="nm"><a href="<?= h($r['url']) ?>" target="_blank" rel="noopener"><?= h($r['name']) ?></a></div>
              <div class="meta">
                id <?= (int) $r['id'] ?> · <code class="<?= strlen($r['ref']) === 10 ? 'old' : '' ?>"><?= h($r['ref']) ?></code>
                · <?= (int) $r['imgs'] ?> img · descr. <?= (int) $r['dlen'] ?> car.
                <?= (int) $r['active'] === 0 ? ' · <strong>inactiv</strong>' : '' ?>
              </div>
              <div class="meta"><?= lei($r['price']) ?> fără TVA · <?= lei($r['price_vat']) ?> cu TVA</div>
              <?php foreach ($r['warnings'] as $w): ?><span class="warn"><?= h($w) ?></span><?php endforeach; ?>
            </div>
          </div>
        </td>

        <td>
          <?php if ($class === 'B'): ?>
            <ul class="cands">
            <?php foreach ($r['candidates'] as $c): ?>
              <li><label>
                <input type="radio" name="pick[<?= (int) $r['id'] ?>]" value="<?= h($c['sku']) ?>">
                <span>
                  <code class="new"><?= h($c['sku']) ?></code><br>
                  <span class="meta"><?= h($c['name']) ?></span><br>
                  <span class="meta">
                    <?= $c['eur'] > 0 ? number_format($c['eur'], 2, ',', '.') . ' EUR → ' . lei($c['expected']) : 'fără preț' ?>
                    <?= $c['twin'] ? ' · geamăn id ' . (int) $c['twin']['id_product'] . ' (' . (int) $c['twin']['imgs'] . ' img)' : ' · fără geamăn' ?>
                  </span>
                </span>
              </label></li>
            <?php endforeach; ?>
            </ul>
          <?php elseif ($y): ?>
            <div class="prod">
              <?php if ($y['image']): ?><img class="thumb" src="<?= h($y['image']) ?>" alt="" loading="lazy"><?php endif; ?>
              <div>
                <div class="nm"><?= h($y['name']) ?></div>
                <div class="meta"><code><?= h($y['sku_raw']) ?></code></div>
                <div class="meta"><?= $y['eur'] > 0 ? number_format($y['eur'], 2, ',', '.') . ' EUR' : '<em>fără preț public</em>' ?></div>
              </div>
            </div>
          <?php else: ?>
            <span class="meta">—</span>
          <?php endif; ?>
        </td>

        <td>
          <?php if ($t): ?>
            <div class="nm" style="font-size:13px"><?= h((string) $t['name']) ?></div>
            <div class="meta">id <?= (int) $t['id_product'] ?> · <code><?= h((string) $t['reference']) ?></code></div>
            <div class="meta">cat. <?= h((string) $t['cats']) ?> · <?= (int) $t['imgs'] ?> img · descr. <?= (int) $t['dlen'] ?></div>
            <div class="meta"><?= lei((float) $t['price']) ?> fără TVA</div>
            <?php if ((int) $t['imgs'] === 0): ?>
              <span class="warn">se retrage</span>
            <?php endif; ?>
          <?php else: ?>
            <span class="meta">nu există</span>
          <?php endif; ?>
        </td>

        <td>
          <?php if ($y && $y['expected'] !== null): ?>
            <div>cod: <code class="new"><?= h($y['sku']) ?></code></div>
            <div style="margin-top:3px">preț: <strong><?= lei($y['expected']) ?></strong> fără TVA</div>
            <?php if ($delta !== null && abs($delta) >= 0.01): ?>
              <div class="meta">
                acum <?= lei($r['price']) ?> →
                <span class="<?= $delta > 0 ? 'up' : 'down' ?>"><?= $delta > 0 ? '+' : '' ?><?= lei($delta) ?></span>
              </div>
            <?php else: ?>
              <div class="meta">prețul e deja corect</div>
            <?php endif; ?>
            <?php if ($r['implied_rate'] !== null): ?>
              <div class="meta">curs implicit actual: <?= number_format((float) $r['implied_rate'], 3, ',', '.') ?></div>
            <?php endif; ?>
            <?php if ($r['sup_new'] !== null && (int) $r['sup_count'] === 1): ?>
              <div class="meta">furnizor: <code><?= h($r['sup_ref']) ?></code> → <code class="new"><?= h($r['sup_new']) ?></code></div>
            <?php elseif ((int) $r['sup_count'] !== 1): ?>
              <div class="meta"><span class="warn">preț negestionabil automat (<?= (int) $r['sup_count'] ?> furnizori)</span></div>
            <?php endif; ?>
          <?php else: ?>
            <span class="meta">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endforeach; ?>

<?php if ($dedupe['groups']): ?>
<section id="s-DUP">
  <h2>Duplicate — același produs Yamaha <span class="n"><?= count($dedupe['groups']) ?></span></h2>
  <p class="desc">Produse active din categoria <?= RefAudit::CAT_MAIN ?> care se mapează pe același cod Yamaha (comparat normalizat, deci prinde și diferențele de scriere gen <code>2sapgf473140</code> vs <code>2SAPGF473140</code>). Se păstrează exemplarul cu imagini + descriere care există și la Yamaha; restul se propun spre dezactivare. Niciodată nu se dezactivează tot grupul.</p>
  <table>
    <thead><tr><th class="pick"></th><th style="width:40%">Produs</th><th style="width:20%">Stare</th><th>Decizie</th></tr></thead>
    <tbody>
    <?php foreach ($dedupe['groups'] as $g): ?>
      <tr><td colspan="4" style="background:#f4f6f8;font-weight:600">
        <code><?= h($g['key']) ?></code>
        <?= $g['on_yamaha'] ? '· la Yamaha: ' . h((string) $g['yamaha']['name']) : '· <span style="color:var(--red)">inexistent la Yamaha</span>' ?>
        <?= $g['keeper_ok'] ? '' : ' · <span class="warn">niciun exemplar complet — verifică manual</span>' ?>
      </td></tr>
      <?php foreach ($g['members'] as $m): ?>
      <tr>
        <td class="pick">
          <?php if (!$m['keeper']): ?>
            <input type="checkbox" name="deact[]" value="<?= (int) $m['id'] ?>" <?= $g['keeper_ok'] ? 'checked' : '' ?>>
          <?php endif; ?>
        </td>
        <td>
          <div class="prod">
            <?php if ($m['image']): ?><img class="thumb" src="<?= h($m['image']) ?>" alt="" loading="lazy"><?php endif; ?>
            <div>
              <div class="nm"><a href="<?= h($m['url']) ?>" target="_blank" rel="noopener"><?= h($m['name']) ?></a></div>
              <div class="meta">id <?= (int) $m['id'] ?> · <code><?= h($m['ref']) ?></code></div>
            </div>
          </div>
        </td>
        <td class="meta">
          <?= (int) $m['imgs'] ?> img · descr. <?= (int) $m['dlen'] ?> car.<br>
          stoc <?= (int) $m['stock'] ?> · <?= $m['on_yamaha'] ? 'la Yamaha' : 'nu e la Yamaha' ?><br>
          <?= lei($m['price']) ?> fără TVA
        </td>
        <td>
          <?php if ($m['keeper']): ?>
            <strong class="up">se păstrează</strong>
          <?php else: ?>
            <strong class="down">se dezactivează</strong>
            <?php foreach ($m['reasons'] as $rr): ?><div class="meta"><?= h($rr) ?></div><?php endforeach; ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<?php if ($dedupe['orphans']): ?>
<section id="s-ORPH">
  <h2>Fără stoc și inexistente la Yamaha <span class="n"><?= count($dedupe['orphans']) ?></span></h2>
  <p class="desc">
    Produse active, <strong>unice</strong> (nu au duplicat), cu stoc 0 și fără corespondent în catalogul Yamaha —
    de exemplu <code>907983091100</code> și <code>2sapgf473140</code>.
    <strong>Nu sunt bifate implicit</strong>, fiindcă regula de dezactivare a fost stabilită pentru duplicate;
    bifează-le tu pe cele pe care vrei să le scoți din ofertă.
  </p>
  <table>
    <thead><tr><th class="pick"></th><th style="width:45%">Produs</th><th style="width:20%">Stare</th><th>Motiv</th></tr></thead>
    <tbody>
    <?php foreach ($dedupe['orphans'] as $m): ?>
      <tr>
        <td class="pick"><input type="checkbox" name="deact[]" value="<?= (int) $m['id'] ?>"></td>
        <td>
          <div class="prod">
            <?php if ($m['image']): ?><img class="thumb" src="<?= h($m['image']) ?>" alt="" loading="lazy"><?php endif; ?>
            <div>
              <div class="nm"><a href="<?= h($m['url']) ?>" target="_blank" rel="noopener"><?= h($m['name']) ?: '<em>fără nume</em>' ?></a></div>
              <div class="meta">id <?= (int) $m['id'] ?> · <code><?= h($m['ref']) ?></code></div>
            </div>
          </div>
        </td>
        <td class="meta">
          <?= (int) $m['imgs'] ?> img · descr. <?= (int) $m['dlen'] ?> car.<br>
          stoc <?= (int) $m['stock'] ?><br>
          <?= lei($m['price']) ?> fără TVA
        </td>
        <td class="meta"><?= h(implode('; ', $m['reasons'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<div class="bar">
  <button type="submit">Salvează selecția</button>
  <span style="font-size:13px;opacity:.85">Bifele din A1 și A3 sunt pre-selectate. La clasa B alege întâi codul corect.</span>
</div>
</form>
</main>
</body>
</html>
