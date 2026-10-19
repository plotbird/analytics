<?php
/**
 * PlotBird Analytics - statistics API for the dashboard.
 *
 * Authorisation is either a signed-in session that may see the site, or a
 * valid share token for exactly that site. Everything is read-only, so a GET
 * needs no CSRF token.
 *
 * GET parameters
 *   site=<id>            site id (session mode)
 *   share=<token>        share token (public mode)
 *   preset=<name>        today|yesterday|last7|last30|last90|month|lastmonth|year|custom
 *   from=YYYY-MM-DD      with preset=custom
 *   to=YYYY-MM-DD
 *   only=realtime        return just the real-time block (cheap polling)
 *
 * @package PlotBird
 */

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

use PlotBird\Auth;
use PlotBird\Config;
use PlotBird\Database;
use PlotBird\Site;
use PlotBird\Stats;
use PlotBird\StatsPayload;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!Config::isInstalled()) {
    json_out(['error' => 'not_installed'], 503);
}

try {
    Database::instance();
} catch (\Throwable $e) {
    json_out(['error' => 'database_unavailable'], 503);
}

$site  = null;
$scope = 'session';

// --- Resolution ------------------------------------------------------------
$shareToken = query_param('share');
if ($shareToken !== '') {
    $link = \PlotBird\ShareLink::resolve($shareToken);
    if ($link === null) {
        json_out(['error' => 'invalid_share_token'], 403);
    }
    $site  = Site::find((int) $link['site_id']);
    $scope = 'share';
    if ($site === null || (int) $site['is_active'] !== 1) {
        json_out(['error' => 'invalid_share_token'], 403);
    }
} else {
    Auth::requireLogin(true);
    $user = Auth::user();

    $siteId = (int) query_param('site', '0');
    if ($siteId <= 0) {
        $candidate = $user === null ? null : Site::firstForUser($user);
        $siteId = $candidate === null ? 0 : (int) $candidate['id'];
    }

    $candidate = $siteId > 0 ? Site::find($siteId) : null;
    if ($candidate === null || $user === null || !Site::canAccess($user, $siteId)) {
        json_out(['error' => 'forbidden'], 403);
    }
    $site = $candidate;
}

// --- Parameters ------------------------------------------------------------
$only = query_param('only');

if ($only === 'realtime') {
    $minutes = (int) query_param('minutes', '5');
    json_out([
        'ok'       => true,
        'realtime' => Stats::realtime((int) $site['id'], $minutes),
    ]);
}

if ($only === 'summary') {
    // Used by the period comparison cards to refresh without the whole payload.
    $period = Stats::resolvePeriod($site, query_param('preset', 'last7'), query_param('from'), query_param('to'));
    \PlotBird\Aggregator::ensureFresh((int) $site['id']);
    $summary = Stats::summary((int) $site['id'], $period['from'], $period['to']);
    $previous = Stats::previousPeriod($period['from'], $period['to']);
    $previousSummary = Stats::summary((int) $site['id'], $previous['from'], $previous['to']);

    json_out([
        'ok'      => true,
        'period'  => $period,
        'summary' => $summary,
        'comparison' => [
            'period'  => $previous,
            'summary' => $previousSummary,
            'delta'   => [
                'pageviews'    => Stats::delta($summary['pageviews'], $previousSummary['pageviews']),
                'visitors'     => Stats::delta($summary['visitors'], $previousSummary['visitors']),
                'sessions'     => Stats::delta($summary['sessions'], $previousSummary['sessions']),
                'bounce_rate'  => Stats::delta($summary['bounce_rate'], $previousSummary['bounce_rate']),
                'avg_duration' => Stats::delta($summary['avg_duration'], $previousSummary['avg_duration']),
            ],
        ],
    ]);
}

if ($only === 'visit') {
    // The pages of one session, loaded when a visitor-log row is expanded.
    $visitId = (int) query_param('visit', '0');
    if ($visitId <= 0) {
        json_out(['ok' => false, 'error' => 'bad_request'], 400);
    }
    json_out([
        'ok'      => true,
        'visit'   => $visitId,
        // visitPages() filters by site, so a session id from another website
        // simply returns nothing.
        'pages'   => Stats::visitPages($site, $visitId),
    ]);
}

$payload = StatsPayload::build($site, [
    'preset'   => query_param('preset', 'last7'),
    'from'     => query_param('from'),
    'to'       => query_param('to'),
    'limit'    => query_param('limit', '10'),
    'realtime' => query_param('realtime', '5'),
    'visits'   => query_param('visits', '50'),
]);

json_out(['ok' => true, 'scope' => $scope] + $payload);
