<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterLinksTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Links;

$tail = 'utm_source=newsletter&utm_medium=email&utm_campaign=nl-7-toamna&utm_content=produs-1';

check('link simplu spre portal',
    Links::utm('https://www.motociclete.com.ro/yamaha/motociclete', 'nl-7-toamna', 'produs-1')
    === 'https://www.motociclete.com.ro/yamaha/motociclete?' . $tail);
check('link spre bikershop.ro fără www',
    Links::utm('https://bikershop.ro/722786-79528-jacheta.html', 'nl-7-toamna', 'produs-1')
    === 'https://bikershop.ro/722786-79528-jacheta.html?' . $tail);
check('link cu parametri existenți → se adaugă cu &',
    Links::utm('https://bikershop.ro/cauta?q=casca', 'nl-7-toamna', 'produs-1') === 'https://bikershop.ro/cauta?q=casca&' . $tail);
check('fragmentul rămâne la final',
    Links::utm('https://www.motociclete.com.ro/service#programare', 'nl-7-toamna', 'produs-1')
    === 'https://www.motociclete.com.ro/service?' . $tail . '#programare');
check('parametri + fragment',
    Links::utm('https://www.motociclete.com.ro/x?a=1#b', 'nl-7-toamna', 'produs-1') === 'https://www.motociclete.com.ro/x?a=1&' . $tail . '#b');
check('link extern rămâne neatins',
    Links::utm('https://www.yamaha-motor.eu/ro/ro/', 'nl-7-toamna', 'produs-1') === 'https://www.yamaha-motor.eu/ro/ro/');
check('domeniu care doar seamănă rămâne neatins',
    Links::utm('https://bikershop.ro.evil.example/x', 'nl-7-toamna', 'produs-1') === 'https://bikershop.ro.evil.example/x');
check('link care are deja utm_source rămâne neatins',
    Links::utm('https://bikershop.ro/x?utm_source=facebook', 'nl-7-toamna', 'produs-1') === 'https://bikershop.ro/x?utm_source=facebook');
check('tel: și mailto: rămân neatinse',
    Links::utm('tel:0722354437', 'c', 'x') === 'tel:0722354437' && Links::utm('mailto:info@motociclete.com.ro', 'c', 'x') === 'mailto:info@motociclete.com.ro');
check('marcajele de personalizare și linkurile goale rămân neatinse',
    Links::utm('%%UNSUB_URL%%', 'c', 'x') === '%%UNSUB_URL%%' && Links::utm('', 'c', 'x') === '' && Links::utm('#', 'c', 'x') === '#');
check('valorile sunt codate pentru URL',
    str_contains(Links::utm('https://bikershop.ro/', 'campanie cu spații & semne', 'bloc/1'), 'utm_campaign=campanie%20cu%20spa%C8%9Bii%20%26%20semne&utm_content=bloc%2F1'));
check('gazda e comparată fără diferență de litere mari',
    str_contains(Links::utm('https://WWW.BikerShop.ro/x', 'c', 'x'), 'utm_source=newsletter'));
check('domeniul local de dezvoltare primește UTM',
    str_contains(Links::utm('http://motociclete.test/yamaha', 'c', 'x'), 'utm_source=newsletter'));

nl_done();
