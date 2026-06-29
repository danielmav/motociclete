<?php

declare(strict_types=1);

/**
 * Central configuration. Reads from environment (.env loaded in Bootstrap).
 */
return [
    'app' => [
        'env'          => $_ENV['APP_ENV']   ?? 'prod',
        'debug'        => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
        'url'          => rtrim($_ENV['APP_URL'] ?? 'http://motociclete.test', '/'),
        // Sub-folder the app is served from, e.g. "/2026". Empty = site root.
        'base_path'    => rtrim($_ENV['BASE_PATH'] ?? '', '/'),
        // Where the "Drive test" buttons point (drivetest module).
        'testride_url' => $_ENV['TESTRIDE_URL'] ?? 'https://www.motociclete.com.ro/drive-test/',
    ],

    // Admin back-office. `path` = hidden URL prefix (not "admin"), configurable so
    // it can differ per environment. Auth is DB-based (admin_users); the legacy
    // HTTP Basic user/pass are kept only for the old fitment guard fallback.
    'admin' => [
        'path' => '/' . trim($_ENV['ADMIN_PATH'] ?? 'dm-control', '/'),
        'user' => $_ENV['ADMIN_USER'] ?? 'admin',
        'pass' => $_ENV['ADMIN_PASS'] ?? '',
    ],

    // Email (My Garage OTP + dealer notifications). When SMTP_HOST is empty the
    // Mailer logs to storage/logs/mail.log instead of sending (dev). Address that
    // receives service-request notifications = MAIL_DEALER.
    'mail' => [
        'from'      => $_ENV['MAIL_FROM'] ?? 'noreply@motociclete.com.ro',
        'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'Dual Motors',
        // Destinatari per tip: lead-uri (ofertă/test drive) -> dealer (info@);
        // service (programare + cerere din garage) -> service@ (fallback la dealer).
        'dealer'    => $_ENV['MAIL_DEALER'] ?? ($_ENV['MAIL_FROM'] ?? 'info@motociclete.com.ro'),
        'service'   => $_ENV['MAIL_SERVICE'] ?? ($_ENV['MAIL_DEALER'] ?? 'service@motociclete.com.ro'),
        'smtp_host' => $_ENV['SMTP_HOST'] ?? '',
        'smtp_port' => (int) ($_ENV['SMTP_PORT'] ?? 587),
        'smtp_user' => $_ENV['SMTP_USER'] ?? '',
        'smtp_pass' => $_ENV['SMTP_PASS'] ?? '',
        'smtp_secure' => $_ENV['SMTP_SECURE'] ?? 'tls', // tls|ssl|''
    ],

    'twig' => [
        'templates' => dirname(__DIR__) . '/templates',
        // File cache only when explicitly enabled (avoids write-permission issues on shared hosting).
        'cache'     => filter_var($_ENV['TWIG_CACHE'] ?? false, FILTER_VALIDATE_BOOL)
            ? dirname(__DIR__) . '/storage/cache/twig'
            : false,
    ],

    'db' => [
        'local' => [
            'host' => $_ENV['DB_LOCAL_HOST'] ?? '127.0.0.1',
            'port' => $_ENV['DB_LOCAL_PORT'] ?? '3306',
            'name' => $_ENV['DB_LOCAL_NAME'] ?? 'dualmotors_portal',
            'user' => $_ENV['DB_LOCAL_USER'] ?? 'root',
            'pass' => $_ENV['DB_LOCAL_PASS'] ?? '',
        ],
        'bikershop' => [
            'host'     => $_ENV['BIKERSHOP_HOST'] ?? '',
            'port'     => $_ENV['BIKERSHOP_PORT'] ?? '3306',
            'name'     => $_ENV['BIKERSHOP_NAME'] ?? '',
            'user'     => $_ENV['BIKERSHOP_USER'] ?? '',
            'pass'     => $_ENV['BIKERSHOP_PASS'] ?? '',
            'prefix'   => $_ENV['BIKERSHOP_PREFIX'] ?? 'ps_',
            'lang_id'  => (int) ($_ENV['BIKERSHOP_LANG_ID'] ?? 1),
            'base_url' => $_ENV['BIKERSHOP_BASE_URL'] ?? 'https://bikershop.ro',
        ],
        // Legacy source DBs (existing site content). Used ONLY by the one-off
        // catalog migration script (database/migrate_catalog.php), not at runtime.
        'dm' => [
            'host'      => $_ENV['DM_HOST'] ?? '',
            'port'      => $_ENV['DM_PORT'] ?? '3306',
            'user'      => $_ENV['DM_USER'] ?? '',
            'pass'      => $_ENV['DM_PASS'] ?? '',
            'db_moto'   => $_ENV['DM_DB_MOTO'] ?? 'dualmotors_motociclete',
            'db_cfmoto' => $_ENV['DM_DB_CFMOTO'] ?? 'dualmotors_cfmoto',
        ],
    ],
];
