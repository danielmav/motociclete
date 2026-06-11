<?php

declare(strict_types=1);

/**
 * Adds + populates `telefon_norm` and `email_norm` on `clienti` so the My Garage
 * login can match a client by phone or email regardless of formatting (spaces,
 * +40/0040 prefixes, casing). Idempotent — re-run any time after import.
 *
 * Run with the Laragon 8.1 binary:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" database/normalize_clienti.php
 */

use App\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$settings = require $root . '/config/settings.php';
$db    = new Database($settings['db']);
$local = $db->local();

// 1. Ensure columns + indexes exist (idempotent).
$cols = $local->query("SHOW COLUMNS FROM clienti")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('telefon_norm', $cols, true)) {
    $local->exec("ALTER TABLE clienti ADD COLUMN telefon_norm VARCHAR(20) NULL AFTER telefon, ADD KEY idx_clienti_tel (telefon_norm)");
    echo "+ coloana telefon_norm\n";
}
if (!in_array('email_norm', $cols, true)) {
    $local->exec("ALTER TABLE clienti ADD COLUMN email_norm VARCHAR(256) NULL AFTER email, ADD KEY idx_clienti_email (email_norm)");
    echo "+ coloana email_norm\n";
}

// 2. Populate.
$rows = $local->query("SELECT id, telefon, email FROM clienti")->fetchAll(PDO::FETCH_ASSOC);
$upd  = $local->prepare("UPDATE clienti SET telefon_norm = :tel, email_norm = :email WHERE id = :id");

$stats = ['tel' => 0, 'no_tel' => 0, 'email' => 0, 'no_email' => 0];
foreach ($rows as $r) {
    $tel   = normalize_phone($r['telefon']);
    $email = normalize_email($r['email']);
    $upd->execute([':tel' => $tel, ':email' => $email, ':id' => $r['id']]);
    $tel   ? $stats['tel']++   : $stats['no_tel']++;
    $email ? $stats['email']++ : $stats['no_email']++;
}

echo sprintf(
    "\nProcesat %d clienți.\n  ✓ telefon normalizat: %d   (✗ invalid/lipsă: %d)\n  ✓ email normalizat:   %d   (✗ invalid/lipsă: %d)\n",
    count($rows), $stats['tel'], $stats['no_tel'], $stats['email'], $stats['no_email']
);
