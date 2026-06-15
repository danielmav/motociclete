<?php
declare(strict_types=1);
// Small, cross-engine helpers for the admin migrations/seeders.

/** Run a .sql file statement-by-statement (PDO::exec dislikes multi-statements). */
function run_sql_file(PDO $pdo, string $path): void
{
    $sql = (string) file_get_contents($path);
    // strip line comments, then split on ';'
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $pdo->exec($stmt);
    }
}

/** Add a column only if it doesn't exist (works on MySQL 8 + MariaDB). */
function ensure_column(PDO $pdo, string $table, string $column, string $alterSql): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute([':t' => $table, ':c' => $column]);
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec($alterSql);
        echo "  + {$table}.{$column}\n";
    }
}
