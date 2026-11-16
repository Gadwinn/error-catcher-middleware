<?php

/**
 * Run in a separate process by ErrorCatcherMiddlewareTest: a fatal error cannot be tested in-process.
 *
 * Usage: php fatal.php <default|custom|none>
 */

declare(strict_types=1);

use G\ErrorCatcherMiddleware\ErrorCatcherMiddleware;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

ini_set('display_errors', '0');
ini_set('log_errors', '0');

$mode = $argv[1] ?? 'default';

if ($mode === 'custom') {
    ErrorCatcherMiddleware::registerShutdownHandler(static function (ErrorException $e): void {
        echo 'custom:', $e->getSeverity() === E_ERROR ? 'E_ERROR' : 'other', ':', $e->getMessage();
    });
} else {
    ErrorCatcherMiddleware::registerShutdownHandler();
}

if ($mode === 'none') {
    @trigger_error('not fatal', E_USER_WARNING);
    echo 'done';

    return;
}

/** @phpstan-ignore-next-line */
undefined_function_for_fatal_error();
