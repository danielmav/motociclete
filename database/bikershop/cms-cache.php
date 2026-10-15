<?php
/**
 * Invalidează cache-ul unor pagini CMS după scrieri SQL directe (ex. cms-dainese.php --apply):
 * cache-ul Smarty al iqitelementor (var/cache/prod/smarty/cache/iqitelementor/<hook>/<id_cms>/ —
 * cauza reală a conținutului vechi) + obiectul CMS din Redis (teamwant_redis).
 * Rulează PE SERVERUL BIKERSHOP, din ~/public_html/tool/:
 *   /usr/local/bin/ea-php84 cms-cache.php 6 18
 * Verificare: pagina cu ?_DISABLE_REDIS_=1 arată conținutul din DB.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
define('_PS_ADMIN_DIR_', dirname(__DIR__) . '/__admin322y');
require dirname(__DIR__) . '/config/config.inc.php';

$ids = array_filter(array_map('intval', array_slice($argv, 1)));
if (!$ids) {
    fwrite(STDERR, "Utilizare: cms-cache.php <id_cms> [<id_cms> …]\n");
    exit(2);
}
/** Șterge recursiv un director de cache (doar sub var/cache). */
function rm_tree(string $dir): int
{
    $n = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : (unlink($f->getPathname()) && $n++);
    }
    rmdir($dir);
    return $n;
}

foreach ($ids as $id) {
    // iqitelementor randează conținutul CMS printr-un șablon cu cache Smarty pe id_cms — fără asta,
    // pagina rămâne veche chiar și cu Redis golit
    foreach (glob(_PS_CACHE_DIR_ . "smarty/cache/iqitelementor/*/{$id}", GLOB_ONLYDIR) ?: [] as $dir) {
        echo "CMS #{$id}: " . rm_tree($dir) . " fișiere șterse din " . basename(dirname($dir)) . "\n";
    }
    $n = 0;
    foreach ([1, 2] as $shop) {
        foreach ([null, 1, 2] as $lang) {
            (new CMS($id, $lang, $shop))->overrideObjectCache($lang);
            $n++;
        }
    }
    echo "CMS #{$id}: {$n} intrări rescrise\n";
}
