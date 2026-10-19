<?php
/**
 * PlotBird Analytics - end-to-end test suite.
 *
 * Installs the whole application through the real installer, tracks hits,
 * logs in, and checks every page and endpoint. Run it with:
 *
 *   php tests/e2e.php                 # HTTP transport (starts php -S)
 *   php tests/e2e.php --with-geoip    # also imports the full IP database
 *
 * Environment overrides: PB_TEST_TRANSPORT, PB_TEST_PORT, PB_TEST_DB_HOST,
 * PB_TEST_DB_PORT, PB_TEST_DB_NAME, PB_TEST_DB_USER, PB_TEST_DB_PASS.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$withGeoip = in_array('--with-geoip', $argv, true);

echo "PlotBird Analytics - end-to-end test\n";
echo "====================================\n\n";

echo "[0] Reset\n";
resetDatabase();
Result::ok(true, 'database and configuration reset');

$client = Client::make();

/* ---------------------------------------------------------------- install */

echo "\n[1] Installer: redirect to /install/\n";
$response = $client->request('GET', '/');
Result::ok($response['status'] === 302, 'GET / redirects while not installed', 'status=' . $response['status']);
Result::contains((string) ($response['headers']['location'] ?? ''), '/install/', 'redirect points at the installer');

echo "\n[2] Installer step 1: language\n";
$response = $client->request('GET', '/install/?step=1');
Result::ok($response['status'] === 200, 'step 1 renders', 'status=' . $response['status']);
Result::contains($response['body'], 'PlotBird Analytics', 'step 1 contains the product name');
$token = csrf($response['body']);
Result::ok($token !== '', 'step 1 exposes a CSRF token');

$response = $client->request('POST', '/install/?action=save-locale', ['_token' => $token, 'locale' => 'ru']);
Result::ok($response['status'] === 302, 'language was saved', 'status=' . $response['status']);

echo "\n[3] Installer step 2: requirements\n";
$response = $client->request('GET', '/install/?step=2');
Result::ok($response['status'] === 200, 'step 2 renders', 'status=' . $response['status']);
Result::contains($response['body'], 'data/', 'requirements list the writable folders');
Result::notContains($response['body'], 'Fatal error', 'no fatal error on step 2');

echo "\n[4] Installer step 3: database\n";
$response = $client->request('GET', '/install/?step=3');
$token = csrf($response['body']);
Result::ok($response['status'] === 200 && $token !== '', 'step 3 renders with a token');
assert_components($response['body'], 'installer step 3');
dump_page('install_step3.html', $response['body']);

$dbForm = [
    '_token'   => $token,
    'host'     => DB_HOST,
    'port'     => (string) DB_PORT,
    'name'     => DB_NAME,
    'user'     => DB_USER,
    'password' => DB_PASS,
    'prefix'   => DB_PREFIX,
];

$response = $client->request('POST', '/install/?action=test-db', $dbForm);
$json = json_decode($response['body'], true);
Result::ok(is_array($json) && ($json['ok'] ?? false) === true, 'AJAX connection test succeeds', $response['body']);
// Any server is fine here: the CI matrix runs MySQL 8.0 and MariaDB 11.4, and
// the installer reports whatever VERSION() returns ("8.0.43" or "11.4.13-MariaDB").
$serverVersion = (string) ($json['version'] ?? '');
Result::ok(preg_match('/\d+\.\d+/', $serverVersion) === 1, 'server version reported', 'got=' . $serverVersion);

$response = $client->request('POST', '/install/?action=save-db', $dbForm + ['pretty_urls' => '1']);
Result::ok($response['status'] === 302, 'database settings saved', 'status=' . $response['status']);

echo "\n[5] Installer step 4: schema + config.php\n";
$response = $client->request('GET', '/install/?step=4');
Result::ok($response['status'] === 200, 'step 4 renders', 'status=' . $response['status']);
$token = csrf($response['body']);

$response = $client->request('POST', '/install/?action=install-schema', ['_token' => $token]);
Result::ok($response['status'] === 302, 'schema created', 'status=' . $response['status'] . ' body=' . substr($response['body'], 0, 300));
Result::ok(is_file(ROOT . '/config/config.php'), 'config/config.php was written');

$pdo = db();
// Say what we are running against: MySQL and MariaDB differ in ways that matter,
// and the CI summary has to name the one that failed.
echo 'runtime: PHP ' . PHP_VERSION . ', ' . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
Result::ok(count($tables) >= 12, 'schema created at least 12 tables', 'found=' . count($tables) . ': ' . implode(',', $tables));
Result::ok(in_array(DB_PREFIX . 'pageviews', $tables, true), 'pageviews table exists');
Result::ok(in_array(DB_PREFIX . 'stats_dim', $tables, true), 'stats_dim table exists');

echo "\n[6] Installer step 4b: GeoIP import\n";
$response = $client->request('GET', '/install/?step=4&stage=done');
$token = csrf($response['body']);
Result::ok($response['status'] === 200, 'step 4 completion page renders');

// The step view wires the shared import component to the installer endpoint.
// The layout used to replace window.PB wholesale and wipe that endpoint, which
// sent the request to the fallback URL and made the import fail silently - with
// no browser in CI, these two assertions are what guard the wiring.
Result::contains($response['body'], 'PB.endpoints.geoip', 'step 4 wires the import endpoint into PB');
Result::ok(
    strpos($response['body'], 'window.PB = {') === false,
    'no layout replaces window.PB wholesale (it would wipe per-page endpoints)'
);

if ($withGeoip) {
    $response = $client->request('POST', '/install/?action=geoip', ['_token' => $token, 'phase' => 'prepare']);
    $json = json_decode($response['body'], true);
    Result::ok(is_array($json) && ($json['ok'] ?? false) === true, 'GeoIP CSV prepared', $response['body']);

    $offset = 0;
    $guard  = 0;
    $done   = false;
    $started = microtime(true);
    while (!$done && $guard < 400) {
        $response = $client->request('POST', '/install/?action=geoip', ['_token' => $token, 'phase' => 'chunk', 'offset' => (string) $offset]);
        $json = json_decode($response['body'], true);
        if (!is_array($json) || ($json['ok'] ?? false) !== true) {
            Result::ok(false, 'GeoIP chunk import', $response['body']);
            break;
        }
        $offset = (int) $json['offset'];
        $done   = (bool) $json['done'];
        $guard++;
    }
    $seconds = round(microtime(true) - $started, 1);
    Result::ok($done, "GeoIP import finished in $guard chunks / {$seconds}s");
    $ranges = (int) $pdo->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'geoip_ranges')->fetchColumn();
    Result::ok($ranges > 100000, "geoip_ranges populated ($ranges rows)");

    // What does the table hold, and does the comparison match at all? Running the
    // same lookup with a binary parameter and with a hex parameter says whether a
    // failure lives in the stored bytes or in how the address is bound.
    $probe = $pdo->query(
        'SELECT `family`, HEX(`start_bin`) AS s, HEX(`end_bin`) AS e, `country_code`
         FROM ' . DB_PREFIX . 'geoip_ranges WHERE `family` = 4 ORDER BY `start_bin` DESC LIMIT 1'
    )->fetch();
    echo 'geoip probe: ' . json_encode($probe) . "\n";

    foreach (['8.8.8.8', '8.8.8.0'] as $probeIp) {
        $packed = str_repeat("\x00", 10) . "\xff\xff" . (string) inet_pton($probeIp);
        $probeStmt = $pdo->prepare(
            'SELECT HEX(`start_bin`) AS s, HEX(`end_bin`) AS e, `country_code`
             FROM ' . DB_PREFIX . 'geoip_ranges
             WHERE `family` = 4 AND `start_bin` <= UNHEX(?) ORDER BY `start_bin` DESC LIMIT 1'
        );
        $probeStmt->execute([bin2hex($packed)]);
        echo 'geoip row for ' . $probeIp . ': ' . json_encode($probeStmt->fetch()) . "\n";
    }

    $packed = str_repeat("\x00", 10) . "\xff\xff" . (string) inet_pton('8.8.8.8');
    $sqlBase = 'SELECT `country_code` FROM ' . DB_PREFIX . 'geoip_ranges
                WHERE `family` = 4 AND `start_bin` <= %s ORDER BY `start_bin` DESC LIMIT 1';

    $binaryStmt = $pdo->prepare(sprintf($sqlBase, '?'));
    $binaryStmt->execute([$packed]);
    echo 'geoip via binary parameter: ' . json_encode($binaryStmt->fetchColumn()) . "\n";

    $hexStmt = $pdo->prepare(sprintf($sqlBase, 'UNHEX(?)'));
    $hexStmt->execute([bin2hex($packed)]);
    $hexCountry = (string) $hexStmt->fetchColumn();
    echo 'geoip via hex parameter: ' . json_encode($hexCountry) . "\n";
    Result::ok($hexCountry !== '', 'the geoip comparison matches for a hex parameter', 'got=' . $hexCountry);
} else {
    Result::ok(true, 'GeoIP import skipped (run with --with-geoip)');
}

echo "\n[7] Installer step 5: administrator\n";
$response = $client->request('GET', '/install/?step=5');
$token = csrf($response['body']);
Result::ok($response['status'] === 200 && $token !== '', 'step 5 renders');

$response = $client->request('POST', '/install/?action=save-admin', [
    '_token'          => $token,
    'email'           => ADMIN_EMAIL,
    'name'            => 'Test Admin',
    'password'        => ADMIN_PASS,
    'password_repeat' => ADMIN_PASS,
]);
Result::ok($response['status'] === 302, 'administrator created', 'status=' . $response['status'] . ' ' . substr(strip_tags($response['body']), 0, 200));

$admin = $pdo->query('SELECT id, email, role, password_hash FROM ' . DB_PREFIX . 'users')->fetch();
Result::ok($admin !== false && $admin['role'] === 'super_admin', 'super admin row exists');
Result::ok($admin !== false && password_verify(ADMIN_PASS, (string) $admin['password_hash']), 'password is hashed and verifiable');

echo "\n[8] Installer step 6: first website\n";
$response = $client->request('GET', '/install/?step=6');
$token = csrf($response['body']);
Result::ok($response['status'] === 200 && $token !== '', 'step 6 renders');
assert_components($response['body'], 'installer step 6');
Result::notContains($response['body'], 'this.creating = false', 'no JavaScript leaked onto installer step 6');
dump_page('install_step6.html', $response['body']);

$response = $client->request('POST', '/install/?action=create-site', [
    '_token'   => $token,
    'name'     => 'Example Blog',
    'domain'   => 'example.com',
    'timezone' => 'Europe/Berlin',
]);
$json = json_decode($response['body'], true);
Result::ok(is_array($json) && ($json['ok'] ?? false) === true, 'website created through AJAX', $response['body']);
$publicId = (string) ($json['public_id'] ?? '');
$siteId   = (int) ($json['site_id'] ?? 0);
Result::ok(strlen($publicId) === 36, 'website got a UUID', $publicId);
Result::contains((string) ($json['snippet'] ?? ''), 'data-website-id="' . $publicId . '"', 'snippet carries the UUID');
Result::contains((string) ($json['snippet'] ?? ''), '/assets/tracker.js', 'snippet points at the tracker');

$response = $client->request('POST', '/install/?action=check-tracker', ['_token' => $token, 'site_id' => (string) $siteId]);
$json = json_decode($response['body'], true);
Result::ok(is_array($json) && ($json['ok'] ?? false) === false, 'tracker check reports "nothing yet"');

echo "\n[9] Installer step 7: finish\n";
$response = $client->request('GET', '/install/?step=6');
$token = csrf($response['body']);
$response = $client->request('POST', '/install/?action=finish', ['_token' => $token]);
Result::ok($response['status'] === 302, 'finish redirects', 'status=' . $response['status']);
Result::ok(is_file(ROOT . '/config/install.lock'), 'install.lock written');

// The suite fakes visitor addresses with X-Forwarded-For, so the installation
// has to trust the loopback address as a proxy. This is the same code path an
// operator behind Cloudflare or nginx would configure, so it is worth testing.
$configPath   = ROOT . '/config/config.php';
$configSource = (string) file_get_contents($configPath);
$configPatched = str_replace("'trusted_proxies' => '',", "'trusted_proxies' => '127.0.0.1',", $configSource);
Result::ok($configPatched !== $configSource, 'test installation trusts the loopback proxy');
$configWritten = file_put_contents($configPath, $configPatched);
// Verify the effect, not the intention: if this write fails, every later
// address assertion silently falls back to 127.0.0.1 and the failure surfaces
// somewhere unrelated.
Result::ok($configWritten !== false && $configWritten > 0, 'the patched config was written', var_export($configWritten, true));
clearstatcache(true, $configPath);
Result::contains((string) file_get_contents($configPath), "'trusted_proxies' => '127.0.0.1'", 'the config on disk trusts the loopback');
// The application may have compiled the old file already: start it again.
$client->restart();

$response = $client->request('GET', '/install/?step=7');
Result::ok($response['status'] === 200, 'final page renders for the wizard session');
assert_components($response['body'], 'installer step 7');
Result::contains($response['body'], 'install', 'final page talks about removing /install/');

echo "\n[10] Installer is locked for outsiders\n";
$stranger = Client::make();
$response = $stranger->request('GET', '/install/', [], null, [], '198.51.100.5', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36');
Result::notContains($response['body'], 'name="host"', 'a fresh browser never sees the database form');
Result::contains($response['body'], 'rm -rf', 'and is shown the delete instruction');
Result::contains($response['body'], '/install', 'the lock page mentions the installer folder');

/* ------------------------------------------------------------------- tracking */

echo "\n[11] Collection endpoint\n";
$payload = static fn (string $path, string $referrer = '', string $title = 'Home'): string => (string) json_encode([
    'w' => $publicId, 'p' => $path, 'r' => $referrer, 't' => $title,
    's' => '1920x1080', 'l' => 'en-US', 'dnt' => '0',
]);

$visitors = [
    ['8.8.8.8',         'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36', '/', 'https://www.google.com/search?q=test'],
    ['8.8.8.8',         'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36', '/pricing', ''],
    ['1.1.1.1',         'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1', '/', 'https://twitter.com/'],
    ['9.9.9.9',         'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0 Safari/537.36', '/blog/hello-world', 'https://news.ycombinator.com/'],
    ['185.199.108.153', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Firefox/123.0', '/docs', ''],
];

$accepted = 0;
foreach ($visitors as $index => [$ip, $ua, $path, $referrer]) {
    $response = $client->request('POST', '/api/collect.php?json=1', [], $payload($path, $referrer), [
        'Origin' => 'https://example.com',
    ], $ip, $ua);
    $json = json_decode($response['body'], true);
    if (is_array($json) && ($json['status'] ?? '') === 'ok') {
        $accepted++;
    } else {
        echo "        visitor $index not accepted: " . substr($response['body'], 0, 200) . "\n";
    }
}
Result::ok($accepted === count($visitors), 'all real visitors were accepted', "accepted=$accepted");

$bots = [
    ['66.249.66.1', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
    ['203.0.113.9', 'curl/8.4.0'],
    ['203.0.113.10', 'python-requests/2.31.0'],
    ['203.0.113.11', 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)'],
];
$acceptedBots = 0;
foreach ($bots as [$ip, $ua]) {
    $response = $client->request('POST', '/api/collect.php?json=1', [], $payload('/bot-page'), ['Origin' => 'https://example.com'], $ip, $ua);
    $json = json_decode($response['body'], true);
    if (is_array($json) && ($json['status'] ?? '') === 'ok') {
        $acceptedBots++;
    }
}
Result::ok($acceptedBots === 0, 'bots are filtered out', "accepted=$acceptedBots");

// A wrong host must be rejected for a site with a configured domain.
$response = $client->request('POST', '/api/collect.php?json=1', [], $payload('/spam'), ['Origin' => 'https://evil.example'], '203.0.113.44', 'Mozilla/5.0 (Windows NT 10.0) Chrome/122.0 Safari/537.36');
$json = json_decode($response['body'], true);
Result::ok(is_array($json) && ($json['status'] ?? '') === 'ignored' && ($json['reason'] ?? '') === 'host', 'foreign origin is ignored', $response['body']);

// Unknown website id.
$response = $client->request('POST', '/api/collect.php?json=1', [], (string) json_encode(['w' => 'not-a-uuid', 'p' => '/']), ['Origin' => 'https://example.com']);
Result::ok($response['status'] === 400, 'invalid website id is rejected', 'status=' . $response['status']);

// The tracker accepts a small JSON body. Anything larger is refused before it is
// parsed, so a hostile client cannot make the server buffer megabytes.
$response = $client->request(
    'POST',
    '/api/collect.php?json=1',
    [],
    str_repeat('x', 5000),
    ['Origin' => 'https://example.com'],
    '203.0.113.7'
);
Result::ok($response['status'] === 413, 'an oversized tracker payload is refused', 'status=' . $response['status']);

$pageviews = (int) $pdo->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'pageviews')->fetchColumn();
$visits    = (int) $pdo->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'visits')->fetchColumn();
$paths     = $pdo->query('SELECT id, path, country FROM ' . DB_PREFIX . 'pageviews ORDER BY id ASC')->fetchAll();
Result::ok($pageviews === 5, "five pageviews stored (got $pageviews)", json_encode($paths));
Result::ok($visits === 4, "four visits stored (got $visits)");

$stored = $pdo->query('SELECT country, device, os, browser FROM ' . DB_PREFIX . 'pageviews ORDER BY id ASC')->fetchAll();
if ($withGeoip) {
    // What the application itself looked up, and what answered. Printing it here
    // means a geoip failure is diagnosable from the job log alone.
    $traceFile = ROOT . '/data/logs/geoip-trace.log';
    if (is_file($traceFile)) {
        echo "geoip trace (last 8 lookups):\n";
        $traceLines = file($traceFile, FILE_IGNORE_NEW_LINES) ?: [];
        foreach (array_slice($traceLines, -8) as $traceLine) {
            echo '  ' . $traceLine . "\n";
        }
        echo '  -- total ' . count($traceLines) . " lookups\n";
    } else {
        echo "geoip trace: no file (is PB_GEOIP_TRACE set?)\n";
    }

    Result::ok(in_array('US', array_column($stored, 'country'), true), 'geolocation resolved to a country', json_encode(array_column($stored, 'country')));
} else {
    Result::ok(true, 'geolocation assertions skipped (no --with-geoip)');
}
Result::ok(in_array('mobile', array_column($stored, 'device'), true), 'mobile device detected');
Result::ok(in_array('Chrome', array_column($stored, 'browser'), true), 'Chrome detected');
Result::ok(in_array('Firefox', array_column($stored, 'browser'), true), 'Firefox detected');

$rawIpLeak = $pdo->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'pageviews WHERE visitor_hash LIKE "%8.8.8.8%"')->fetchColumn();
Result::ok((int) $rawIpLeak === 0, 'no raw IP address is stored');

// Regression: counter files once landed in the project root because
// is_writable() lied about /data/ratelimit.
$buckets = glob(ROOT . '/data/ratelimit/collect-*.json') ?: [];
Result::ok($buckets !== [], 'rate limiter counters are stored in data/ratelimit', 'found=' . count($buckets));
$stray = glob(ROOT . '/collect-*.json') ?: [];
Result::ok($stray === [], 'no rate limiter file leaked into the project root', implode(',', array_slice($stray, 0, 3)));

/* ------------------------------------------------------------------ session */

echo "\n[12] Sign in\n";
// A brand new browser: the installer session is not an application session.
$client = Client::make();

$response = $client->request('GET', '/login');
Result::ok($response['status'] === 200, 'login page renders', 'status=' . $response['status']);
$token = csrf($response['body']);
Result::contains($response['body'], '/install/ folder is still', 'sign-in page warns about the installer folder');

// A visitor session is not a signed-in user.
$anon = Client::make();
$response = $anon->request('GET', '/api/stats.php');
Result::ok($response['status'] === 401, 'statistics API refuses anonymous callers', 'status=' . $response['status']);

$response = $client->request('POST', '/login', ['_token' => $token, 'email' => ADMIN_EMAIL, 'password' => 'wrong-password'], null, [], '203.0.113.7');
Result::ok($response['status'] === 302, 'wrong password is rejected with a redirect');
$response = $client->request('GET', '/login');
Result::contains($response['body'], 'Wrong email or password', 'wrong password shows an error');

$response = $client->request('GET', '/login');
$token = csrf($response['body']);
$response = $client->request('POST', '/login', ['_token' => $token, 'email' => ADMIN_EMAIL, 'password' => ADMIN_PASS]);
Result::ok($response['status'] === 302, 'correct password signs in', 'status=' . $response['status']);

echo "\n[13] Dashboard\n";
// The administrator's account locale came from the installer wizard (Russian),
// so switch to English to keep the assertions below locale independent.
$response = $client->request('GET', '/locale/en?next=/dashboard');
Result::ok($response['status'] === 302, 'administrator switched back to English');

$response = $client->request('GET', '/dashboard?site=' . $siteId);
Result::ok($response['status'] === 200, 'dashboard renders', 'status=' . $response['status']);
Result::contains($response['body'], 'Example Blog', 'dashboard shows the website name');
Result::contains($response['body'], 'pbDashboard', 'dashboard boots the Alpine component');
Result::contains($response['body'], 'pb-main-chart', 'chart canvas is present');
Result::contains($response['body'], 'pb-world-map', 'world map container is present');
Result::notContains($response['body'], 'Fatal error', 'no fatal error');
Result::notContains($response['body'], 'Warning:', 'no PHP warning');
Result::notContains($response['body'], 'Notice:', 'no PHP notice');
assert_components($response['body'], 'dashboard');

echo "\n[14] Statistics API\n";
$response = $client->request('GET', '/api/stats.php?site=' . $siteId . '&preset=today');
$json = json_decode($response['body'], true);
Result::ok(is_array($json) && ($json['ok'] ?? false) === true, 'stats payload returned', substr($response['body'], 0, 300));
Result::ok((int) ($json['summary']['pageviews'] ?? 0) === 5, 'summary counts five pageviews', 'got=' . ($json['summary']['pageviews'] ?? 'null'));
Result::ok(count($json['series']['labels'] ?? []) === 24, 'hourly series has 24 buckets');
Result::ok(($json['series']['granularity'] ?? '') === 'hour', 'single-day range switches to hourly');
Result::ok(($json['top']['path'][0]['value'] ?? '') === '/', 'top page is the home page', json_encode($json['top']['path'][0] ?? null));
Result::ok(count($json['top']['country'] ?? []) > 0, 'country breakdown is present');
Result::ok(isset($json['top']['country'][0]['flag']), 'countries carry a flag');
Result::ok(isset($json['top']['country'][0]['name']), 'countries carry a localised name');
Result::ok((int) ($json['realtime']['pageviews'] ?? -1) === 5, 'realtime counts the fresh hits', 'got=' . ($json['realtime']['pageviews'] ?? 'null'));
$referrerValues = array_column($json['top']['referrer'] ?? [], 'value');
Result::ok(in_array('google.com', $referrerValues, true), 'referrers recorded', json_encode($referrerValues));

$response = $client->request('GET', '/api/stats.php?site=' . $siteId . '&preset=last7');
$json = json_decode($response['body'], true);
Result::ok(count($json['series']['labels'] ?? []) === 7, 'seven day series has 7 buckets');
Result::ok(($json['series']['granularity'] ?? '') === 'day', 'multi-day range uses daily buckets');

$response = $client->request('GET', '/api/stats.php?site=' . $siteId . '&preset=last7&only=realtime&minutes=5');
$json = json_decode($response['body'], true);
Result::ok(isset($json['realtime']), 'lightweight realtime endpoint works');

/* --------------------------------------------------------------- management */

echo "\n[15] Website management\n";
$response = $client->request('GET', '/sites');
Result::ok($response['status'] === 200, 'sites page renders');
Result::contains($response['body'], 'data-website-id', 'tracker snippet is shown');
$token = csrf($response['body']);

$response = $client->request('POST', '/sites/save', [
    '_token'   => $token,
    'id'       => '0',
    'name'     => 'Second Site',
    'domain'   => 'second.example.org',
    'timezone' => 'UTC',
    'is_active' => '1',
    'exclude_bots' => '1',
]);
Result::ok($response['status'] === 302, 'second website saved');
$siteCount = (int) $pdo->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'sites')->fetchColumn();
Result::ok($siteCount === 2, "two websites exist (got $siteCount)");

$response = $client->request('GET', '/sites');
Result::contains($response['body'], 'Second Site', 'new website appears in the list');
assert_components($response['body'], 'sites');

echo "\n[16] Share links\n";
$response = $client->request('GET', '/sites?site=' . $siteId);
$token = csrf($response['body']);
$response = $client->request('POST', '/sites/share', ['_token' => $token, 'site_id' => (string) $siteId, 'label' => 'client']);
Result::ok($response['status'] === 302, 'share link created');

// The URL is shown in a dialog, not in a flash: a flash disappears on its own
// while the operator is still selecting the text.
$response = $client->request('GET', '/sites?site=' . $siteId);
$shareUrl = '';
if (preg_match('#(https?://[^\s"<]+/share/[a-f0-9]{16,})#', $response['body'], $m) === 1) {
    $shareUrl = $m[1];
}
Result::ok($shareUrl !== '', 'the raw share URL is shown once', substr($response['body'], 0, 200));
Result::contains($response['body'], 'role="dialog"', 'the link opens in a dialog');
Result::contains($response['body'], 'Public link created', 'the dialog is titled');
Result::contains($response['body'], 'x-ref="shareUrl"', 'the dialog offers the URL for copying');

// Reloading the page must not show it again.
$response = $client->request('GET', '/sites?site=' . $siteId);
Result::notContains($response['body'], (string) $shareUrl, 'the dialog does not come back on reload');

$hashed = $pdo->query('SELECT token_hash, token_hint FROM ' . DB_PREFIX . 'share_links ORDER BY id DESC LIMIT 1')->fetch();
$sharePath = (string) parse_url($shareUrl, PHP_URL_PATH);

$public = Client::make();
$response = $public->request('GET', $sharePath);
Result::ok($response['status'] === 200, 'shared dashboard is reachable without an account', 'status=' . $response['status'] . ' path=' . $sharePath);
Result::contains($response['body'], 'Public link', 'share page is marked as public');
Result::contains($response['body'], 'Example Blog', 'share page shows the website');
assert_components($response['body'], 'share page');
dump_page('share.html', $response['body']);

$response = $public->request('GET', '/api/stats.php?share=' . basename($sharePath) . '&preset=last7');
$json = json_decode($response['body'], true);
Result::ok(is_array($json) && ($json['ok'] ?? false) === true, 'share token authorises the statistics API', substr($response['body'], 0, 200));
Result::ok(($json['scope'] ?? '') === 'share', 'the API reports share scope');

$response = $public->request('GET', '/api/stats.php?share=deadbeefdeadbeefdeadbeefdeadbeef&preset=last7');
Result::ok($response['status'] === 403, 'a forged token is refused', 'status=' . $response['status']);

$response = $client->request('GET', '/sites?site=' . $siteId);
$token = csrf($response['body']);

// The form used to show a bare date input and nothing else, which made "what is
// this date?" a fair question. Both fields carry a visible label now, and the
// date explains itself.
Result::contains($response['body'], 'Valid until', 'the share form labels the date field');
Result::contains($response['body'], 'The date is optional', 'the share form explains what the date does');
Result::contains($response['body'], 'name="label"', 'the share form has the label field');

// A link without an expiry is not a blank cell: it means "never".
$response = $client->request('POST', '/sites/share', [
    '_token'  => $token,
    'site_id' => (string) $siteId,
    'label'   => 'no-expiry',
]);
$response = $client->request('GET', '/sites?site=' . $siteId);
$noExpiryRow = $pdo->query('SELECT expires_at FROM ' . DB_PREFIX . 'share_links WHERE label = "no-expiry"')->fetch();
Result::ok($noExpiryRow !== false && ($noExpiryRow['expires_at'] === null || $noExpiryRow['expires_at'] === ''), 'a link can be created without an expiry');
Result::ok(substr_count($response['body'], '—') > 0, 'a link without an expiry shows a dash, not a blank cell');

// An expiry in the past must stop the link from opening.
$past = gmdate('Y-m-d', time() - 86400 * 2);
$pdo->exec('DELETE FROM ' . DB_PREFIX . 'share_links WHERE label = "expired"');
$response = $client->request('POST', '/sites/share', [
    '_token'     => $token,
    'site_id'    => (string) $siteId,
    'label'      => 'expired',
    'expires_at' => $past,
]);
$response = $client->request('GET', '/sites?site=' . $siteId);
$expiredToken = '';
if (preg_match('#(https?://[^\s"<]+/share/[a-f0-9]{16,})#', $response['body'], $expiredMatch) === 1) {
    $expiredToken = (string) parse_url($expiredMatch[1], PHP_URL_PATH);
}
Result::ok($expiredToken !== '', 'a link with a past expiry is created and shown once', substr($response['body'], 0, 300));
$response = $public->request('GET', $expiredToken);
Result::contains($response['body'], 'Link not available', 'an expired link stops working');
$storedExpiry = (string) $pdo->query('SELECT expires_at FROM ' . DB_PREFIX . 'share_links WHERE label = "expired"')->fetchColumn();
Result::ok(substr($storedExpiry, 0, 10) === $past, 'the expiry is stored as the chosen date', $storedExpiry);
$pdo->exec('DELETE FROM ' . DB_PREFIX . 'share_links WHERE label IN ("no-expiry", "expired")');

$linkId = (int) $pdo->query('SELECT id FROM ' . DB_PREFIX . 'share_links ORDER BY id DESC LIMIT 1')->fetchColumn();
$response = $client->request('POST', '/sites/share/revoke', ['_token' => $token, 'site_id' => (string) $siteId, 'link_id' => (string) $linkId]);
Result::ok($response['status'] === 302, 'share link revoked');

// Revocation has to take effect immediately: the token is still in the wild.
$response = $public->request('GET', $sharePath);
Result::contains($response['body'], 'Link not available', 'a revoked link stops working');
$response = $public->request('GET', '/api/stats.php?share=' . basename($sharePath) . '&preset=last7');
Result::ok($response['status'] === 403, 'a revoked token no longer authorises the API', 'status=' . $response['status']);

// A revoked link can be deleted, otherwise the panel fills up with dead rows.
$response = $client->request('GET', '/sites?site=' . $siteId);
Result::contains($response['body'], 'sites/share/delete', 'a revoked link offers deletion');
$token = csrf($response['body']);
$response = $client->request('POST', '/sites/share/delete', [
    '_token'  => $token,
    'site_id' => (string) $siteId,
    'link_id' => (string) $linkId,
]);
Result::ok($response['status'] === 302, 'a revoked link can be deleted');
$remaining = (int) $pdo->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'share_links WHERE id = ' . $linkId)->fetchColumn();
Result::ok($remaining === 0, 'the deleted link is gone from the database');

echo "\n[17] Users\n";
$response = $client->request('GET', '/users');
Result::ok($response['status'] === 200, 'users page renders');
assert_components($response['body'], 'users');
$token = csrf($response['body']);

$response = $client->request('POST', '/users/save', [
    '_token'          => $token,
    'id'              => '0',
    'email'           => 'client@example.com',
    'name'            => 'Client User',
    'role'            => 'client',
    'locale'          => 'ru',
    'is_active'       => '1',
    'password'        => 'ClientPass123',
    'password_repeat' => 'ClientPass123',
    'sites'           => [(string) $siteId],
]);
Result::ok($response['status'] === 302, 'client account created', substr(strip_tags($response['body']), 0, 200));

$clientRow = $pdo->query('SELECT id, role FROM ' . DB_PREFIX . 'users WHERE email = "client@example.com"')->fetch();
Result::ok($clientRow !== false && $clientRow['role'] === 'client', 'client row stored');
$assigned = (int) $pdo->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'site_users WHERE user_id = ' . (int) $clientRow['id'])->fetchColumn();
Result::ok($assigned === 1, 'website assignment stored');

// The client must not see the other website.
$clientSession = Client::make();
$response = $clientSession->request('GET', '/login');
$token = csrf($response['body']);
$response = $clientSession->request('POST', '/login', ['_token' => $token, 'email' => 'client@example.com', 'password' => 'ClientPass123']);
Result::ok($response['status'] === 302, 'client can sign in');

$response = $clientSession->request('GET', '/api/stats.php?site=' . $siteId);
$json = json_decode($response['body'], true);
Result::ok(is_array($json) && ($json['ok'] ?? false) === true, 'client can read the assigned website');

$otherSite = (int) $pdo->query('SELECT id FROM ' . DB_PREFIX . 'sites WHERE name = "Second Site"')->fetchColumn();
$response = $clientSession->request('GET', '/api/stats.php?site=' . $otherSite);
Result::ok($response['status'] === 403, 'client cannot read a foreign website', 'status=' . $response['status']);

$response = $clientSession->request('GET', '/users');
Result::ok($response['status'] === 403, 'client cannot open user management', 'status=' . $response['status']);

// Read access is not the only thing worth refusing: a client must not be able to
// mint a public link for a website it cannot see, because that link would leak
// the statistics to anybody who receives it.
$response = $clientSession->request('GET', '/sites?site=' . $siteId);
$clientToken = csrf($response['body']);
$before = (int) $pdo->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'share_links WHERE site_id = ' . $otherSite)->fetchColumn();

$response = $clientSession->request('POST', '/sites/share', [
    '_token'  => $clientToken,
    'site_id' => (string) $otherSite,
    'label'   => 'foreign-attempt',
]);

$after = (int) $pdo->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'share_links WHERE site_id = ' . $otherSite)->fetchColumn();
Result::ok($after === $before, 'a client cannot create a share link for a foreign website', 'before=' . $before . ' after=' . $after);

// The same attempt against goals: only an administrator configures them.
$response = $clientSession->request('POST', '/sites/goals', [
    '_token'      => $clientToken,
    'site_id'     => (string) $siteId,
    'name'        => 'client-goal',
    'type'        => 'event',
    'match_value' => 'signup',
]);
$clientGoals = (int) $pdo->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'goals WHERE name = "client-goal"')->fetchColumn();
Result::ok($clientGoals === 0, 'a client cannot create a goal', 'goals=' . $clientGoals);

echo "\n[18] Settings and maintenance\n";
$response = $client->request('GET', '/settings');
Result::ok($response['status'] === 200, 'settings page renders');
assert_components($response['body'], 'settings');
Result::contains($response['body'], 'api/cron.php', 'cron URL is shown');
Result::contains($response['body'], 'IP geolocation', 'GeoIP panel is shown');
$token = csrf($response['body']);

$response = $client->request('POST', '/settings/save', [
    '_token'          => $token,
    'default_locale'  => 'en',
    'retention_days'  => '90',
    'session_timeout' => '30',
    'anonymize_ip'    => '1',
    'exclude_bots'    => '1',
]);
Result::ok($response['status'] === 302, 'settings saved');
$retention = $pdo->query('SELECT value FROM ' . DB_PREFIX . 'settings WHERE name = "retention_days"')->fetchColumn();
Result::ok((string) $retention === '90', 'retention setting persisted', 'got=' . var_export($retention, true));

$response = $client->request('POST', '/settings/password', [
    '_token'           => $token,
    'current_password' => 'wrong',
    'password'         => 'AnotherPass123',
    'password_repeat'  => 'AnotherPass123',
]);
$response = $client->request('GET', '/settings');
Result::contains($response['body'], 'current password is wrong', 'a wrong current password is refused');

$response = $client->request('GET', '/settings');
$token = csrf($response['body']);
$response = $client->request('POST', '/settings/maintenance', ['_token' => $token, 'action' => 'aggregate']);
Result::ok($response['status'] === 302, 'aggregation can be triggered manually');
$daily = (int) $pdo->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'stats_daily')->fetchColumn();
Result::ok($daily >= 1, 'daily rollup was written', "rows=$daily");
$dim = (int) $pdo->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'stats_dim')->fetchColumn();
Result::ok($dim >= 10, 'dimension rollup was written', "rows=$dim");

$row = $pdo->query('SELECT pageviews, visitors, sessions, bounces FROM ' . DB_PREFIX . 'stats_daily ORDER BY date DESC LIMIT 1')->fetch();
Result::ok((int) $row['pageviews'] === 5, 'rollup counts five pageviews', json_encode($row));
Result::ok((int) $row['visitors'] === 4, 'rollup counts four unique visitors', json_encode($row));
Result::ok((int) $row['sessions'] === 4, 'rollup counts four sessions', json_encode($row));
Result::ok((int) $row['bounces'] === 3, 'rollup counts three bounces', json_encode($row));

echo "\n[19] Cron endpoint\n";
$secret = (string) $pdo->query('SELECT value FROM ' . DB_PREFIX . 'settings WHERE name = "cron_secret"')->fetchColumn();
$response = $client->request('GET', '/api/cron.php?secret=' . $secret . '&task=all');
$json = json_decode($response['body'], true);
Result::ok(is_array($json) && ($json['ok'] ?? false) === true, 'cron runs with the right secret', substr($response['body'], 0, 300));
$response = $client->request('GET', '/api/cron.php?secret=wrong');
Result::ok($response['status'] === 403, 'cron refuses a wrong secret', 'status=' . $response['status']);

echo "\n[20] CSRF and access control\n";
$response = $client->request('POST', '/sites/save', ['name' => 'No token', 'id' => '0']);
Result::ok($response['status'] === 419, 'POST without a CSRF token is refused', 'status=' . $response['status']);

$response = $anon->request('GET', '/settings', [], null, ['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8']);
Result::ok($response['status'] === 302, 'anonymous access to settings redirects to the login page', 'status=' . $response['status']);

// Regression: a mis-detected base path used to produce "//" in every link.
$response = $client->request('GET', '/sites');
Result::notContains($response['body'], SITE . '//', 'generated URLs contain no double slash');

$response = $client->request('GET', '/does-not-exist');
Result::ok($response['status'] === 404, 'unknown route returns 404', 'status=' . $response['status']);

echo "\n[21] Localisation\n";
$response = $client->request('GET', '/locale/de?next=/dashboard');
Result::ok($response['status'] === 302, 'language switch redirects');
$response = $client->request('GET', '/dashboard?site=' . $siteId);
Result::contains($response['body'], 'Übersicht', 'German dashboard is rendered');

$deClient = Client::make();
$response = $deClient->request('GET', '/login', [], null, ['Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8']);
Result::contains($response['body'], 'Anmelden', 'a German browser gets a German sign-in page');

$frClient = Client::make();
$response = $frClient->request('GET', '/login', [], null, ['Accept-Language' => 'fr-FR,fr;q=0.9']);
Result::contains($response['body'], 'Connexion', 'a French browser gets a French sign-in page');

$zhClient = Client::make();
$response = $zhClient->request('GET', '/login', [], null, ['Accept-Language' => 'zh-CN,zh;q=0.9']);
Result::notContains($response['body'], 'Sign in', 'a Chinese browser does not get the English page');

echo "\n[22] Static assets\n";
foreach (['/assets/tracker.js', '/assets/css/app.css', '/assets/js/app.js', '/assets/vendor/chart.umd.min.js'] as $asset) {
    $response = $client->request('GET', $asset);
    Result::ok($response['status'] === 200 && strlen($response['body']) > 200, "asset served: $asset", 'status=' . $response['status'] . ' size=' . strlen($response['body']));
}
$tracker = (string) @file_get_contents(ROOT . '/assets/tracker.js');
Result::ok(strlen($tracker) < 2048, 'tracker.js is under 2 KB', strlen($tracker) . ' bytes');
// Alpine blanks the value of a checkbox when its model is undefined at startup:
// the expression is dotted (form.is_active), so Alpine turns undefined into ""
// and binds that to value. A checkbox with value="" posts an empty string, which
// post_bool() reads as false — the box looks ticked and is saved as off.
// Verified in a DOM; this guard keeps the initialisation honest.
$appJs = (string) @file_get_contents(ROOT . '/assets/js/app.js');
Result::ok(strpos($appJs, 'form: {},') === false, 'no component starts with an empty form object');
Result::ok(substr_count($appJs, 'form: blankForm(),') === 2, 'both modals start from the blank form, so their checkboxes keep value="1"');

/* ------------------------------------------------------------------- report */

echo "\n[23] New features: visitor log, IP storage, migrations, PWA, assets\n";

// --- visitor log widget ---------------------------------------------------
$response = $client->request('GET', '/dashboard?site=' . $siteId);
Result::contains($response['body'], 'data-widget="visitors"', 'dashboard has the visitor log widget');
Result::contains($response['body'], 'payload.visits.rows', 'the log is driven by the payload');
Result::contains($response['body'], 'toggleVisit', 'rows expand into the session pages');
Result::notContains($response['body'], 'widget.visitors.', 'no untranslated visitor-log key leaks into the page');

// --- compiled Tailwind, not the Play CDN ----------------------------------
Result::contains($response['body'], 'css/tailwind.css', 'pages load the compiled Tailwind CSS');
Result::notContains($response['body'], 'vendor/tailwind.js', 'the Play CDN runtime is gone');
Result::ok(!is_file(ROOT . '/assets/vendor/tailwind.js'), 'vendor/tailwind.js was deleted');
$compiledSize = is_file(ROOT . '/assets/css/tailwind.css') ? (int) filesize(ROOT . '/assets/css/tailwind.css') : 0;
Result::ok($compiledSize > 5000 && $compiledSize < 200000, 'compiled Tailwind CSS is committed and sane', $compiledSize . ' bytes');

// --- SVG flags ------------------------------------------------------------
Result::contains($response['body'], 'vendor/flags/', 'flags are rendered as SVG images');
$flagResponse = $client->request('GET', '/assets/vendor/flags/ru.svg');
Result::ok($flagResponse['status'] === 200 && strpos($flagResponse['body'], '<svg') !== false, 'flag files are served');
$flagCount = count(glob(ROOT . '/assets/vendor/flags/*.svg') ?: []);
Result::ok($flagCount > 200, 'the flag set is vendored', 'files=' . $flagCount);

// --- footer ---------------------------------------------------------------
Result::contains($response['body'], 'Open Source', 'the footer links the source');
Result::contains($response['body'], 'viewBox="0 0 16 16"', 'the footer carries the GitHub mark');
Result::contains($response['body'], 'flex min-h-screen flex-col', 'the layout is a full-height column (sticky footer)');
Result::contains($response['body'], 'mt-auto border-t', 'the footer is pushed to the bottom');

// --- PWA ------------------------------------------------------------------
$manifest = $client->request('GET', '/manifest.php');
Result::ok($manifest['status'] === 200, 'the manifest is served', 'status=' . $manifest['status']);
$manifestData = json_decode($manifest['body'], true);
Result::ok(is_array($manifestData), 'the manifest is valid JSON', substr($manifest['body'], 0, 200));
Result::ok(($manifestData['display'] ?? '') === 'standalone', 'the manifest requests standalone display');
Result::ok(count($manifestData['icons'] ?? []) >= 3, 'the manifest declares icons');
Result::ok(strpos((string) ($manifestData['start_url'] ?? ''), 'dashboard') !== false, 'the app starts on the dashboard');

$serviceWorker = $client->request('GET', '/sw.js');
Result::ok($serviceWorker['status'] === 200, 'sw.js is served', 'status=' . $serviceWorker['status']);
Result::contains($serviceWorker['body'], 'addEventListener', 'the service worker registers handlers');
Result::contains($serviceWorker['body'], "indexOf('/api/') !== -1", 'the service worker never caches the API');

foreach (['icon-192.png', 'icon-512.png', 'maskable-512.png', 'apple-touch-icon.png'] as $icon) {
    $bytes = @file_get_contents(ROOT . '/assets/img/' . $icon);
    Result::ok(is_string($bytes) && strncmp($bytes, "\x89PNG", 4) === 0, 'PWA icon is a real PNG: ' . $icon);
}

// --- IP storage (opt-in) --------------------------------------------------
$response = $client->request('GET', '/settings');
Result::contains($response['body'], 'name="store_ip"', 'the settings page offers IP storage');
$token = csrf($response['body']);

$settingsForm = [
    '_token'          => $token,
    'default_locale'  => 'en',
    'retention_days'  => '90',
    'session_timeout' => '30',
    'anonymize_ip'    => '1',
    'exclude_bots'    => '1',
];

$response = $client->request('POST', '/settings/save', $settingsForm + ['store_ip' => '1']);
Result::ok($response['status'] === 302, 'IP storage enabled');

$markerIp = '198.51.100.77';
$response = $client->request('POST', '/api/collect.php?json=1', [], (string) json_encode([
    'w' => $publicId, 'p' => '/ip-test', 'r' => '', 't' => 'IP test',
    's' => '1920x1080', 'l' => 'en-US', 'dnt' => '0',
]), ['Origin' => 'https://example.com'], $markerIp);
$json = json_decode($response['body'], true);
Result::ok(is_array($json) && ($json['status'] ?? '') === 'ok', 'a hit was recorded with IP storage on');

$storedIp = (string) $pdo->query('SELECT ip FROM ' . DB_PREFIX . 'visits WHERE ip = ' . $pdo->quote($markerIp) . ' LIMIT 1')->fetchColumn();
Result::ok($storedIp === $markerIp, 'the IP address is stored when enabled', 'got=' . var_export($storedIp, true));

$response = $client->request('GET', '/dashboard?site=' . $siteId);
Result::contains($response['body'], $markerIp, 'the visitor log shows the stored IP');

$response = $client->request('GET', '/settings');
$token = csrf($response['body']);
$response = $client->request('POST', '/settings/save', ['_token' => $token] + $settingsForm);
Result::ok($response['status'] === 302, 'IP storage disabled again');
$auditRows = (int) $pdo->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'audit_log WHERE action LIKE "store_ip%"')->fetchColumn();
Result::ok($auditRows >= 2, 'turning IP storage on and off is audited', 'rows=' . $auditRows);

// --- schema migrations ----------------------------------------------------
$migrationFile = ROOT . '/migrations/zz_test_marker.sql';

// Start from a clean slate: a crashed earlier run may have left the file or its
// bookkeeping row behind, and a file that exists during installation is
// baselined as "already applied" - which would make this block test nothing.
@unlink($migrationFile);
$pdo->exec('DELETE FROM `' . DB_PREFIX . 'migrations` WHERE filename = "zz_test_marker.sql"');
$pdo->exec('DROP TABLE IF EXISTS `' . DB_PREFIX . 'zz_test_marker`');

file_put_contents(
    $migrationFile,
    "CREATE TABLE IF NOT EXISTS `{{PREFIX}}zz_test_marker` (\n"
    . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
    . "  PRIMARY KEY (`id`)\n"
    . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n"
);

$response = $client->request('GET', '/settings');
Result::contains($response['body'], 'zz_test_marker.sql', 'a pending migration is listed in the settings');
$token = csrf($response['body']);

$response = $client->request('GET', '/dashboard?site=' . $siteId);
Result::contains($response['body'], 'value="migrate"', 'the dashboard offers to apply the update');

$response = $client->request('POST', '/settings/maintenance', ['_token' => $token, 'action' => 'migrate']);
Result::ok($response['status'] === 302, 'the migration action ran');
$appliedTables = $pdo->query('SHOW TABLES LIKE "' . DB_PREFIX . 'zz_test_marker"')->fetchAll();
Result::ok($appliedTables !== [], 'the migration created its table');
$recorded = (int) $pdo->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'migrations WHERE filename = "zz_test_marker.sql"')->fetchColumn();
Result::ok($recorded === 1, 'the applied migration is recorded');

$response = $client->request('GET', '/settings');
Result::notContains($response['body'], 'zz_test_marker.sql', 'the applied migration is no longer pending');

// A fresh installation must baseline the shipped migrations, otherwise the
// first admin visit would offer to re-apply them.
$baselined = (int) $pdo->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'migrations')->fetchColumn();
Result::ok($baselined >= 2, 'the installer baselines the shipped migrations', 'rows=' . $baselined);

@unlink($migrationFile);
$pdo->exec('DROP TABLE IF EXISTS `' . DB_PREFIX . 'zz_test_marker`');
$pdo->exec('DELETE FROM `' . DB_PREFIX . 'migrations` WHERE filename = "zz_test_marker.sql"');

// --- GeoIP base ships with the project ------------------------------------
Result::ok(is_file(ROOT . '/data/geoip/dbip-country-lite.csv.gz'), 'the IP database ships with the project');

echo "\n[24] Goals and events\n";

// Back to English so the assertions below are locale independent.
$response = $client->request('GET', '/locale/en?next=/sites');
Result::ok($response['status'] === 302, 'administrator switched back to English');

$response = $client->request('GET', '/sites?site=' . $siteId);
Result::contains($response['body'], 'name="match_value"', 'the websites page offers goal creation');
$token = csrf($response['body']);

// --- the websites page stays a list --------------------------------------
// Each site is one compact row with quick actions; the tracking code, the
// public links and the goals live in collapsed panels. Without this, ten sites
// turn the page into a wall of forms.
Result::contains($response['body'], 'x-data="{ code: false, details: false }"', 'site cards open collapsed');
Result::contains($response['body'], 'Links &amp; goals', 'the details panel has a toggle');
Result::contains($response['body'], 'Check the code', 'the code panel offers a tracker check');
Result::contains($response['body'], 'pbTrackerCheck(', 'the check button is wired to its component');
Result::ok(
    substr_count($response['body'], 'x-show="details"') >= 1,
    'the shared links and goals are inside the collapsed panel'
);

// --- tracker check -------------------------------------------------------
$response = $client->request('POST', '/sites/check-tracker', [
    '_token'  => $token,
    'site_id' => (string) $siteId,
]);
$json = json_decode($response['body'], true);
Result::ok(is_array($json) && ($json['ok'] ?? false) === true, 'the tracker check reports the hits it has', $response['body']);
Result::ok(!empty($json['last_hit']), 'the tracker check returns the last hit');
Result::contains((string) ($json['message'] ?? ''), 'Working', 'the tracker check returns a readable message');

$response = $client->request('POST', '/sites/check-tracker', [
    '_token'  => $token,
    'site_id' => '999999',
]);
$json = json_decode($response['body'], true);
Result::ok($response['status'] === 404, 'the tracker check refuses an unknown site', 'status=' . $response['status']);
Result::ok(is_array($json) && ($json['ok'] ?? true) === false, 'the refusal is a negative JSON answer');

// --- page goal ------------------------------------------------------------
$goalUrl = '/sites/goals';
$response = $client->request('POST', $goalUrl, [
    '_token'      => $token,
    'site_id'     => (string) $siteId,
    'name'        => 'Thanks page',
    'type'        => 'page',
    'match_value' => '/thanks',
]);
Result::ok($response['status'] === 302, 'a page goal was created');

$pageGoalId = (int) $pdo->query('SELECT id FROM ' . DB_PREFIX . 'goals WHERE site_id = ' . $siteId . ' AND name = "Thanks page"')->fetchColumn();
Result::ok($pageGoalId > 0, 'the page goal is stored');
$storedGoal = $pdo->query('SELECT type, match_value FROM ' . DB_PREFIX . 'goals WHERE id = ' . $pageGoalId)->fetch();
Result::ok($storedGoal !== false && $storedGoal['type'] === 'page' && $storedGoal['match_value'] === '/thanks', 'the goal type and target round-trip');

// A duplicate target must be refused.
$response = $client->request('POST', $goalUrl, [
    '_token'      => $token,
    'site_id'     => (string) $siteId,
    'name'        => 'Duplicate',
    'type'        => 'page',
    'match_value' => '/thanks',
]);
$response = $client->request('GET', '/sites?site=' . $siteId);
Result::contains($response['body'], 'already exists', 'a duplicate goal is refused');
$duplicateCount = (int) $pdo->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'goals WHERE site_id = ' . $siteId)->fetchColumn();
Result::ok($duplicateCount === 1, 'the duplicate was not stored', 'goals=' . $duplicateCount);

// --- a hit on the goal page ----------------------------------------------
$response = $client->request('POST', '/api/collect.php?json=1', [], (string) json_encode([
    'w' => $publicId, 'p' => '/thanks', 'r' => '', 't' => 'Thank you',
    's' => '1920x1080', 'l' => 'en-US', 'dnt' => '0',
]), ['Origin' => 'https://example.com'], '198.51.100.60');
$json = json_decode($response['body'], true);
Result::ok(is_array($json) && ($json['status'] ?? '') === 'ok', 'a hit on the goal page was accepted');

$pageviewsBeforeEvent = (int) $pdo->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'pageviews')->fetchColumn();

// --- event goal -----------------------------------------------------------
$response = $client->request('POST', '/api/collect.php?json=1', [], (string) json_encode([
    'w' => $publicId, 'p' => '/', 'e' => 'signup', 'r' => '', 't' => 'Home',
    's' => '1920x1080', 'l' => 'en-US', 'dnt' => '0',
]), ['Origin' => 'https://example.com'], '198.51.100.61');
$json = json_decode($response['body'], true);
Result::ok(is_array($json) && ($json['status'] ?? '') === 'ok' && ($json['event'] ?? '') === 'signup', 'a custom event was accepted', $response['body']);

$eventRows = (int) $pdo->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'events WHERE site_id = ' . $siteId . ' AND name = "signup"')->fetchColumn();
Result::ok($eventRows === 1, 'the event is stored', 'rows=' . $eventRows);

$pageviewsAfterEvent = (int) $pdo->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'pageviews')->fetchColumn();
Result::ok($pageviewsAfterEvent === $pageviewsBeforeEvent, 'an event does not count as a page view');

$response = $client->request('POST', $goalUrl, [
    '_token'      => $token,
    'site_id'     => (string) $siteId,
    'name'        => 'Signup',
    'type'        => 'event',
    'match_value' => 'signup',
]);
Result::ok($response['status'] === 302, 'an event goal was created');
$eventGoalId = (int) $pdo->query('SELECT id FROM ' . DB_PREFIX . 'goals WHERE site_id = ' . $siteId . ' AND name = "Signup"')->fetchColumn();
Result::ok($eventGoalId > 0, 'the event goal is stored');

// --- aggregation and the API ---------------------------------------------
$response = $client->request('GET', '/settings');
$token = csrf($response['body']);
$response = $client->request('POST', '/settings/maintenance', ['_token' => $token, 'action' => 'aggregate']);
Result::ok($response['status'] === 302, 'aggregation ran for the goals');

$goalStats = $pdo->query('SELECT goal_id, conversions, visitors FROM ' . DB_PREFIX . 'goal_stats WHERE site_id = ' . $siteId . ' ORDER BY goal_id')->fetchAll();
Result::ok(count($goalStats) >= 2, 'both goals have a rollup row', 'rows=' . count($goalStats));

$response = $client->request('GET', '/api/stats.php?site=' . $siteId . '&preset=today');
$json = json_decode($response['body'], true);
Result::ok(is_array($json) && ($json['goals']['has_goals'] ?? false) === true, 'the payload announces goals');

$goalRows = [];
foreach (($json['goals']['rows'] ?? []) as $row) {
    $goalRows[(string) $row['name']] = $row;
}
Result::ok(isset($goalRows['Thanks page']), 'the page goal is in the payload');
Result::ok((int) ($goalRows['Thanks page']['visitors'] ?? 0) >= 1, 'the page goal counted its visitor');
Result::ok((int) ($goalRows['Thanks page']['conversions'] ?? 0) >= 1, 'the page goal counted its conversion');
Result::ok(isset($goalRows['Signup']), 'the event goal is in the payload');
Result::ok((int) ($goalRows['Signup']['visitors'] ?? 0) >= 1, 'the event goal counted its visitor');
Result::ok((int) ($goalRows['Signup']['conversions'] ?? 0) >= 1, 'the event goal counted its conversion');

$totalGoalVisitors = (int) ($json['goals']['visitors'] ?? 0);
$expectedRate = $totalGoalVisitors > 0 ? (int) ($goalRows['Thanks page']['visitors'] ?? 0) / $totalGoalVisitors : 0.0;
Result::ok(abs((float) ($goalRows['Thanks page']['rate'] ?? -1) - $expectedRate) < 0.0001, 'the conversion rate is visitors over all visitors');

// The dashboard itself must render the widget with the goals in it.
$response = $client->request('GET', '/dashboard?site=' . $siteId);
Result::contains($response['body'], 'data-widget="goals"', 'the dashboard has the goals widget');
Result::contains($response['body'], 'Thanks page', 'the goals widget shows the goal name');
Result::contains($response['body'], '/thanks', 'the goals widget shows the target');
assert_components($response['body'], 'dashboard with goals');

// --- disable and delete ---------------------------------------------------
$response = $client->request('POST', '/sites/goals/update', [
    '_token'    => $token,
    'site_id'   => (string) $siteId,
    'goal_id'   => (string) $pageGoalId,
    'name'      => 'Thanks page',
    'is_active' => '0',
]);
Result::ok($response['status'] === 302, 'the page goal was disabled');
$response = $client->request('GET', '/api/stats.php?site=' . $siteId . '&preset=today');
$json = json_decode($response['body'], true);
$goalNames = array_column($json['goals']['rows'] ?? [], 'name');
Result::ok(!in_array('Thanks page', $goalNames, true), 'a disabled goal drops out of the report');
Result::ok(in_array('Signup', $goalNames, true), 'the other goal is still reported');

$response = $client->request('POST', '/sites/goals/delete', [
    '_token'  => $token,
    'site_id' => (string) $siteId,
    'goal_id' => (string) $eventGoalId,
]);
Result::ok($response['status'] === 302, 'a goal can be deleted');
$remaining = (int) $pdo->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'goals WHERE site_id = ' . $siteId)->fetchColumn();
Result::ok($remaining === 1, 'only the disabled goal remains', 'goals=' . $remaining);
$orphanStats = (int) $pdo->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'goal_stats WHERE goal_id = ' . $eventGoalId)->fetchColumn();
Result::ok($orphanStats === 0, 'deleting a goal removes its statistics');

// Leave the demo database tidy.
$pdo->exec('DELETE FROM ' . DB_PREFIX . 'goal_stats WHERE site_id = ' . $siteId);
$pdo->exec('DELETE FROM ' . DB_PREFIX . 'goals WHERE site_id = ' . $siteId);

// With no goals left, the widget explains the feature instead of showing a
// blank table.
$response = $client->request('GET', '/dashboard?site=' . $siteId);
Result::contains($response['body'], 'No goals yet', 'the goals widget has an empty state');

// --- tracker API ----------------------------------------------------------
$tracker = (string) @file_get_contents(ROOT . '/assets/tracker.js');
Result::ok(strlen($tracker) < 2048, 'tracker.js is still under 2 KB with the event API', strlen($tracker) . ' bytes');
Result::contains($tracker, 'plotbird', 'the tracker exposes the plotbird API');
Result::contains($tracker, '.track=', 'the tracker exposes plotbird.track');
$trackerSrc = (string) @file_get_contents(ROOT . '/assets/tracker.src.js');
Result::contains($trackerSrc, "plotbird.track('signup')", 'the readable source documents the event call');

echo "\n[25] Error logs\n";
// Anything in an error-*.log is a problem: PHP diagnostics land there through
// the bootstrap error handler, and application failures are logged explicitly.
$logProblems = [];
foreach (glob(ROOT . '/data/logs/error-*.log') ?: [] as $file) {
    foreach (preg_split('/\r\n|\n/', (string) file_get_contents($file)) ?: [] as $line) {
        if (trim($line) !== '') {
            $logProblems[] = basename($file) . ': ' . trim($line);
        }
    }
}
Result::ok($logProblems === [], 'the application error log is empty', implode(' | ', array_slice($logProblems, 0, 5)));

$phpLog = ROOT . '/.dev/logs/php-error.log';
if (is_file($phpLog)) {
    $content = (string) file_get_contents($phpLog);
    $lines = array_filter(preg_split('/\r\n|\n/', $content) ?: [], static fn ($line) => trim($line) !== '');
    Result::ok($lines === [], 'php-error.log is empty', implode(' | ', array_slice(array_values($lines), 0, 5)));
}

echo "\n====================================\n";
echo 'PASSED: ' . Result::$pass . '   FAILED: ' . Result::$fail . "\n";
if (Result::$fail > 0) {
    echo "\nFailures:\n";
    foreach (Result::$failures as $failure) {
        echo '  - ' . $failure . "\n";
    }
}
exit(Result::$fail === 0 ? 0 : 1);
