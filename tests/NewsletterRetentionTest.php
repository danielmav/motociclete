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
check('dry-run: nu șterge nimic', $row('pending-vechi@nl-test.invalid') !== null);

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

nl_done();
