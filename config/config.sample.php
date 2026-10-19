<?php
/**
 * PlotBird Analytics - example configuration.
 *
 * The installer writes the real file to /config/config.php. This sample exists
 * so that every option is documented and so that a manual installation (or a
 * migration) has something to copy.
 *
 * Copy it with:  cp config/config.sample.php config/config.php
 * Then delete /config/install.lock if you are recovering a broken setup.
 *
 * @package PlotBird
 */

return [

    /*
     * Application.
     */
    'app' => [
        // Shown in the header, the page title and the footer.
        'name'          => 'PlotBird Analytics',

        // Absolute URL of the installation, without a trailing slash.
        // Leave it empty to let PlotBird detect it from the request - that is
        // fine for a single domain, but set it explicitly when the dashboard is
        // reachable through more than one hostname or behind a proxy.
        'url'           => '',

        // Sub-directory the project lives in, e.g. '/analytics'.
        // Empty string means "document root". The installer fills this in.
        'base_path'     => '',

        // Default interface language. Shipped: en, ru, de, es, fr, zh
        // (plus any locale file you add to /locales).
        'default_locale' => 'en',

        // Reference timezone for the application itself. Statistics are always
        // bucketed in each website's own timezone.
        'timezone'      => 'UTC',

        // Pretty URLs (https://host/dashboard instead of /index.php?r=dashboard).
        // Requires mod_rewrite; turn it off if your links 404.
        'pretty_urls'   => true,

        // Cache buster appended to /assets URLs.
        'asset_version' => '1.0.2',

        // Public source repository, linked from the footer. Point it at your
        // own fork if you publish one.
        'repository'    => 'https://github.com/plotbird/analytics',

        // Show stack traces instead of friendly error pages. Never enable this
        // on a public installation.
        'debug'         => false,
    ],

    /*
     * Database.
     */
    'db' => [
        // "localhost", an IP address, or "host:port".
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'plotbird',
        'user'     => '',
        'password' => '',

        // utf8mb4 is required for emoji and CJK characters in URLs and titles.
        'charset'  => 'utf8mb4',

        // Table prefix - use one when the database is shared with another
        // application. Only letters, digits and underscores.
        'prefix'   => 'pb_',

        // Optional TLS for managed MySQL databases. Path to the CA bundle.
        'ssl_ca'   => '',
    ],

    /*
     * Security.
     */
    'security' => [
        // Secret used to hash IP addresses and visitor fingerprints.
        // Rotating it does NOT break anything, but the current day's unique
        // visitor counts start over. Keep it out of version control.
        'salt'            => '',

        // Protects /api/cron.php. Rotate it from the settings page if it leaks.
        'cron_secret'     => '',

        // Name of the session cookie.
        'session_name'    => 'pb_session',

        // Reverse proxies whose X-Forwarded-For / X-Forwarded-Proto headers may
        // be trusted. Leave empty when the site is served directly: otherwise a
        // forged header could fake the visitor's country and defeat the rate
        // limiter. Accepts an IP, a CIDR block or a list of them.
        'trusted_proxies' => '',

        // Send HSTS. Only enable it when the site is HTTPS-only for good.
        'hsts'            => false,

        // Optional Content-Security-Policy header. Empty means "do not send".
        'content_security_policy' => '',
    ],

    /*
     * Tracking endpoint.
     */
    'tracker' => [
        // Maximum number of accepted hits per IP address per minute.
        // Protects the collection endpoint from being used as a free database
        // writer; 120/minute is generous for a real single visitor.
        'rate_limit' => 120,
    ],
];
