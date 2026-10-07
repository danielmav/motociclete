<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterAddressTest.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Newsletter\Address;

$failures = 0;
$count    = 0;

function check(string $label, bool $ok): void
{
    global $failures, $count;
    $count++;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . "\n";
}

// --- normalize() -------------------------------------------------------------
check('normalize: litere mici + fără spații', Address::normalize("  Ion.Pop@Gmail.COM \n") === 'ion.pop@gmail.com');
check('normalize: null rămâne null', Address::normalize(null) === null);
check('normalize: șir gol', Address::normalize('   ') === null);
check('normalize: fără @', Address::normalize('ion.gmail.com') === null);
check('normalize: fără domeniu', Address::normalize('ion@') === null);
check('normalize: spațiu în interior', Address::normalize('ion pop@gmail.com') === null);
check('normalize: peste 190 de caractere', Address::normalize(str_repeat('a', 185) . '@x.ro') === null);

// --- isBlocked() -------------------------------------------------------------
check('blocked: adresă fictivă eMAG', Address::isBlocked('guest-emag-533289276@bikershop.ro'));
check('blocked: orice adresă pe bikershop.ro', Address::isBlocked('comenzi@bikershop.ro'));
check('blocked: subdomeniu bikershop.ro', Address::isBlocked('x@mail.bikershop.ro'));
check('blocked: emag.ro', Address::isBlocked('cineva@emag.ro'));
check('blocked: domeniu de test tfbnw.net', Address::isBlocked('open_abc@tfbnw.net'));
check('blocked: prefix guest-emag pe alt domeniu', Address::isBlocked('guest-emag-1@altceva.ro'));
check('permis: gmail', !Address::isBlocked('ion@gmail.com'));
check('permis: echipa pe motociclete.com.ro', !Address::isBlocked('info@motociclete.com.ro'));
check('permis: domeniu care doar se termină la fel', !Address::isBlocked('ion@notbikershop.ro'));

// --- clean() -----------------------------------------------------------------
check('clean: adresă bună', Address::clean(' Ion@Yahoo.com') === 'ion@yahoo.com');
check('clean: adresă blocată → null', Address::clean('Guest-Emag-1@BikerShop.ro') === null);
check('clean: adresă invalidă → null', Address::clean('nu-e-email') === null);

echo "\n{$count} verificări, {$failures} eșecuri\n";
exit($failures ? 1 : 0);
