<?php
/**
 * Router for PHP's built-in server.
 *
 * It reproduces what the shipped .htaccess does for Apache, so local work and
 * the end-to-end suite exercise the same routing rules as production:
 *
 *   php -S 127.0.0.1:8080 tests/router.php
 *
 * @package PlotBird
 */

$root = realpath(__DIR__ . '/..');
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$uri  = rawurldecode($uri);
$file = $root . str_replace('/', DIRECTORY_SEPARATOR, $uri);

// A real file: let the built-in server handle it (static assets, api/*.php).
if ($uri !== '/' && is_file($file)) {
    return false;
}

// A real directory with its own entry point: /install/.
if ($uri !== '/' && is_dir($file)) {
    $index = rtrim($file, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index.php';
    if (is_file($index)) {
        require $index;
        return true;
    }
}

// Everything else is a route.
require $root . DIRECTORY_SEPARATOR . 'index.php';
return true;
