<?php
// Instalează modulul dmlegalguarantee pe BikerShop (CLI) și (re)înregistrează hook-urile pe toate shop-urile.
// Rulare: ea-php84 install_dmlegalguarantee.php [--uninstall]
define('_PS_ADMIN_DIR_', '/home2/bikershop/public_html/__admin322y');
require '/home2/bikershop/public_html/config/config.inc.php';
Shop::setContext(Shop::CONTEXT_ALL);
$m = Module::getInstanceByName('dmlegalguarantee');
if (!$m) { fwrite(STDERR, "modul negasit\n"); exit(1); }
if (in_array('--uninstall', $argv, true)) { var_dump($m->uninstall()); exit; }
if (!Module::isInstalled('dmlegalguarantee')) {
    echo $m->install() ? "instalat\n" : ("EROARE install: " . implode('; ', $m->getErrors()) . "\n");
}
$shops = Shop::getShops(false, null, true);
foreach (['displayHeader', 'displayNav1', 'displayProductAdditionalInfo', 'displayPaymentTop', 'displayFooterAfter', 'displayBeforeBodyClosingTag'] as $h) {
    $ok = $m->isRegisteredInHook($h) ?: $m->registerHook($h, $shops);
    echo str_pad($h, 32) . ($ok ? "OK" : "EROARE") . "\n";
}
