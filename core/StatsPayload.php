<?php
/**
 * PlotBird Analytics - dashboard/API payload builder.
 *
 * One place that decides what a dashboard looks like, used both by the initial
 * server-side render and by /api/stats.php. That guarantees the numbers do not
 * change when a widget refreshes itself.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class StatsPayload
{
    /** Dimensions returned in the "top" block. */
    private const TOP_DIMENSIONS = ['path', 'referrer', 'country', 'entry', 'exit', 'title', 'language', 'screen'];

    /** How many rows each top-list carries. */
    public const DEFAULT_LIMIT = 10;

    /**
     * Build the complete payload for a site and period.
     *
     * @param array<string,mixed> $site
     * @param array<string,mixed> $params Already validated request parameters.
     * @return array<string,mixed>
     */
    public static function build(array $site, array $params = []): array
    {
        $siteId   = (int) $site['id'];
        $preset   = (string) ($params['preset'] ?? 'last7');
        $from     = (string) ($params['from'] ?? '');
        $to       = (string) ($params['to'] ?? '');
        $limit    = (int) ($params['limit'] ?? self::DEFAULT_LIMIT);
        $realtime = (int) ($params['realtime'] ?? 5);
        $visitsLimit = (int) ($params['visits'] ?? 50);

        $limit = max(3, min(50, $limit));

        $period = Stats::resolvePeriod($site, $preset, $from, $to);

        // Make sure the rollups cover the requested range before reading them.
        Aggregator::ensureFresh($siteId);

        $summary   = Stats::summary($siteId, $period['from'], $period['to']);
        $previous  = Stats::previousPeriod($period['from'], $period['to']);
        $previousSummary = Stats::summary($siteId, $previous['from'], $previous['to']);

        $top = [];
        foreach (self::TOP_DIMENSIONS as $dimension) {
            $top[$dimension] = self::decorate($dimension, Stats::topDimension($siteId, $period['from'], $period['to'], $dimension, $limit));
        }

        return [
            'site'      => [
                'id'        => $siteId,
                'name'      => (string) $site['name'],
                'domain'    => (string) $site['domain'],
                'public_id' => (string) $site['public_id'],
                'timezone'  => (string) $site['timezone'],
            ],
            'period'    => $period,
            'summary'   => $summary,
            'previous'  => [
                'period'  => $previous,
                'summary' => $previousSummary,
                'delta'   => [
                    'pageviews'   => Stats::delta($summary['pageviews'], $previousSummary['pageviews']),
                    'visitors'    => Stats::delta($summary['visitors'], $previousSummary['visitors']),
                    'sessions'    => Stats::delta($summary['sessions'], $previousSummary['sessions']),
                    'bounce_rate' => Stats::delta($summary['bounce_rate'], $previousSummary['bounce_rate']),
                    'avg_duration' => Stats::delta($summary['avg_duration'], $previousSummary['avg_duration']),
                ],
            ],
            'series'    => Stats::series($site, $period['from'], $period['to']),
            'sparklines' => [
                'pageviews' => Stats::sparkline($siteId, $period['from'], $period['to'], 'pageviews'),
                'visitors'  => Stats::sparkline($siteId, $period['from'], $period['to'], 'visitors'),
                'sessions'  => Stats::sparkline($siteId, $period['from'], $period['to'], 'sessions'),
            ],
            'top'       => $top,
            'breakdown' => Stats::breakdown($siteId, $period['from'], $period['to']),
            'realtime'  => Stats::realtime($siteId, $realtime),
            'visits'    => Stats::visits($site, $period['from'], $period['to'], $visitsLimit),
            'goals'     => self::goalsWithDelta($siteId, $period, $previous),
            'range'     => Stats::availableRange($siteId),
            'generated_at' => now_utc(),
        ];
    }

    /**
     * Goal conversions for the period, each with the change against the
     * equivalent previous period.
     *
     * @param array{from:string,to:string,preset:string,label:string} $period
     * @param array{from:string,to:string} $previous
     * @return array<string,mixed>
     */
    private static function goalsWithDelta(int $siteId, array $period, array $previous): array
    {
        $current = Stats::goals($siteId, $period['from'], $period['to']);
        if (!$current['has_goals']) {
            return $current;
        }

        $before = Stats::goals($siteId, $previous['from'], $previous['to']);
        $previousByGoal = [];
        foreach ($before['rows'] as $row) {
            $previousByGoal[(int) $row['id']] = $row;
        }

        foreach ($current['rows'] as $index => $row) {
            $prior = $previousByGoal[(int) $row['id']] ?? null;
            $priorVisitors = $prior === null ? 0 : (int) $prior['visitors'];

            $current['rows'][$index]['previous_visitors'] = $priorVisitors;
            $current['rows'][$index]['delta'] = Stats::delta((int) $row['visitors'], $priorVisitors);
        }

        return $current;
    }

    /**
     * Add presentation metadata (country name and flag, readable labels) to a
     * dimension list so that templates and JavaScript stay dumb.
     *
     * @param list<array{value:string,pageviews:int,visitors:int,sessions:int}> $rows
     * @return list<array<string,mixed>>
     */
    private static function decorate(string $dimension, array $rows): array
    {
        $decorated = [];
        foreach ($rows as $row) {
            $value = (string) $row['value'];
            $extra = [];

            if ($dimension === 'country') {
                $extra['name'] = $value === '' ? I18n::translate('dimension.unknown') : I18n::country($value);
                $extra['flag'] = country_flag($value);
                // The interface renders an SVG flag image; the emoji above is
                // kept for exports and for clients that cannot load images.
                $extra['flag_url'] = flag_url($value);
            }

            $decorated[] = array_merge($row, $extra);
        }
        return $decorated;
    }
}
