<?php

declare(strict_types=1);

require __DIR__ . '/_nl.php';

use App\Used\Thumb;

$dir = sys_get_temp_dir() . '/used-thumb-' . bin2hex(random_bytes(4));
mkdir($dir, 0775, true);

$big = imagecreatetruecolor(2000, 1500);
imagejpeg($big, $dir . '/mare.jpg', 90);
$small = imagecreatetruecolor(400, 300);
imagepng($small, $dir . '/mica.png');
file_put_contents($dir . '/stricat.jpg', 'nu sunt o imagine');

check('imagine mare: miniatură creată', Thumb::make($dir, 'mare.jpg') === true);
[$w, $h] = getimagesize($dir . '/thumbs/mare.jpg');
check('lățime 800, proporție păstrată', $w === 800 && $h === 600);
check('imagine mică: copiată la dimensiunea ei', Thumb::make($dir, 'mica.png') && getimagesize($dir . '/thumbs/mica.png')[0] === 400);
check('a doua rulare nu strică nimic', Thumb::make($dir, 'mare.jpg') === true);
check('fișier care nu e imagine → false', Thumb::make($dir, 'stricat.jpg') === false);
check('fișier lipsă → false', Thumb::make($dir, 'lipsa.jpg') === false);
check('cale cu ../ e redusă la numele fișierului', Thumb::make($dir, '../mare.jpg') === true);

// Fotografie de telefon ținut vertical: pixelii sunt culcați (1000×500), iar
// eticheta EXIF Orientation = 6 spune „rotește 90°". Miniatura trebuie să iasă în picioare.
ob_start();
imagejpeg(imagecreatetruecolor(1000, 500), null, 90);
$raw = (string) ob_get_clean();
$tiff = "II*\x00" . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, 6, 0) . pack('V', 0);
$exif = "Exif\x00\x00" . $tiff;
file_put_contents($dir . '/telefon.jpg', substr($raw, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($raw, 2));
check('proba: PHP citește orientarea 6', (int) (exif_read_data($dir . '/telefon.jpg')['Orientation'] ?? 0) === 6);
check('fotografie cu orientare EXIF: miniatură creată', Thumb::make($dir, 'telefon.jpg') === true);
[$tw, $th] = getimagesize($dir . '/thumbs/telefon.jpg');
check('miniatura e rotită în picioare (500×1000)', $tw === 500 && $th === 1000);

check('imagine peste limita de pixeli: fără miniatură, fără eroare', Thumb::make($dir, 'mica.png', 800, 1000) === true);
imagejpeg(imagecreatetruecolor(1200, 900), $dir . '/uriasa.jpg', 90);
check('imagine peste limita de pixeli → false', Thumb::make($dir, 'uriasa.jpg', 800, 1_000_000) === false);
check('și nu lasă fișier în thumbs/', !is_file($dir . '/thumbs/uriasa.jpg'));

array_map('unlink', glob($dir . '/thumbs/*') ?: []);
@rmdir($dir . '/thumbs');
array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);
nl_done();
