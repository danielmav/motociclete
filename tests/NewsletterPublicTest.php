<?php

declare(strict_types=1);

/**
 * Test HTTP al fluxului abonare → confirmare → preferințe → dezabonare.
 * Necesită situl local pornit (Laragon) pe APP_URL.
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterPublicTest.php
 */

require __DIR__ . '/_nl.php';

use App\Newsletter\Repository;

$base = rtrim((string) $GLOBALS['nl_settings']['app']['url'], '/') . (string) $GLOBALS['nl_settings']['app']['base_path'];
$pdo  = nl_db()->local();
$repo = new Repository(nl_db());

$cleanup = static function () use ($pdo): void {
    $pdo->exec("DELETE s FROM nl_subscriptions s JOIN nl_subscribers u ON u.id = s.subscriber_id WHERE u.email LIKE '%@nl-test.invalid'");
    $pdo->exec("DELETE FROM nl_subscribers WHERE email LIKE '%@nl-test.invalid'");
    $pdo->exec("DELETE FROM email_log WHERE to_addr LIKE '%@nl-test.invalid'");
};
$cleanup();
register_shutdown_function($cleanup);

/** @return array{0:int,1:string} [status, body] */
$http = static function (string $method, string $url, array $fields = [], bool $ajax = true): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $ajax ? ['X-Requested-With: XMLHttpRequest'] : [],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
};
$mails = static fn (string $to): int => (int) $pdo->query(
    'SELECT COUNT(*) FROM email_log WHERE to_addr = ' . $pdo->quote($to)
)->fetchColumn();

$email = 'vizitator@nl-test.invalid';
$api   = $base . '/api/newsletter/abonare';

// --- validare ----------------------------------------------------------------
[$c, $b] = $http('POST', $api, ['email' => $email, 'lists' => ['stiri']]);
check('fără acord → 422', $c === 422 && str_contains($b, '"ok":false'));
[$c] = $http('POST', $api, ['email' => $email, 'consent' => '1']);
check('fără nicio listă → 422', $c === 422);
[$c] = $http('POST', $api, ['email' => 'nu-e-email', 'lists' => ['stiri'], 'consent' => '1']);
check('email invalid → 422', $c === 422);
[$c] = $http('POST', $api, ['email' => 'guest-emag-9@bikershop.ro', 'lists' => ['stiri'], 'consent' => '1']);
check('adresă blocată → 422', $c === 422);
[$c, $b] = $http('POST', $api, ['email' => 'bot@nl-test.invalid', 'lists' => ['stiri'], 'consent' => '1', 'website' => 'spam']);
check('honeypot → succes aparent, fără rând', $c === 200 && $repo->findByEmail('bot@nl-test.invalid') === null);

// --- abonare -----------------------------------------------------------------
[$c, $b] = $http('POST', $api, ['email' => ' Vizitator@NL-Test.invalid ', 'lists' => ['stiri', 'altceva'], 'consent' => '1']);
$sub = $repo->findByEmail($email);
check('abonare validă → 200 ok', $c === 200 && str_contains($b, '"ok":true'));
check('abonatul e creat ca pending, fără abonamente',
    $sub !== null && $sub['status'] === 'pending' && $repo->subscriptions((int) $sub['id']) === []);
check('un email de confirmare', $mails($email) === 1);

// Review Focus 5: retrimiterea imediată nu inundă adresa.
$http('POST', $api, ['email' => $email, 'lists' => ['stiri'], 'consent' => '1']);
$http('POST', $api, ['email' => $email, 'lists' => ['oferte'], 'consent' => '1']);
check('retrimitere în 15 minute → tot un singur email', $mails($email) === 1);

$token = (string) $sub['token'];

// --- confirmare --------------------------------------------------------------
[$c] = $http('GET', $base . '/newsletter/confirmare/' . str_repeat('0', 32) . '?l=stiri', [], false);
check('token necunoscut → 404', $c === 404);
[$c] = $http('GET', $base . '/newsletter/confirmare/nu-e-token', [], false);
check('token cu format greșit → 404', $c === 404);

[$c, $b] = $http('GET', $base . '/newsletter/confirmare/' . $token . '?l=stiri,altceva', [], false);
$sub  = $repo->findByEmail($email);
$subs = $repo->subscriptions((int) $sub['id']);
check('confirmare → 200 + abonat activ', $c === 200 && $sub['status'] === 'active');
check('confirmare → doar lista validă cerută, sursa portal',
    isset($subs['stiri']) && !isset($subs['oferte']) && $subs['stiri']['source'] === 'portal');
check('pagina de confirmare e noindex', str_contains($b, 'noindex'));

// --- preferințe: GET nu schimbă starea (Review Focus 4) ----------------------
[$c, $b] = $http('GET', $base . '/newsletter/dezabonare/' . $token . '?l=stiri', [], false);
check('pagina de preferințe → 200', $c === 200 && str_contains($b, 'Dual Motors știri'));
check('GET pe linkul de dezabonare NU dezabonează',
    $repo->subscriptions((int) $sub['id'])['stiri']['status'] === 'active');

// --- dezabonare cu un clic (Gmail/Yahoo) -------------------------------------
[$c, $b] = $http('POST', $base . '/newsletter/dezabonare/' . $token . '?l=stiri', ['List-Unsubscribe' => 'One-Click'], false);
check('one-click → 200', $c === 200);
check('one-click → dezabonat de la lista din link',
    $repo->subscriptions((int) $sub['id'])['stiri']['status'] === 'unsubscribed');

// --- formularul de preferințe ------------------------------------------------
[$c] = $http('POST', $base . '/newsletter/dezabonare/' . $token, ['keep' => ['oferte', 'stiri']], false);
$subs = $repo->subscriptions((int) $sub['id']);
check('salvare preferințe → 303', $c === 303);
check('bifat → ambele liste active', $subs['oferte']['status'] === 'active' && $subs['stiri']['status'] === 'active');

$http('POST', $base . '/newsletter/dezabonare/' . $token, ['keep' => ['oferte']], false);
$subs = $repo->subscriptions((int) $sub['id']);
check('debifat → doar lista debifată e dezabonată',
    $subs['oferte']['status'] === 'active' && $subs['stiri']['status'] === 'unsubscribed');

$http('POST', $base . '/newsletter/dezabonare/' . $token, ['unsub_all' => '1', 'keep' => ['oferte']], false);
$subs = $repo->subscriptions((int) $sub['id']);
check('„dezabonează-mă de la tot" → ambele dezabonate',
    $subs['oferte']['status'] === 'unsubscribed' && $subs['stiri']['status'] === 'unsubscribed');

[$c] = $http('POST', $base . '/newsletter/dezabonare/' . str_repeat('0', 32), ['unsub_all' => '1'], false);
check('POST cu token necunoscut → 404', $c === 404);

// --- limita pe IP (Review Focus 5) -------------------------------------------
$codes = [];
for ($i = 1; $i <= 6; $i++) {
    [$codes[]] = $http('POST', $api, ['email' => "ip{$i}@nl-test.invalid", 'lists' => ['stiri'], 'consent' => '1']);
}
check('peste 5 abonați noi pe oră de pe același IP → 429', $codes[0] === 200 && end($codes) === 429);
check('adresa peste limită nu e creată', $repo->findByEmail('ip6@nl-test.invalid') === null);

// --- fallback fără JavaScript ------------------------------------------------
[$c] = $http('POST', $api, ['email' => 'nu-e-email', 'lists' => ['stiri'], 'consent' => '1'], false);
check('fără AJAX → redirect 303', $c === 303);
[$c, $b] = $http('GET', $base . '/newsletter/abonare?ok=1', [], false);
check('pagina de stare → 200', $c === 200 && str_contains($b, 'Verifică-ți emailul'));

nl_done();
