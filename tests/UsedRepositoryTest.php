<?php

declare(strict_types=1);

require __DIR__ . '/_nl.php';

use App\Used\Repository;

$pdo = nl_db()->local();
$pdo->beginTransaction();
$pdo->exec('DELETE FROM used_images');
$pdo->exec('DELETE FROM used_vehicles');
$pdo->exec('DELETE FROM used_brands');
$pdo->exec('DELETE FROM used_categories');
register_shutdown_function(static function () use ($pdo): void {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
});

$media = sys_get_temp_dir() . '/used-test-' . bin2hex(random_bytes(4));
mkdir($media . '/thumbs', 0775, true);

$now = '2026-10-08 12:00:00';
$clock = static function () use (&$now): string {
    return $now;
};
$repo = new Repository(nl_db(), $media, $clock);

echo "Taxonomii\n";
$yamaha = $repo->addBrand('Yamaha');
$honda  = $repo->addBrand('Honda');
$moto   = $repo->addCategory('Motociclete');
$scut   = $repo->addCategory('Scutere');
check('marca se creează', is_int($yamaha) && is_int($honda));
check('marca duplicată e refuzată', $repo->addBrand('yamaha') === null);
check('nume gol e refuzat', $repo->addBrand('  ') === null);
check('categoria „Marca” e refuzată (slug rezervat)', $repo->addCategory('Marca') === null);
check('categoria „125 cc” e refuzată (arată ca un URL de anunț)', $repo->addCategory('125 cc') === null);
check('categoria „Enduro 125” e acceptată', is_int($repo->addCategory('Enduro 125')));

echo "Creare și formă\n";
$base = ['title' => 'Yamaha MT-07 ABS', 'brand_id' => $yamaha, 'category_id' => $moto,
         'price_eur' => 6500, 'year' => 2021, 'km' => 12400, 'cc' => 689,
         'description_html' => '<p>Stare bună</p>', 'video' => 'https://youtu.be/dQw4w9WgXcQ'];
$a = $repo->save(null, $base, ['a.jpg', 'b.jpg']);
$v = $repo->find($a);
check('anunț nou e public', $v !== null && $repo->isPublic($v));
check('stare active, 30 de zile rămase', $v['state'] === 'active' && $v['days_left'] === 30);
check('expiră peste 30 de zile', $v['expires_at'] === '2026-11-07 12:00:00');
check('url cu id și slug', $v['url'] === '/rulate/' . $a . '-yamaha-mt-07-abs');
check('linia de date', $v['facts'] === '2021 · 12.400 km · 689 cc');
check('prima imagine e coperta', $v['image'] === '/media/rulate/a.jpg' && count($v['images']) === 2);
check('fără miniatură pe disc, thumb = imaginea', $v['thumb'] === '/media/rulate/a.jpg');
check('id video extras', $v['video_id'] === 'dQw4w9WgXcQ');
check('numerele sunt int/float', $v['year'] === 2021 && $v['price_eur'] === 6500.0 && $v['brand_id'] === $yamaha);

$bare = $repo->save(null, ['title' => 'Honda SH 150', 'brand_id' => $honda, 'category_id' => $scut], []);
$b = $repo->find($bare);
check('fără imagini: image și thumb sunt null', $b['image'] === null && $b['thumb'] === null && $b['images'] === []);
check('fără date: facts gol, preț null', $b['facts'] === '' && $b['price_eur'] === null && $b['video_id'] === null);

echo "Slug-uri doar ASCII (ruta acceptă [a-z0-9-])\n";
$cz = $repo->find($repo->save(null, ['title' => 'Jawa ČZ 350 nº 3', 'brand_id' => $honda, 'category_id' => $moto], []));
check('titlu cu litere din afara alfabetului: slug ASCII', preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $cz['slug']) === 1);
$jp = $repo->find($repo->save(null, ['title' => '本田', 'brand_id' => $honda, 'category_id' => $moto], []));
check('titlu fără nicio literă ASCII: slug de rezervă', $jp['slug'] === 'anunt');
$skoda = $repo->addBrand('Škoda Moto');
check('marcă cu literă străină: slug ASCII', $skoda !== null && preg_match('/^[a-z0-9-]+$/', (string) $repo->brandBySlug('koda-moto')['slug']) === 1);
check('marcă fără nicio literă ASCII e refuzată', $repo->addBrand('本田') === null);
$repo->delete($cz['id']);
$repo->delete($jp['id']);
$repo->deleteBrand((int) $skoda);

echo "Video\n";
check('URL watch', Repository::youtubeId('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=5') === 'dQw4w9WgXcQ');
check('URL shorts', Repository::youtubeId('https://youtube.com/shorts/dQw4w9WgXcQ') === 'dQw4w9WgXcQ');
check('doar ID', Repository::youtubeId('dQw4w9WgXcQ') === 'dQw4w9WgXcQ');
check('text oarecare → null', Repository::youtubeId('vezi pe facebook') === null);
check('gol → null', Repository::youtubeId('') === null && Repository::youtubeId(null) === null);

echo "Expirare\n";
$now = '2026-11-07 11:59:59';
check('cu o secundă înainte de termen e public', $repo->isPublic($repo->find($a)));
$now = '2026-11-07 12:00:00';
$v = $repo->find($a);
check('exact la termen nu mai e public', !$repo->isPublic($v) && $v['state'] === 'expired');
check('dispare din listă', $repo->page(null, null, 1, 12)['total'] === 0);
check('apare la expirate', array_column($repo->expired(), 'id') === [$bare, $a] || array_column($repo->expired(), 'id') === [$a, $bare]);

echo "Editarea nu prelungește\n";
$repo->save($a, ['title' => 'Yamaha MT-07 ABS (redus)'] + $base, ['b.jpg']);
$v = $repo->find($a);
check('termenul rămâne', $v['expires_at'] === '2026-11-07 12:00:00');
check('slug-ul rămâne cel de la creare', $v['slug'] === 'yamaha-mt-07-abs');
check('imaginile sunt înlocuite', count($v['images']) === 1 && $v['image'] === '/media/rulate/b.jpg');

echo "Reactivare și dezactivare\n";
$repo->reactivate($a);
$v = $repo->find($a);
check('reactivat: public, +30 de zile de acum', $repo->isPublic($v) && $v['expires_at'] === '2026-12-07 12:00:00');
$repo->deactivate($a);
$v = $repo->find($a);
check('dezactivat: nu e public, stare inactive', !$repo->isPublic($v) && $v['state'] === 'inactive');
check('dezactivatul nu apare la expirate', !in_array($a, array_column($repo->expired(), 'id'), true));
check('termenul nu s-a schimbat la dezactivare', $v['expires_at'] === '2026-12-07 12:00:00');
$repo->reactivate($a);
check('reactivarea unui dezactivat îl face public', $repo->isPublic($repo->find($a)));

echo "Liste și numărători\n";
$repo->reactivate($bare);
$all = $repo->page(null, null, 1, 12);
check('două publice', $all['total'] === 2 && count($all['items']) === 2);
check('filtru pe categorie', $repo->page($moto, null, 1, 12)['total'] === 1);
check('filtru pe marcă', $repo->page(null, $honda, 1, 12)['items'][0]['id'] === $bare);
check('paginare: pagina 2 din 1 pe pagină', count($repo->page(null, null, 2, 1)['items']) === 1);
$cats = $repo->categoriesWithCounts();
check('doar categoriile cu anunțuri publice', array_column($cats, 'slug') === ['motociclete', 'scutere'] && $cats[0]['n'] === 1);
$repo->deactivate($bare);
check('categoria fără anunțuri publice dispare', array_column($repo->categoriesWithCounts(), 'slug') === ['motociclete']);
check('la fel marca', array_column($repo->brandsWithCounts(), 'slug') === ['yamaha']);
check('activeCount', $repo->activeCount() === 1);
check('latest exclude anunțul curent', $repo->latest(6, $a) === []);
check('categoryBySlug', ($repo->categoryBySlug('motociclete')['id'] ?? null) === $moto && $repo->categoryBySlug('nu-exista') === null);
$paths = array_column($repo->sitemapEntries(), 'path');
check('sitemap: categorie, marcă, anunț', $paths === ['/rulate/motociclete', '/rulate/marca/yamaha', '/rulate/' . $a . '-yamaha-mt-07-abs']);
check('adminList pe stare', array_column($repo->adminList('inactive'), 'id') === [$bare] && count($repo->adminList()) === 2);

echo "Ștergere\n";
check('marca folosită nu se șterge', $repo->deleteBrand($yamaha) === false);
file_put_contents($media . '/b.jpg', 'x');
file_put_contents($media . '/thumbs/b.jpg', 'x');
$repo->delete($a);
check('anunțul dispare', $repo->find($a) === null);
check('fișierele dispar', !is_file($media . '/b.jpg') && !is_file($media . '/thumbs/b.jpg'));
check('marca nefolosită se șterge', $repo->deleteBrand($yamaha) === true);

@rmdir($media . '/thumbs');
@rmdir($media);
nl_done();
