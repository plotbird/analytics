<?php
/**
 * PlotBird Analytics - site repository.
 *
 * A "site" is one tracked website: it owns a public UUID (handed to the
 * tracker), tracker settings and the statistics rows.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class Site
{
    /** Tracker behaviour that can be tuned per website. */
    public const TRACKER_DEFAULTS = [
        // Path prefixes that must not be tracked, e.g. ["/admin", "/checkout"].
        'exclude_paths' => [],
        // CIDR list of IP ranges that must not be tracked (offices, bots).
        'exclude_ips'   => [],
        // Honour the browser Do-Not-Track header.
        'respect_dnt'   => false,
        // Keep "?query=string" in the recorded path.
        'track_query'   => false,
        // Extra hostnames allowed to send data for this site.
        'allowed_hosts' => [],
        // Drop obvious bots server-side.
        'exclude_bots'  => true,
    ];

    /**
     * @return array<string,mixed>|null
     */
    public static function find(int $id): ?array
    {
        $db = Database::instance();
        return $db->one('SELECT * FROM ' . $db->table('sites') . ' WHERE `id` = :id', [':id' => $id]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findByPublicId(string $publicId): ?array
    {
        $db = Database::instance();
        return $db->one(
            'SELECT * FROM ' . $db->table('sites') . ' WHERE `public_id` = :pid',
            [':pid' => trim($publicId)]
        );
    }

    /**
     * Every site, newest first, with owner counts.
     *
     * @return list<array<string,mixed>>
     */
    public static function all(): array
    {
        $db = Database::instance();
        return $db->all(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM ' . $db->table('site_users') . ' su WHERE su.`site_id` = s.`id`) AS user_count,
                    (SELECT COUNT(*) FROM ' . $db->table('pageviews') . ' p WHERE p.`site_id` = s.`id`) AS pageview_count
             FROM ' . $db->table('sites') . ' s
             ORDER BY s.`id` DESC'
        );
    }

    /**
     * Sites a user may open. Super admins see everything.
     *
     * @param array<string,mixed> $user
     * @return list<array<string,mixed>>
     */
    public static function forUser(array $user): array
    {
        $db = Database::instance();

        if (($user['role'] ?? '') === User::ROLE_SUPER_ADMIN) {
            return $db->all('SELECT * FROM ' . $db->table('sites') . ' ORDER BY `name` ASC, `id` ASC');
        }

        return $db->all(
            'SELECT s.* FROM ' . $db->table('sites') . ' s
             INNER JOIN ' . $db->table('site_users') . ' su ON su.`site_id` = s.`id`
             WHERE su.`user_id` = :uid
             ORDER BY s.`name` ASC, s.`id` ASC',
            [':uid' => (int) $user['id']]
        );
    }

    /**
     * The first site a user may open (dashboard default).
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>|null
     */
    public static function firstForUser(array $user): ?array
    {
        $sites = self::forUser($user);
        return $sites === [] ? null : $sites[0];
    }

    /**
     * Whether a user may read a site's statistics.
     *
     * @param array<string,mixed> $user
     */
    public static function canAccess(array $user, int $siteId): bool
    {
        if (($user['role'] ?? '') === User::ROLE_SUPER_ADMIN) {
            return true;
        }
        $db = Database::instance();
        return (bool) $db->value(
            'SELECT 1 FROM ' . $db->table('site_users') . ' WHERE `site_id` = :sid AND `user_id` = :uid LIMIT 1',
            [':sid' => $siteId, ':uid' => (int) $user['id']],
            false
        );
    }

    /**
     * Create a site and return its id.
     *
     * @param array{name:string,domain?:string,timezone?:string,created_by?:int,tracker_settings?:array<string,mixed>} $data
     */
    public static function create(array $data): int
    {
        $db = Database::instance();
        $timezone = self::validTimezone((string) ($data['timezone'] ?? 'UTC'));

        return $db->insert('sites', [
            'public_id'        => uuid4(),
            'name'             => mb_substr(trim($data['name']), 0, 120, 'UTF-8'),
            'domain'           => self::normalizeDomain((string) ($data['domain'] ?? '')),
            'timezone'         => $timezone,
            'is_active'        => 1,
            'tracker_settings' => json_encode(array_replace(self::TRACKER_DEFAULTS, $data['tracker_settings'] ?? []), JSON_UNESCAPED_UNICODE),
            'created_by'       => isset($data['created_by']) ? (int) $data['created_by'] : Auth::id(),
            'created_at'       => now_utc(),
        ]);
    }

    /**
     * Update the editable fields of a site.
     *
     * @param array<string,mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        $update = [];
        if (array_key_exists('name', $data)) {
            $update['name'] = mb_substr(trim((string) $data['name']), 0, 120, 'UTF-8');
        }
        if (array_key_exists('domain', $data)) {
            $update['domain'] = self::normalizeDomain((string) $data['domain']);
        }
        if (array_key_exists('timezone', $data)) {
            $update['timezone'] = self::validTimezone((string) $data['timezone']);
        }
        if (array_key_exists('is_active', $data)) {
            $update['is_active'] = (int) ((bool) $data['is_active']);
        }
        if (array_key_exists('tracker_settings', $data) && is_array($data['tracker_settings'])) {
            $update['tracker_settings'] = json_encode(
                array_replace(self::TRACKER_DEFAULTS, $data['tracker_settings']),
                JSON_UNESCAPED_UNICODE
            );
        }
        if ($update === []) {
            return;
        }
        $update['updated_at'] = now_utc();
        Database::instance()->update('sites', $update, '`id` = :id', [':id' => $id]);
    }

    /**
     * Delete a site together with all of its data.
     */
    public static function delete(int $id): void
    {
        $db = Database::instance();
        $db->beginTransaction();
        try {
            foreach (['pageviews', 'visits', 'stats_daily', 'stats_dim'] as $table) {
                $db->delete($table, '`site_id` = :id', [':id' => $id]);
            }
            // share_links and site_users cascade, but be explicit for hosts
            // where foreign keys are silently ignored (MyISAM leftovers).
            $db->delete('share_links', '`site_id` = :id', [':id' => $id]);
            $db->delete('site_users', '`site_id` = :id', [':id' => $id]);
            $db->delete('sites', '`id` = :id', [':id' => $id]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * User ids explicitly assigned to a site.
     *
     * @return list<int>
     */
    public static function assignedUserIds(int $siteId): array
    {
        $db = Database::instance();
        $ids = $db->column('SELECT `user_id` FROM ' . $db->table('site_users') . ' WHERE `site_id` = :id', [':id' => $siteId]);
        return array_map('intval', $ids);
    }

    /**
     * Replace the set of clients that may see a site. Super admins are not
     * stored here: they see every site by definition.
     *
     * @param list<int> $userIds
     */
    public static function assignUsers(int $siteId, array $userIds): void
    {
        $db = Database::instance();
        $db->beginTransaction();
        try {
            $db->delete('site_users', '`site_id` = :id', [':id' => $siteId]);
            foreach (array_unique(array_map('intval', $userIds)) as $userId) {
                if ($userId <= 0) {
                    continue;
                }
                $db->insert('site_users', [
                    'site_id'    => $siteId,
                    'user_id'    => $userId,
                    'created_at' => now_utc(),
                ]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Tracker settings of a site, with defaults filled in.
     *
     * @param array<string,mixed> $site
     * @return array<string,mixed>
     */
    public static function trackerSettings(array $site): array
    {
        $stored = json_decode_safe($site['tracker_settings'] ?? null, []);
        return array_replace(self::TRACKER_DEFAULTS, $stored);
    }

    /**
     * The HTML snippet a customer pastes into their site.
     *
     * The script tag is deliberately self-describing: tracker.js reads its own
     * data-website-id, so no inline configuration is needed.
     *
     * @param array<string,mixed> $site
     */
    public static function trackerSnippet(array $site): string
    {
        return '<script async defer src="' . asset('tracker.js') . '" data-website-id="' . e((string) $site['public_id']) . '"></script>';
    }

    /**
     * Turn user input into a bare hostname.
     */
    public static function normalizeDomain(string $domain): string
    {
        $domain = trim(mb_strtolower($domain, 'UTF-8'));
        if ($domain === '') {
            return '';
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*://~', $domain) === 1) {
            $host = parse_url($domain, PHP_URL_HOST);
            $domain = is_string($host) ? $host : '';
        }
        $domain = preg_replace('~[/?#].*$~', '', $domain) ?? $domain;
        $domain = preg_replace('/[^a-z0-9.\-:*_]/', '', $domain) ?? $domain;
        return mb_substr($domain, 0, 190, 'UTF-8');
    }

    /**
     * Whether a timezone identifier is usable by PHP.
     */
    public static function validTimezone(string $timezone): string
    {
        $timezone = trim($timezone);
        if ($timezone === '') {
            return 'UTC';
        }
        try {
            new \DateTimeZone($timezone);
            return $timezone;
        } catch (\Exception $e) {
            return 'UTC';
        }
    }

    /**
     * Whether a submission from $host is acceptable for this site.
     *
     * An empty domain and an empty allowed_hosts list means "accept any host",
     * which is what a fresh installation does before the customer fills in the
     * domain field.
     *
     * @param array<string,mixed> $site
     */
    public static function hostAllowed(array $site, string $host): bool
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        $settings = self::trackerSettings($site);
        /** @var list<string> $allowed */
        $allowed = array_filter(array_map('strval', (array) ($settings['allowed_hosts'] ?? [])));

        if ($host === '') {
            return $allowed === [] && trim((string) $site['domain']) === '';
        }

        foreach ($allowed as $candidate) {
            if (self::hostMatches($host, $candidate)) {
                return true;
            }
        }

        $domain = trim((string) $site['domain']);
        if ($domain !== '' && self::hostMatches($host, $domain)) {
            return true;
        }

        // No restriction configured: accept anything, the UUID is the secret.
        return $allowed === [] && $domain === '';
    }

    /**
     * Wildcard-aware host comparison: "*.example.com" also matches "example.com".
     */
    private static function hostMatches(string $host, string $pattern): bool
    {
        $pattern = strtolower(trim($pattern));
        if ($pattern === '') {
            return false;
        }
        $pattern = preg_replace('/:\d+$/', '', $pattern) ?? $pattern;

        if ($pattern === $host) {
            return true;
        }
        if (strpos($pattern, '*.') === 0) {
            $base = substr($pattern, 2);
            return $host === $base || substr($host, -strlen('.' . $base)) === '.' . $base;
        }
        return false;
    }

    /**
     * All timezones grouped for a <select>, with UTC first.
     *
     * @return array<string,string>
     */
    public static function timezoneOptions(): array
    {
        /** @var list<string> $identifiers */
        $identifiers = \DateTimeZone::listIdentifiers();
        $options = ['UTC' => 'UTC'];
        foreach ($identifiers as $identifier) {
            if ($identifier === 'UTC') {
                continue;
            }
            $options[$identifier] = str_replace('_', ' ', $identifier);
        }
        return $options;
    }
}
