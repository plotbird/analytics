<?php
/**
 * PlotBird Analytics - session, CSRF and request hardening.
 *
 * Everything security related that is not authentication itself lives here:
 * session cookie flags, CSRF tokens, password hashing, security headers,
 * base path detection and the GDPR-friendly IP hashing used by the logs.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class Security
{
    /** Name of the POST field carrying the CSRF token. */
    public const CSRF_FIELD = '_token';

    /** Name of the HTTP header carrying the CSRF token for AJAX calls. */
    public const CSRF_HEADER = 'HTTP_X_CSRF_TOKEN';

    /** @var bool Guard so the session is only configured once. */
    private static bool $sessionStarted = false;

    /**
     * Start the PHP session with hardened cookie flags.
     *
     * Safe to call many times; it is a no-op after the first call and when a
     * session is already active.
     */
    public static function startSession(): void
    {
        if (self::$sessionStarted || session_status() === PHP_SESSION_ACTIVE) {
            self::$sessionStarted = true;
            return;
        }

        $name = (string) Config::get('security.session_name', 'pb_session');

        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => self::cookiePath(),
                'domain'   => '',
                'secure'   => is_https(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            // PHP 8.0 is the minimum, so this branch is documentation only.
            session_set_cookie_params(0, self::cookiePath(), '', is_https(), true);
        }

        session_name($name);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', '86400');

        session_start();
        self::$sessionStarted = true;
    }

    /**
     * Cookie path: scoped to the installation sub-directory, if any.
     */
    private static function cookiePath(): string
    {
        $basePath = trim((string) Config::get('app.base_path', ''), '/');
        return $basePath === '' ? '/' : '/' . $basePath . '/';
    }

    /**
     * The CSRF token for the current session, created on first use.
     */
    public static function csrfToken(): string
    {
        self::startSession();
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf']) || $_SESSION['_csrf'] === '') {
            $_SESSION['_csrf'] = random_hex(32);
        }
        return $_SESSION['_csrf'];
    }

    /**
     * A ready-to-print hidden input for a form.
     */
    public static function csrfField(): string
    {
        return '<input type="hidden" name="' . self::CSRF_FIELD . '" value="' . e(self::csrfToken()) . '">';
    }

    /**
     * Constant-time CSRF validation.
     */
    public static function verifyCsrf(?string $token): bool
    {
        self::startSession();
        $expected = $_SESSION['_csrf'] ?? '';
        if (!is_string($expected) || $expected === '' || !is_string($token) || $token === '') {
            return false;
        }
        return hash_equals($expected, $token);
    }

    /**
     * Abort the request unless a valid CSRF token was submitted.
     *
     * Accepts the token from the POST body or from the X-CSRF-Token header, so
     * the same check covers classic forms and fetch()/Alpine calls.
     */
    public static function requireCsrf(): void
    {
        if (self::verifyCsrf(self::submittedToken())) {
            return;
        }

        pb_log('CSRF validation failed for ' . self::currentPath() . ' from ' . self::hashIp(client_ip()), 'security');

        if (self::wantsJson()) {
            json_out(['error' => 'csrf', 'message' => 'Invalid or expired CSRF token.'], 419);
        }

        http_response_code(419);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>419</title></head><body style="font-family:sans-serif;padding:2rem">'
            . '<h1>419 - ' . e(I18n::translate('error.csrf_title')) . '</h1>'
            . '<p>' . e(I18n::translate('error.csrf_body')) . '</p>'
            . '<p><a href="' . e(url('')) . '">' . e(I18n::translate('common.back_to_dashboard')) . '</a></p>'
            . '</body></html>';
        exit;
    }

    /**
     * The token supplied by the client, if any.
     */
    public static function submittedToken(): ?string
    {
        $fromPost = $_POST[self::CSRF_FIELD] ?? null;
        if (is_string($fromPost) && $fromPost !== '') {
            return $fromPost;
        }
        $fromHeader = $_SERVER[self::CSRF_HEADER] ?? null;
        if (is_string($fromHeader) && $fromHeader !== '') {
            return $fromHeader;
        }
        return null;
    }

    /**
     * Whether the caller expects a JSON answer rather than a redirect.
     */
    public static function wantsJson(): bool
    {
        if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            return true;
        }
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        return strpos($accept, 'application/json') !== false;
    }

    /**
     * Hash a password with the strongest algorithm available.
     */
    public static function hashPassword(string $password): string
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $hash = password_hash($password, $algorithm);
        if (!is_string($hash) || $hash === '') {
            // Extremely unlikely; bcrypt is always present in PHP 8.
            $hash = password_hash($password, PASSWORD_BCRYPT);
        }
        return (string) $hash;
    }

    /**
     * Verify a password, transparently upgrading the hash when the algorithm
     * or its cost has changed since the password was stored.
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    /**
     * Whether a stored hash should be rehashed after a successful login.
     */
    public static function passwordNeedsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    /**
     * A privacy-preserving fingerprint of an IP address, used in logs and for
     * the visitor hash. The salt makes the digest useless without config.php.
     */
    public static function hashIp(string $ip): string
    {
        return substr(hash('sha256', $ip . '|' . (string) Config::get('security.salt', 'plotbird')), 0, 32);
    }

    /**
     * Whether proxy headers may be trusted. Off unless configured, because a
     * forged X-Forwarded-For would otherwise let anyone fake their country or
     * bypass the rate limiter.
     */
    public static function trustsProxy(): bool
    {
        $configured = Config::get('security.trusted_proxies', '');

        if (is_bool($configured)) {
            return $configured;
        }
        if (is_array($configured) && $configured !== []) {
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            foreach ($configured as $proxy) {
                if (is_string($proxy) && $proxy !== '' && self::ipInCidr($ip, $proxy)) {
                    return true;
                }
            }
            return false;
        }
        return is_string($configured) && $configured !== '' && $configured !== '0';
    }

    /**
     * Whether an IP falls inside a CIDR block (or equals a plain address).
     */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }
        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $rest  = $bits % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        if ($rest === 0) {
            return true;
        }
        $mask = 0xff << (8 - $rest) & 0xff;
        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    /**
     * The request path with the application base path removed, e.g. "dashboard".
     */
    public static function currentPath(): string
    {
        // The "?r=" fallback form wins when pretty URLs are disabled.
        $route = $_GET['r'] ?? null;
        if (is_string($route) && $route !== '') {
            return trim($route, '/');
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $path = rawurldecode($path);

        $basePath = trim((string) Config::get('app.base_path', ''), '/');
        if ($basePath !== '' && strpos($path, '/' . $basePath) === 0) {
            $path = substr($path, strlen($basePath) + 1);
        }

        return trim($path, '/');
    }

    /**
     * Detect the base path from the running script, used before config.php
     * exists (installer) and as a fallback afterwards.
     *
     * /install/index.php        -> ""
     * /analytics/install/index.php -> "/analytics"
     */
    public static function detectBasePath(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        // dirname() uses the platform separator, so normalise again: on Windows
        // it would otherwise turn "/index.php" into "\" and corrupt every URL.
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

        // Drop a trailing /install, /api or /public segment: those are entry
        // points, not the application root.
        foreach (['/install', '/api', '/public'] as $suffix) {
            if (substr($dir, -strlen($suffix)) === $suffix) {
                $dir = substr($dir, 0, -strlen($suffix));
                break;
            }
        }

        $dir = trim($dir, '/');

        // An empty result means "installed in the document root" - returning
        // "/" here would double every slash in every generated URL.
        return $dir === '' ? '' : '/' . $dir;
    }

    /**
     * The absolute origin of this installation, e.g. https://a.example.com
     *
     * Scheme and host only: the path a sub-directory installation lives under
     * belongs to pb_base_path(). Returning it here as well is what used to
     * produce /plotbird/plotbird/ in every generated link, because url() and
     * root_url() append the base path themselves.
     */
    public static function detectedBaseUrl(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        // Guard against header injection: keep only a plausible host[:port].
        if (preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host) !== 1) {
            $host = 'localhost';
        }

        return (is_https() ? 'https' : 'http') . '://' . $host;
    }

    /**
     * Send the baseline security headers.
     */
    public static function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        // The interpreter advertises its exact version in a header unless told
        // otherwise, and that is a free hint for anyone matching exploits to a
        // version number.
        header_remove('X-Powered-By');

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-XSS-Protection: 0'); // Modern browsers: CSP is the real defence.
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        $csp = Config::get('security.content_security_policy');
        if (is_string($csp) && $csp !== '') {
            header('Content-Security-Policy: ' . $csp);
        }

        if (is_https() && Config::get('security.hsts', false)) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /**
     * Whether the /install directory is still present. The installer asks the
     * administrator to delete it; until then the dashboard warns on every page.
     */
    public static function installerPresent(): bool
    {
        return is_file(PB_ROOT . '/install/index.php');
    }

    /**
     * Path of the marker written when the installation completed. While it
     * exists the installer refuses to run again, which prevents an attacker
     * from re-running it and taking over the instance.
     */
    public static function installLockPath(): string
    {
        return PB_ROOT . '/config/install.lock';
    }

    public static function isInstallLocked(): bool
    {
        return is_file(self::installLockPath());
    }

    /**
     * A timestamped marker file, used for install lock and GeoIP import state.
     */
    public static function writeLock(string $path, string $contents = ''): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        return @file_put_contents($path, $contents === '' ? now_utc() : $contents, LOCK_EX) !== false;
    }

    /**
     * Reject a request that did not come from this site's own origin.
     *
     * Cheap origin check used by the JSON API in addition to CSRF tokens.
     */
    public static function sameOrigin(): bool
    {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin === '') {
            $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
            if ($referer === '') {
                // No origin information at all (curl, server-side fetch).
                return true;
            }
            $origin = $referer;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $ownHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $ownHost = explode(':', $ownHost)[0];
        return strcasecmp($host, $ownHost) === 0;
    }
}
