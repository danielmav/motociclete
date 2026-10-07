<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterTransportTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Transport;

$dir = sys_get_temp_dir() . '/nl-tr-' . bin2hex(random_bytes(4));
mkdir($dir);
$cfg = ['smtp_host' => 'smtp.exemplu.invalid', 'smtp_port' => 587, 'smtp_user' => '', 'smtp_pass' => '', 'smtp_secure' => 'tls',
    'from' => 'noutati@news.motociclete.com.ro', 'from_name' => 'Dual Motors', 'reply_to' => 'info@motociclete.com.ro'];

// --- modul dev: scrie în jurnal, nu trimite ----------------------------------
$t = new Transport($cfg, $dir, true);
$ok = $t->send('ion@nl-test.invalid', 'Subiect de probă', '<p>Salut <b>Ion</b></p>', "Salut Ion\n",
    ['List-Unsubscribe' => '<https://x.test/u>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
$log = (string) @file_get_contents($dir . '/newsletter.log');
check('dev: send întoarce true', $ok === true);
check('dev: jurnalul are destinatarul, subiectul și expeditorul',
    str_contains($log, 'TO: ion@nl-test.invalid') && str_contains($log, 'SUBJECT: Subiect de probă') && str_contains($log, 'FROM: Dual Motors <noutati@news.motociclete.com.ro>'));
check('dev: jurnalul are headerele și textul', str_contains($log, 'List-Unsubscribe: <https://x.test/u>') && str_contains($log, 'Salut Ion'));
check('dev: ultimul HTML e salvat separat', file_get_contents($dir . '/newsletter-last.html') === '<p>Salut <b>Ion</b></p>');

$t->send('ana@nl-test.invalid', 'Al doilea', '<p>2</p>', '2');
check('dev: jurnalul se completează, nu se suprascrie',
    substr_count((string) file_get_contents($dir . '/newsletter.log'), 'TO: ') === 2);

// --- validare ----------------------------------------------------------------
check('adresă invalidă → false cu motiv', $t->send('nu-e-email', 'x', '<p>x</p>', 'x') === false && $t->lastError() !== '');
check('încercare de injectare în subiect → false',
    $t->send('ion@nl-test.invalid', "Subiect\r\nBcc: victima@nl-test.invalid", '<p>x</p>', 'x') === false);
check('încercare de injectare în header → false',
    $t->send('ion@nl-test.invalid', 'x', '<p>x</p>', 'x', ['X-Test' => "a\r\nBcc: victima@nl-test.invalid"]) === false);
check('după un eșec, jurnalul nu primește mesajul', substr_count((string) file_get_contents($dir . '/newsletter.log'), 'TO: ') === 2);

// --- fără gazdă SMTP configurată: tot jurnal, chiar și în afara dev ----------
$t2 = new Transport(['smtp_host' => ''] + $cfg, $dir, false);
check('fără smtp_host: scrie în jurnal în loc să trimită',
    $t2->send('ion@nl-test.invalid', 'Fără SMTP', '<p>x</p>', 'x') === true
    && str_contains((string) file_get_contents($dir . '/newsletter.log'), 'SUBJECT: Fără SMTP'));

array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);

nl_done();
