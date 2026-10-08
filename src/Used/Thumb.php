<?php

declare(strict_types=1);

namespace App\Used;

/**
 * Miniaturi pentru cardurile de rulate: /media/rulate/thumbs/<fișier>, max 800 px
 * lățime, același format ca sursa. Eșecul nu e fatal — cardul folosește originalul.
 */
final class Thumb
{
    public static function make(string $mediaDir, string $filename, int $maxWidth = 800, int $maxPixels = 25_000_000): bool
    {
        $filename = basename($filename);
        $src = $mediaDir . '/' . $filename;
        $dst = $mediaDir . '/thumbs/' . $filename;
        if (is_file($dst)) {
            return true;
        }
        if (!is_file($src)) {
            return false;
        }
        $info = @getimagesize($src);
        if ($info === false) {
            return false;
        }
        [$w, $h, $type] = $info;
        // GD decodează necomprimat (~5 octeți/pixel): o poză de 48 MP ar depăși memory_limit.
        if ($w * $h > $maxPixels) {
            return false;
        }
        $img = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
            IMAGETYPE_PNG  => @imagecreatefrompng($src),
            IMAGETYPE_WEBP => @imagecreatefromwebp($src),
            default        => false,
        };
        if (!$img) {
            return false;
        }
        // Pozele de telefon țin rotația în EXIF; browserul o aplică pe original, GD nu.
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($src);
            $angle = [3 => 180, 6 => -90, 8 => 90][is_array($exif) ? (int) ($exif['Orientation'] ?? 0) : 0] ?? 0;
            $rotated = $angle !== 0 ? imagerotate($img, $angle, 0) : false;
            if ($rotated) {
                imagedestroy($img);
                $img = $rotated;
                $w = imagesx($img);
                $h = imagesy($img);
            }
        }
        if (!is_dir($mediaDir . '/thumbs')) {
            @mkdir($mediaDir . '/thumbs', 0775, true);
        }
        $nw = min($w, $maxWidth);
        $nh = (int) round($h * $nw / $w);
        $out = imagecreatetruecolor($nw, $nh);
        if ($type !== IMAGETYPE_JPEG) {
            imagealphablending($out, false);
            imagesavealpha($out, true);
        }
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $ok = match ($type) {
            IMAGETYPE_JPEG => imagejpeg($out, $dst, 82),
            IMAGETYPE_PNG  => imagepng($out, $dst, 6),
            IMAGETYPE_WEBP => imagewebp($out, $dst, 82),
        };
        imagedestroy($img);
        imagedestroy($out);
        return $ok && is_file($dst);
    }
}
