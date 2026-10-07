<?php

declare(strict_types=1);

/**
 * Sincronizează abonații de newsletter din BikerShop (conturi + footer) și My Garage.
 * Dry-run implicit (doar raportează). --apply execută.
 *
 * Local:
 *   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/newsletter_sync.php [--apply]
 * Server (cron noaptea):
 *   30 1 * * * /usr/local/bin/ea-php81 /home/dualmotors/public_html/motociclete.com.ro/database/newsletter_sync.php --apply >> /home/dualmotors/newsletter_sync.log 2>&1
 */

use App\BikerShop\Client as BikerShop;
use App\Client\Repository as Garage;
use App\Database;
use App\Newsletter\Repository;
use App\Newsletter\Sync;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
$settings = require $root . '/config/settings.php';

$apply = in_array('--apply', $argv, true);
$db    = new Database($settings['db']);

echo date('Y-m-d H:i:s') . ($apply ? " SYNC (--apply)\n" : " SYNC (dry-run): folosește --apply pentru a executa\n");

try {
    $sources = Sync::gather(new BikerShop($db, $settings['db']['bikershop']), new Garage($db));
    foreach ($sources as $name => $rows) {
        echo "  sursa {$name}: " . (is_array($rows) ? count($rows) . ' rânduri' : 'INDISPONIBILĂ') . "\n";
    }
    $r = (new Sync(new Repository($db)))->run($sources, $apply);
} catch (Throwable $e) {
    fwrite(STDERR, 'Eroare: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($r['aborted'] !== null) {
    fwrite(STDERR, 'ABANDONAT: ' . $r['aborted'] . "\n");
    exit(1);
}

$verb = $apply ? '' : ' (ar fi)';
echo "  abonați noi{$verb}: {$r['new_subscribers']}\n";
echo "  activați din pending{$verb}: {$r['activated']}\n";
foreach ($r['added'] as $source => $n) {
    echo "  abonamente adăugate din {$source}{$verb}: {$n}\n";
}
echo "  dezabonați (bifă scoasă în BikerShop){$verb}: {$r['unsubscribed']}\n";
echo "  sărite: {$r['blocked']} blocate, {$r['invalid']} invalide\n";
exit(0);
