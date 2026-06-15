<?php
declare(strict_types=1);
// Creates schema_admin.sql tables and upserts an admin user. Run with Laragon PHP 8.1:
//   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/seed_admin_user.php <username> <password> ["Nume complet"]

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_dbutil.php';
if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}
$settings = require __DIR__ . '/../config/settings.php';
$db = new App\Database($settings['db']);
$pdo = $db->local();

run_sql_file($pdo, __DIR__ . '/schema_admin.sql');

$username = $argv[1] ?? null;
$password = $argv[2] ?? null;
$name     = $argv[3] ?? $username;
if (!$username || !$password) {
    fwrite(STDERR, "Usage: seed_admin_user.php <username> <password> [\"Nume\"]\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare(
    'INSERT INTO admin_users (username, password_hash, name, is_active)
     VALUES (:u, :h, :n, 1)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), name = VALUES(name), is_active = 1'
);
$stmt->execute([':u' => $username, ':h' => $hash, ':n' => $name]);
echo "Admin user '{$username}' ready.\n";
