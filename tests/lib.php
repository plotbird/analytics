<?php
/**
 * End-to-end test harness.
 *
 * Two transports drive the real entry points:
 *   * "http" - starts PHP's built-in server and talks to it over HTTP. This is
 *     what CI uses, and what any developer on a normal machine should use.
 *   * "cgi"  - invokes php-cgi directly through proc_open. Needed in sandboxes
 *     that forbid binding a socket.
 *
 * Selected with PB_TEST_TRANSPORT=http|cgi (default: http).
 */

declare(strict_types=1);

define('ROOT', rtrim(getenv('PB_TEST_ROOT') ?: dirname(__DIR__), '/\\'));
define('PHP_CGI', getenv('PB_TEST_PHP_CGI') ?: (PHP_BINARY === '' ? 'php-cgi' : dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '')));
define('SITE', '127.0.0.1:' . (getenv('PB_TEST_PORT') ?: '8099'));
define('DB_HOST', getenv('PB_TEST_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int) (getenv('PB_TEST_DB_PORT') ?: '33061'));
define('DB_NAME', getenv('PB_TEST_DB_NAME') ?: 'plotbird');
define('DB_USER', getenv('PB_TEST_DB_USER') ?: 'plotbird');
define('DB_PASS', getenv('PB_TEST_DB_PASS') ?: 'plotbird');
define('DB_PREFIX', getenv('PB_TEST_DB_PREFIX') ?: 'pb_');

define('ADMIN_EMAIL', 'admin@example.com');
define('ADMIN_PASS', 'SuperSecret123');

/* ------------------------------------------------------------------ harness */

final class Result
{
    public static int $pass = 0;
    public static int $fail = 0;
    /** @var list<string> */
    public static array $failures = [];

    public static function ok(bool $condition, string $label, string $extra = ''): bool
    {
        if ($condition) {
            self::$pass++;
            echo "  PASS  $label\n";
            return true;
        }
        self::$fail++;
        self::$failures[] = $label . ($extra !== '' ? ' :: ' . $extra : '');
        echo "  FAIL  $label" . ($extra !== '' ? "\n        $extra" : '') . "\n";
        return false;
    }

    public static function contains(string $haystack, string $needle, string $label): bool
    {
        return self::ok(strpos($haystack, $needle) !== false, $label, 'missing: ' . $needle);
    }

    public static function notContains(string $haystack, string $needle, string $label): bool
    {
        return self::ok(strpos($haystack, $needle) === false, $label, 'unexpected: ' . $needle);
    }
}

final class CgiClient
{
    /** @var array<string,string> */
    private array $cookies = [];
    public string $lastStatus = '';
    public array $lastHeaders = [];

    /**
     * @param array<string,string> $post
     * @param array<string,string> $headers
     * @return array{status:int,headers:array<string,string>,body:string,raw:string}
     */
    /**
     * Nothing to restart: every request is a fresh process, so it always reads
     * the current config/config.php.
     */
    public function restart(): void
    {
    }

    public function request(string $method, string $uri, array $post = [], ?string $rawBody = null, array $headers = [], string $ip = '203.0.113.7', string $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36'): array
    {
        $parts     = parse_url($uri);
        $path      = (string) ($parts['path'] ?? '/');
        $query     = (string) ($parts['query'] ?? '');
        $scriptFile = $this->scriptFor($path);
        $scriptName = $this->scriptNameFor($path);

        $body = $rawBody;
        $contentType = '';
        if ($body === null && $post !== []) {
            $body = http_build_query($post);
            $contentType = 'application/x-www-form-urlencoded';
        }
        if ($rawBody !== null) {
            $contentType = $headers['Content-Type'] ?? 'application/json';
        }

        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        // Only string values, only valid environment names.
        $env = array_filter($env, static fn ($value, $key) => is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key) === 1, ARRAY_FILTER_USE_BOTH);

        $env = array_merge($env, [
            'REDIRECT_STATUS'    => '1',
            'GATEWAY_INTERFACE'  => 'CGI/1.1',
            'SERVER_PROTOCOL'    => 'HTTP/1.1',
            'REQUEST_METHOD'     => strtoupper($method),
            'REQUEST_URI'        => $uri,
            'QUERY_STRING'       => $query,
            'SCRIPT_FILENAME'    => $scriptFile,
            'SCRIPT_NAME'        => $scriptName,
            'PHP_SELF'           => $scriptName,
            'DOCUMENT_ROOT'      => ROOT,
            'SERVER_NAME'        => '127.0.0.1',
            'SERVER_PORT'        => '8080',
            'SERVER_SOFTWARE'    => 'e2e',
            'HTTP_HOST'          => SITE,
            'REMOTE_ADDR'        => $ip,
            'REMOTE_PORT'        => '54321',
            'HTTP_USER_AGENT'    => $ua,
            'HTTP_ACCEPT'        => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
            'CONTENT_TYPE'       => $contentType,
            'CONTENT_LENGTH'     => $body === null ? '0' : (string) strlen($body),
            'PATH'               => (string) getenv('PATH'),
            'SystemRoot'         => (string) getenv('SystemRoot'),
            'TEMP'               => (string) getenv('TEMP'),
            'TMP'                => (string) getenv('TMP'),
        ]);

        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $env['HTTP_COOKIE'] = implode('; ', $pairs);
        }

        foreach ($headers as $name => $value) {
            $env['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        // Pass the harness switches (PB_TEST_*, PB_GEOIP_TRACE) through to the
        // application process. Without this an opt-in diagnostic can never fire
        // under the cgi transport, which is the only one some sandboxes allow.
        foreach (getenv() as $envKey => $envValue) {
            if (strpos((string) $envKey, 'PB_') === 0 && !isset($env[$envKey])) {
                $env[$envKey] = (string) $envValue;
            }
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(PHP_CGI, $descriptors, $pipes, ROOT, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('cannot start php-cgi');
        }

        fwrite($pipes[0], $body ?? '');
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($stderr !== '') {
            echo "  [cgi stderr] " . trim($stderr) . "\n";
        }

        [$headerBlock, $responseBody] = $this->split($stdout);
        $parsed = $this->parseHeaders($headerBlock);

        foreach ($parsed['cookies'] as $name => $value) {
            if ($value === '') {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }

        $this->lastStatus  = $parsed['status'];
        $this->lastHeaders = $parsed['headers'];

        return [
            'status'  => (int) ($parsed['code'] ?? 200),
            'headers' => $parsed['headers'],
            'body'    => $responseBody,
            'raw'     => $stdout,
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function split(string $raw): array
    {
        $pos = strpos($raw, "\r\n\r\n");
        if ($pos === false) {
            $pos = strpos($raw, "\n\n");
            if ($pos === false) {
                return [$raw, ''];
            }
            return [substr($raw, 0, $pos), substr($raw, $pos + 2)];
        }
        return [substr($raw, 0, $pos), substr($raw, $pos + 4)];
    }

    /**
     * @return array{status:string,code:int,headers:array<string,string>,cookies:array<string,string>}
     */
    private function parseHeaders(string $block): array
    {
        $headers = [];
        $cookies = [];
        $code = 200;
        $status = '';

        foreach (preg_split('/\r\n|\n/', $block) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (stripos($line, 'Status:') === 0) {
                $status = trim(substr($line, 7));
                if (preg_match('/(\d{3})/', $status, $m) === 1) {
                    $code = (int) $m[1];
                }
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name  = trim(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1));
            $headers[strtolower($name)] = $value;

            if (strcasecmp($name, 'Set-Cookie') === 0) {
                $pair = explode(';', $value)[0];
                $eq   = strpos($pair, '=');
                if ($eq !== false) {
                    $cookies[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
                }
            }
        }

        return ['status' => $status, 'code' => $code, 'headers' => $headers, 'cookies' => $cookies];
    }

    private function scriptFor(string $path): string
    {
        if (strpos($path, '/install') === 0) {
            return ROOT . '/install/index.php';
        }
        if (preg_match('#^/api/([a-z]+\.php)$#', $path, $m) === 1) {
            return ROOT . '/api/' . $m[1];
        }
        if (strpos($path, '/assets/') === 0) {
            return ROOT . str_replace('/', DIRECTORY_SEPARATOR, $path);
        }

        // Any other real file in the document root is served as-is, the way a
        // web server would: sw.js, manifest.php, robots.txt.
        $candidate = ROOT . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if ($path !== '/' && is_file($candidate)) {
            return $candidate;
        }

        return ROOT . '/index.php';
    }

    private function scriptNameFor(string $path): string
    {
        if (strpos($path, '/install') === 0) {
            return '/install/index.php';
        }
        if (preg_match('#^/api/([a-z]+\.php)$#', $path, $m) === 1) {
            return '/api/' . $m[1];
        }
        if (is_file(ROOT . str_replace('/', DIRECTORY_SEPARATOR, $path))) {
            return $path;
        }
        return '/index.php';
    }
}

/* ------------------------------------------------------------------ helpers */

function csrf(string $html): string
{
    // Classic form field...
    if (preg_match('/name="_token" value="([^"]+)"/', $html, $m) === 1) {
        return $m[1];
    }
    // ...or the token handed to JavaScript for AJAX-only pages. Both shapes the
    // layouts have emitted are accepted, so refactoring that script does not
    // silently turn every POST in this suite into a CSRF failure.
    if (preg_match('/csrf:\s*"([a-f0-9]+)"/', $html, $m) === 1) {
        return $m[1];
    }
    if (preg_match('/PB\.csrf\s*=\s*"([a-f0-9]+)"/', $html, $m) === 1) {
        return $m[1];
    }
    return '';
}

/**
 * Collect x-data attributes that are not complete expressions.
 *
 * A raw json_encode() inside an inline Alpine component terminates the
 * attribute at its first double quote: the rest of the component spills onto
 * the page as visible text (this really happened on installer step 6).
 *
 * @return list<string>
 */
function broken_components(string $html): array
{
    $broken = [];
    if (preg_match_all('/x-data="([^"]*)"/', $html, $matches) === false) {
        return $broken;
    }
    foreach ($matches[1] as $value) {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $trimmed = rtrim($decoded);
        $last = substr($trimmed, -1);
        if ($last !== '}' && $last !== ')') {
            $broken[] = substr($trimmed, 0, 90);
        }
    }
    return $broken;
}

/**
 * Assert that every Alpine component on a page is intact.
 */
function assert_components(string $html, string $label): void
{
    $broken = broken_components($html);
    Result::ok($broken === [], 'all Alpine components are intact on ' . $label, implode(' || ', $broken));
}

function db(): PDO
{
    return new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function resetDatabase(): void
{
    $root = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT),
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $root->exec('DROP DATABASE IF EXISTS `' . DB_NAME . '`');
    $root->exec('CREATE DATABASE `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    // Remove a previous configuration so the installer really starts fresh.
    foreach ([ROOT . '/config/config.php', ROOT . '/config/install.lock'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach (['logs', 'cache', 'ratelimit'] as $dir) {
        foreach (glob(ROOT . '/data/' . $dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    // Start with a clean PHP error log so the final assertion is meaningful.
    $phpLog = ROOT . '/.dev/logs/php-error.log';
    if (is_file($phpLog)) {
        unlink($phpLog);
    }
}

require_once __DIR__ . '/HttpClient.php';

/**
 * Save a rendered page for offline inspection.
 *
 * Only does something when the development dump directory exists, so a fresh
 * clone (and CI) never gets a "failed to open stream" warning: the HTML dump is
 * a local debugging aid, not part of the assertions.
 */
function dump_page(string $filename, string $html): void
{
    $dir = ROOT . '/.dev/dump';
    if (!is_dir($dir)) {
        return;
    }
    @file_put_contents($dir . '/' . $filename, $html);
}