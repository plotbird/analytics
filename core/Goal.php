<?php
/**
 * PlotBird Analytics - goals (conversions).
 *
 * A goal answers one question: how many visitors did what the website exists
 * for? Two kinds are supported, both deliberately simple:
 *
 *   * "page"  - reaching a path, e.g. "/thanks" or "/checkout/*". Counted from
 *               ordinary page views, so no change to the website is needed.
 *   * "event" - a named event sent by the tracker: plotbird.track('signup').
 *
 * Conversions are counted per visit (a visitor who reaches the goal page twice
 * in one session converts once) and per daily pseudonym, then rolled up into
 * `goal_stats` by the aggregator.
 *
 * What a goal points AT never changes: only the label and the active flag are
 * editable. Repointing a goal would silently reinterpret everything already
 * recorded, so the interface asks you to delete it and create another one.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class Goal
{
    public const TYPE_PAGE  = 'page';
    public const TYPE_EVENT = 'event';

    public const TYPES = [self::TYPE_PAGE, self::TYPE_EVENT];

    /** Sanity limit, so a panel cannot be flooded by mistake. */
    public const MAX_PER_SITE = 50;

    /**
     * Every goal of a site, oldest first (stable order in the interface).
     *
     * @return list<array<string,mixed>>
     */
    public static function forSite(int $siteId): array
    {
        $db = Database::instance();
        return $db->all(
            'SELECT * FROM ' . $db->table('goals') . ' WHERE `site_id` = :sid ORDER BY `id` ASC',
            [':sid' => $siteId]
        );
    }

    /**
     * Active goals of a site, for the aggregator and the dashboard.
     *
     * @return list<array<string,mixed>>
     */
    public static function active(int $siteId): array
    {
        $db = Database::instance();
        return $db->all(
            'SELECT * FROM ' . $db->table('goals') . ' WHERE `site_id` = :sid AND `is_active` = 1 ORDER BY `id` ASC',
            [':sid' => $siteId]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(int $id): ?array
    {
        $db = Database::instance();
        return $db->one('SELECT * FROM ' . $db->table('goals') . ' WHERE `id` = :id', [':id' => $id]);
    }

    public static function countForSite(int $siteId): int
    {
        $db = Database::instance();
        return (int) $db->value(
            'SELECT COUNT(*) FROM ' . $db->table('goals') . ' WHERE `site_id` = :sid',
            [':sid' => $siteId],
            0
        );
    }

    /**
     * Create a goal.
     *
     * @param array<string,mixed> $data name, type, match_value, is_active
     * @return array{ok:bool,error:string,id:int}
     */
    public static function create(int $siteId, array $data): array
    {
        $clean = self::normalize($data);
        $error = self::validate($siteId, $clean);
        if ($error !== '') {
            return ['ok' => false, 'error' => $error, 'id' => 0];
        }

        $db = Database::instance();
        $id = $db->insert('goals', [
            'site_id'     => $siteId,
            'name'        => $clean['name'],
            'type'        => $clean['type'],
            'match_value' => $clean['match_value'],
            'is_active'   => $clean['is_active'],
            'created_at'  => now_utc(),
        ]);

        return ['ok' => true, 'error' => '', 'id' => $id];
    }

    /**
     * Rename a goal or toggle it. The target itself is immutable on purpose.
     *
     * @param array<string,mixed> $data name, is_active
     * @return array{ok:bool,error:string}
     */
    public static function update(int $id, array $data): array
    {
        $goal = self::find($id);
        if ($goal === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        $name = mb_substr(trim((string) ($data['name'] ?? $goal['name'])), 0, 120, 'UTF-8');
        if ($name === '') {
            return ['ok' => false, 'error' => 'name_required'];
        }

        $isActive = array_key_exists('is_active', $data)
            ? (bool) $data['is_active']
            : (int) $goal['is_active'] === 1;

        Database::instance()->update('goals', [
            'name'       => $name,
            'is_active'  => (int) $isActive,
            'updated_at' => now_utc(),
        ], '`id` = :id', [':id' => $id]);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Delete a goal and its statistics.
     */
    public static function delete(int $id): void
    {
        $db = Database::instance();
        $db->delete('goal_stats', '`goal_id` = :id', [':id' => $id]);
        $db->delete('goals', '`id` = :id', [':id' => $id]);
    }

    /**
     * Whether a tracked path satisfies a goal pattern.
     *
     * Supports an exact path and a trailing wildcard ("/checkout/*") - the same
     * syntax the per-site exclusion rules use.
     */
    public static function pathMatches(string $path, string $pattern): bool
    {
        $path = (string) explode('?', $path)[0];
        $pattern = (string) explode('?', $pattern)[0];

        if ($pattern === '') {
            return false;
        }

        if (substr($pattern, -1) === '*') {
            $prefix = rtrim(substr($pattern, 0, -1), '/');
            if ($prefix === '') {
                return true;
            }
            return $path === $prefix || strpos($path, $prefix . '/') === 0;
        }

        // "/thanks/" and "/thanks" describe the same page.
        return rtrim($path, '/') === rtrim($pattern, '/');
    }

    /**
     * The SQL fragment that matches a page goal, plus its parameters.
     *
     * SUBSTRING_INDEX() drops a query string, so a goal still matches when a
     * website keeps query parameters in its tracked paths.
     *
     * @return array{0:string,1:array<string,string>}
     */
    public static function pathCondition(string $pattern): array
    {
        $normalized = 'SUBSTRING_INDEX(`path`, \'?\', 1)';

        if (substr($pattern, -1) === '*') {
            $prefix = rtrim(substr($pattern, 0, -1), '/');
            return [
                '(' . $normalized . ' = :goal_exact OR ' . $normalized . ' LIKE :goal_like)',
                [':goal_exact' => $prefix, ':goal_like' => $prefix . '/%'],
            ];
        }

        $path = rtrim($pattern, '/');
        return [
            '(' . $normalized . ' = :goal_exact OR ' . $normalized . ' = :goal_slash)',
            [':goal_exact' => $path, ':goal_slash' => $path . '/'],
        ];
    }

    /**
     * Trim and canonicalise the submitted fields.
     *
     * @param array<string,mixed> $data
     * @return array{name:string,type:string,match_value:string,is_active:int}
     */
    private static function normalize(array $data): array
    {
        $type = (string) ($data['type'] ?? self::TYPE_PAGE);
        if (!in_array($type, self::TYPES, true)) {
            $type = self::TYPE_PAGE;
        }

        $value = (string) ($data['match_value'] ?? '');

        return [
            'name'        => mb_substr(trim((string) ($data['name'] ?? '')), 0, 120, 'UTF-8'),
            'type'        => $type,
            'match_value' => $type === self::TYPE_PAGE
                ? self::normalizePath($value)
                : self::normalizeEvent($value),
            'is_active'   => (int) (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * Validate a normalised goal. Returns an error key or an empty string.
     *
     * @param array{name:string,type:string,match_value:string,is_active:int} $clean
     */
    private static function validate(int $siteId, array $clean): string
    {
        if ($clean['name'] === '') {
            return 'name_required';
        }
        if ($clean['match_value'] === '') {
            return $clean['type'] === self::TYPE_PAGE ? 'path_required' : 'event_required';
        }
        if ($clean['type'] === self::TYPE_EVENT
            && preg_match('/^[A-Za-z0-9_.:-]{1,120}$/', $clean['match_value']) !== 1) {
            return 'event_invalid';
        }

        $db = Database::instance();
        $duplicate = $db->value(
            'SELECT `id` FROM ' . $db->table('goals') . '
             WHERE `site_id` = :sid AND `type` = :type AND `match_value` = :value LIMIT 1',
            [':sid' => $siteId, ':type' => $clean['type'], ':value' => $clean['match_value']],
            null
        );
        if ($duplicate !== null) {
            return 'duplicate';
        }

        if (self::countForSite($siteId) >= self::MAX_PER_SITE) {
            return 'too_many';
        }

        return '';
    }

    /**
     * Canonical form of a goal path: leading slash, no query string, trimmed.
     */
    public static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $path = (string) explode('?', $path)[0];
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        return mb_substr($path, 0, 191, 'UTF-8');
    }

    /**
     * Canonical form of an event name.
     */
    public static function normalizeEvent(string $name): string
    {
        return mb_substr(trim($name), 0, 120, 'UTF-8');
    }
}
