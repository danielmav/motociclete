<?php

declare(strict_types=1);

/**
 * Sincronizează accesoriile Yamaha din baza LOCALĂ în staging (dualmotors_motociclete2026
 * @ DM_* host). ID-urile produselor sunt aliniate (staging = dump din local), deci
 * sync-ul pe `id` e sigur.
 *
 *   - products.yamaha_pid : oglindă exactă a localului (UPDATE pe id)
 *   - yamaha_accessories  : oglindă EXACTĂ (delete + insert verbatim cu id explicit)
 *   - yamaha_accessory_models : oglindă exactă (delete + insert verbatim)
 *
 * IMPORTANT: oglindire exactă = staging devine identic cu local (acesta e
 * autoritativ la încărcarea inițială). Ulterior, reîmprospătarea se face de cronul
 * de pe server (rulează importul direct în staging), nu de acest script.
 *
 * Rulează cu Laragon PHP 8.1:
 *   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe tests/sync_accessories_to_staging.php [--apply]
 * Fără --apply = dry-run (doar raportează).
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/database/_dbutil.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$settings = require dirname(__DIR__) . '/config/settings.php';

$apply = in_array('--apply', $argv, true);

$loc = (new App\Database($settings['db']))->local();
$dm  = $settings['db']['dm'];
if (empty($dm['host'])) {
    fwrite(STDERR, "DM_* neconfigurat în .env\n");
    exit(1);
}
$stg = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dm['host'], $dm['port'], 'dualmotors_motociclete2026'),
    $dm['user'], $dm['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$cnt = fn (PDO $p, string $sql) => (int) $p->query($sql)->fetchColumn();
echo "── ÎNAINTE ──\n";
echo sprintf("local : pid=%d  acc=%d  links=%d\n",
    $cnt($loc, "SELECT COUNT(*) FROM products WHERE yamaha_pid IS NOT NULL AND yamaha_pid<>''"),
    $cnt($loc, "SELECT COUNT(*) FROM yamaha_accessories"),
    $cnt($loc, "SELECT COUNT(*) FROM yamaha_accessory_models"));
echo sprintf("stagng: pid=%d  acc=%d  links=%d\n",
    $cnt($stg, "SELECT COUNT(*) FROM products WHERE yamaha_pid IS NOT NULL AND yamaha_pid<>''"),
    $cnt($stg, "SELECT COUNT(*) FROM yamaha_accessories"),
    $cnt($stg, "SELECT COUNT(*) FROM yamaha_accessory_models"));

if (!$apply) {
    echo "\n(dry-run — adaugă --apply ca să sincronizezi)\n";
    exit;
}

// 0. Schema pe staging (idempotent — no-op dacă există deja).
run_sql_file($stg, dirname(__DIR__) . '/database/schema_accessories.sql');
ensure_column($stg, 'products', 'yamaha_pid', 'ALTER TABLE `products` ADD COLUMN `yamaha_pid` VARCHAR(32) NULL');

$stg->beginTransaction();

// 1. products.yamaha_pid — oglindă exactă (toate produsele, pe id).
$upd = $stg->prepare("UPDATE products SET yamaha_pid = :p WHERE id = :id");
foreach ($loc->query("SELECT id, yamaha_pid FROM products") as $r) {
    $upd->execute([':p' => ($r['yamaha_pid'] !== '' ? $r['yamaha_pid'] : null), ':id' => (int) $r['id']]);
}

// 2. yamaha_accessories — oglindă EXACTĂ (delete + insert verbatim cu id explicit,
//    ca accessory_id din legături să corespundă). Întâi golim ambele tabele.
$stg->exec("DELETE FROM yamaha_accessory_models");
$stg->exec("DELETE FROM yamaha_accessories");
$insAcc = $stg->prepare(
    "INSERT INTO yamaha_accessories (id, yamaha_id, reference, name, price_eur, accessory_type, bs_product_id)
     VALUES (:id,:yid,:ref,:name,:price,:type,:bs)"
);
foreach ($loc->query("SELECT id,yamaha_id,reference,name,price_eur,accessory_type,bs_product_id FROM yamaha_accessories") as $r) {
    $insAcc->execute([
        ':id' => (int) $r['id'], ':yid' => $r['yamaha_id'], ':ref' => $r['reference'], ':name' => $r['name'],
        ':price' => $r['price_eur'], ':type' => $r['accessory_type'], ':bs' => $r['bs_product_id'] !== null ? (int) $r['bs_product_id'] : null,
    ]);
}

// 3. yamaha_accessory_models — insert verbatim (accessory_id-urile corespund acum).
$insLink = $stg->prepare("INSERT INTO yamaha_accessory_models (accessory_id, product_id, position) VALUES (:a,:p,:pos)");
foreach ($loc->query("SELECT accessory_id,product_id,position FROM yamaha_accessory_models") as $r) {
    $insLink->execute([':a' => (int) $r['accessory_id'], ':p' => (int) $r['product_id'], ':pos' => (int) $r['position']]);
}

$stg->commit();

echo "\n── DUPĂ ──\n";
echo sprintf("stagng: pid=%d  acc=%d  links=%d\n",
    $cnt($stg, "SELECT COUNT(*) FROM products WHERE yamaha_pid IS NOT NULL AND yamaha_pid<>''"),
    $cnt($stg, "SELECT COUNT(*) FROM yamaha_accessories"),
    $cnt($stg, "SELECT COUNT(*) FROM yamaha_accessory_models"));
echo "Sincronizat.\n";
