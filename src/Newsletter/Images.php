<?php

declare(strict_types=1);

namespace App\Newsletter;

use Closure;

/**
 * Imaginile produselor BikerShop din newsletter se copiază pe situl nostru
 * (`/media/newsletter/bs/`), redimensionate la cel mult 600 px: protecția Cloudflare
 * a magazinului le poate bloca în clienții de email. Se descarcă doar de pe gazdele
 * permise. La orice eșec rămâne URL-ul original și se adaugă un avertisment.
 */
final class Images
{
    private const SUBDIR = 'newsletter/bs';

    /** @var string[] */
    private array $warnings = [];

    private Closure $fetch;

    /**
     * @param string[] $allowedHosts
     * @param Closure(string):?string|null $fetch înlocuiește descărcarea (teste)
     */
    public function __construct(
        private string $mediaDir,
        private string $siteUrl,
        private array $allowedHosts = ['bikershop.ro', 'www.bikershop.ro'],
        ?Closure $fetch = null,
    ) {
        $this->siteUrl = rtrim($siteUrl, '/');
        $this->fetch = $fetch ?? Closure::fromCallable([self::class, 'download']);
    }

    /** @return string[] */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function localize(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($url === '' || !in_array($host, $this->allowedHosts, true)) {
            return $url;
        }
        $name   = sha1($url) . '.jpg';
        $dir    = rtrim($this->mediaDir, '/\\') . '/' . self::SUBDIR;
        $public = $this->siteUrl . '/media/' . self::SUBDIR . '/' . $name;
        if (is_file($dir . '/' . $name)) {
            return $public;
        }
        $bytes = ($this->fetch)($url);
        $jpeg  = $bytes !== null ? self::fit($bytes) : null;
        if ($jpeg === null) {
            $this->warnings[] = "Imaginea {$url} nu a putut fi copiată; rămâne servită de pe BikerShop.";
            return $url;
        }
        if ((!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) || @file_put_contents($dir . '/' . $name, $jpeg) === false) {
            $this->warnings[] = "Nu pot scrie în {$dir}; imaginea rămâne servită de pe BikerShop.";
            return $url;
        }
        return $public;
    }

    /** Redimensionează la cel mult $maxWidth și întoarce JPEG; null dacă nu e imagine. */
    public static function fit(string $bytes, int $maxWidth = 600, int $quality = 82): ?string
    {
        if ($bytes === '') {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $nw = min($w, $maxWidth);
        $nh = (int) max(1, round($h * $nw / $w));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // fundal alb sub transparență
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($dst, null, $quality);
        $out = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        return $out !== '' ? $out : null;
    }

    private static function download(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (newsletter motociclete.com.ro)',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return is_string($body) && $code === 200 && $body !== '' ? $body : null;
    }
}
