<?php

declare(strict_types=1);

/**
 * Helper comun pentru testele de newsletter (plain PHP, fără PHPUnit).
 * Testele cu DB rulează într-o tranzacție pe baza locală și fac rollback la final.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$GLOBALS['nl_settings'] = require dirname(__DIR__) . '/config/settings.php';
$GLOBALS['nl_failures'] = 0;
$GLOBALS['nl_count']    = 0;

function check(string $label, bool $ok): void
{
    $GLOBALS['nl_count']++;
    if (!$ok) {
        $GLOBALS['nl_failures']++;
    }
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . "\n";
}

function nl_db(): App\Database
{
    static $db = null;
    return $db ??= new App\Database($GLOBALS['nl_settings']['db']);
}

/**
 * Pornește o tranzacție, golește tabelele nl_* în interiorul ei și programează
 * rollback la ieșire: testul vede tabele goale, iar datele locale rămân neatinse.
 */
function nl_isolate(): PDO
{
    $pdo = nl_db()->local();
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM nl_subscriptions');
    $pdo->exec('DELETE FROM nl_subscribers');
    register_shutdown_function(static function () use ($pdo): void {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    });
    return $pdo;
}

function nl_done(): void
{
    echo "\n{$GLOBALS['nl_count']} verificări, {$GLOBALS['nl_failures']} eșecuri\n";
    exit($GLOBALS['nl_failures'] ? 1 : 0);
}
