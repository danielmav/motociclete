<?php

declare(strict_types=1);

/**
 * Retention GDPR: anonimizează IP-uri (30 zile) + restul PII (365 zile) pe tabelele
 * tranzitorii și șterge logurile vechi. Lead-urile/programările NU se șterg —
 * se golește PII-ul (se păstrează tip/brand/dată pentru statistici).
 *
 * Dry-run implicit (doar raportează counts). --apply execută modificările.
 *
 * Rulează cu PHP 8.1 (pdo_mysql). Pe server, cron zilnic (alt-php81):
 *   30 3 * * * /opt/alt/php81/usr/bin/php /home/dualmotors/public_html/motociclete.com.ro/database/retention.php --apply >> /home/dualmotors/retention.log 2>&1
 * Local:
 *   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/retention.php [--apply]
 *
 * NU atinge `clienti` / `service_requests` (bază legală: contract).
 */

use App\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
$settings = require $root . '/config/settings.php';

const IP_DAYS       = 30;   // anonimizare IP (Stage A)
const PII_DAYS      = 365;  // anonimizare restul PII (Stage B)
const EMAILLOG_DAYS = 365;  // ștergere email_log
const OTP_DAYS      = 7;    // ștergere coduri OTP

$apply = in_array('--apply', $argv, true);
$pdo   = (new Database($settings['db']))->local();

echo $apply
    ? "RETENTION (--apply): execut modificările\n"
    : "RETENTION (dry-run): doar raportez — folosește --apply pentru a executa\n";

$errors = [];

/**
 * O operație de retention: numără rândurile țintă cu $countSql; dacă --apply și
 * există rânduri, execută $writeSql (aceleași predicate). Raportează și prinde erorile.
 */
$op = static function (string $label, string $countSql, string $writeSql) use ($pdo, $apply, &$errors): void {
    try {
        $n = (int) $pdo->query($countSql)->fetchColumn();
        if ($apply && $n > 0) {
            $pdo->exec($writeSql);
        }
        $verb = $apply ? 'modificate' : 'ar fi modificate';
        echo "  {$label}: {$n} {$verb}\n";
    } catch (Throwable $e) {
        $errors[] = "{$label}: " . $e->getMessage();
        echo "  {$label}: EROARE — " . $e->getMessage() . "\n";
    }
};

// 1. site_messages — Stage A (IP, 30 zile)
$op(
    'site_messages IP (' . IP_DAYS . 'z)',
    'SELECT COUNT(*) FROM site_messages WHERE created_at < (NOW() - INTERVAL ' . IP_DAYS . ' DAY) AND ip IS NOT NULL',
    'UPDATE site_messages SET ip=NULL WHERE created_at < (NOW() - INTERVAL ' . IP_DAYS . ' DAY) AND ip IS NOT NULL'
);

// site_messages — Stage B (PII, 365 zile)
$op(
    'site_messages PII (' . PII_DAYS . 'z)',
    'SELECT COUNT(*) FROM site_messages WHERE created_at < (NOW() - INTERVAL ' . PII_DAYS . ' DAY) AND anonymized_at IS NULL',
    "UPDATE site_messages SET name='', email='', phone='', message=NULL, licence=NULL, ip=NULL, anonymized_at=NOW() WHERE created_at < (NOW() - INTERVAL " . PII_DAYS . ' DAY) AND anonymized_at IS NULL'
);

// 2. service_bookings — Stage A (IP, 30 zile)
$op(
    'service_bookings IP (' . IP_DAYS . 'z)',
    'SELECT COUNT(*) FROM service_bookings WHERE created_at < (NOW() - INTERVAL ' . IP_DAYS . ' DAY) AND ip IS NOT NULL',
    'UPDATE service_bookings SET ip=NULL WHERE created_at < (NOW() - INTERVAL ' . IP_DAYS . ' DAY) AND ip IS NOT NULL'
);

// service_bookings — Stage B (PII, 365 zile)
$op(
    'service_bookings PII (' . PII_DAYS . 'z)',
    'SELECT COUNT(*) FROM service_bookings WHERE created_at < (NOW() - INTERVAL ' . PII_DAYS . ' DAY) AND anonymized_at IS NULL',
    "UPDATE service_bookings SET name='', email=NULL, phone=NULL, sasiu=NULL, lucrari=NULL, ip=NULL, anonymized_at=NOW() WHERE created_at < (NOW() - INTERVAL " . PII_DAYS . ' DAY) AND anonymized_at IS NULL'
);

// 3. email_log — ștergere (365 zile)
$op(
    'email_log delete (' . EMAILLOG_DAYS . 'z)',
    'SELECT COUNT(*) FROM email_log WHERE created_at < (NOW() - INTERVAL ' . EMAILLOG_DAYS . ' DAY)',
    'DELETE FROM email_log WHERE created_at < (NOW() - INTERVAL ' . EMAILLOG_DAYS . ' DAY)'
);

// 4. client_otp — ștergere (7 zile)
$op(
    'client_otp delete (' . OTP_DAYS . 'z)',
    'SELECT COUNT(*) FROM client_otp WHERE created_at < (NOW() - INTERVAL ' . OTP_DAYS . ' DAY)',
    'DELETE FROM client_otp WHERE created_at < (NOW() - INTERVAL ' . OTP_DAYS . ' DAY)'
);

if ($errors) {
    fwrite(STDERR, 'Erori: ' . implode('; ', $errors) . "\n");
    exit(1);
}
echo $apply ? "Retention aplicat.\n" : "Dry-run complet.\n";
exit(0);
