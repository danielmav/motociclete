<?php

declare(strict_types=1);

/**
 * Procesează coada de trimitere a newsletterului: o tranșă din campaniile puse la
 * trimis, în limitele din admin (mesaje pe rulare / pe 24 de ore).
 * Dry-run implicit (doar raportează ce ar pleca). --apply trimite.
 *
 * Local (APP_ENV=dev: mesajele se scriu în storage/logs/newsletter.log):
 *   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/newsletter_send.php [--apply]
 * Server (cron la 5 minute; cere NL_SEND_ENABLED=1 și releul configurat în .env):
 *   [la 5 minute] /usr/local/bin/ea-php81 /home/dualmotors/public_html/motociclete.com.ro/database/newsletter_send.php --apply >> /home/dualmotors/newsletter_send.log 2>&1
 */

use App\Database;
use App\Newsletter\Sender;
use App\Newsletter\Sends;
use App\Newsletter\Tracking;
use App\Newsletter\Transport;
use App\Support\Settings;
use Dotenv\Dotenv;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
$settings = require $root . '/config/settings.php';

// PHP pe server rulează pe UTC: jurnalul se citește în ora României.
date_default_timezone_set('Europe/Bucharest');

$apply = in_array('--apply', $argv, true);
$dev   = ($settings['app']['env'] ?? 'prod') === 'dev';
$cfg   = $settings['newsletter'];
$mode  = Transport::mode($cfg, $dev);
$stamp = date('Y-m-d H:i:s');

// O singură rulare o dată: cronul următor iese dacă cea dinainte încă lucrează.
$lock = fopen($root . '/storage/cache/newsletter_send.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "{$stamp} altă rulare e în curs; ies.\n";
    exit(0);
}

try {
    $db    = new Database($settings['db']);
    $sends = new Sends($db);
    $app   = new Settings($db);
    $perRun = max(1, $app->int('nl_batch_size', 50));
    $perDay = max(1, $app->int('nl_daily_limit', 200));
    $active = $sends->activeCampaigns();

    if (!$active) {
        // Tăcut când nu e nimic de făcut: cronul rulează la 5 minute.
        exit(0);
    }
    if ($mode === 'off') {
        fwrite(STDERR, "{$stamp} " . count($active) . " campanii în coadă, dar trimiterea nu e activată (NL_SEND_ENABLED=1 + NL_SMTP_HOST în .env).\n");
        exit(1);
    }
    if (!$apply) {
        $left = max(0, $perDay - $sends->sentLast24h());
        echo "{$stamp} DRY-RUN ({$mode}): limită {$perRun}/rulare, {$perDay}/24 h, mai pot pleca azi {$left}\n";
        foreach ($active as $c) {
            $st = $sends->stats((int) $c['id']);
            echo "  #{$c['id']} „{$c['subject']}” [{$c['list_key']}]: {$st['queued']} în coadă, {$st['sent']} trimise\n";
        }
        exit(0);
    }

    $transport = new Transport($cfg, $root . '/storage/logs', $dev);
    $delay     = max(0, (int) ($cfg['send_delay_ms'] ?? 100)) * 1000;
    $sender = new Sender(
        $sends,
        new Tracking($db),
        static function (string $to, string $subject, string $html, string $text, array $headers) use ($transport): array {
            $ok = $transport->send($to, $subject, $html, $text, $headers);
            return ['ok' => $ok, 'error' => $transport->lastError(), 'message_id' => $transport->lastMessageId()];
        },
        rtrim((string) $settings['app']['url'], '/') . ($settings['app']['base_path'] ?? ''),
        $mode === 'live' && $delay > 0 ? static fn () => usleep($delay) : null
    );
    $r = $sender->run($perRun, $perDay);
} catch (Throwable $e) {
    fwrite(STDERR, "{$stamp} Eroare: " . $e->getMessage() . "\n");
    exit(1);
}

echo "{$stamp} ({$mode}) trimise {$r['sent']}, de reîncercat {$r['retry']}, eșuate {$r['failed']}, sărite {$r['skipped']}"
    . ($r['stale'] ? ", întrerupte {$r['stale']}" : '')
    . ($r['day_limit'] ? ', limita pe 24 h atinsă' : '')
    . ($r['finished'] ? ', campanii terminate: #' . implode(', #', $r['finished']) : '') . "\n";
foreach ($r['paused'] as $id => $reason) {
    echo "  PAUZĂ campania #{$id}: {$reason}\n";
}
exit(0);
