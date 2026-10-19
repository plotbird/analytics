<?php
/**
 * PlotBird Analytics - authentication.
 *
 * Session based, with a user-agent fingerprint to blunt session theft, and a
 * two-layer brute-force lockout (per account+IP and per IP).
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class Auth
{
    /** Failed sign-ins allowed for one account from one IP inside the window. */
    private const MAX_ATTEMPTS_PER_ACCOUNT = 5;

    /** Failed sign-ins allowed from one IP inside the window, any account. */
    private const MAX_ATTEMPTS_PER_IP = 20;

    /** Length of the lockout window, in seconds. */
    private const LOCKOUT_WINDOW = 900;

    /** Minimum accepted password length. */
    public const MIN_PASSWORD_LENGTH = 8;

    /** @var array<string,mixed>|null Cached current user row. */
    private static ?array $user = null;

    /** @var bool Whether the current user row was already looked up. */
    private static bool $resolved = false;

    /**
     * Attempt a sign-in.
     *
     * @return array{ok:bool,error:string,user?:array<string,mixed>,locked_for?:int}
     */
    public static function attempt(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email), 'UTF-8');
        $ip    = client_ip();

        // Cheap rejection before touching the database.
        $lockRemaining = self::lockoutRemaining($email, $ip);
        if ($lockRemaining > 0) {
            self::recordAttempt($email, false, $ip);
            return ['ok' => false, 'error' => 'locked', 'locked_for' => $lockRemaining];
        }

        $record = User::findWithSecret($email);

        // Always run a hash comparison so that a missing account and a wrong
        // password take a comparable amount of time.
        $hash = is_array($record) ? (string) $record['password_hash'] : '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        $valid = Security::verifyPassword($password, $hash);

        if (!is_array($record) || !$valid) {
            self::recordAttempt($email, false, $ip);
            return ['ok' => false, 'error' => 'credentials'];
        }

        if ((int) $record['is_active'] !== 1) {
            self::recordAttempt($email, false, $ip);
            return ['ok' => false, 'error' => 'blocked'];
        }

        // Opportunistically upgrade the stored hash.
        if (Security::passwordNeedsRehash((string) $record['password_hash'])) {
            User::setPassword((int) $record['id'], $password);
        }

        self::recordAttempt($email, true, $ip);
        self::establish($record);

        return ['ok' => true, 'error' => '', 'user' => $record];
    }

    /**
     * Put a verified user into the session.
     *
     * @param array<string,mixed> $record
     */
    public static function establish(array $record): void
    {
        Security::startSession();
        session_regenerate_id(true);

        $_SESSION['_uid']       = (int) $record['id'];
        $_SESSION['_auth_time'] = time();
        $_SESSION['_fp']        = self::fingerprint();

        self::$user     = $record;
        self::$resolved = true;

        User::touchLogin((int) $record['id'], client_ip());
        Audit::log('login', 'user', (int) $record['id']);
    }

    /**
     * Extend the session cookie so the browser keeps the sign-in for 30 days.
     */
    public static function rememberDevice(): void
    {
        Security::startSession();
        $lifetime = 60 * 60 * 24 * 30;

        $basePath = trim((string) Config::get('app.base_path', ''), '/');
        setcookie(session_name(), session_id(), [
            'expires'  => time() + $lifetime,
            'path'     => $basePath === '' ? '/' : '/' . $basePath . '/',
            'domain'   => '',
            'secure'   => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Sign out and destroy the session.
     */
    public static function logout(): void
    {
        Security::startSession();
        $userId = isset($_SESSION['_uid']) ? (int) $_SESSION['_uid'] : null;

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => (string) $params['domain'],
                'secure'   => (bool) $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();

        self::$user     = null;
        self::$resolved = true;

        if ($userId !== null) {
            Audit::log('logout', 'user', $userId);
        }
    }

    /**
     * The signed-in user row, or null.
     *
     * @return array<string,mixed>|null
     */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        self::$user     = null;

        Security::startSession();
        $uid = $_SESSION['_uid'] ?? null;
        if (!is_int($uid) && !ctype_digit((string) $uid)) {
            return null;
        }

        // The session is bound to the browser that created it.
        $fingerprint = (string) ($_SESSION['_fp'] ?? '');
        if ($fingerprint !== '' && !hash_equals($fingerprint, self::fingerprint())) {
            pb_log('session fingerprint mismatch for user ' . $uid, 'security');
            self::logout();
            return null;
        }

        $record = User::find((int) $uid);
        if ($record === null || (int) $record['is_active'] !== 1) {
            self::logout();
            return null;
        }

        // Rotate the session id periodically to limit the value of a stolen id.
        $authTime = (int) ($_SESSION['_auth_time'] ?? 0);
        if ($authTime > 0 && time() - $authTime > 1800) {
            session_regenerate_id(true);
            $_SESSION['_auth_time'] = time();
        }

        return self::$user = $record;
    }

    public static function id(): ?int
    {
        $user = self::user();
        return $user === null ? null : (int) $user['id'];
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isSuperAdmin(): bool
    {
        $user = self::user();
        return $user !== null && $user['role'] === User::ROLE_SUPER_ADMIN;
    }

    /**
     * Abort the request unless somebody is signed in.
     *
     * @param bool $json Force a JSON answer instead of a redirect to the login
     *                   page. API entry points pass true: a redirect is never a
     *                   useful answer to a programmatic caller, and whether the
     *                   caller "wants JSON" should not depend on it sending an
     *                   Accept header.
     */
    public static function requireLogin(bool $json = false): void
    {
        if (self::check()) {
            return;
        }

        if ($json || Security::wantsJson()) {
            json_out(['error' => 'unauthenticated', 'message' => 'Sign in required.'], 401);
        }

        $next = '/' . Security::currentPath();
        redirect(url('login', $next === '/' ? [] : ['next' => $next]));
    }

    /**
     * Abort the request unless the signed-in user is a super admin.
     */
    public static function requireSuperAdmin(): void
    {
        self::requireLogin();
        if (self::isSuperAdmin()) {
            return;
        }

        if (Security::wantsJson()) {
            json_out(['error' => 'forbidden', 'message' => 'Super administrator rights required.'], 403);
        }

        http_response_code(403);
        View::renderError(403, I18n::translate('error.forbidden_title'), I18n::translate('error.forbidden_body'));
        exit;
    }

    /**
     * Seconds left in a lockout for this account/IP pair, 0 when unlocked.
     */
    public static function lockoutRemaining(string $email, ?string $ip = null): int
    {
        $ipHash = Security::hashIp($ip ?? client_ip());
        $db     = Database::instance();
        $since  = gmdate('Y-m-d H:i:s', time() - self::LOCKOUT_WINDOW);

        $accountFailures = (int) $db->value(
            'SELECT COUNT(*) FROM ' . $db->table('login_attempts') . '
             WHERE `successful` = 0 AND `email` = :email AND `ip_hash` = :ip AND `created_at` >= :since',
            [':email' => $email, ':ip' => $ipHash, ':since' => $since],
            0
        );
        $ipFailures = (int) $db->value(
            'SELECT COUNT(*) FROM ' . $db->table('login_attempts') . '
             WHERE `successful` = 0 AND `ip_hash` = :ip AND `created_at` >= :since',
            [':ip' => $ipHash, ':since' => $since],
            0
        );

        if ($accountFailures < self::MAX_ATTEMPTS_PER_ACCOUNT && $ipFailures < self::MAX_ATTEMPTS_PER_IP) {
            return 0;
        }

        $lastFailure = (string) $db->value(
            'SELECT MAX(`created_at`) FROM ' . $db->table('login_attempts') . '
             WHERE `successful` = 0 AND `ip_hash` = :ip AND `created_at` >= :since',
            [':ip' => $ipHash, ':since' => $since],
            ''
        );
        if ($lastFailure === '') {
            return self::LOCKOUT_WINDOW;
        }

        $elapsed = time() - (int) strtotime($lastFailure . ' UTC');
        return max(0, self::LOCKOUT_WINDOW - $elapsed);
    }

    /**
     * Remember one sign-in attempt.
     */
    public static function recordAttempt(string $email, bool $success, ?string $ip = null): void
    {
        try {
            $db = Database::instance();
            $db->insert('login_attempts', [
                'ip_hash'    => Security::hashIp($ip ?? client_ip()),
                'email'      => mb_substr(mb_strtolower(trim($email), 'UTF-8'), 0, 190, 'UTF-8'),
                'successful' => $success ? 1 : 0,
                'created_at' => now_utc(),
            ]);

            // Successful sign-ins clear the account bucket.
            if ($success) {
                $db->delete('login_attempts', '`email` = :email AND `successful` = 0', [':email' => mb_strtolower(trim($email), 'UTF-8')]);
            }

            // Housekeeping: attempts older than a day are useless.
            if (random_int(1, 50) === 1) {
                $db->delete('login_attempts', '`created_at` < :cutoff', [':cutoff' => gmdate('Y-m-d H:i:s', time() - 86400 * 7)]);
            }
        } catch (\Throwable $e) {
            pb_log('login_attempts write failed: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Change the signed-in user's own password (verifies the current one).
     *
     * @return array{ok:bool,error:string}
     */
    public static function changeOwnPassword(string $current, string $new, string $repeat): array
    {
        $user = self::user();
        if ($user === null) {
            return ['ok' => false, 'error' => 'auth'];
        }
        $record = User::findWithSecret((string) $user['email']);
        if ($record === null || !Security::verifyPassword($current, (string) $record['password_hash'])) {
            return ['ok' => false, 'error' => 'current'];
        }
        $problem = self::validatePassword($new, $repeat);
        if ($problem !== '') {
            return ['ok' => false, 'error' => $problem];
        }

        User::setPassword((int) $user['id'], $new);
        Audit::log('password_changed', 'user', (int) $user['id']);
        return ['ok' => true, 'error' => ''];
    }

    /**
     * Validate a new password. Returns an error key or an empty string.
     */
    public static function validatePassword(string $password, string $repeat = ''): string
    {
        if (mb_strlen($password, 'UTF-8') < self::MIN_PASSWORD_LENGTH) {
            return 'too_short';
        }
        if ($repeat !== '' && !hash_equals($password, $repeat)) {
            return 'mismatch';
        }
        // Reject the handful of passwords that would be guessed first.
        $weak = ['password', 'password1', '12345678', '123456789', 'qwerty123', 'admin123', 'plotbird'];
        if (in_array(mb_strtolower($password, 'UTF-8'), $weak, true)) {
            return 'too_weak';
        }
        return '';
    }

    /**
     * A stable, non-reversible identifier of the current browser.
     */
    private static function fingerprint(): string
    {
        $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $lang  = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        return substr(hash('sha256', $agent . '|' . $lang . '|' . (string) Config::get('security.salt', 'plotbird')), 0, 32);
    }
}
