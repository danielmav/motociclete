<?php

declare(strict_types=1);

/**
 * Setează în bloc `products.yamaha_pid` dintr-un CSV (id,categorie,model,pid).
 * Completează coloana `pid` în tests/yamaha_pids.csv DOAR pentru motociclete &
 * scutere (bărci/motoare/golf/ATV n-au accesorii moto pe endpointul Yamaha),
 * apoi rulează:
 *   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe tests/set_yamaha_pids.php [--apply]
 * Fără --apply = dry-run. PID gol pe un rând = ignorat (nu șterge ce e deja setat).
 */

require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$settings = require dirname(__DIR__) . '/config/settings.php';
$pdo = (new App\Database($settings['db']))->local();

$apply = in_array('--apply', $argv, true);
$csv = __DIR__ . '/yamaha_pids.csv';
if (!is_file($csv)) {
    fwrite(STDERR, "Lipsește {$csv}. Generează-l întâi (vezi instrucțiunile).\n");
    exit(1);
}

// Excel pe RO salvează cu ';' — detectează separatorul din prima linie.
$firstLine = (string) fgets(fopen($csv, 'r'));
$delim = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

$fh = fopen($csv, 'r');
$header = fgetcsv($fh, 0, $delim); // id,categorie,model,pid
$upd = $pdo->prepare("UPDATE products SET yamaha_pid = :p WHERE id = :id AND brand = 'yamaha'");
$set = 0;
$skipped = 0;
while (($row = fgetcsv($fh, 0, $delim)) !== false) {
    $id = (int) ($row[0] ?? 0);
    $pidRaw = trim((string) ($row[3] ?? ''));
    if ($id < 1 || $pidRaw === '' || !preg_match('/^\d+$/', $pidRaw)) {
        $skipped++;
        continue;
    }
    echo sprintf("  id=%-4d → PID %s   %s\n", $id, $pidRaw, $row[2] ?? '');
    if ($apply) {
        $upd->execute([':p' => $pidRaw, ':id' => $id]);
    }
    $set++;
}
fclose($fh);

echo str_repeat('-', 50) . "\n";
echo ($apply ? "Setate: {$set}" : "DRY-RUN: ar seta {$set}") . " | rânduri fără PID (ignorate): {$skipped}\n";
echo $apply ? "Gata. Următor: import_yamaha_accessories.php --apply\n" : "Adaugă --apply ca să salvezi.\n";
