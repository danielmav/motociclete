<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterBrevoImportTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\BrevoImport;
use App\Newsletter\Repository;
use App\Newsletter\Sync;

$pdo    = nl_isolate();
$repo   = new Repository(nl_db());
$import = new BrevoImport($repo);

$tmp = static function (string $content): string {
    $path = tempnam(sys_get_temp_dir(), 'nlcsv');
    file_put_contents($path, $content);
    return $path;
};

// --- citirea CSV-ului --------------------------------------------------------
$semi = $tmp("\xEF\xBB\xBFCONTACT ID;EMAIL;NUME\n1; Dan@NL-Test.invalid ;Dan\n2;eva@nl-test.invalid;Eva\n3;dan@nl-test.invalid;Dublura\n4;nu-e-email;X\n");
check('csv: separator ; + BOM + dubluri + invalide',
    BrevoImport::emailsFromCsv($semi) === ['dan@nl-test.invalid', 'eva@nl-test.invalid']);

$comma = $tmp("email,added\n\"fane@nl-test.invalid\",2026-01-01\n");
check('csv: separator , + antet cu litere mici + ghilimele',
    BrevoImport::emailsFromCsv($comma) === ['fane@nl-test.invalid']);

$noCol = $tmp("NUME;TELEFON\nDan;0700\n");
$threw = false;
try {
    BrevoImport::emailsFromCsv($noCol);
} catch (RuntimeException) {
    $threw = true;
}
check('csv: fără coloana EMAIL → excepție', $threw);

// --- dezabonați --------------------------------------------------------------
$r = $import->run(['dan@nl-test.invalid'], 'unsubscribed', false);
check('dry-run: raportează fără să scrie', $r['total'] === 1 && $repo->findByEmail('dan@nl-test.invalid') === null);

$existing = $repo->ensureSubscriber('eva@nl-test.invalid', 'Eva', 'active');
$repo->addSubscription((int) $existing['id'], 'oferte', 'bs_account');

$r = $import->run(['dan@nl-test.invalid', 'eva@nl-test.invalid'], 'unsubscribed', true);
check('unsubscribed: 1 creat, 1 actualizat', $r['created'] === 1 && $r['updated'] === 1);
$dan = $repo->findByEmail('dan@nl-test.invalid');
$dsubs = $repo->subscriptions((int) $dan['id']);
check('unsubscribed: ambele liste excluse, sursa brevo',
    $dsubs['oferte']['status'] === 'unsubscribed' && $dsubs['stiri']['status'] === 'unsubscribed' && $dsubs['stiri']['source'] === 'brevo');
check('unsubscribed: abonamentul existent devine dezabonat',
    $repo->subscriptions((int) $existing['id'])['oferte']['status'] === 'unsubscribed');

// Sincronizarea de după import nu îi readuce.
(new Sync($repo))->run([
    'bs_account' => [['email' => 'dan@nl-test.invalid', 'name' => 'Dan']],
    'bs_footer'  => [],
    'garage'     => [['email' => 'eva@nl-test.invalid', 'name' => 'Eva']],
], true);
check('după sync: dezabonatul din Brevo rămâne dezabonat',
    $repo->subscriptions((int) $dan['id'])['oferte']['status'] === 'unsubscribed'
    && $repo->subscriptions((int) $existing['id'])['stiri']['status'] === 'unsubscribed');

// --- respinși ----------------------------------------------------------------
$import->run(['fane@nl-test.invalid', 'eva@nl-test.invalid'], 'bounced', true);
check('bounced: adresă nouă creată ca bounced', $repo->findByEmail('fane@nl-test.invalid')['status'] === 'bounced');
check('bounced: adresă existentă trecută pe bounced', $repo->findByEmail('eva@nl-test.invalid')['status'] === 'bounced');

$threw = false;
try {
    $import->run(['x@nl-test.invalid'], 'altceva', true);
} catch (InvalidArgumentException) {
    $threw = true;
}
check('tip necunoscut → excepție', $threw);

nl_done();
