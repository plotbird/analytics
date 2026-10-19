<?php
/**
 * PlotBird Analytics - daily aggregation.
 *
 * Raw pageviews are the source of truth, but a dashboard must not scan a year
 * of them on every page load. This class rolls raw rows up into `stats_daily`
 * and `stats_dim`, one row set per site per local day.
 *
 * Three deliberate properties:
 *   * idempotent - a day is always deleted and recomputed, so late hits and
 *     backfilled data are picked up automatically;
 *   * purge-safe - a day whose raw rows were removed by retention is skipped,
 *     which keeps long term aggregates intact forever;
 *   * timezone aware - "a day" means a day in the site's own timezone, computed
 *     in PHP so that no MySQL timezone tables are required (shared hostings
 *     almost never have them loaded).
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class Aggregator
{
    /**
     * Dimensions rolled up per day.
     *
     * "entry" and "exit" come from visits (they are properties of a session),
     * everything else from pageviews.
     *
     * @var list<string>
     */
    public const DIMENSIONS = ['path', 'title', 'referrer', 'country', 'device', 'os', 'browser', 'language', 'screen', 'entry', 'exit'];

    /** Upper bound on how many days a single refresh may walk. */
    private const MAX_DAYS_PER_RUN = 400;

    /**
     * Make sure the aggregates for a site are usable and recent.
     *
     * Throttled with a lock file, so a dashboard with several widgets and a
     * burst of AJAX calls still aggregates at most once per interval.
     */
    public static function ensureFresh(int $siteId, int $throttleSeconds = 45): void
    {
        $site = Site::find($siteId);
        if ($site === null) {
            return;
        }

        $lock = self::lockPath($siteId);
        if (is_file($lock)) {
            $mtime = @filemtime($lock);
            if ($mtime !== false && $mtime > time() - $throttleSeconds) {
                return;
            }
        }
        // Touch the lock first: a slow run must not be started twice.
        @file_put_contents($lock, (string) time(), LOCK_EX);

        try {
            self::refresh($site);
        } catch (\Throwable $e) {
            pb_log('aggregation failed for site ' . $siteId . ': ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Recompute every day that may have changed.
     *
     * @param array<string,mixed> $site
     * @return int Number of days recomputed.
     */
    public static function refresh(array $site): int
    {
        $siteId   = (int) $site['id'];
        $timezone = (string) ($site['timezone'] ?? 'UTC');

        $earliest = self::earliestRawDate($siteId, $timezone);
        if ($earliest === null) {
            // Nothing tracked yet: clear any stale aggregate for today so the
            // dashboard does not show numbers that no longer exist.
            self::clearDay($siteId, self::todayIn($timezone));
            return 0;
        }

        $today = self::todayIn($timezone);

        // Re-aggregate a short trailing window (late hits, timezone edges) plus
        // everything since the last aggregated day.
        $from = self::daysAgo($timezone, 2);
        $last = self::lastAggregatedDate($siteId);
        if ($last !== null && $last < $from) {
            $from = $last;
        }
        if ($from < $earliest) {
            $from = $earliest;
        }

        $span = self::daysBetween($from, $today);
        if ($span > self::MAX_DAYS_PER_RUN) {
            $from = self::daysAgo($timezone, self::MAX_DAYS_PER_RUN);
            pb_log('aggregation window truncated to ' . self::MAX_DAYS_PER_RUN . ' days for site ' . $siteId, 'error');
        }

        $count = 0;
        $cursor = $from;
        while ($cursor <= $today) {
            if (self::aggregateDay($site, $cursor)) {
                $count++;
            }
            $cursor = self::addDays($cursor, 1);
        }

        return $count;
    }

    /**
     * Recompute a single local day.
     *
     * @param array<string,mixed> $site
     * @return bool Whether the day had raw data and was written.
     */
    public static function aggregateDay(array $site, string $localDate): bool
    {
        $siteId   = (int) $site['id'];
        $timezone = (string) ($site['timezone'] ?? 'UTC');

        [$startUtc, $endUtc] = self::dayBounds($localDate, $timezone);

        $db = Database::instance();

        // Purged (or never tracked) days keep whatever aggregate already exists.
        $rawCount = (int) $db->value(
            'SELECT COUNT(*) FROM ' . $db->table('pageviews') . '
             WHERE `site_id` = :sid AND `created_at` >= :start AND `created_at` < :end',
            [':sid' => $siteId, ':start' => $startUtc, ':end' => $endUtc],
            0
        );
        if ($rawCount === 0) {
            return false;
        }

        $db->beginTransaction();
        try {
            $db->delete('stats_daily', '`site_id` = :sid AND `date` = :date', [':sid' => $siteId, ':date' => $localDate]);

            $pageviews = (int) $db->value(
                'SELECT COUNT(*) FROM ' . $db->table('pageviews') . '
                 WHERE `site_id` = :sid AND `created_at` >= :start AND `created_at` < :end',
                [':sid' => $siteId, ':start' => $startUtc, ':end' => $endUtc],
                0
            );
            $visitors = (int) $db->value(
                'SELECT COUNT(DISTINCT `visitor_hash`) FROM ' . $db->table('pageviews') . '
                 WHERE `site_id` = :sid AND `created_at` >= :start AND `created_at` < :end',
                [':sid' => $siteId, ':start' => $startUtc, ':end' => $endUtc],
                0
            );

            $visitStats = $db->one(
                'SELECT COUNT(*) AS sessions,
                        COALESCE(SUM(`is_bounce`), 0) AS bounces,
                        COALESCE(SUM(`duration`), 0) AS duration_total
                 FROM ' . $db->table('visits') . '
                 WHERE `site_id` = :sid AND `started_at` >= :start AND `started_at` < :end',
                [':sid' => $siteId, ':start' => $startUtc, ':end' => $endUtc]
            );

            $db->insert('stats_daily', [
                'site_id'        => $siteId,
                'date'           => $localDate,
                'pageviews'      => $pageviews,
                'visitors'       => $visitors,
                'sessions'       => (int) ($visitStats['sessions'] ?? 0),
                'bounces'        => (int) ($visitStats['bounces'] ?? 0),
                'duration_total' => (int) ($visitStats['duration_total'] ?? 0),
                'updated_at'     => now_utc(),
            ]);

            $db->delete('stats_dim', '`site_id` = :sid AND `date` = :date', [':sid' => $siteId, ':date' => $localDate]);
            self::aggregateDimensions($siteId, $localDate, $startUtc, $endUtc);
            self::aggregateGoals($siteId, $localDate, $startUtc, $endUtc);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Roll up every dimension for one day.
     */
    private static function aggregateDimensions(int $siteId, string $localDate, string $startUtc, string $endUtc): void
    {
        $db = Database::instance();
        $pageviewsTable = $db->table('pageviews');
        $visitsTable    = $db->table('visits');
        $dimTable       = $db->table('stats_dim');

        // Dimension => column. Values are truncated to the column width so the
        // primary key can never be violated by an unusually long URL.
        $columns = [
            'path'     => '`path`',
            'title'    => '`title`',
            'referrer' => '`referrer_domain`',
            'country'  => '`country`',
            'device'   => '`device`',
            'os'       => '`os`',
            'browser'  => '`browser`',
            'language' => '`language`',
            'screen'   => '`screen`',
        ];

        foreach ($columns as $dimension => $column) {
            $sql = 'INSERT INTO ' . $dimTable . ' (`site_id`, `date`, `dimension`, `value`, `pageviews`, `visitors`, `sessions`)
                    SELECT :sid, :date, :dimension, LEFT(' . $column . ', 191) AS value,
                           COUNT(*) AS pageviews,
                           COUNT(DISTINCT `visitor_hash`) AS visitors,
                           COUNT(DISTINCT `session_key`) AS sessions
                    FROM ' . $pageviewsTable . '
                    WHERE `site_id` = :sid2 AND `created_at` >= :start AND `created_at` < :end
                    GROUP BY LEFT(' . $column . ', 191)';

            $db->run($sql, [
                ':sid'       => $siteId,
                ':date'      => $localDate,
                ':dimension' => $dimension,
                ':sid2'      => $siteId,
                ':start'     => $startUtc,
                ':end'       => $endUtc,
            ]);
        }

        // Entry and exit pages describe a session, so they come from visits.
        foreach (['entry' => '`entry_path`', 'exit' => '`exit_path`'] as $dimension => $column) {
            $sql = 'INSERT INTO ' . $dimTable . ' (`site_id`, `date`, `dimension`, `value`, `pageviews`, `visitors`, `sessions`)
                    SELECT :sid, :date, :dimension, LEFT(' . $column . ', 191) AS value,
                           COALESCE(SUM(`pageviews`), 0) AS pageviews,
                           COUNT(DISTINCT `visitor_hash`) AS visitors,
                           COUNT(*) AS sessions
                    FROM ' . $visitsTable . '
                    WHERE `site_id` = :sid2 AND `started_at` >= :start AND `started_at` < :end
                    GROUP BY LEFT(' . $column . ', 191)';

            $db->run($sql, [
                ':sid'       => $siteId,
                ':date'      => $localDate,
                ':dimension' => $dimension,
                ':sid2'      => $siteId,
                ':start'     => $startUtc,
                ':end'       => $endUtc,
            ]);
        }
    }

    /**
     * Roll up goal conversions for one day.
     *
     * Page goals are counted from page views, event goals from the `events`
     * table. A conversion is a visit that reached the goal; an event that
     * arrived without an open visit counts as its own conversion, otherwise
     * "conversions" could be smaller than "visitors", which reads like a bug.
     */
    private static function aggregateGoals(int $siteId, string $localDate, string $startUtc, string $endUtc): void
    {
        $db = Database::instance();
        $goals = Goal::active($siteId);
        if ($goals === []) {
            return;
        }

        $db->delete('goal_stats', '`site_id` = :sid AND `date` = :date', [':sid' => $siteId, ':date' => $localDate]);

        $aggregate = 'COUNT(DISTINCT `visit_id`)'
            . ' + COALESCE(SUM(CASE WHEN `visit_id` IS NULL THEN 1 ELSE 0 END), 0) AS conversions,'
            . ' COUNT(DISTINCT `visitor_hash`) AS visitors';

        foreach ($goals as $goal) {
            $goalId = (int) $goal['id'];

            if ((string) $goal['type'] === Goal::TYPE_EVENT) {
                $row = $db->one(
                    'SELECT ' . $aggregate . ' FROM ' . $db->table('events') . '
                     WHERE `site_id` = :sid AND `created_at` >= :start AND `created_at` < :end
                       AND `name` = :name',
                    [
                        ':sid'   => $siteId,
                        ':start' => $startUtc,
                        ':end'   => $endUtc,
                        ':name'  => (string) $goal['match_value'],
                    ]
                );
            } else {
                [$condition, $conditionParams] = Goal::pathCondition((string) $goal['match_value']);
                $row = $db->one(
                    'SELECT ' . $aggregate . ' FROM ' . $db->table('pageviews') . '
                     WHERE `site_id` = :sid AND `created_at` >= :start AND `created_at` < :end
                       AND ' . $condition,
                    array_merge([
                        ':sid'   => $siteId,
                        ':start' => $startUtc,
                        ':end'   => $endUtc,
                    ], $conditionParams)
                );
            }

            $conversions = (int) ($row['conversions'] ?? 0);
            $visitors    = (int) ($row['visitors'] ?? 0);
            if ($conversions === 0 && $visitors === 0) {
                continue;
            }

            $db->insert('goal_stats', [
                'site_id'     => $siteId,
                'date'        => $localDate,
                'goal_id'     => $goalId,
                'conversions' => $conversions,
                'visitors'    => $visitors,
                'updated_at'  => now_utc(),
            ]);
        }
    }

    /**
     * Aggregates for a single day, grouped by a dimension.
     *
     * @return list<array<string,mixed>>
     */
    public static function dimensionForDay(int $siteId, string $date, string $dimension, int $limit = 10): array
    {
        $db = Database::instance();
        if (!in_array($dimension, self::DIMENSIONS, true) && $dimension !== 'city') {
            return [];
        }
        $limit = max(1, min(100, $limit));

        return $db->all(
            'SELECT `value`, `pageviews`, `visitors`, `sessions` FROM ' . $db->table('stats_dim') . '
             WHERE `site_id` = :sid AND `date` = :date AND `dimension` = :dim
             ORDER BY `pageviews` DESC LIMIT ' . $limit,
            [':sid' => $siteId, ':date' => $date, ':dim' => $dimension]
        );
    }

    /**
     * Remove everything aggregated for a day (used when a site has no raw data
     * at all any more, e.g. a freshly truncated installation).
     */
    public static function clearDay(int $siteId, string $date): void
    {
        $db = Database::instance();
        $db->delete('stats_daily', '`site_id` = :sid AND `date` = :date', [':sid' => $siteId, ':date' => $date]);
        $db->delete('stats_dim', '`site_id` = :sid AND `date` = :date', [':sid' => $siteId, ':date' => $date]);
    }

    /**
     * Delete raw data older than the retention window.
     *
     * Aggregated numbers are never touched: that is the whole point of keeping
     * them, and it is what lets a dashboard show a year of history on a hosting
     * plan with a 200 MB database limit.
     *
     * @return array{pageviews:int,visits:int}
     */
    public static function purgeRaw(?int $siteId = null, ?int $retentionDays = null): array
    {
        $retentionDays = $retentionDays ?? Settings::retentionDays();
        $cutoffDate    = gmdate('Y-m-d', time() - $retentionDays * 86400);
        $cutoffTime    = $cutoffDate . ' 00:00:00';

        $db = Database::instance();
        $where = '`created_at` < :cutoff';
        $params = [':cutoff' => $cutoffTime];
        if ($siteId !== null) {
            $where .= ' AND `site_id` = :sid';
            $params[':sid'] = $siteId;
        }

        $pageviews = $db->delete('pageviews', $where, $params);

        // Custom events age out with the page views they belong to; the daily
        // goal rollup keeps the numbers.
        $db->delete('events', $where, $params);

        $visitWhere = '`started_at` < :cutoff';
        $visitParams = [':cutoff' => $cutoffTime];
        if ($siteId !== null) {
            $visitWhere .= ' AND `site_id` = :sid';
            $visitParams[':sid'] = $siteId;
        }
        $visits = $db->delete('visits', $visitWhere, $visitParams);

        // Sessions that lost all of their pageviews are meaningless.
        $db->run(
            'DELETE v FROM ' . $db->table('visits') . ' v
             LEFT JOIN ' . $db->table('pageviews') . ' p ON p.`visit_id` = v.`id`
             WHERE p.`id` IS NULL AND v.`started_at` < :cutoff' . ($siteId !== null ? ' AND v.`site_id` = :sid' : ''),
            $visitParams
        );

        return ['pageviews' => $pageviews, 'visits' => $visits];
    }

    /**
     * The earliest local date that still has raw pageviews.
     */
    public static function earliestRawDate(int $siteId, string $timezone): ?string
    {
        $db = Database::instance();
        $earliest = $db->value(
            'SELECT MIN(`created_at`) FROM ' . $db->table('pageviews') . ' WHERE `site_id` = :sid',
            [':sid' => $siteId],
            null
        );
        if (!is_string($earliest) || $earliest === '') {
            return null;
        }
        return self::toLocalDate($earliest, $timezone);
    }

    /**
     * The newest local date present in the aggregate table.
     */
    public static function lastAggregatedDate(int $siteId): ?string
    {
        $db = Database::instance();
        $value = $db->value(
            'SELECT MAX(`date`) FROM ' . $db->table('stats_daily') . ' WHERE `site_id` = :sid',
            [':sid' => $siteId],
            null
        );
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Today's date in a timezone.
     */
    public static function todayIn(string $timezone): string
    {
        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            return $now->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d');
        } catch (\Exception $e) {
            return gmdate('Y-m-d');
        }
    }

    /**
     * The local date N days ago.
     */
    public static function daysAgo(string $timezone, int $days): string
    {
        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            return $now->setTimezone(new \DateTimeZone($timezone))->modify('-' . max(0, $days) . ' days')->format('Y-m-d');
        } catch (\Exception $e) {
            return gmdate('Y-m-d', time() - $days * 86400);
        }
    }

    /**
     * Add days to a Y-m-d string.
     */
    public static function addDays(string $date, int $days): string
    {
        $timestamp = strtotime($date . ' 12:00:00 UTC');
        if ($timestamp === false) {
            return $date;
        }
        return gmdate('Y-m-d', $timestamp + $days * 86400);
    }

    /**
     * Whole days between two Y-m-d strings (b - a).
     */
    public static function daysBetween(string $from, string $to): int
    {
        $a = strtotime($from . ' 12:00:00 UTC');
        $b = strtotime($to . ' 12:00:00 UTC');
        if ($a === false || $b === false) {
            return 0;
        }
        return (int) round(($b - $a) / 86400);
    }

    /**
     * UTC bounds of a local day, as MySQL DATETIME strings.
     *
     * @return array{0:string,1:string}
     */
    public static function dayBounds(string $localDate, string $timezone): array
    {
        try {
            $tz    = new \DateTimeZone($timezone);
            $start = new \DateTimeImmutable($localDate . ' 00:00:00', $tz);
            $end   = $start->modify('+1 day');
        } catch (\Exception $e) {
            $start = new \DateTimeImmutable($localDate . ' 00:00:00', new \DateTimeZone('UTC'));
            $end   = $start->modify('+1 day');
        }

        $utc = new \DateTimeZone('UTC');
        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * The UTC offset of a timezone on a given local date, in seconds.
     */
    public static function offsetSeconds(string $timezone, string $localDate): int
    {
        try {
            $tz = new \DateTimeZone($timezone);
            $date = new \DateTimeImmutable($localDate . ' 12:00:00', $tz);
            return $date->getOffset();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Convert a UTC DATETIME to a local Y-m-d date.
     */
    public static function toLocalDate(string $utcDateTime, string $timezone): string
    {
        $timestamp = strtotime($utcDateTime . ' UTC');
        if ($timestamp === false) {
            return substr($utcDateTime, 0, 10);
        }
        try {
            $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone($timezone));
            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            return gmdate('Y-m-d', $timestamp);
        }
    }

    /**
     * Convert a UTC DATETIME to a local "Y-m-d H:i" string.
     */
    public static function toLocalDateTime(string $utcDateTime, string $timezone): string
    {
        $timestamp = strtotime($utcDateTime . ' UTC');
        if ($timestamp === false) {
            return $utcDateTime;
        }
        try {
            $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone($timezone));
            return $date->format('Y-m-d H:i');
        } catch (\Exception $e) {
            return gmdate('Y-m-d H:i', $timestamp);
        }
    }

    /**
     * Lock file that throttles aggregation for one site.
     */
    private static function lockPath(int $siteId): string
    {
        $dir = PB_ROOT . '/data/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/aggregate-' . $siteId . '.lock';
    }
}
