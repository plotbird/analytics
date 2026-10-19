<?php
/**
 * PlotBird Analytics - bootstrap.
 *
 * Included by every entry point (index.php, /api/*.php, /install/index.php).
 * It defines the paths and versions, registers the class autoloader, loads the
 * configuration when the installation is complete and installs the error
 * handlers. It deliberately does no routing and no output.
 *
 * @package PlotBird
 */

declare(strict_types=1);

if (!defined('PB_ROOT')) {
    define('PB_ROOT', dirname(__DIR__));
}

require_once PB_ROOT . '/core/Helper.php';
require_once PB_ROOT . '/core/i18n.php';

/*
 * Class autoloader.
 *
 * PlotBird\Database        -> /core/Database.php
 * PlotBird\Controller\X    -> /core/Controller/X.php
 *
 * Filenames are matched case-insensitively as a second step so that the
 * PSR-4-ish class name and the historically lower-case file name (i18n.php)
 * can coexist - shared hostings run on case-sensitive filesystems.
 */
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'PlotBird\\', 9) !== 0) {
        return;
    }

    $relative = substr($class, 9);
    if ($relative === '' || strpos($relative, '..') !== false) {
        return;
    }

    $path = PB_ROOT . '/core/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
        return;
    }

    $dir  = dirname($path);
    $base = basename($path);
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..' && strcasecmp($entry, $base) === 0) {
            require_once $dir . '/' . $entry;
            return;
        }
    }
});

/*
 * Error handling.
 *
 * On a shared hosting nothing is watching the logs, so notices are written to
 * /data/logs instead of being printed, and an uncaught exception becomes a
 * clean 500 page rather than a stack trace in the customer's browser.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '0'); // We do our own logging.

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    // Respect the @ operator and diagnostics we cannot act on.
    if ((error_reporting() & $severity) === 0) {
        return true;
    }
    PlotBird\Log::error(sprintf('%s in %s:%d', $message, $file, $line));
    return true;
});

set_exception_handler(static function (\Throwable $e): void {
    PlotBird\Log::error('uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    if (PlotBird\Config::isInstalled() && PlotBird\Config::get('app.debug', false)) {
        echo '<pre>' . e($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';
        return;
    }
    echo '<!doctype html><html><head><meta charset="utf-8"><title>500</title></head>'
        . '<body style="font-family:system-ui,sans-serif;padding:3rem;text-align:center">'
        . '<h1>500</h1><p>' . e(PlotBird\I18n::translate('error.server_body')) . '</p></body></html>';
});

// Fail loudly while developing, quietly in production.
if (PlotBird\Config::isInstalled() && (bool) PlotBird\Config::get('app.debug', false)) {
    ini_set('display_errors', '1');
}

PlotBird\Security::sendSecurityHeaders();
