<?php
/**
 * HTTP transport for the end-to-end suite.
 *
 * Starts PHP's built-in server once per process and talks to it with curl. This
 * is the transport CI uses, and the one a developer on a normal machine should
 * use: no CGI emulation, real headers, real cookies, real redirects.
 *
 * The client can pretend to come from any IP address: requests carry an
 * X-Forwarded-For header, and the suite configures the installation to trust
 * 127.0.0.1 as a proxy. That also exercises the trusted-proxy code path, which
 * would otherwise never be tested.
 *
 * @package PlotBird
 */

declare(strict_types=1);

final class HttpClient
{
    /** @var resource|null The shared `php -S` process. */
    private static $server = null;

    /** @var array<string,string> */
    private array $cookies = [];

    private string $base;

    public function __construct()
    {
        self::ensureServer();
        $this->base = 'http://127.0.0.1:' . (getenv('PB_TEST_PORT') ?: '8099');
    }

    /**
     * Start the built-in server unless it is already running.
     */
    private static function ensureServer(): void
    {
        if (self::$server !== null) {
            return;
        }

        $port = (string) (getenv('PB_TEST_PORT') ?: '8099');
        $host = '127.0.0.1:' . $port;

        // A previous run may have left a server behind.
        $probe = @fsockopen('127.0.0.1', (int) $port, $errno, $errstr, 0.3);
        if (is_resource($probe)) {
            fclose($probe);
            self::$server = true;
            return;
        }

        $log = sys_get_temp_dir() . '/plotbird-test-server.log';
        $command = [
            PHP_BINARY,
            '-d', 'display_errors=0',
            // A long-running server plus opcache means an edited config.php is
            // never re-read: the cached bytecode keeps the old array. The suite
            // edits the config on purpose, so the cache is off here.
            '-d', 'opcache.enable=0',
            '-d', 'opcache.enable_cli=0',
            '-S', $host,
            '-t', ROOT,
            ROOT . '/tests/router.php',
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ];

        $process = proc_open($command, $descriptors, $pipes, ROOT);
        if (!is_resource($process)) {
            throw new RuntimeException('cannot start php -S; see ' . $log);
        }
        self::$server = $process;

        // Wait for the port to accept connections.
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @fsockopen('127.0.0.1', (int) $port, $errno, $errstr, 0.3);
            if (is_resource($socket)) {
                fclose($socket);
                return;
            }
            usleep(100000);
        }

        throw new RuntimeException('the built-in server did not start; see ' . $log);
    }

    /**
     * Restart the built-in server.
     *
     * The suite edits config/config.php while the application is running (to
     * grant the loopback proxy trust). A long-running server may have compiled
     * that file already, so a restart is the only way to be certain the new
     * values are in force.
     */
    public function restart(): void
    {
        if (is_resource(self::$server)) {
            @proc_terminate(self::$server);
            @proc_close(self::$server);
        }
        self::$server = null;
        self::ensureServer();
    }

    /**
     * @param array<string,string> $post
     * @param array<string,string> $headers
     * @return array{status:int,headers:array<string,string>,body:string,raw:string}
     */
    public function request(
        string $method,
        string $uri,
        array $post = [],
        ?string $rawBody = null,
        array $headers = [],
        string $ip = '203.0.113.7',
        string $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36'
    ): array {
        $url = $this->base . $uri;

        $body = $rawBody;
        if ($body === null && $post !== []) {
            $body = http_build_query($post);
        }

        // Headers are kept in a map keyed by lower-cased name so a header the
        // caller passes REPLACES the default instead of being appended. Two
        // `Accept-Language` lines reach PHP as one comma-joined string, and the
        // first match wins: with an appended header the default `en` always beat
        // the requested `de`, and the default `Accept` carried
        // `application/json`, which made every request look like an AJAX call
        // (the application answers those with 401 instead of a redirect).
        $requestHeaders = [
            'user-agent'      => 'User-Agent: ' . $ua,
            'accept'          => 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'accept-language' => 'Accept-Language: en-US,en;q=0.9',
            // The suite trusts 127.0.0.1 as a proxy, so this is what the
            // application will treat as the visitor's address.
            'x-forwarded-for' => 'X-Forwarded-For: ' . $ip,
        ];

        if ($body !== null) {
            $requestHeaders['content-type'] = 'Content-Type: '
                . ($headers['Content-Type'] ?? 'application/x-www-form-urlencoded');
        }

        foreach ($headers as $name => $value) {
            $requestHeaders[strtolower($name)] = $name . ': ' . $value;
        }

        $requestHeaders = array_values($requestHeaders);

        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $requestHeaders[] = 'Cookie: ' . implode('; ', $pairs);
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('curl_init failed');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $requestHeaders,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        if ($raw === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException('request to ' . $url . ' failed: ' . $error);
        }

        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $raw = (string) $raw;
        $headerBlock = substr($raw, 0, $headerSize);
        $responseBody = substr($raw, $headerSize);

        $parsedHeaders = [];
        foreach (preg_split('/\r\n|\n/', $headerBlock) ?: [] as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));

            if ($name === 'set-cookie') {
                $pair = explode(';', $value)[0];
                $eq = strpos($pair, '=');
                if ($eq !== false) {
                    $cookieName = trim(substr($pair, 0, $eq));
                    $cookieValue = trim(substr($pair, $eq + 1));
                    if ($cookieValue === '') {
                        unset($this->cookies[$cookieName]);
                    } else {
                        $this->cookies[$cookieName] = $cookieValue;
                    }
                }
                continue;
            }

            $parsedHeaders[$name] = $value;
        }

        return [
            'status'  => $status,
            'headers' => $parsedHeaders,
            'body'    => $responseBody,
            'raw'     => $raw,
        ];
    }
}

/**
 * Picks the transport: HTTP by default, CGI when the environment forbids
 * binding a socket.
 */
final class Client
{
    public static function make(): object
    {
        $transport = strtolower((string) (getenv('PB_TEST_TRANSPORT') ?: 'http'));
        return $transport === 'cgi' ? new CgiClient() : new HttpClient();
    }
}
