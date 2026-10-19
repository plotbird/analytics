<?php
/**
 * PlotBird Analytics - URL checks.
 *
 * The application can live in the document root or in a sub-directory. Every
 * generated link has to survive both, and a sub-directory install used to
 * double its own path (/plotbird/plotbird/login) because the base URL carried
 * the path as well as the base path. These checks stop that coming back.
 *
 * Run after the suite, against an installation whose config/config.php exists:
 *
 *   php tests/urls.php
 *
 * Output is buffered on purpose: the application starts a session lazily, and in
 * a command-line process a single echo before that makes PHP refuse it. The test
 * must not depend on the order in which it happens to print.
 *
 * @package PlotBird
 */

declare(strict_types=1);

ob_start();

$root = getenv('PB_TEST_ROOT');
$root = is_string($root) && $root !== '' ? rtrim(str_replace('\\', '/', $root), '/') : dirname(__DIR__);

if (!is_file($root . '/config/config.php')) {
    ob_end_clean();
    fwrite(STDERR, "config/config.php is missing: install the application first\n");
    exit(1);
}

$_SERVER['HTTP_HOST']      = 'example.test';
$_SERVER['HTTPS']          = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

require $root . '/core/bootstrap.php';

/** Collected output, printed once at the end. */
$lines = [];
$failures = 0;

/**
 * Record one check.
 *
 * @param string $label     What is being checked.
 * @param bool   $condition Result.
 * @param string $actual    Observed value.
 */
function url_check(string $label, bool $condition, string $actual): void
{
    global $lines, $failures;

    if (!$condition) {
        $failures++;
    }

    $lines[] = sprintf('  %s %-46s %s', $condition ? 'PASS' : 'FAIL', $label, $actual);
}

/**
 * Simulate one layout and read the URL helpers.
 *
 * @param string $script          Path of the entry point as the server reports it.
 * @param string $basePathInConfig What the configuration says about the folder.
 * @return array<string,string>
 */
function urls_for(string $script, string $basePathInConfig): array
{
    $_SERVER['SCRIPT_NAME'] = $script;
    $_SERVER['REQUEST_URI'] = $script === '/index.php' ? '/' : '/plotbird/';
    $_SERVER['PHP_SELF']    = $script;

    // The configuration wins over detection, so it has to describe the same
    // situation being simulated.
    PlotBird\Config::set('app.base_path', $basePathInConfig);
    PlotBird\Config::set('app.url', '');

    return [
        'base'  => base_url(),
        'path'  => pb_base_path(),
        'url'   => url('login'),
        'root'  => root_url('assets/tracker.js'),
        'asset' => asset('css/app.css'),
    ];
}

$lines[] = '';
$lines[] = '[1] Installed in the document root';
$rootCase = urls_for('/index.php', '');
url_check('base URL is the origin', $rootCase['base'] === 'https://example.test', $rootCase['base']);
url_check('base path is empty', $rootCase['path'] === '', $rootCase['path']);
url_check('route URL', $rootCase['url'] === 'https://example.test/login', $rootCase['url']);
url_check('file URL', $rootCase['root'] === 'https://example.test/assets/tracker.js', $rootCase['root']);

$lines[] = '';
$lines[] = '[2] Installed in a sub-directory';
$subCase = urls_for('/plotbird/index.php', '/plotbird');
url_check('base URL stays the origin', $subCase['base'] === 'https://example.test', $subCase['base']);
url_check('base path is the folder', $subCase['path'] === '/plotbird', $subCase['path']);
url_check('route URL carries the folder once', $subCase['url'] === 'https://example.test/plotbird/login', $subCase['url']);
url_check('file URL carries the folder once', $subCase['root'] === 'https://example.test/plotbird/assets/tracker.js', $subCase['root']);
url_check('asset URL has no doubled folder', strpos($subCase['asset'], '/plotbird/plotbird/') === false, $subCase['asset']);

$lines[] = '';
$lines[] = '[3] A forgotten setting falls back to detection';
$detected = urls_for('/plotbird/index.php', '');
url_check('detection still finds the folder', $detected['path'] === '/plotbird', $detected['path']);
url_check('route URL carries the folder once', $detected['url'] === 'https://example.test/plotbird/login', $detected['url']);

$lines[] = '';
$lines[] = $failures === 0
    ? 'PASSED: URL generation is correct in both layouts'
    : sprintf('FAILED: %d check(s)', $failures);

// Throw away anything the application printed while the checks ran.
ob_end_clean();

echo implode("\n", $lines) . "\n";

exit($failures === 0 ? 0 : 1);
