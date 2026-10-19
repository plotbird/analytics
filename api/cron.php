<?php
/**
 * PlotBird Analytics - scheduled maintenance endpoint.
 *
 * Point a cron service (or the hosting panel's cron) at the URL shown on the
 * settings page. There is no CLI requirement anywhere in this project, because
 * classic shared hostings do not offer one.
 *
 *   https://example.com/api/cron.php?secret=<cron secret>
 *
 * Tasks
 *   aggregate (default) - refresh the daily rollups
 *   purge               - delete raw data past the retention window
 *   geoip               - import the IP database if it is missing
 *   all                 - everything above
 *
 * The endpoint is idempotent and safe to run every five minutes.
 *
 * @package PlotBird
 */

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

use PlotBird\Aggregator;
use PlotBird\Config;
use PlotBird\Database;
use PlotBird\GeoIP;
use PlotBird\RateLimiter;
use PlotBird\Settings;
use PlotBird\Site;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** How long this run may spend aggregating, in seconds. */
const CRON_TIME_BUDGET = 45;

if (!Config::isInstalled()) {
    json_out(['ok' => false, 'error' => 'not_installed'], 503);
}

$expected = Settings::cronSecret();
$provided = (string) ($_GET['secret'] ?? $_SERVER['HTTP_X_CRON_SECRET'] ?? '');

if ($expected === '' || !hash_equals($expected, $provided)) {
    PlotBird\Log::security('cron called with a wrong secret from ' . client_ip());
    json_out(['ok' => false, 'error' => 'forbidden'], 403);
}

try {
    Database::instance();
} catch (\Throwable $e) {
    json_out(['ok' => false, 'error' => 'database_unavailable'], 503);
}

$task   = strtolower(trim((string) ($_GET['task'] ?? 'aggregate')));
$result = ['ok' => true, 'task' => $task, 'started_at' => now_utc(), 'sites' => [], 'totals' => []];

$started = microtime(true);

// ---------------------------------------------------------------------------
// Aggregation
// ---------------------------------------------------------------------------
if (in_array($task, ['aggregate', 'all'], true)) {
    $sites = Site::all();
    $days  = 0;

    foreach ($sites as $site) {
        if ((microtime(true) - $started) > CRON_TIME_BUDGET) {
            $result['truncated'] = true;
            break;
        }
        try {
            $updated = Aggregator::refresh($site);
            $days += $updated;
            $result['sites'][] = ['id' => (int) $site['id'], 'days' => $updated];
        } catch (\Throwable $e) {
            PlotBird\Log::error('cron aggregation failed for site ' . $site['id'] . ': ' . $e->getMessage());
            $result['sites'][] = ['id' => (int) $site['id'], 'error' => 'failed'];
        }
    }
    $result['totals']['days'] = $days;
}

// ---------------------------------------------------------------------------
// Retention
// ---------------------------------------------------------------------------
if (in_array($task, ['purge', 'all'], true)) {
    $purged = Aggregator::purgeRaw();
    $result['totals']['pageviews_purged'] = $purged['pageviews'];
    $result['totals']['visits_purged']    = $purged['visits'];
    $result['totals']['retention_days']   = Settings::retentionDays();
}

// ---------------------------------------------------------------------------
// Geolocation database
// ---------------------------------------------------------------------------
if (in_array($task, ['geoip', 'all'], true)) {
    if (GeoIP::isImported()) {
        $result['totals']['geoip_ranges'] = GeoIP::count();
    } elseif (GeoIP::baseAvailable()) {
        $prepared = GeoIP::prepareCsv();
        if (!$prepared['ok']) {
            $result['totals']['geoip_error'] = $prepared['error'];
        } else {
            $offset = 0;
            $guard  = 0;
            do {
                $chunk = GeoIP::importChunk($prepared['csv'], $offset);
                if (!$chunk['ok']) {
                    $result['totals']['geoip_error'] = $chunk['error'];
                    break;
                }
                $offset = $chunk['offset'];
                $guard++;
            } while (!$chunk['done'] && $guard < 40 && (microtime(true) - $started) < CRON_TIME_BUDGET);

            $result['totals']['geoip_ranges'] = GeoIP::count();
            $result['totals']['geoip_complete'] = $chunk['done'] ?? false;
        }
    } else {
        $result['totals']['geoip_error'] = 'no_base_file';
    }
}

// ---------------------------------------------------------------------------
// Housekeeping
// ---------------------------------------------------------------------------
RateLimiter::garbageCollect(172800);
$result['totals']['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
$result['finished_at'] = now_utc();

Settings::set('cron_last_run', now_utc());

json_out($result);
