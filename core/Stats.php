<?php
/**
 * PlotBird Analytics - reporting engine.
 *
 * Every dashboard number comes from here. Range reports read the daily rollups
 * (see Aggregator); only the real-time widget touches raw pageviews, because
 * "the last five minutes" cannot be pre-aggregated.
 *
 * Documented semantics (the same conventions mainstream privacy-first
 * analytics use, so the numbers are comparable):
 *   * visitor  - a unique daily pseudonym: md5(IP + UA + salt + date);
 *   * session  - a visit: consecutive pageviews closer together than the
 *                session timeout (30 minutes by default);
 *   * bounce   - a visit with exactly one pageview;
 *   * duration - last pageview minus first pageview of the visit;
 *   * a range's "visitors" is the sum of the daily uniques, not a distinct
 *     count across days. Cross-day tracking is impossible by design, so the
 *     sum is the honest number to show.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class Stats
{
    /** Named ranges offered by the dashboard picker. */
    public const PRESETS = ['today', 'yesterday', 'last7', 'last30', 'last90', 'month', 'lastmonth', 'year', 'custom'];

    /**
     * Resolve a preset (or a custom range) into local dates plus bounds.
     *
     * @param array<string,mixed> $site
     * @return array{from:string,to:string,preset:string,label:string}
     */
    public static function resolvePeriod(array $site, string $preset, string $from = '', string $to = ''): array
    {
        $timezone = (string) ($site['timezone'] ?? 'UTC');
        $today    = Aggregator::todayIn($timezone);

        if (!in_array($preset, self::PRESETS, true)) {
            $preset = 'last7';
        }

        switch ($preset) {
            case 'today':
                $range = [$today, $today];
                break;
            case 'yesterday':
                $yesterday = Aggregator::addDays($today, -1);
                $range = [$yesterday, $yesterday];
                break;
            case 'last30':
                $range = [Aggregator::addDays($today, -29), $today];
                break;
            case 'last90':
                $range = [Aggregator::addDays($today, -89), $today];
                break;
            case 'month':
                $range = [substr($today, 0, 8) . '01', $today];
                break;
            case 'lastmonth':
                $firstThisMonth = substr($today, 0, 8) . '01';
                $lastMonthEnd   = Aggregator::addDays($firstThisMonth, -1);
                $range = [substr($lastMonthEnd, 0, 8) . '01', $lastMonthEnd];
                break;
            case 'year':
                $range = [substr($today, 0, 4) . '-01-01', $today];
                break;
            case 'custom':
                $cleanFrom = self::validDate($from) ? $from : Aggregator::addDays($today, -29);
                $cleanTo   = self::validDate($to) ? $to : $today;
                if ($cleanFrom > $cleanTo) {
                    [$cleanFrom, $cleanTo] = [$cleanTo, $cleanFrom];
                }
                $range = [$cleanFrom, $cleanTo];
                break;
            case 'last7':
            default:
                $preset = 'last7';
                $range = [Aggregator::addDays($today, -6), $today];
                break;
        }

        return [
            'from'   => $range[0],
            'to'     => $range[1],
            'preset' => $preset,
            'label'  => self::periodLabel($preset, $range[0], $range[1]),
        ];
    }

    /**
     * The equally long period immediately before the given range.
     *
     * @return array{from:string,to:string}
     */
    public static function previousPeriod(string $from, string $to): array
    {
        $length = Aggregator::daysBetween($from, $to) + 1;
        return [
            'from' => Aggregator::addDays($from, -$length),
            'to'   => Aggregator::addDays($from, -1),
        ];
    }

    /**
     * Totals for a range.
     *
     * @return array{pageviews:int,visitors:int,sessions:int,bounces:int,duration_total:int,bounce_rate:float,avg_duration:float,pages_per_session:float}
     */
    public static function summary(int $siteId, string $from, string $to): array
    {
        $db = Database::instance();
        $row = $db->one(
            'SELECT COALESCE(SUM(`pageviews`), 0) AS pageviews,
                    COALESCE(SUM(`visitors`), 0) AS visitors,
                    COALESCE(SUM(`sessions`), 0) AS sessions,
                    COALESCE(SUM(`bounces`), 0) AS bounces,
                    COALESCE(SUM(`duration_total`), 0) AS duration_total
             FROM ' . $db->table('stats_daily') . '
             WHERE `site_id` = :sid AND `date` >= :from AND `date` <= :to',
            [':sid' => $siteId, ':from' => $from, ':to' => $to]
        ) ?? [];

        $sessions = (int) ($row['sessions'] ?? 0);
        $bounces  = (int) ($row['bounces'] ?? 0);
        $duration = (int) ($row['duration_total'] ?? 0);
        $pageviews = (int) ($row['pageviews'] ?? 0);

        return [
            'pageviews'         => $pageviews,
            'visitors'          => (int) ($row['visitors'] ?? 0),
            'sessions'          => $sessions,
            'bounces'           => $bounces,
            'duration_total'    => $duration,
            'bounce_rate'       => $sessions > 0 ? $bounces / $sessions : 0.0,
            'avg_duration'      => $sessions > 0 ? $duration / $sessions : 0.0,
            'pages_per_session' => $sessions > 0 ? $pageviews / $sessions : 0.0,
        ];
    }

    /**
     * Daily series with gaps filled, ready for the main chart.
     *
     * @return array{labels:list<string>,pageviews:list<int>,visitors:list<int>,sessions:list<int>,granularity:string}
     */
    public static function series(array $site, string $from, string $to): array
    {
        $siteId   = (int) $site['id'];
        $timezone = (string) ($site['timezone'] ?? 'UTC');

        if ($from === $to) {
            return self::hourlySeries($siteId, $from, $timezone);
        }

        $db = Database::instance();
        $rows = $db->all(
            'SELECT `date`, `pageviews`, `visitors`, `sessions` FROM ' . $db->table('stats_daily') . '
             WHERE `site_id` = :sid AND `date` >= :from AND `date` <= :to
             ORDER BY `date` ASC',
            [':sid' => $siteId, ':from' => $from, ':to' => $to]
        );

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[(string) $row['date']] = $row;
        }

        $labels = $pageviews = $visitors = $sessions = [];
        $cursor = $from;
        $guard  = 0;
        while ($cursor <= $to && $guard < 1200) {
            $labels[]    = $cursor;
            $pageviews[] = (int) ($byDate[$cursor]['pageviews'] ?? 0);
            $visitors[]  = (int) ($byDate[$cursor]['visitors'] ?? 0);
            $sessions[]  = (int) ($byDate[$cursor]['sessions'] ?? 0);
            $cursor      = Aggregator::addDays($cursor, 1);
            $guard++;
        }

        return [
            'labels'      => $labels,
            'pageviews'   => $pageviews,
            'visitors'    => $visitors,
            'sessions'    => $sessions,
            'granularity' => 'day',
        ];
    }

    /**
     * Hourly series for one local day, straight from raw pageviews.
     *
     * @return array{labels:list<string>,pageviews:list<int>,visitors:list<int>,sessions:list<int>,granularity:string}
     */
    public static function hourlySeries(int $siteId, string $localDate, string $timezone): array
    {
        $offset = Aggregator::offsetSeconds($timezone, $localDate);
        [$startUtc, $endUtc] = Aggregator::dayBounds($localDate, $timezone);

        $db = Database::instance();
        $rows = $db->all(
            'SELECT HOUR(DATE_ADD(`created_at`, INTERVAL ' . (int) $offset . ' SECOND)) AS `hour`,
                    COUNT(*) AS pageviews,
                    COUNT(DISTINCT `visitor_hash`) AS visitors,
                    COUNT(DISTINCT `session_key`) AS sessions
             FROM ' . $db->table('pageviews') . '
             WHERE `site_id` = :sid AND `created_at` >= :start AND `created_at` < :end
             GROUP BY `hour`',
            [':sid' => $siteId, ':start' => $startUtc, ':end' => $endUtc]
        );

        $byHour = [];
        foreach ($rows as $row) {
            $byHour[(int) $row['hour']] = $row;
        }

        $labels = $pageviews = $visitors = $sessions = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $labels[]    = str_pad((string) $hour, 2, '0', STR_PAD_LEFT) . ':00';
            $pageviews[] = (int) ($byHour[$hour]['pageviews'] ?? 0);
            $visitors[]  = (int) ($byHour[$hour]['visitors'] ?? 0);
            $sessions[]  = (int) ($byHour[$hour]['sessions'] ?? 0);
        }

        return [
            'labels'      => $labels,
            'pageviews'   => $pageviews,
            'visitors'    => $visitors,
            'sessions'    => $sessions,
            'granularity' => 'hour',
        ];
    }

    /**
     * Top values of one dimension over a range.
     *
     * @return list<array{value:string,pageviews:int,visitors:int,sessions:int}>
     */
    public static function topDimension(int $siteId, string $from, string $to, string $dimension, int $limit = 10): array
    {
        $db = Database::instance();
        $limit = max(1, min(100, $limit));

        $rows = $db->all(
            'SELECT `value`,
                    COALESCE(SUM(`pageviews`), 0) AS pageviews,
                    COALESCE(SUM(`visitors`), 0) AS visitors,
                    COALESCE(SUM(`sessions`), 0) AS sessions
             FROM ' . $db->table('stats_dim') . '
             WHERE `site_id` = :sid AND `date` >= :from AND `date` <= :to AND `dimension` = :dim
             GROUP BY `value`
             ORDER BY pageviews DESC
             LIMIT ' . $limit,
            [':sid' => $siteId, ':from' => $from, ':to' => $to, ':dim' => $dimension]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'value'     => (string) $row['value'],
                'pageviews' => (int) $row['pageviews'],
                'visitors'  => (int) $row['visitors'],
                'sessions'  => (int) $row['sessions'],
            ];
        }
        return $result;
    }

    /**
     * Everything the devices widget needs, in one call.
     *
     * @return array{device:list<array<string,mixed>>,os:list<array<string,mixed>>,browser:list<array<string,mixed>>}
     */
    public static function breakdown(int $siteId, string $from, string $to, int $limit = 8): array
    {
        return [
            'device'  => self::topDimension($siteId, $from, $to, 'device', $limit),
            'os'      => self::topDimension($siteId, $from, $to, 'os', $limit),
            'browser' => self::topDimension($siteId, $from, $to, 'browser', $limit),
        ];
    }

    /**
     * Real-time activity.
     *
     * @return array{pageviews:int,visitors:int,pages:list<array{value:string,pageviews:int}>,window:int}
     */
    public static function realtime(int $siteId, int $minutes = 5): array
    {
        $minutes = max(1, min(60, $minutes));
        $since   = gmdate('Y-m-d H:i:s', time() - $minutes * 60);

        $db = Database::instance();
        $totals = $db->one(
            'SELECT COUNT(*) AS pageviews, COUNT(DISTINCT `visitor_hash`) AS visitors
             FROM ' . $db->table('pageviews') . '
             WHERE `site_id` = :sid AND `created_at` >= :since',
            [':sid' => $siteId, ':since' => $since]
        ) ?? ['pageviews' => 0, 'visitors' => 0];

        $rows = $db->all(
            'SELECT `path` AS value, COUNT(*) AS pageviews
             FROM ' . $db->table('pageviews') . '
             WHERE `site_id` = :sid AND `created_at` >= :since
             GROUP BY `path` ORDER BY pageviews DESC LIMIT 8',
            [':sid' => $siteId, ':since' => $since]
        );

        $pages = [];
        foreach ($rows as $row) {
            $pages[] = ['value' => (string) $row['value'], 'pageviews' => (int) $row['pageviews']];
        }

        return [
            'pageviews' => (int) $totals['pageviews'],
            'visitors'  => (int) $totals['visitors'],
            'pages'     => $pages,
            'window'    => $minutes,
        ];
    }

    /**
     * A per-day sparkline for the overview cards.
     *
     * @return list<int>
     */
    public static function sparkline(int $siteId, string $from, string $to, string $metric = 'pageviews'): array
    {
        $column = in_array($metric, ['pageviews', 'visitors', 'sessions'], true) ? $metric : 'pageviews';
        $db = Database::instance();

        $rows = $db->all(
            'SELECT `date`, `' . $column . '` AS value FROM ' . $db->table('stats_daily') . '
             WHERE `site_id` = :sid AND `date` >= :from AND `date` <= :to ORDER BY `date` ASC',
            [':sid' => $siteId, ':from' => $from, ':to' => $to]
        );

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[(string) $row['date']] = (int) $row['value'];
        }

        $series = [];
        $cursor = $from;
        $guard = 0;
        while ($cursor <= $to && $guard < 400) {
            $series[] = $byDate[$cursor] ?? 0;
            $cursor = Aggregator::addDays($cursor, 1);
            $guard++;
        }
        return $series;
    }

    /**
     * The date range that actually has data, plus the number of tracked days.
     *
     * @return array{first:?string,last:?string,days:int}
     */
    public static function availableRange(int $siteId): array
    {
        $db = Database::instance();
        $row = $db->one(
            'SELECT MIN(`date`) AS `first`, MAX(`date`) AS `last`, COUNT(*) AS `days`
             FROM ' . $db->table('stats_daily') . ' WHERE `site_id` = :sid',
            [':sid' => $siteId]
        ) ?? [];

        return [
            'first' => is_string($row['first'] ?? null) ? (string) $row['first'] : null,
            'last'  => is_string($row['last'] ?? null) ? (string) $row['last'] : null,
            'days'  => (int) ($row['days'] ?? 0),
        ];
    }

    /**
     * Whether a site has received at least one hit - used by the installer's
     * "is the tracker working?" check.
     */
    public static function hasData(int $siteId): bool
    {
        $db = Database::instance();
        return (bool) $db->value(
            'SELECT 1 FROM ' . $db->table('pageviews') . ' WHERE `site_id` = :sid LIMIT 1',
            [':sid' => $siteId],
            false
        );
    }

    /**
     * Timestamp of the most recent hit, for the connection test.
     */
    public static function lastHitAt(int $siteId): ?string
    {
        $db = Database::instance();
        $value = $db->value(
            'SELECT MAX(`created_at`) FROM ' . $db->table('pageviews') . ' WHERE `site_id` = :sid',
            [':sid' => $siteId],
            null
        );
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The visitor log: individual sessions with their time, duration and pages.
     *
     * This reads the raw `visits` table, not the rollups - a log of single
     * sessions cannot be aggregated. Sessions older than the retention window
     * are gone, which the caller reports as "no data for this period".
     *
     * @param array<string,mixed> $site
     * @return array{rows:list<array<string,mixed>>,total:int,limit:int,truncated:bool,show_ip:bool,timezone:string}
     */
    public static function visits(array $site, string $from, string $to, int $limit = 50): array
    {
        $siteId   = (int) $site['id'];
        $timezone = (string) ($site['timezone'] ?? 'UTC');
        $limit    = max(5, min(200, $limit));

        [$startUtc] = Aggregator::dayBounds($from, $timezone);
        [, $endUtc] = Aggregator::dayBounds($to, $timezone);

        $db = Database::instance();
        $params = [':sid' => $siteId, ':start' => $startUtc, ':end' => $endUtc];

        $total = (int) $db->value(
            'SELECT COUNT(*) FROM ' . $db->table('visits') . '
             WHERE `site_id` = :sid AND `started_at` >= :start AND `started_at` < :end',
            $params,
            0
        );

        $rows = $db->all(
            'SELECT `id`, `started_at`, `last_seen_at`, `duration`, `pageviews`, `is_bounce`,
                    `entry_path`, `exit_path`, `referrer_domain`, `country`, `device`, `os`,
                    `browser`, `language`, `visitor_hash`, `ip`
             FROM ' . $db->table('visits') . '
             WHERE `site_id` = :sid AND `started_at` >= :start AND `started_at` < :end
             ORDER BY `started_at` DESC, `id` DESC
             LIMIT ' . $limit,
            $params
        );

        $log = [];
        foreach ($rows as $row) {
            $log[] = [
                'id'         => (int) $row['id'],
                'time'       => Aggregator::toLocalDateTime((string) $row['started_at'], $timezone),
                'time_utc'   => (string) $row['started_at'],
                'duration'   => (int) $row['duration'],
                'pageviews'  => (int) $row['pageviews'],
                'bounce'     => (int) $row['is_bounce'] === 1,
                'entry'      => (string) $row['entry_path'],
                'exit'       => (string) $row['exit_path'],
                'referrer'   => (string) $row['referrer_domain'],
                'country'    => (string) $row['country'],
                'country_name' => (string) $row['country'] === '' ? '' : I18n::country((string) $row['country']),
                'flag_url'   => flag_url((string) $row['country']),
                'device'     => (string) $row['device'],
                'os'         => (string) $row['os'],
                'browser'    => (string) $row['browser'],
                'language'   => (string) $row['language'],
                // A short, non-reversible label so an operator can tell two
                // sessions of the same daily pseudonym apart.
                'visitor'    => substr((string) $row['visitor_hash'], 0, 8),
                'ip'         => (string) ($row['ip'] ?? ''),
            ];
        }

        return [
            'rows'      => $log,
            'total'     => $total,
            'limit'     => $limit,
            'truncated' => $total > count($log),
            'show_ip'   => Settings::getBool('store_ip', false),
            'timezone'  => $timezone,
        ];
    }

    /**
     * The page views of one session, for the expandable row in the visitor log.
     *
     * @return list<array{path:string,title:string,time:string}>
     */
    public static function visitPages(array $site, int $visitId): array
    {
        $siteId   = (int) $site['id'];
        $timezone = (string) ($site['timezone'] ?? 'UTC');

        $db = Database::instance();
        $rows = $db->all(
            'SELECT `path`, `title`, `created_at` FROM ' . $db->table('pageviews') . '
             WHERE `site_id` = :sid AND `visit_id` = :vid
             ORDER BY `created_at` ASC, `id` ASC
             LIMIT 100',
            [':sid' => $siteId, ':vid' => $visitId]
        );

        $pages = [];
        foreach ($rows as $row) {
            $pages[] = [
                'path'  => (string) $row['path'],
                'title' => (string) $row['title'],
                'time'  => Aggregator::toLocalDateTime((string) $row['created_at'], $timezone),
            ];
        }
        return $pages;
    }

    /**
     * Goal conversions over a range.
     *
     * Returns one row per active goal, ordered by conversions, plus the total
     * number of unique visitors in the range so the caller can compute a
     * conversion rate.
     *
     * @return array{rows:list<array<string,mixed>>,visitors:int,has_goals:bool}
     */
    public static function goals(int $siteId, string $from, string $to): array
    {
        $db = Database::instance();

        $goals = Goal::active($siteId);
        if ($goals === []) {
            return ['rows' => [], 'visitors' => 0, 'has_goals' => false];
        }

        $stats = [];
        $rows = $db->all(
            'SELECT `goal_id`,
                    COALESCE(SUM(`conversions`), 0) AS conversions,
                    COALESCE(SUM(`visitors`), 0) AS visitors
             FROM ' . $db->table('goal_stats') . '
             WHERE `site_id` = :sid AND `date` >= :from AND `date` <= :to
             GROUP BY `goal_id`',
            [':sid' => $siteId, ':from' => $from, ':to' => $to]
        );
        foreach ($rows as $row) {
            $stats[(int) $row['goal_id']] = [
                'conversions' => (int) $row['conversions'],
                'visitors'    => (int) $row['visitors'],
            ];
        }

        $totalVisitors = (int) ($db->value(
            'SELECT COALESCE(SUM(`visitors`), 0) FROM ' . $db->table('stats_daily') . '
             WHERE `site_id` = :sid AND `date` >= :from AND `date` <= :to',
            [':sid' => $siteId, ':from' => $from, ':to' => $to],
            0
        ));

        $result = [];
        foreach ($goals as $goal) {
            $goalId = (int) $goal['id'];
            $values = $stats[$goalId] ?? ['conversions' => 0, 'visitors' => 0];

            $result[] = [
                'id'          => $goalId,
                'name'        => (string) $goal['name'],
                'type'        => (string) $goal['type'],
                'match_value' => (string) $goal['match_value'],
                'conversions' => $values['conversions'],
                'visitors'    => $values['visitors'],
                'rate'        => $totalVisitors > 0 ? $values['visitors'] / $totalVisitors : 0.0,
            ];
        }

        usort($result, static function (array $a, array $b): int {
            if ($a['visitors'] === $b['visitors']) {
                return $a['id'] <=> $b['id'];
            }
            return $b['visitors'] <=> $a['visitors'];
        });

        return ['rows' => $result, 'visitors' => $totalVisitors, 'has_goals' => true];
    }

    /**
     * Percentage change between two values, capped for display sanity.
     */
    public static function delta($current, $previous): ?float
    {
        $current  = (float) $current;
        $previous = (float) $previous;

        if ($previous === 0.0) {
            return $current === 0.0 ? 0.0 : null; // null = "new", not "+inf%"
        }
        return ($current - $previous) / $previous;
    }

    /**
     * A human readable label for the period picker.
     */
    private static function periodLabel(string $preset, string $from, string $to): string
    {
        $key = 'period.' . $preset;
        $translated = I18n::translate($key);
        if ($translated !== $key) {
            return $translated;
        }
        return $from === $to ? $from : $from . ' - ' . $to;
    }

    /**
     * Whether a string is a Y-m-d date.
     */
    private static function validDate(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        return checkdate($month, $day, $year);
    }

    /**
     * Dimensions a drill-down may filter by, mapped to their raw column.
     *
     * Only these names ever reach SQL, so the column can be interpolated safely
     * after a whitelist lookup.
     *
     * @return array<string,string>
     */
    public static function filterable(): array
    {
        return [
            'path'     => 'path',
            'referrer' => 'referrer_domain',
            'country'  => 'country',
            'device'   => 'device',
            'os'       => 'os',
            'browser'  => 'browser',
            'title'    => 'title',
            'language' => 'language',
            'screen'   => 'screen',
        ];
    }

    /**
     * The column for a filterable dimension, or null when the name is unknown.
     */
    public static function filterColumn(string $dimension): ?string
    {
        $map = self::filterable();
        return $map[$dimension] ?? null;
    }

    /**
     * Totals for one filtered value.
     *
     * @return array{pageviews:int,visitors:int,sessions:int,bounces:int}
     */
    public static function filteredSummary(int $siteId, string $from, string $to, string $column, string $value): array
    {
        $db = Database::instance();

        $row = $db->one(
            'SELECT COUNT(*) AS pageviews,
                    COUNT(DISTINCT `visitor_hash`) AS visitors,
                    COUNT(DISTINCT `session_key`) AS sessions
               FROM ' . $db->table('pageviews') . '
              WHERE `site_id` = :sid
                AND `created_date` >= :from AND `created_date` <= :to
                AND `is_bot` = 0
                AND `' . $column . '` = :value',
            [':sid' => $siteId, ':from' => $from, ':to' => $to, ':value' => $value]
        );

        return [
            'pageviews' => (int) ($row['pageviews'] ?? 0),
            'visitors'  => (int) ($row['visitors'] ?? 0),
            'sessions'  => (int) ($row['sessions'] ?? 0),
            'bounces'   => 0,
        ];
    }

    /**
     * A daily series for one filtered value.
     *
     * @return array{labels:list<string>,pageviews:list<int>,visitors:list<int>,sessions:list<int>,granularity:string}
     */
    public static function filteredSeries(array $site, string $from, string $to, string $column, string $value): array
    {
        $db = Database::instance();

        $rows = $db->all(
            'SELECT `created_date` AS day,
                    COUNT(*) AS pageviews,
                    COUNT(DISTINCT `visitor_hash`) AS visitors,
                    COUNT(DISTINCT `session_key`) AS sessions
               FROM ' . $db->table('pageviews') . '
              WHERE `site_id` = :sid
                AND `created_date` >= :from AND `created_date` <= :to
                AND `is_bot` = 0
                AND `' . $column . '` = :value
              GROUP BY `created_date`
              ORDER BY `created_date` ASC',
            [':sid' => (int) $site['id'], ':from' => $from, ':to' => $to, ':value' => $value]
        );

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['day']] = [
                'pageviews' => (int) $row['pageviews'],
                'visitors'  => (int) $row['visitors'],
                'sessions'  => (int) $row['sessions'],
            ];
        }

        $labels = $pageviews = $visitors = $sessions = [];
        $cursor = strtotime($from . ' 00:00:00 UTC');
        $end    = strtotime($to . ' 00:00:00 UTC');

        while ($cursor !== false && $cursor <= $end) {
            $day = gmdate('Y-m-d', $cursor);
            $labels[]    = $day;
            $pageviews[] = $byDay[$day]['pageviews'] ?? 0;
            $visitors[]  = $byDay[$day]['visitors'] ?? 0;
            $sessions[]  = $byDay[$day]['sessions'] ?? 0;
            $cursor = strtotime('+1 day', $cursor);
        }

        return [
            'labels'      => $labels,
            'pageviews'   => $pageviews,
            'visitors'    => $visitors,
            'sessions'    => $sessions,
            'granularity' => 'day',
        ];
    }

    /**
     * A breakdown of one dimension inside a filtered value.
     *
     * @return list<array{value:string,pageviews:int,visitors:int,sessions:int}>
     */
    public static function filteredTop(
        int $siteId,
        string $from,
        string $to,
        string $filterColumn,
        string $value,
        string $dimension,
        int $limit = 25
    ): array {
        $columns = self::filterable();
        if (!isset($columns[$dimension])) {
            return [];
        }

        $column = $columns[$dimension];
        $limit  = max(1, min(100, $limit));
        $db     = Database::instance();

        $rows = $db->all(
            'SELECT `' . $column . '` AS `value`,
                    COUNT(*) AS pageviews,
                    COUNT(DISTINCT `visitor_hash`) AS visitors,
                    COUNT(DISTINCT `session_key`) AS sessions
               FROM ' . $db->table('pageviews') . '
              WHERE `site_id` = :sid
                AND `created_date` >= :from AND `created_date` <= :to
                AND `is_bot` = 0
                AND `' . $filterColumn . '` = :value
              GROUP BY `' . $column . '`
              ORDER BY pageviews DESC
              LIMIT ' . $limit,
            [':sid' => $siteId, ':from' => $from, ':to' => $to, ':value' => $value]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'value'     => (string) $row['value'],
                'pageviews' => (int) $row['pageviews'],
                'visitors'  => (int) $row['visitors'],
                'sessions'  => (int) $row['sessions'],
            ];
        }

        return $result;
    }
}
