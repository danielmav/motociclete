<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/EmailTemplateLinkTest.php
 */

require __DIR__ . '/_nl.php';

use App\Support\EmailTemplate;

$url  = 'https://www.motociclete.com.ro/newsletter/confirmare/0123456789abcdef0123456789abcdef?l=oferte,stiri';
$html = EmailTemplate::textToHtml("Salut,\n\nConfirmă abonarea:\n{$url}\n\nMulțumim.");

check('linia cu URL devine link', str_contains($html, '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'));
check('restul textului rămâne paragraf', str_contains($html, '<p style="margin:0 0 12px">Salut,</p>'));
check('URL-ul nu e tratat ca rând „Cheie: valoare"', !str_contains($html, '<td style="padding:6px 10px;border:1px solid #E4E4E7;background:#FAFAFA'));

$otp = EmailTemplate::textToHtml('Codul tău este 123456');
check('codul OTP rămâne evidențiat', str_contains($otp, 'letter-spacing:4px') && str_contains($otp, '123456'));

$mixed = EmailTemplate::textToHtml('Vezi https://example.com/x pentru detalii');
check('URL în mijlocul unei fraze rămâne text', !str_contains($mixed, '<a href="https://example.com/x"'));

$xss = EmailTemplate::textToHtml('https://example.com/?a="><script>');
check('URL-ul e escapat', !str_contains($xss, '<script>'));

nl_done();
