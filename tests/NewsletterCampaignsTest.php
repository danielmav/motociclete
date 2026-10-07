<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterCampaignsTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Campaigns;
use App\Newsletter\Composer;
use App\Newsletter\Content;
use App\Newsletter\Images;
use App\Newsletter\Renderer;

$pdo = nl_isolate();
$campaigns = new Campaigns(nl_db());

// --- ciorne ------------------------------------------------------------------
$id = $campaigns->create('stiri', 'stiri', 'Noua AGV K5');
$row = $campaigns->find($id);
check('create: ciornă cu cheie de vizualizare de 16 caractere hex',
    $id > 0 && $row['status'] === 'draft' && (bool) preg_match('/^[a-f0-9]{16}$/', (string) $row['view_key'])
    && $row['list_key'] === 'stiri' && $row['type'] === 'stiri' && $row['subject'] === 'Noua AGV K5');

$campaigns->update($id, ['subject' => 'Subiect nou', 'preheader' => 'Pre', 'html' => '<p>salut</p>', 'body_text' => 'salut',
    'input_json' => '{"a":1}', 'list_key' => 'oferte', 'type' => 'oferte', 'status' => 'sent', 'view_key' => 'x']);
$row = $campaigns->find($id);
check('update: scrie câmpurile permise',
    $row['subject'] === 'Subiect nou' && $row['preheader'] === 'Pre' && $row['html'] === '<p>salut</p>' && $row['body_text'] === 'salut'
    && $row['input_json'] === '{"a":1}' && $row['list_key'] === 'oferte' && $row['type'] === 'oferte' && $row['updated_at'] !== null);
check('update: ignoră câmpurile nepermise (status, view_key)',
    $row['status'] === 'draft' && (bool) preg_match('/^[a-f0-9]{16}$/', (string) $row['view_key']));

check('findPublic: cheia corectă', (int) ($campaigns->findPublic($id, (string) $row['view_key'])['id'] ?? 0) === $id);
check('findPublic: cheie greșită → null', $campaigns->findPublic($id, str_repeat('0', 16)) === null);
check('find: id necunoscut → null', $campaigns->find(999999) === null);

$id2 = $campaigns->create('oferte', 'oferte', 'A doua');
$all = $campaigns->all();
check('all: cele mai noi primele, fără coloanele mari',
    count($all) === 2 && (int) $all[0]['id'] === $id2 && !array_key_exists('html', $all[0]) && array_key_exists('subject', $all[0]));

// O campanie pusă la trimis nu mai poate fi modificată sau ștearsă.
$pdo->exec("UPDATE nl_campaigns SET status = 'queued' WHERE id = {$id2}");
$threw = false;
try {
    $campaigns->update($id2, ['subject' => 'Modificat']);
} catch (RuntimeException) {
    $threw = true;
}
check('update pe o campanie care nu e ciornă → excepție, fără modificare',
    $threw && $campaigns->find($id2)['subject'] === 'A doua');
check('delete pe o campanie care nu e ciornă → false', $campaigns->delete($id2) === false && $campaigns->find($id2) !== null);
check('delete pe o ciornă → true', $campaigns->delete($id) === true && $campaigns->find($id) === null);

// --- Composer ----------------------------------------------------------------
$shop = [
    1 => ['id' => 1, 'name' => 'Produs unu', 'image' => 'https://bikershop.ro/1-large_default/unu.jpg', 'price' => 800.0, 'price_old' => 1000.0, 'reduction_pct' => 20, 'url' => 'https://bikershop.ro/1-unu.html'],
    2 => ['id' => 2, 'name' => 'Produs doi', 'image' => 'https://bikershop.ro/2-large_default/doi.jpg', 'price' => 500.0, 'price_old' => null, 'reduction_pct' => null, 'url' => 'https://bikershop.ro/2-doi.html'],
];
$content = new Content(
    static fn (string $b, string $s): ?array => null,
    static fn (array $specs): array => array_intersect_key($shop, array_flip(array_column($specs, 'id')))
);
$renderer = new Renderer(dirname(__DIR__) . '/templates/email/newsletter', 'https://www.motociclete.com.ro');
$dir = sys_get_temp_dir() . '/nl-cmp-' . bin2hex(random_bytes(4));
mkdir($dir);
$im = imagecreatetruecolor(800, 800);
ob_start();
imagepng($im);
$png = (string) ob_get_clean();
$images = new Images($dir, 'https://www.motociclete.com.ro', ['bikershop.ro'],
    static fn (string $url): ?string => str_contains($url, 'doi') ? null : $png);

$input = ['subiect' => 'Reduceri', 'stire' => ['titlu_html' => 'Reduceri de toamnă'], 'produse' => [1, 2]];
$out = (new Composer($content, $renderer, $images))->compose('oferte', $input, 'nl-1-reduceri', []);
check('compose: HTML cu produsele și prețul redus',
    str_contains($out['html'], 'Produs unu') && str_contains($out['html'], '800 lei') && str_contains($out['html'], '1.000 lei'));
check('compose: imaginea copiată e servită de la noi',
    str_contains($out['html'], 'https://www.motociclete.com.ro/media/newsletter/bs/' . sha1('https://bikershop.ro/1-large_default/unu.jpg') . '.jpg'));
check('compose: imaginea necopiată rămâne pe BikerShop, cu avertisment',
    str_contains($out['html'], 'https://bikershop.ro/2-large_default/doi.jpg') && count($out['warnings']) === 1);
check('compose: UTM cu numele campaniei', str_contains($out['html'], 'utm_campaign=nl-1-reduceri'));
check('compose: și varianta text', str_contains($out['text'], 'Produs unu — 800 lei (în loc de 1.000 lei)'));

$noImages = (new Composer($content, $renderer))->compose('oferte', $input, 'x', []);
check('compose fără Images: imaginile rămân cele originale', str_contains($noImages['html'], 'https://bikershop.ro/1-large_default/unu.jpg'));

$threw = '';
try {
    (new Composer($content, $renderer))->compose('oferte', ['subiect' => 'x', 'stire' => ['titlu_html' => 'y'], 'produse' => [1, 77]], 'x', []);
} catch (RuntimeException $e) {
    $threw = $e->getMessage();
}
check('compose: produs negăsit → excepția din Content ajunge la apelant', str_contains($threw, '77'));

array_map('unlink', glob($dir . '/newsletter/bs/*') ?: []);
@rmdir($dir . '/newsletter/bs');
@rmdir($dir . '/newsletter');
@rmdir($dir);

nl_done();
