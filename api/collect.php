<?php
/**
 * PlotBird Analytics - hit collection endpoint.
 *
 * Called by tracker.js from the customer's website, which means:
 *   * it must answer cross-origin (CORS) - the caller is another domain;
 *   * it must be cheap: no sessions, no cookies, no rendering;
 *   * it must never leak an error page into somebody's console.
 *
 * POST body (JSON, at most 4 KB):
 *   { "w": "<website uuid>", "p": "/path?query", "r": "<referrer>",
 *     "t": "Page title", "s": "1920x1080", "l": "en-US", "dnt": "1" }
 *
 * @package PlotBird
 */

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

use PlotBird\Collector;
use PlotBird\Config;
use PlotBird\Database;
use PlotBird\RateLimiter;
use PlotBird\Security;
use PlotBird\Site;

// ---------------------------------------------------------------------------
// CORS. The tracker runs on the customer's domain, so the response is readable
// from anywhere; nothing sensitive is ever returned.
// ---------------------------------------------------------------------------
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');
header('Vary: Origin');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * A completely blank answer. sendBeacon() ignores the body anyway.
 */
function collect_respond(int $status, string $body = ''): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    if ($body !== '') {
        echo $body;
    }
    exit;
}

/**
 * A 1x1 transparent GIF for the <noscript><img> fallback.
 */
function collect_gif(): void
{
    header('Content-Type: image/gif');
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    // 43 byte transparent GIF.
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

if (!Config::isInstalled()) {
    collect_respond(204);
}

try {
    $db = Database::instance();
} catch (\Throwable $e) {
    collect_respond(204);
}

$wantsGif = isset($_GET['gif']);
$method   = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// ---------------------------------------------------------------------------
// Read the payload: JSON body for POST, query string for the image fallback.
// ---------------------------------------------------------------------------
$payload = [];

if ($method === 'POST') {
    $raw = (string) file_get_contents('php://input', false, null, 0, Collector::MAX_PAYLOAD_BYTES + 1);
    if (strlen($raw) > Collector::MAX_PAYLOAD_BYTES) {
        collect_respond(413);
    }
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        } else {
            // text/plain form encoding, used by sendBeacon in old browsers.
            parse_str($raw, $parsed);
            if (is_array($parsed)) {
                $payload = $parsed;
            }
        }
    }
} else {
    $payload = $_GET;
}

$publicId = trim((string) ($payload['w'] ?? $payload['website_id'] ?? $payload['id'] ?? ''));
if ($publicId === '' || preg_match('/^[0-9a-fA-F-]{8,64}$/', $publicId) !== 1) {
    if ($wantsGif) {
        collect_gif();
    }
    collect_respond(400);
}

// ---------------------------------------------------------------------------
// Rate limiting, per client address.
// ---------------------------------------------------------------------------
$limit  = (int) Config::get('tracker.rate_limit', Collector::DEFAULT_RATE_LIMIT);
$limit  = $limit > 0 ? $limit : Collector::DEFAULT_RATE_LIMIT;
$bucket = RateLimiter::hit('collect', Security::hashIp(client_ip()), $limit, 60);

if (!$bucket['allowed']) {
    header('Retry-After: ' . max(1, $bucket['retry_after']));
    if ($wantsGif) {
        collect_gif();
    }
    collect_respond(429);
}

// Occasionally clean old counters up.
if (random_int(1, 200) === 1) {
    RateLimiter::garbageCollect(7200);
}

// ---------------------------------------------------------------------------
// Resolve the site.
// ---------------------------------------------------------------------------
$site = Site::findByPublicId($publicId);
if ($site === null || (int) $site['is_active'] !== 1) {
    if ($wantsGif) {
        collect_gif();
    }
    collect_respond(204);
}

// The UUID is not a secret, so an origin check adds a real layer: a random
// website cannot feed somebody else's dashboard.
$senderHost = '';
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $senderHost = (string) (parse_url($origin, PHP_URL_HOST) ?? '');
} else {
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($referer !== '') {
        $senderHost = (string) (parse_url($referer, PHP_URL_HOST) ?? '');
    }
}

if ($senderHost !== '' && !Site::hostAllowed($site, $senderHost)) {
    pb_log('collect rejected: host ' . $senderHost . ' not allowed for site ' . $site['id'], 'security');
    if ($wantsGif) {
        collect_gif();
    }
    // Answer the documented "nothing was recorded" shape when the caller asked
    // for JSON, so an integrator can see why their hits are dropped.
    if (isset($_GET['json'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ignored', 'reason' => 'host', 'visit_id' => 0, 'pageview_id' => 0, 'country' => '']);
        exit;
    }
    collect_respond(204);
}

// ---------------------------------------------------------------------------
// Record it.
// ---------------------------------------------------------------------------
$settings = Site::trackerSettings($site);
$result   = Collector::collect($site, $payload, $settings);

if ($wantsGif) {
    collect_gif();
}

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result);
    exit;
}

// 204 keeps the request out of the customer's devtools network noise.
collect_respond(204);
