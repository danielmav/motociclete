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
        // Where the "Test ride" buttons point (drivetest module / placeholder).
        'testride_url' => $_ENV['TESTRIDE_URL'] ?? 'http://drivetest.test',
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
    ],
];
