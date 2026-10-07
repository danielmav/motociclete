<?php

declare(strict_types=1);

/**
 * Import unic al excluderilor din Brevo. Dry-run implicit; --apply execută.
 *
 *   php database/newsletter_import_brevo.php <fisier.csv> --as=unsubscribed [--apply]
 *   php database/newsletter_import_brevo.php <fisier.csv> --as=bounced      [--apply]
 *
 * Exporturile se fac din Brevo → Contacts, filtrate pe „Unsubscribed" / „Blocklisted"
 * (→ --as=unsubscribed) și pe „Hard bounced" (→ --as=bounced). CSV-urile conțin date
 * personale: ține-le în storage/newsletter/ (gitignored) și șterge-le după import.
 */

use App\Database;
use App\Newsletter\BrevoImport;
use App\Newsletter\Repository;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
$settings = require $root . '/config/settings.php';

$file = null;
$as   = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--as=')) {
        $as = substr($arg, 5);
    } elseif (!str_starts_with($arg, '--')) {
        $file = $arg;
    }
}
$apply = in_array('--apply', $argv, true);

if ($file === null || !in_array($as, ['unsubscribed', 'bounced'], true)) {
    fwrite(STDERR, "Utilizare: newsletter_import_brevo.php <fisier.csv> --as=unsubscribed|bounced [--apply]\n");
    exit(2);
}

try {
    $emails = BrevoImport::emailsFromCsv($file);
    $r = (new BrevoImport(new Repository(new Database($settings['db']))))->run($emails, $as, $apply);
} catch (Throwable $e) {
    fwrite(STDERR, 'Eroare: ' . $e->getMessage() . "\n");
    exit(1);
}

$verb = $apply ? '' : ' (ar fi)';
echo ($apply ? "IMPORT BREVO (--apply)\n" : "IMPORT BREVO (dry-run): folosește --apply pentru a executa\n");
echo "  adrese valide în fișier: {$r['total']}\n";
echo "  marcate {$as}{$verb}: {$r['total']} ({$r['created']} noi, {$r['updated']} existente)\n";
exit(0);
