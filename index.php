<?php

declare(strict_types=1);

/**
 * Front controller for motociclete.com.ro (Dual Motors portal).
 * Laragon document root = project root; .htaccess routes all requests here.
 */

require __DIR__ . '/vendor/autoload.php';

App\Bootstrap::create()->run();
