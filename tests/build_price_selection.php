<?php

declare(strict_types=1);

/**
 * Generează o selecție pentru database/apply_dup_ref_473.php care conține TOATE
 * produsele din categoria 473 al căror preț diferă de cel derivat din Yamaha și
 * care pot fi corectate durabil (adică au exact un furnizor, deci nu intră în
 * logica de prioritate a modulului supplierpricing).
 *
 * Rulează cu binarul Laragon 8.1:
 *   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe tests/build_price_selection.php
 *
 * Scrie storage/dup473_selection.json (suprascrie selecția existentă — fă backup
 * dacă ai una salvată din raport).
 */

use App\Accessories\RefAudit;
use App\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

$settings = require $root . '/config/settings.php';
$audit    = new RefAudit(new Database($settings['db']), $settings['db']['bikershop']);
if (!$audit->isAvailable()) {
    fwrite(STDERR, "BikerShop sau baza locală indisponibilă.\n");
    exit(1);
}

$data  = $audit->build();
$picks = [];
$skipMulti = 0;

foreach ($data['rows'] as $r) {
    if ($r['price_delta'] === null || abs($r['price_delta']) < 0.01) {
        continue;                       // preț deja corect
    }
    if (empty($r['yamaha']['sku'])) {
        continue;                       // fără corespondent Yamaha (clasa C)
    }
    if ((int) $r['sup_count'] !== 1) {
        $skipMulti++;                   // prioritatea între furnizori se rezolvă manual
        continue;
    }
    $picks[] = ['id_product' => $r['id'], 'sku' => $r['yamaha']['sku']];
}

$file = $root . '/storage/dup473_selection.json';
file_put_contents($file, json_encode([
    'created_at' => date('c'),
    'rate'       => $data['rate'],
    'picks'      => $picks,
    'deactivate' => [],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

printf("Selecție scrisă în storage/dup473_selection.json\n");
printf("  de corectat        : %d\n", count($picks));
printf("  sărite (>1 furnizor): %d\n", $skipMulti);
printf("  curs               : %.2f\n", $data['rate']);
echo "\nRulează apoi: database/apply_dup_ref_473.php (dry-run), apoi cu --apply\n";
