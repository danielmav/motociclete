<?php
// Jetonul modulului BikerShop dmnewsletterguard (fără PrestaShop: doar metodele statice).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
define('_PS_VERSION_', '9.0.2');
define('_COOKIE_KEY_', 'cheie-de-test');
class Module
{
}
require __DIR__ . '/../database/bikershop/modules/dmnewsletterguard/dmnewsletterguard.php';

$fail = 0;
$check = static function (string $what, bool $ok) use (&$fail): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fail += $ok ? 0 : 1;
};

$t0 = 1791400000;
$tok = DmNewsletterGuard::issueToken('Ana@Example.com', $t0);

$check('prea devreme (1 s) e respins', !DmNewsletterGuard::tokenValid($tok, 'ana@example.com', $t0 + 1));
$check('dupa MIN_AGE e acceptat', DmNewsletterGuard::tokenValid($tok, 'ana@example.com', $t0 + DmNewsletterGuard::MIN_AGE));
$check('adresa se compara fara majuscule/spatii', DmNewsletterGuard::tokenValid($tok, ' ANA@example.com ', $t0 + 5));
$check('alta adresa e respinsa', !DmNewsletterGuard::tokenValid($tok, 'alt@example.com', $t0 + 5));
$check('expirat e respins', !DmNewsletterGuard::tokenValid($tok, 'ana@example.com', $t0 + DmNewsletterGuard::MAX_AGE + 1));
$check('din viitor e respins', !DmNewsletterGuard::tokenValid($tok, 'ana@example.com', $t0 - 60));
$check('semnatura modificata e respinsa', !DmNewsletterGuard::tokenValid(substr($tok, 0, -1) . (substr($tok, -1) === 'a' ? 'b' : 'a'), 'ana@example.com', $t0 + 5));
$check('gol / forma gresita e respins', !DmNewsletterGuard::tokenValid('', 'ana@example.com', $t0 + 5) && !DmNewsletterGuard::tokenValid('abc', 'ana@example.com', $t0 + 5));

exit($fail ? 1 : 0);
