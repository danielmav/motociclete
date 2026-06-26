<?php

declare(strict_types=1);

/**
 * Actualizează cursurile valutare EUR→RON folosite la afișarea prețurilor:
 *   - Yamaha  = cursul oficial BNR        (https://curs.bnr.ro/nbrfxrates.xml, Rate currency="EUR")
 *   - CFMOTO  = cursul de vânzare BRD     (https://www.brd.ro/... , „Vânz. BRD (RON)" din contul curent)
 * Le scrie în tabela `settings` (chei `eur_ron_rate_yamaha` / `eur_ron_rate_cfmoto`),
 * citite de App\Support\Settings::currency(). Read-only în admin.
 *
 * Rulează cu PHP 8.1 (are curl + pdo_mysql). Pe server, cron zilnic la 07:00:
 *   0 7 * * * /opt/alt/php81/usr/bin/php /home/dualmotors/public_html/motociclete.com.ro/database/update_currency.php >> /home/dualmotors/currency.log 2>&1
 * Local:
 *   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/update_currency.php
 *
 * Degradare grațioasă: dacă o sursă pică sau nu se poate parsa, NU suprascrie valoarea
 * existentă (păstrează ultimul curs bun) și raportează eroarea.
 */

use App\Database;
use App\Support\Settings;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
$settings = require $root . '/config/settings.php';

$store = new Settings(new Database($settings['db']));

/** GET simplu cu curl (User-Agent de browser; BRD respinge cererile fără). */
$fetch = static function (string $url): ?string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body === false || $code >= 400 || $body === '') ? null : (string) $body;
};

/** Formatează cursul (4 zecimale, fără zerouri inutile) pentru stocare. */
$fmt = static fn (float $v): string => rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');

$errors = [];

// --- Yamaha: BNR ----------------------------------------------------------
$bnrXml = $fetch('https://curs.bnr.ro/nbrfxrates.xml');
if ($bnrXml === null) {
    $errors[] = 'BNR: cerere eșuată';
} elseif (preg_match('/<Rate\s+currency="EUR"[^>]*>\s*([\d.]+)\s*<\/Rate>/i', $bnrXml, $m)) {
    $rate = (float) $m[1];
    if ($rate > 0) {
        $val = $fmt($rate);
        $store->set('eur_ron_rate_yamaha', $val);
        echo "Yamaha (BNR) EUR: {$val}\n";
    } else {
        $errors[] = 'BNR: valoare invalidă';
    }
} else {
    $errors[] = 'BNR: nu am găsit Rate EUR în XML';
}

// --- CFMOTO: BRD (curs de vânzare cont curent) ----------------------------
$brdHtml = $fetch('https://www.brd.ro/curs-valutar-si-dobanzi-de-referinta');
if ($brdHtml === null) {
    $errors[] = 'BRD: cerere eșuată';
} else {
    // Izolează secțiunea „cont curent" și ia primul curs „Vânz. BRD (RON)" (= rândul EUR).
    $pos = stripos($brdHtml, 'tabAccountExchangeRates');
    $hay = $pos !== false ? substr($brdHtml, $pos) : $brdHtml;
    if (preg_match('/V[\x{00e2}a]nz\.\s*BRD\s*\(RON\)<\/p>\s*<p>\s*([\d.,]+)\s*<\/p>/iu', $hay, $m)) {
        $rate = (float) str_replace(',', '.', $m[1]);
        if ($rate > 0) {
            $val = $fmt($rate);
            $store->set('eur_ron_rate_cfmoto', $val);
            echo "CFMOTO (BRD) EUR vânz.: {$val}\n";
        } else {
            $errors[] = 'BRD: valoare invalidă';
        }
    } else {
        $errors[] = 'BRD: nu am găsit „Vânz. BRD (RON)" în pagină';
    }
}

if ($errors) {
    fwrite(STDERR, "Avertismente: " . implode('; ', $errors) . " (cursurile vechi rămân neschimbate)\n");
    exit(1);
}
echo "Cursuri actualizate.\n";
