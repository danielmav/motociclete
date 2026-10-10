<?php

declare(strict_types=1);

/**
 * Helper comun pentru testele de newsletter (plain PHP, fără PHPUnit).
 * Testele cu DB rulează într-o tranzacție pe baza locală și fac rollback la final.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

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
    foreach (['nl_clicks', 'nl_links', 'nl_sends', 'nl_events'] as $table) {
        $pdo->exec('DELETE FROM ' . $table);
    }
    $pdo->exec('DELETE FROM nl_subscriptions');
    $pdo->exec('DELETE FROM nl_subscribers');
    $pdo->exec('DELETE FROM nl_campaigns');
    register_shutdown_function(static function () use ($pdo): void {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    });
    return $pdo;
}

/**
 * Abonat de test, activ pe listele date (sau cu starea cerută).
 * @param array<int,string> $lists
 */
function nl_subscriber(string $email, array $lists = ['stiri'], string $status = 'active'): int
{
    $repo = new App\Newsletter\Repository(nl_db());
    $id = (int) $repo->ensureSubscriber($email, null, $status)['id'];
    foreach ($lists as $list) {
        $repo->addSubscription($id, $list, 'manual');
    }
    return $id;
}

/** Campanie de test cu mesaj generat, gata de pus la trimis. */
function nl_campaign(string $list = 'stiri', string $html = '<p>Salut %%EMAIL%%</p>'): int
{
    $campaigns = new App\Newsletter\Campaigns(nl_db());
    $id = $campaigns->create($list, 'stiri', 'Campanie de test');
    $campaigns->update($id, ['html' => $html, 'body_text' => 'Salut %%EMAIL%% %%UNSUB_URL%%']);
    return $id;
}

function nl_done(): void
{
    echo "\n{$GLOBALS['nl_count']} verificări, {$GLOBALS['nl_failures']} eșecuri\n";
    exit($GLOBALS['nl_failures'] ? 1 : 0);
}
