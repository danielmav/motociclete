<?php
declare(strict_types=1);
// Rulează DOAR pe server (sursa de adevăr). mysqldump read-only pe DB_LOCAL_* din .env,
// scrie rezultatul în fișierul dat ca argument. Folosit de database/sync_from_prod.sh
// ca să nu fie nevoie să manevrăm parola (caractere speciale) prin quoting de shell.
//   php database/prod_dump.php /home/dualmotors/dump.sql

require __DIR__ . '/../vendor/autoload.php';
if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

$out = $argv[1] ?? null;
if (!$out) {
    fwrite(STDERR, "Usage: php prod_dump.php <output-file>\n");
    exit(1);
}

$host = $_ENV['DB_LOCAL_HOST'] ?? 'localhost';
$user = $_ENV['DB_LOCAL_USER'] ?? '';
$pass = $_ENV['DB_LOCAL_PASS'] ?? '';
$name = $_ENV['DB_LOCAL_NAME'] ?? '';

if ($user === '' || $name === '') {
    fwrite(STDERR, "DB_LOCAL_USER/DB_LOCAL_NAME lipsesc din .env\n");
    exit(1);
}

$cmd = sprintf(
    'mysqldump -h %s -u %s --single-transaction --routines --triggers %s > %s',
    escapeshellarg($host),
    escapeshellarg($user),
    escapeshellarg($name),
    escapeshellarg($out)
);

$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$env = array_merge($_SERVER, ['MYSQL_PWD' => $pass]);
$proc = proc_open($cmd, $descriptors, $pipes, null, $env);
if (!is_resource($proc)) {
    fwrite(STDERR, "proc_open a eșuat\n");
    exit(1);
}
fclose($pipes[0]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);

if ($code !== 0) {
    fwrite(STDERR, "mysqldump a eșuat ($code): $stderr\n");
    exit($code);
}

echo "OK: $out (" . filesize($out) . " bytes)\n";
