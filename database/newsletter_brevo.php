<?php
/**
 * Generator de newsletter Brevo (Developer mode) — varianta CLI a App\Newsletter\Generator.
 *
 *   php database/newsletter_brevo.php documente/newsletter/input.json > documente/newsletter/out.yml
 *
 * Fragmentele de template: storage/newsletter/parts/*.yml (gitignored; tăiate din template-ul Brevo).
 * Formatul de input: documente/newsletter/input.example.json. Rulează cu PHP 8.1 Laragon (pdo_mysql).
 * Aceeași logică e expusă și în admin la {ADMIN_PATH}/newsletter.
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$inputFile = $argv[1] ?? __DIR__ . '/../documente/newsletter/input.json';
if (!is_file($inputFile)) {
    fwrite(STDERR, "Lipsește fișierul de input: $inputFile\n");
    exit(1);
}
$in = json_decode((string) file_get_contents($inputFile), true, 512, JSON_THROW_ON_ERROR);

$settings = require __DIR__ . '/../config/settings.php';
$db  = new App\Database($settings['db']);
$gen = new App\Newsletter\Generator(
    new App\Catalog\Repository($db),
    new App\BikerShop\Client($db, $settings['db']['bikershop']),
    $db,
    dirname(__DIR__) . '/storage/newsletter/parts',
);

try {
    echo $gen->generate($in);
} catch (Throwable $e) {
    fwrite(STDERR, 'EROARE: ' . $e->getMessage() . "\n");
    exit(1);
}
foreach ($gen->warnings() as $w) {
    fwrite(STDERR, "AVERTISMENT: $w\n");
}
fwrite(STDERR, "OK\n  " . implode("\n  ", $gen->summary()) . "\n");
