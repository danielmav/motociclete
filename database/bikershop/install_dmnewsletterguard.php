<?php
// Instalează modulul dmnewsletterguard pe BikerShop (CLI) și (re)înregistrează hook-urile pe toate shop-urile.
// Rulare: ea-php84 install_dmnewsletterguard.php [--enforce | --observe | --uninstall]
//   --enforce  pornește verificarea jetonului (DOAR după golirea cache-ului LiteSpeed din BO)
//   --observe  o oprește la loc (lipsa jetonului e doar notată în var/logs/dmnewsletterguard.log)
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Doar din linia de comandă.\n");
}
define('_PS_ADMIN_DIR_', '/home2/bikershop/public_html/__admin322y');
require '/home2/bikershop/public_html/config/config.inc.php';
Shop::setContext(Shop::CONTEXT_ALL);
$m = Module::getInstanceByName('dmnewsletterguard');
if (!$m) { fwrite(STDERR, "modul negasit\n"); exit(1); }
if (in_array('--uninstall', $argv, true)) { var_dump($m->uninstall()); exit; }
if (!Module::isInstalled('dmnewsletterguard')) {
    echo $m->install() ? "instalat\n" : ("EROARE install: " . implode('; ', $m->getErrors()) . "\n");
}
$shops = Shop::getShops(false, null, true);
foreach (DmNewsletterGuard::HOOKS as $h) {
    $ok = $m->isRegisteredInHook($h) ?: $m->registerHook($h, $shops);
    echo str_pad($h, 38) . ($ok ? "OK" : "EROARE") . "\n";
}
if (in_array('--enforce', $argv, true)) {
    Configuration::updateGlobalValue(DmNewsletterGuard::CFG_ENFORCE, 1);
} elseif (in_array('--observe', $argv, true)) {
    Configuration::updateGlobalValue(DmNewsletterGuard::CFG_ENFORCE, 0);
}
echo 'verificare jeton: ' . ((int) Configuration::get(DmNewsletterGuard::CFG_ENFORCE) ? "PORNITA\n" : "doar notare\n");
// Redis (teamwant) ține lista veche de module pe hook-uri → fără golire modulul nu apare în front.
Cache::getInstance()->flush();
echo "cache Redis golit\n";
