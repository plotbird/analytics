<?php
/**
 * PlotBird Analytics - application settings (the settings table).
 *
 * Global, installation-wide values only. Per-site behaviour (excluded paths,
 * Do-Not-Track handling, ...) is stored in sites.tracker_settings, and user
 * preferences live on the user row or in localStorage.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class Settings
{
    /** Values that exist even before the row is written. */
    private const DEFAULTS = [
        'schema_version'      => PB_SCHEMA_VERSION,
        'default_locale'      => 'en',
        // How long raw pageviews and visits are kept. Aggregated numbers are
        // permanent, so a dashboard still shows last year after pruning.
        'retention_days'      => 365,
        // A visit is closed after this many minutes without activity.
        'session_timeout'     => 30,
        // Store geolocation only at country level and never keep raw IPs.
        'anonymize_ip'        => true,
        // Honour the browser's Do-Not-Track signal.
        'respect_dnt'         => false,
        // Track visitors from these countries/IP ranges is not a feature yet;
        // the setting stays as documented default.
        'exclude_bots'        => true,
        'geoip_count'         => 0,
        'geoip_updated_at'    => '',
        'installed_at'        => '',
        'cron_last_run'       => '',
        'update_check'        => true,
    ];

    /** @var array<string,string>|null In-memory cache of the whole table. */
    private static ?array $cache = null;

    /**
     * Load every setting once per request.
     *
     * @return array<string,string>
     */
    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $rows = Database::instance()->all('SELECT `name`, `value` FROM ' . Database::instance()->table('settings'));
        $values = [];
        foreach ($rows as $row) {
            $values[(string) $row['name']] = (string) ($row['value'] ?? '');
        }
        return self::$cache = $values;
    }

    /**
     * Read a setting, falling back to the built-in default.
     *
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $name, $default = null)
    {
        $all = self::load();
        if (array_key_exists($name, $all)) {
            return $all[$name];
        }
        if (array_key_exists($name, self::DEFAULTS)) {
            return self::DEFAULTS[$name];
        }
        return $default;
    }

    public static function getInt(string $name, int $default = 0): int
    {
        $value = self::get($name, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function getBool(string $name, bool $default = false): bool
    {
        $value = self::get($name, $default ? '1' : '0');
        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Write a setting (insert or update).
     *
     * @param mixed $value
     */
    public static function set(string $name, $value): void
    {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        $stringValue = is_scalar($value) ? (string) $value : json_encode($value);

        $db = Database::instance();
        $db->run(
            'INSERT INTO ' . $db->table('settings') . ' (`name`, `value`, `updated_at`) VALUES (:name, :value, :now)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = VALUES(`updated_at`)',
            [':name' => $name, ':value' => $stringValue, ':now' => now_utc()]
        );

        if (self::$cache === null) {
            self::load();
        }
        self::$cache[$name] = $stringValue;
    }

    /**
     * All settings as a plain array (defaults included).
     *
     * @return array<string,string>
     */
    public static function all(): array
    {
        $defaults = [];
        foreach (self::DEFAULTS as $name => $value) {
            $defaults[$name] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }
        return array_merge($defaults, self::load());
    }

    /**
     * Forget the cache (used by the installer and by tests).
     */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * The secret that protects the public cron endpoint. Generated on demand,
     * so a fresh installation cannot be polled by strangers.
     */
    public static function cronSecret(): string
    {
        $secret = (string) self::get('cron_secret', '');
        if ($secret === '') {
            $secret = random_hex(24);
            self::set('cron_secret', $secret);
        }
        return $secret;
    }

    /**
     * How many days of raw data are kept.
     */
    public static function retentionDays(): int
    {
        $days = self::getInt('retention_days', 365);
        return max(1, min(3650, $days));
    }

    /**
     * Session gap in seconds.
     */
    public static function sessionTimeoutSeconds(): int
    {
        $minutes = self::getInt('session_timeout', 30);
        return max(1, min(240, $minutes)) * 60;
    }
}
