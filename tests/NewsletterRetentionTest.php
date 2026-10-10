<?php

declare(strict_types=1);

/**
 * Rulează:
 *   & "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" tests/NewsletterRetentionTest.php
 */

require __DIR__ . '/_nl.php';

$pdo = nl_db()->local();
$cleanup = static function () use ($pdo): void {
    $pdo->exec("DELETE s FROM nl_subscriptions s JOIN nl_subscribers u ON u.id = s.subscriber_id WHERE u.email LIKE '%@nl-test.invalid'");
    $pdo->exec("DELETE FROM nl_subscribers WHERE email LIKE '%@nl-test.invalid'");
    $pdo->exec("DELETE FROM nl_events WHERE email LIKE '%@nl-test.invalid'");
    $pdo->exec('DELETE FROM nl_clicks WHERE link_id = 4000000000');
};
$cleanup();
register_shutdown_function($cleanup);

$insert = $pdo->prepare(
    "INSERT INTO nl_subscribers (email, token, status, signup_ip, created_at)
     VALUES (:e, :t, :s, '10.9.9.9', NOW() - INTERVAL :d DAY)"
);
$add = static function (string $email, string $status, int $daysAgo) use ($insert): void {
    $insert->execute([':e' => $email, ':t' => bin2hex(random_bytes(16)), ':s' => $status, ':d' => $daysAgo]);
};
$add('pending-vechi@nl-test.invalid', 'pending', 45);
$add('pending-nou@nl-test.invalid', 'pending', 5);
$add('activ-vechi@nl-test.invalid', 'active', 45);
$add('activ-nou@nl-test.invalid', 'active', 5);
// Neconfirmat vechi, dar dezabonat în Brevo: rândul de excludere trebuie să supraviețuiască.
$add('pending-exclus@nl-test.invalid', 'pending', 45);
$pdo->exec("INSERT INTO nl_subscriptions (subscriber_id, list_key, status, source, unsubscribed_at)
            SELECT id, 'stiri', 'unsubscribed', 'brevo', NOW() FROM nl_subscribers WHERE email = 'pending-exclus@nl-test.invalid'");
// Neconfirmat vechi care a cerut din nou linkul ieri: nu-l ștergem încă.
$add('pending-recerut@nl-test.invalid', 'pending', 45);
$pdo->exec("UPDATE nl_subscribers SET confirm_sent_at = NOW() - INTERVAL 1 DAY WHERE email = 'pending-recerut@nl-test.invalid'");

// Clicuri și notificări de la releu: vechi și noi.
$pdo->exec("INSERT INTO nl_clicks (link_id, subscriber_id, clicked_at) VALUES
    (4000000000, 1, NOW() - INTERVAL 400 DAY), (4000000000, 2, NOW() - INTERVAL 300 DAY)");
$pdo->exec("INSERT INTO nl_events (provider, type, email, created_at) VALUES
    ('ses', 'bounce_hard', 'vechi@nl-test.invalid', NOW() - INTERVAL 100 DAY),
    ('ses', 'bounce_hard', 'nou@nl-test.invalid', NOW() - INTERVAL 80 DAY)");
$count = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();

$php = PHP_BINARY;
$script = dirname(__DIR__) . '/database/retention.php';

$row = static function (string $email) use ($pdo): ?array {
    $s = $pdo->prepare('SELECT status, signup_ip FROM nl_subscribers WHERE email = :e');
    $s->execute([':e' => $email]);
    return $s->fetch() ?: null;
};

// --- dry-run -----------------------------------------------------------------
exec(escapeshellarg($php) . ' ' . escapeshellarg($script), $out, $code);
check('dry-run: cod de ieșire 0', $code === 0);
check('dry-run: raportează operațiile de newsletter', str_contains(implode("\n", $out), 'nl_subscribers'));
check('dry-run: nu șterge nimic', $row('pending-vechi@nl-test.invalid') !== null
    && $count('SELECT COUNT(*) FROM nl_clicks WHERE link_id = 4000000000') === 2);

// --- apply -------------------------------------------------------------------
exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --apply', $out2, $code2);
check('apply: cod de ieșire 0', $code2 === 0);
check('pending de 45 de zile: șters', $row('pending-vechi@nl-test.invalid') === null);
check('pending de 5 zile: păstrat', $row('pending-nou@nl-test.invalid') !== null);
check('pending vechi cu rând de excludere: păstrat', $row('pending-exclus@nl-test.invalid') !== null);
check('rândul de excludere rămâne', (int) $pdo->query(
    "SELECT COUNT(*) FROM nl_subscriptions s JOIN nl_subscribers u ON u.id = s.subscriber_id WHERE u.email = 'pending-exclus@nl-test.invalid'"
)->fetchColumn() === 1);
check('pending vechi cu link cerut ieri: păstrat', $row('pending-recerut@nl-test.invalid') !== null);
check('activ de 45 de zile: păstrat, IP golit',
    ($row('activ-vechi@nl-test.invalid')['status'] ?? '') === 'active' && $row('activ-vechi@nl-test.invalid')['signup_ip'] === null);
check('activ de 5 zile: IP păstrat', ($row('activ-nou@nl-test.invalid')['signup_ip'] ?? null) === '10.9.9.9');

check('clicul de acum 400 de zile: șters; cel de acum 300: păstrat',
    $count('SELECT COUNT(*) FROM nl_clicks WHERE link_id = 4000000000') === 1
    && $count('SELECT subscriber_id FROM nl_clicks WHERE link_id = 4000000000') === 2);
check('notificarea de acum 100 de zile: ștearsă; cea de acum 80: păstrată',
    $count("SELECT COUNT(*) FROM nl_events WHERE email = 'vechi@nl-test.invalid'") === 0
    && $count("SELECT COUNT(*) FROM nl_events WHERE email = 'nou@nl-test.invalid'") === 1);

nl_done();
