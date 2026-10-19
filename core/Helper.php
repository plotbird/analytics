<?php
/**
 * PlotBird Analytics - global helper functions.
 *
 * Small, dependency-free utilities shared by the front controller, the API
 * endpoints, the installer and every template. Deliberately procedural: these
 * are used from templates where `__()`, `e()` and `url()` should read naturally.
 *
 * @package PlotBird
 */

declare(strict_types=1);

use PlotBird\Config;
use PlotBird\I18n;
use PlotBird\Security;

if (!defined('PB_ROOT')) {
    define('PB_ROOT', dirname(__DIR__));
}

/**
 * Application version, shown in the footer and used for update checks.
 */
if (!defined('PB_VERSION')) {
    define('PB_VERSION', '1.0.2');
}

/**
 * Bumped whenever install/schema.sql changes; the settings page compares it
 * with the value stored in the database and offers a migration.
 */
if (!defined('PB_SCHEMA_VERSION')) {
    define('PB_SCHEMA_VERSION', 1);
}

/**
 * Read a configuration value using a dotted path.
 *
 * @param string $key     e.g. "db.host"
 * @param mixed  $default
 * @return mixed
 */
function config(string $key, $default = null)
{
    return Config::get($key, $default);
}

/**
 * Escape a value for HTML output. Every echo in a template goes through this.
 *
 * @param mixed $value
 */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Translate a locale key, with optional {placeholder} replacement.
 *
 * @param array<string,string|int> $replace
 */
function __(string $key, array $replace = []): string
{
    return I18n::translate($key, $replace);
}

/**
 * Translate and echo, already escaped.
 *
 * @param array<string,string|int> $replace
 */
function _e(string $key, array $replace = []): void
{
    echo e(I18n::translate($key, $replace));
}

/**
 * Current interface language, e.g. "ru".
 */
function locale(): string
{
    return I18n::current();
}

/**
 * Absolute base URL of the installation, without a trailing slash.
 */
function base_url(): string
{
    $configured = (string) config('app.url', '');
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    return rtrim(Security::detectedBaseUrl(), '/');
}

/**
 * URL of the project's public source repository, shown in the footer.
 *
 * Self-hosted installations are often forks, so this is configurable rather
 * than hard-coded (see app.repository in config/config.sample.php).
 */
function repository_url(): string
{
    return (string) config('app.repository', 'https://github.com/plotbird/analytics');
}

/**
 * The application's base path, always starting with "/" (or empty when the
 * installation sits in the document root).
 *
 * The configured value wins; before the configuration exists (installer) it is
 * derived from the running script, so links work during installation too.
 */
function pb_base_path(): string
{
    $configured = (string) config('app.base_path', '');
    if ($configured !== '') {
        return '/' . trim($configured, '/');
    }
    return \PlotBird\Security::detectBasePath();
}

/**
 * Build an application URL.
 *
 * @param array<string,string|int> $query
 */
function url(string $path = '', array $query = []): string
{
    $path = ltrim($path, '/');
    $base = base_url();
    $prefix = pb_base_path();

    // With pretty URLs disabled the router expects a single "r" parameter.
    if (!config('app.pretty_urls', true)) {
        $query = array_merge(['r' => $path], $query);
        $url   = $base . $prefix . '/index.php';
    } else {
        $url = $base . $prefix . '/' . $path;
    }

    if ($query !== []) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
    }
    return $url;
}

/**
 * URL of a physical file inside the installation (an API endpoint, a
 * downloadable file). Unlike url(), it never falls back to the "?r=" form,
 * because the target really exists on disk.
 */
function root_url(string $path = ''): string
{
    $base = base_url();
    $prefix = pb_base_path();
    $path = ltrim($path, '/');

    return $base . $prefix . ($path === '' ? '' : '/' . $path);
}

/**
 * URL of a file inside /assets.
 *
 * The cache buster combines the configured asset version with the file's
 * modification time. That matters for this project: it is updated by
 * uploading files over FTP, and a fixed "?v=1.0.0" would leave every returning
 * visitor with a stale script for as long as the browser cache lives.
 */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $version = (string) config('app.asset_version', PB_VERSION);

    $file = PB_ROOT . '/assets/' . $path;
    if (is_file($file)) {
        $mtime = @filemtime($file);
        if ($mtime !== false) {
            $version .= '-' . $mtime;
        }
    }

    return root_url('assets/' . $path) . (strpos($path, '?') === false ? '?v=' . rawurlencode($version) : '');
}

/**
 * Path of the current request relative to the application base path.
 */
function current_path(): string
{
    return Security::currentPath();
}

/**
 * Whether the current request is a POST.
 */
function is_post(): bool
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
}

/**
 * Trimmed string from $_POST.
 */
function post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    if (is_array($value)) {
        return $default;
    }
    return trim((string) $value);
}

/**
 * Trimmed string from $_GET.
 */
function query_param(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    if (is_array($value)) {
        return $default;
    }
    return trim((string) $value);
}

/**
 * Integer from $_POST.
 */
function post_int(string $key, int $default = 0): int
{
    $value = $_POST[$key] ?? null;
    return is_scalar($value) ? (int) $value : $default;
}

/**
 * Whether a checkbox-style field was submitted as a truthy value.
 */
function post_bool(string $key): bool
{
    $value = $_POST[$key] ?? null;
    if (is_array($value)) {
        return false;
    }
    return in_array((string) $value, ['1', 'on', 'true', 'yes'], true);
}

/**
 * A secure random hex string.
 */
function random_hex(int $bytes = 16): string
{
    try {
        return bin2hex(random_bytes($bytes));
    } catch (\Exception $e) {
        // random_bytes() only fails when the platform has no CSPRNG at all.
        return bin2hex(openssl_random_pseudo_bytes($bytes));
    }
}

/**
 * A RFC 4122 version 4 UUID (used as a website's public id).
 */
function uuid4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Whether the request is served over HTTPS (honouring a reverse proxy header
 * only when proxies are explicitly trusted).
 */
function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    if (Security::trustsProxy()) {
        $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($proto === 'https') {
            return true;
        }
    }
    return false;
}

/**
 * Current UTC timestamp in MySQL DATETIME format.
 *
 * All timestamps in the database are UTC; sites only differ in how they are
 * displayed and bucketed.
 */
function now_utc(string $format = 'Y-m-d H:i:s'): string
{
    return gmdate($format);
}

/**
 * Pretty number: 1234 -> "1 234" (locale-aware separators).
 */
function format_int($value): string
{
    return number_format((float) $value, 0, I18n::decimalSeparator(), I18n::thousandsSeparator());
}

/**
 * A number with one decimal place, trailing ".0" removed.
 */
function format_decimal($value, int $decimals = 1): string
{
    $formatted = number_format((float) $value, $decimals, I18n::decimalSeparator(), I18n::thousandsSeparator());
    $formatted = rtrim(rtrim($formatted, '0'), I18n::decimalSeparator());
    return $formatted === '' ? '0' : $formatted;
}

/**
 * Seconds as "1m 24s" / "2h 05m" / "45s".
 */
function format_duration($seconds): string
{
    $seconds = (int) round((float) $seconds);
    if ($seconds <= 0) {
        return '0' . I18n::translate('unit.second_short');
    }
    if ($seconds < 60) {
        return $seconds . I18n::translate('unit.second_short');
    }
    $minutes = intdiv($seconds, 60);
    $rest    = $seconds % 60;
    if ($minutes < 60) {
        return $minutes . I18n::translate('unit.minute_short') . ' ' . str_pad((string) $rest, 2, '0', STR_PAD_LEFT) . I18n::translate('unit.second_short');
    }
    $hours = intdiv($minutes, 60);
    $minutes %= 60;
    return $hours . I18n::translate('unit.hour_short') . ' ' . str_pad((string) $minutes, 2, '0', STR_PAD_LEFT) . I18n::translate('unit.minute_short');
}

/**
 * A ratio (0..1) as a percentage string.
 */
function format_percent($ratio, int $decimals = 0): string
{
    return number_format((float) $ratio * 100, $decimals, I18n::decimalSeparator(), '') . '%';
}

/**
 * Shorten a string for display without breaking UTF-8.
 */
function str_shorten(string $value, int $length = 60, string $suffix = '…'): string
{
    if (mb_strlen($value, 'UTF-8') <= $length) {
        return $value;
    }
    return rtrim(mb_substr($value, 0, $length - 1, 'UTF-8')) . $suffix;
}

/**
 * The client IP address.
 *
 * Proxy headers are only honoured when the installation explicitly trusts a
 * proxy; otherwise a spoofed X-Forwarded-For would poison geolocation and the
 * rate limiter.
 */
function client_ip(): string
{
    if (Security::trustsProxy()) {
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            $candidate = trim(explode(',', $forwarded)[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }
        $real = (string) ($_SERVER['HTTP_X_REAL_IP'] ?? '');
        if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP) !== false) {
            return $real;
        }
    }
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
}

/**
 * IP protocol family: 4 or 6. Returns 0 for an invalid address.
 */
function ip_family(string $ip): int
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        return 4;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        return 6;
    }
    return 0;
}

/**
 * An IP address as a 16 byte big-endian string.
 *
 * IPv4 addresses are stored IPv4-mapped (::ffff:a.b.c.d) so that both families
 * share one column layout and a single comparison operator.
 */
function ip_to_binary(string $ip): string
{
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return '';
    }
    if (strlen($packed) === 4) {
        return "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . $packed;
    }
    return $packed;
}

/**
 * Normalise a tracked path: no scheme/host, no query string, no fragment,
 * no control characters, always starting with "/".
 */
function normalize_path(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '/';
    }
    // Strip scheme and host if the tracker sent an absolute URL.
    if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $path) === 1) {
        $parts = parse_url($path);
        $path  = (string) ($parts['path'] ?? '/');
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
    }
    $path = preg_replace('/[\x00-\x1F\x7F]/u', '', $path) ?? $path;
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }
    if (mb_strlen($path, 'UTF-8') > 512) {
        $path = mb_substr($path, 0, 512, 'UTF-8');
    }
    return $path;
}

/**
 * The registrable-ish host of a referrer URL, without "www.".
 */
function referrer_domain(string $referrer): string
{
    $referrer = trim($referrer);
    if ($referrer === '') {
        return '';
    }
    $host = parse_url($referrer, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return '';
    }
    $host = strtolower($host);
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    return mb_substr($host, 0, 190, 'UTF-8');
}

/**
 * Whether a directory can really be written to.
 *
 * is_writable() reports the ACL, which is misleading on some hosts (and inside
 * restrictive containers it can be false for a directory that accepts writes).
 * This performs an actual write, which is the only answer that matters.
 */
function pb_dir_writable(string $dir): bool
{
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $probe = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '.pb-write-test-' . uniqid('', true);
    $handle = @fopen($probe, 'wb');
    if ($handle === false) {
        return false;
    }
    fclose($handle);
    @unlink($probe);

    return true;
}

/**
 * Append a line to today's application log (best effort, never fatal).
 */
function pb_log(string $message, string $channel = 'app'): void
{
    $dir = PB_ROOT . '/data/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }
    $line = sprintf("[%s] %s\n", now_utc(), str_replace(["\r", "\n"], ' ', $message));
    @file_put_contents($dir . '/' . $channel . '-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
}

/**
 * Send a JSON response and stop.
 *
 * @param mixed $payload
 */
function json_out($payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        // Statistics responses are per-user; never let a proxy cache them.
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Redirect and stop.
 */
function redirect(string $target, int $status = 302): void
{
    if (!headers_sent()) {
        header('Location: ' . $target, true, $status);
    }
    exit;
}

/**
 * Redirect back to the page the form was submitted from, with flash messages.
 *
 * @param array<string,string> $params
 */
function redirect_to(string $path, array $params = []): void
{
    redirect(url($path, $params));
}

/**
 * Read and clear a one-shot flash message.
 */
function flash(string $key, ?string $value = null): ?string
{
    Security::startSession();
    if ($value !== null) {
        $_SESSION['_flash'][$key] = $value;
        return null;
    }
    $stored = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return is_string($stored) ? $stored : null;
}

/**
 * Whether a flash message of that kind exists (for templates).
 */
function has_flash(string $key): bool
{
    Security::startSession();
    return isset($_SESSION['_flash'][$key]);
}

/**
 * JSON decode helper that never returns null for valid "null".
 *
 * @return mixed
 */
function json_decode_safe(?string $value, $default = [])
{
    if ($value === null || $value === '') {
        return $default;
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : $default;
}

/**
 * Human readable byte size, used by the environment check page.
 */
function format_bytes($bytes): string
{
    $bytes = (float) $bytes;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }
    return ($index === 0 ? (string) (int) $bytes : number_format($bytes, 1)) . ' ' . $units[$index];
}

/**
 * URL of a country flag from the vendored SVG set, or an empty string when the
 * flag is not available.
 *
 * SVG files are used instead of Unicode flag emoji on purpose: Windows (and
 * several Linux distributions) do not have a flag emoji font and render the
 * two regional indicator letters - "RU" instead of a flag - which looks broken.
 */
function flag_url(string $code): string
{
    $code = strtolower(trim($code));
    if (preg_match('/^[a-z]{2}$/', $code) !== 1) {
        $code = 'xx';
    }

    $relative = 'vendor/flags/' . $code . '.svg';
    if (!is_file(PB_ROOT . '/assets/' . $relative)) {
        if ($code === 'xx') {
            return '';
        }
        $relative = 'vendor/flags/xx.svg';
        if (!is_file(PB_ROOT . '/assets/' . $relative)) {
            return '';
        }
    }

    return asset($relative);
}

/**
 * A country flag as an <img>, with a globe fallback when no flag file exists.
 */
function country_flag_img(string $code, string $class = 'pb-flag'): string
{
    $url = flag_url($code);
    if ($url === '') {
        return '<span class="' . e($class) . ' pb-flag-fallback" aria-hidden="true">🌐</span>';
    }

    return '<img src="' . e($url) . '" alt="" width="20" height="15" loading="lazy" class="' . e($class) . '">';
}

/**
 * Country flag emoji from an ISO 3166-1 alpha-2 code.
 *
 * Kept for contexts where an image is impractical (plain text, logs); the
 * interface uses country_flag_img() instead.
 */
function country_flag(string $code): string
{
    $code = strtoupper(trim($code));
    if (strlen($code) !== 2 || !ctype_alpha($code)) {
        return '🌐';
    }
    $a = mb_ord($code[0], 'UTF-8');
    $b = mb_ord($code[1], 'UTF-8');
    if ($a === false || $b === false) {
        return '🌐';
    }
    return mb_chr($a + 127397, 'UTF-8') . mb_chr($b + 127397, 'UTF-8');
}

/**
 * A link back to the site itself that produced a pageview.
 */
function path_to_display(string $path): string
{
    return $path === '' ? '/' : $path;
}
