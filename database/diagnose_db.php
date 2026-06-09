<?php

declare(strict_types=1);

/**
 * Connection diagnostics — verifică cele 3 conexiuni (local, news, bikershop)
 * și raportează CLAR de ce eșuează fiecare. NU afișează parole.
 *
 * Pe server (PHP host cu pdo_mysql):
 *   php database/diagnose_db.php
 * Pe dev (Laragon 8.1):
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" database/diagnose_db.php
 */

use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
$settings = require $root . '/config/settings.php';

$test = function (string $label, array $cfg): void {
    $host = $cfg['host'] ?? '';
    $name = $cfg['name'] ?? '';
    $user = $cfg['user'] ?? '';
    echo str_pad($label, 12) . " host=" . ($host ?: '(gol)') . " db=" . ($name ?: '(gol)') . " user=" . ($user ?: '(gol)') . "\n";
    if ($host === '' || $name === '' || $user === '') {
        echo "             → ✗ NECONFIGURAT (lipsesc valori în .env)\n\n";
        return;
    }
    try {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $cfg['port'] ?? '3306', $name);
        $pdo = new PDO($dsn, $user, $cfg['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 8,
        ]);
        $v = $pdo->query('SELECT VERSION()')->fetchColumn();
        echo "             → ✓ OK (MySQL {$v})\n\n";
    } catch (Throwable $e) {
        echo "             → ✗ EȘEC: " . $e->getMessage() . "\n";
        echo "               (timed out = IP neautorizat în Remote MySQL · Access denied = user/parolă · Unknown database = nume DB)\n\n";
    }
};

echo "=== Diagnoză conexiuni DB ===\n\n";
$db = $settings['db'];
$test('LOCAL', $db['local']);
$test('NEWS (dm)', ['host' => $db['dm']['host'] ?? '', 'port' => $db['dm']['port'] ?? '3306', 'name' => $db['dm']['db_moto'] ?? '', 'user' => $db['dm']['user'] ?? '', 'pass' => $db['dm']['pass'] ?? '']);
$test('BIKERSHOP', $db['bikershop']);
