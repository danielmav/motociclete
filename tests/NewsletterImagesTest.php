<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterImagesTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Images;

$png = static function (int $w, int $h, bool $alpha = false): string {
    $im = imagecreatetruecolor($w, $h);
    if ($alpha) {
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    } else {
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
    }
    ob_start();
    imagepng($im);
    return (string) ob_get_clean();
};
$size = static fn (string $jpeg): array => array_slice((array) getimagesizefromstring($jpeg), 0, 2);

// --- fit() -------------------------------------------------------------------
$big = Images::fit($png(1200, 800));
check('imagine lată → 600 px, proporții păstrate', $big !== null && $size($big) === [600, 400]);
$small = Images::fit($png(300, 200));
check('imagine mică → dimensiune neschimbată', $small !== null && $size($small) === [300, 200]);
check('rezultatul e JPEG', $big !== null && str_starts_with($big, "\xFF\xD8"));
check('conținut care nu e imagine → null', Images::fit('<html>403 Forbidden</html>') === null && Images::fit('') === null);
$alpha = Images::fit($png(100, 100, true));
$im = imagecreatefromstring((string) $alpha);
$rgb = imagecolorat($im, 50, 50);
check('transparența devine fundal alb, nu negru', (($rgb >> 16) & 0xFF) > 240 && (($rgb >> 8) & 0xFF) > 240 && ($rgb & 0xFF) > 240);

// --- localize() --------------------------------------------------------------
$dir = sys_get_temp_dir() . '/nl-img-' . bin2hex(random_bytes(4));
mkdir($dir);
$calls = 0;
$images = new Images($dir, 'https://www.motociclete.com.ro', ['bikershop.ro', 'www.bikershop.ro'],
    static function (string $url) use (&$calls, $png): ?string {
        $calls++;
        return str_contains($url, 'lipsa') ? null : (str_contains($url, 'html') ? '<html>challenge</html>' : $png(1000, 1000));
    });

$url = 'https://bikershop.ro/12345-large_default/jacheta.jpg';
$local = $images->localize($url);
$file = $dir . '/newsletter/bs/' . sha1($url) . '.jpg';
check('imaginea BikerShop primește URL local', $local === 'https://www.motociclete.com.ro/media/newsletter/bs/' . sha1($url) . '.jpg');
check('fișierul e scris și redimensionat', is_file($file) && $size((string) file_get_contents($file)) === [600, 600]);
$images->localize($url);
check('a doua cerere pentru aceeași imagine nu mai descarcă', $calls === 1);

check('gazdă nepermisă → URL-ul original, fără descărcare',
    $images->localize('https://evil.example/x.jpg') === 'https://evil.example/x.jpg' && $calls === 1);
check('imagine de pe situl nostru → neatinsă',
    $images->localize('https://www.motociclete.com.ro/media/newsletter/k5.jpg') === 'https://www.motociclete.com.ro/media/newsletter/k5.jpg');
check('descărcare eșuată → URL-ul original + avertisment',
    $images->localize('https://bikershop.ro/lipsa.jpg') === 'https://bikershop.ro/lipsa.jpg' && count($images->warnings()) === 1);
check('răspuns care nu e imagine → URL-ul original + avertisment',
    $images->localize('https://bikershop.ro/html.jpg') === 'https://bikershop.ro/html.jpg' && count($images->warnings()) === 2);
check('URL gol → gol', $images->localize('') === '');

array_map('unlink', glob($dir . '/newsletter/bs/*') ?: []);
@rmdir($dir . '/newsletter/bs');
@rmdir($dir . '/newsletter');
@rmdir($dir);

nl_done();
