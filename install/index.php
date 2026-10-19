<?php
/**
 * PlotBird Analytics - installation wizard.
 *
 * Runs before /config/config.php exists. It keeps its whole state in the
 * session, so an interrupted installation can be resumed at any step without
 * re-typing the database credentials.
 *
 * Steps
 *   1 language   2 requirements   3 database   4 schema + config (+ GeoIP base)
 *   5 super admin   6 first site + tracker code   7 finish
 *
 * @package PlotBird
 */

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

use PlotBird\Config;
use PlotBird\Database;
use PlotBird\GeoIP;
use PlotBird\I18n;
use PlotBird\Security;
use PlotBird\Settings;
use PlotBird\Site;
use PlotBird\Stats;
use PlotBird\User;
use PlotBird\View;

Security::startSession();

/** Hard requirement: the schema and the code both assume these. */
const REQUIRED_PHP = '8.0.0';

/**
 * Write a JSON answer for an AJAX call and stop.
 *
 * @param array<string,mixed> $payload
 */
function install_json(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Render one wizard page.
 *
 * @param array<string,mixed> $data
 */
function install_view(string $template, array $data = []): void
{
    $state = install_state();
    View::render('install/' . $template, array_merge([
        'state'        => $state,
        'locales'      => I18n::available(),
        'locale'       => I18n::current(),
        'csrf'         => Security::csrfToken(),
        'app_version'  => PB_VERSION,
        'base_path'    => Security::detectBasePath(),
        'step'         => (int) ($data['step'] ?? 1),
        'required_php' => REQUIRED_PHP,
    ], $data), 'install/layout');
}

/**
 * Session state of the wizard.
 *
 * @return array<string,mixed>
 */
function install_state(): array
{
    if (!isset($_SESSION['install']) || !is_array($_SESSION['install'])) {
        $_SESSION['install'] = [
            'locale'   => I18n::negotiate((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')),
            'database' => [
                'host'     => 'localhost',
                'port'     => 3306,
                'name'     => '',
                'user'     => '',
                'password' => '',
                'prefix'   => 'pb_',
            ],
            'admin'    => ['email' => '', 'name' => ''],
            'site_id'  => 0,
            'pretty_urls' => true,
        ];
    }
    return $_SESSION['install'];
}

/**
 * @param array<string,mixed> $values
 */
function install_state_save(array $values): void
{
    $_SESSION['install'] = array_replace_recursive(install_state(), $values);
}

/**
 * The environment checklist rendered on step 2.
 *
 * @return list<array{label:string,ok:bool,required:bool,detail:string}>
 */
function install_requirements(): array
{
    $checks = [];

    $phpOk = version_compare(PHP_VERSION, REQUIRED_PHP, '>=');
    $checks[] = [
        'label'    => 'PHP >= ' . REQUIRED_PHP,
        'ok'       => $phpOk,
        'required' => true,
        'detail'   => PHP_VERSION,
    ];

    foreach (['pdo_mysql' => true, 'mbstring' => true, 'json' => true, 'curl' => false, 'zlib' => true, 'openssl' => false] as $extension => $required) {
        $loaded = extension_loaded($extension);
        $checks[] = [
            'label'    => 'ext/' . $extension,
            'ok'       => $loaded,
            'required' => (bool) $required,
            'detail'   => $loaded ? 'OK' : I18n::translate('install.requirement_missing'),
        ];
    }

    // Directories the application must be able to write to.
    $writable = [
        'config'          => PB_ROOT . '/config',
        'data'            => PB_ROOT . '/data',
        'data/logs'       => PB_ROOT . '/data/logs',
        'data/cache'      => PB_ROOT . '/data/cache',
        'data/ratelimit'  => PB_ROOT . '/data/ratelimit',
        'data/geoip'      => PB_ROOT . '/data/geoip',
        'assets'          => PB_ROOT . '/assets',
    ];

    foreach ($writable as $label => $path) {
        // A real write probe: is_writable() reports ACLs, which are misleading
        // on some hostings (and inside containers).
        $ok = pb_dir_writable($path);
        $checks[] = [
            'label'    => $label . '/',
            'ok'       => $ok,
            'required' => $label !== 'assets',
            'detail'   => $ok ? I18n::translate('install.requirement_writable') : I18n::translate('install.requirement_not_writable'),
        ];
    }

    // Nice to have, not fatal.
    $installWritable = pb_dir_writable(PB_ROOT . '/install');
    $checks[] = [
        'label'    => 'install/ ' . I18n::translate('install.requirement_removable'),
        'ok'       => $installWritable,
        'required' => false,
        'detail'   => $installWritable ? 'OK' : I18n::translate('install.requirement_remove_manually'),
    ];

    $checks[] = [
        'label'    => 'allow_url_fopen / curl',
        'ok'       => (bool) ini_get('allow_url_fopen') || function_exists('curl_init'),
        'required' => false,
        'detail'   => I18n::translate('install.requirement_geoip_download'),
    ];

    return $checks;
}

/**
 * Whether every required check passes.
 *
 * @param list<array{label:string,ok:bool,required:bool,detail:string}> $checks
 */
function install_requirements_met(array $checks): bool
{
    foreach ($checks as $check) {
        if ($check['required'] && !$check['ok']) {
            return false;
        }
    }
    return true;
}

// ---------------------------------------------------------------------------
// Guard: refuse to run a second time over a finished installation.
// ---------------------------------------------------------------------------
$alreadyInstalled = Config::isInstalled();
$adminCount = 0;

if ($alreadyInstalled) {
    try {
        $adminCount = User::countSuperAdmins();
    } catch (\Throwable $e) {
        // A configuration file that cannot connect is a broken deployment, not
        // a fresh install: never silently reinstall over it.
        $adminCount = -1;
    }
}

if ($alreadyInstalled && ($adminCount !== 0 || Security::isInstallLocked())) {
    // A browser that already walked through this wizard may still reach the
    // final screen (it is the one that just wrote the lock).
    $wizardActive = !empty($_SESSION['install']['admin_id']);

    if (!$wizardActive) {
        install_view('locked', [
            'step'      => 0,
            'locked'    => true,
            'dashboard' => root_url(''),
        ]);
        exit;
    }
}

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

if ($action !== '') {
    switch ($action) {
        // ---- Step 1: language --------------------------------------------
        case 'set-locale':
            $locale = (string) ($_POST['locale'] ?? $_GET['locale'] ?? '');
            if (I18n::isAvailable($locale)) {
                I18n::set($locale);
            }
            install_state_save(['locale' => I18n::current()]);
            // Stay on whichever step the visitor was looking at.
            $target = max(1, min(7, (int) ($_POST['step'] ?? $_GET['step'] ?? 1)));
            redirect(root_url('install/') . '?step=' . $target);

        case 'save-locale':
            Security::requireCsrf();
            $locale = post('locale');
            if (I18n::isAvailable($locale)) {
                I18n::set($locale);
            }
            install_state_save(['locale' => I18n::current()]);
            redirect(root_url('install/') . '?step=2');

        // ---- Step 3: database --------------------------------------------
        case 'test-db':
            Security::requireCsrf();
            $candidate = [
                'host'     => post('host', 'localhost'),
                'port'     => post_int('port', 3306),
                'name'     => post('name'),
                'user'     => post('user'),
                'password' => (string) ($_POST['password'] ?? ''),
                'prefix'   => post('prefix', 'pb_'),
                'charset'  => 'utf8mb4',
            ];

            if ($candidate['name'] === '') {
                install_json(['ok' => false, 'error' => 'empty_database']);
            }

            try {
                $connection = Database::connect($candidate);
                $version = $connection->serverVersion();
                $tables = (int) $connection->value(
                    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :db',
                    [':db' => $candidate['name']],
                    0
                );
                install_json([
                    'ok'      => true,
                    'version' => $version,
                    'tables'  => $tables,
                    'message' => I18n::translate('install.db_connected', ['version' => $version]),
                ]);
            } catch (\Throwable $e) {
                install_json([
                    'ok'      => false,
                    'error'   => 'connection',
                    'message' => $e->getMessage(),
                ]);
            }

        case 'save-db':
            Security::requireCsrf();
            $database = [
                'host'     => post('host', 'localhost'),
                'port'     => post_int('port', 3306),
                'name'     => post('name'),
                'user'     => post('user'),
                'password' => (string) ($_POST['password'] ?? ''),
                'prefix'   => post('prefix', 'pb_'),
                'charset'  => 'utf8mb4',
            ];

            if ($database['name'] === '' || $database['user'] === '') {
                flash('error', I18n::translate('install.db_error_required'));
                redirect(root_url('install/') . '?step=3');
            }
            if (preg_match('/^[a-z0-9_]{1,32}$/i', $database['prefix']) !== 1) {
                flash('error', I18n::translate('install.db_error_prefix'));
                redirect(root_url('install/') . '?step=3');
            }

            try {
                Database::connect($database);
            } catch (\Throwable $e) {
                flash('error', I18n::translate('install.db_error_connect') . ' ' . $e->getMessage());
                redirect(root_url('install/') . '?step=3');
            }

            install_state_save([
                'database'    => $database,
                'pretty_urls' => post_bool('pretty_urls'),
            ]);
            redirect(root_url('install/') . '?step=4');

        // ---- Step 4: schema + configuration -------------------------------
        case 'install-schema':
            Security::requireCsrf();
            $state = install_state();

            if (($state['database']['name'] ?? '') === '') {
                flash('error', I18n::translate('install.db_error_required'));
                redirect(root_url('install/') . '?step=3');
            }

            try {
                $db = Database::connect($state['database']);
            } catch (\Throwable $e) {
                flash('error', I18n::translate('install.db_error_connect') . ' ' . $e->getMessage());
                redirect(root_url('install/') . '?step=3');
            }

            // 1. Schema.
            $schemaFile = PB_ROOT . '/install/schema.sql';
            if (!is_file($schemaFile)) {
                flash('error', I18n::translate('install.schema_missing'));
                redirect(root_url('install/') . '?step=4');
            }
            $schema = (string) file_get_contents($schemaFile);
            $schema = str_replace('{{PREFIX}}', (string) $state['database']['prefix'], $schema);

            try {
                $db->executeScript($schema);
            } catch (\Throwable $e) {
                pb_log('schema import failed: ' . $e->getMessage(), 'error');
                flash('error', I18n::translate('install.schema_error') . ' ' . $e->getMessage());
                redirect(root_url('install/') . '?step=4');
            }

            // 2. Directories the runtime needs.
            foreach (['data', 'data/logs', 'data/cache', 'data/ratelimit', 'data/geoip', 'config'] as $dir) {
                $path = PB_ROOT . '/' . $dir;
                if (!is_dir($path)) {
                    @mkdir($path, 0775, true);
                }
            }

            // 3. config.php.
            $configuration = [
                'app' => [
                    'name'          => 'PlotBird Analytics',
                    'url'           => Security::detectedBaseUrl(),
                    'base_path'     => Security::detectBasePath(),
                    'timezone'      => 'UTC',
                    'debug'         => false,
                    'default_locale' => (string) ($state['locale'] ?? 'en'),
                    'pretty_urls'   => (bool) ($state['pretty_urls'] ?? true),
                    'asset_version' => PB_VERSION,
                    // Shown in the footer; point it at your own fork if you
                    // publish one.
                    'repository'    => 'https://github.com/plotbird/analytics',
                ],
                'db' => [
                    'host'     => (string) $state['database']['host'],
                    'port'     => (int) $state['database']['port'],
                    'name'     => (string) $state['database']['name'],
                    'user'     => (string) $state['database']['user'],
                    'password' => (string) $state['database']['password'],
                    'charset'  => 'utf8mb4',
                    'prefix'   => (string) $state['database']['prefix'],
                ],
                'security' => [
                    // The salt makes the visitor hashes unlinkable without the
                    // configuration file. Rotating it only costs accuracy of
                    // the current day's unique counts.
                    'salt'             => random_hex(32),
                    'cron_secret'      => random_hex(24),
                    'session_name'     => 'pb_session',
                    'trusted_proxies'  => '',
                    'hsts'             => false,
                ],
                'tracker' => [
                    'rate_limit' => 120,
                ],
            ];

            $configPath = Config::path();
            $written = @file_put_contents($configPath, Config::export($configuration), LOCK_EX);
            if ($written === false) {
                flash('error', I18n::translate('install.config_not_writable'));
                redirect(root_url('install/') . '?step=4');
            }
            @chmod($configPath, 0640);

            // 4. Make the fresh configuration visible to this request.
            Config::merge($configuration);

            // 5. Baseline settings rows.
            Settings::flush();
            Settings::set('schema_version', PB_SCHEMA_VERSION);
            Settings::set('default_locale', (string) ($state['locale'] ?? 'en'));
            Settings::set('cron_secret', (string) $configuration['security']['cron_secret']);

            // 6. schema.sql always describes the newest schema, so every
            //    migration on disk is recorded as already applied. Without this
            //    a fresh installation would try to re-apply them on first run.
            \PlotBird\Migrator::baseline();

            redirect(root_url('install/') . '?step=4&stage=done');

        // ---- Step 4b: GeoIP base (AJAX, chunked) ---------------------------
        // One endpoint, two phases - the same contract the settings page uses,
        // so the browser side component is shared.
        case 'geoip':
            Security::requireCsrf();
            $phase = post('phase', post('action', 'prepare'));

            if ($phase === 'prepare') {
                $prepared = GeoIP::prepareCsv();
                if (!$prepared['ok']) {
                    install_json(['ok' => false, 'error' => $prepared['error']]);
                }
                install_json(['ok' => true, 'bytes' => $prepared['bytes'], 'offset' => 0, 'percent' => 0]);
            }

            if ($phase === 'chunk') {
                $offset = max(0, (int) ($_POST['offset'] ?? 0));
                $result = GeoIP::importChunk(GeoIP::csvFile(), $offset);
                if (!$result['ok']) {
                    install_json(['ok' => false, 'error' => $result['error']]);
                }
                $percent = $result['bytes'] > 0 ? min(100, (int) round($result['offset'] / $result['bytes'] * 100)) : 100;
                install_json([
                    'ok'      => true,
                    'done'    => $result['done'],
                    'offset'  => $result['offset'],
                    'percent' => $percent,
                    'count'   => $result['done'] ? GeoIP::count() : 0,
                ]);
            }

            install_json(['ok' => false, 'error' => 'unknown_phase'], 400);

        // ---- Step 5: super administrator ----------------------------------
        case 'save-admin':
            Security::requireCsrf();
            $email    = mb_strtolower(post('email'), 'UTF-8');
            $name     = post('name');
            $password = (string) ($_POST['password'] ?? '');
            $repeat   = (string) ($_POST['password_repeat'] ?? '');

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                flash('error', I18n::translate('users.error_email'));
                redirect(root_url('install/') . '?step=5');
            }
            $problem = \PlotBird\Auth::validatePassword($password, $repeat);
            if ($problem !== '') {
                flash('error', I18n::translate('users.error_password_' . $problem));
                redirect(root_url('install/') . '?step=5');
            }

            try {
                $existing = User::findWithSecret($email);
                if ($existing !== null) {
                    User::update((int) $existing['id'], ['role' => User::ROLE_SUPER_ADMIN, 'locale' => I18n::current(), 'is_active' => 1]);
                    User::setPassword((int) $existing['id'], $password);
                    $adminId = (int) $existing['id'];
                } else {
                    $adminId = User::create([
                        'email'     => $email,
                        'name'      => $name,
                        'password'  => $password,
                        'role'      => User::ROLE_SUPER_ADMIN,
                        'locale'    => I18n::current(),
                        'is_active' => 1,
                    ]);
                }
            } catch (\Throwable $e) {
                flash('error', I18n::translate('install.admin_error') . ' ' . $e->getMessage());
                redirect(root_url('install/') . '?step=5');
            }

            Settings::set('installed_at', now_utc());
            install_state_save(['admin' => ['email' => $email, 'name' => $name], 'admin_id' => $adminId]);

            // Sign the new administrator in so the onboarding step can work.
            plotbird_install_login($adminId);

            redirect(root_url('install/') . '?step=6');

        // ---- Step 6: first website ----------------------------------------
        case 'create-site':
            Security::requireCsrf();
            $name   = post('name');
            $domain = post('domain');
            $tz     = post('timezone', 'UTC');

            if ($name === '') {
                install_json(['ok' => false, 'message' => I18n::translate('sites.error_name_required')]);
            }

            try {
                $siteId = Site::create([
                    'name'       => $name,
                    'domain'     => $domain,
                    'timezone'   => $tz,
                    'created_by' => isset($_SESSION['install']['admin_id']) ? (int) $_SESSION['install']['admin_id'] : null,
                ]);
            } catch (\Throwable $e) {
                install_json(['ok' => false, 'message' => $e->getMessage()]);
            }

            install_state_save(['site_id' => $siteId]);
            $site = Site::find($siteId);

            install_json([
                'ok'       => true,
                'site_id'  => $siteId,
                'snippet'  => $site === null ? '' : Site::trackerSnippet($site),
                'public_id' => $site === null ? '' : (string) $site['public_id'],
            ]);

        case 'check-tracker':
            Security::requireCsrf();
            $siteId = (int) ($_POST['site_id'] ?? 0);
            $site = $siteId > 0 ? Site::find($siteId) : null;
            if ($site === null) {
                install_json(['ok' => false, 'message' => I18n::translate('sites.error_not_found')]);
            }
            $last = Stats::lastHitAt($siteId);
            install_json([
                'ok'       => $last !== null,
                'last_hit' => $last,
                'message'  => $last === null
                    ? I18n::translate('install.tracker_waiting')
                    : I18n::translate('install.tracker_ok', ['time' => $last]),
            ]);

        case 'skip-site':
            Security::requireCsrf();
            redirect(root_url('install/') . '?step=7');

        // ---- Step 7: finish ------------------------------------------------
        case 'finish':
            Security::requireCsrf();

            // The lock prevents the wizard from being replayed later, which
            // would otherwise let anybody take over the instance.
            Security::writeLock(Security::installLockPath(), now_utc());
            Settings::set('installed_at', Settings::get('installed_at', now_utc()));

            redirect(root_url('install/') . '?step=7');
    }

    // Unknown action: fall through to the current step.
    redirect(root_url('install/') . '?step=' . max(1, min(7, (int) ($_GET['step'] ?? 1))));
}

/**
 * Sign the freshly created administrator in, so that step 6 can use the
 * application's own models against a session the wizard controls.
 */
function plotbird_install_login(int $userId): void
{
    $user = User::find($userId);
    if ($user === null) {
        return;
    }
    Security::startSession();
    session_regenerate_id(true);
    $_SESSION['_uid'] = $userId;
    $_SESSION['_auth_time'] = time();
    // No browser fingerprint is stored: the wizard's own session must not be
    // mistaken for a later application session in the same browser.
    unset($_SESSION['_fp']);
}

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------
$step = max(1, min(7, (int) ($_GET['step'] ?? 1)));
$state = install_state();

// The active language of the wizard follows the session choice.
if (!empty($state['locale']) && I18n::isAvailable((string) $state['locale'])) {
    I18n::set((string) $state['locale'], false);
}

$requirements = install_requirements();
$flashError = flash('error');
$flashSuccess = flash('success');

switch ($step) {
    case 1:
        install_view('step1', ['step' => 1, 'flash_error' => $flashError]);
        break;

    case 2:
        install_view('step2', [
            'step'         => 2,
            'checks'       => $requirements,
            'can_continue' => install_requirements_met($requirements),
            'flash_error'  => $flashError,
        ]);
        break;

    case 3:
        install_view('step3', [
            'step'        => 3,
            'database'    => $state['database'],
            'pretty_urls' => (bool) ($state['pretty_urls'] ?? true),
            'flash_error' => $flashError,
        ]);
        break;

    case 4:
        // Before the schema exists there is nothing to import into.
        $stage = (string) ($_GET['stage'] ?? '');
        $dbReady = false;
        if (Config::isInstalled()) {
            try {
                Database::instance();
                $dbReady = true;
            } catch (\Throwable $e) {
                $dbReady = false;
            }
        }

        install_view('step4', [
            'step'        => 4,
            'stage'       => $stage,
            'database'    => $state['database'],
            'db_ready'    => $dbReady,
            'geoip_base'  => GeoIP::baseAvailable(),
            'geoip_count' => $dbReady ? GeoIP::count() : 0,
            // Showing how old the bundled snapshot is makes it obvious that the
            // settings page can fetch a newer one later.
            'geoip_base_date' => GeoIP::baseAvailable()
                ? gmdate('Y-m-d', (int) @filemtime(GeoIP::bundledFile()))
                : '',
            'flash_error' => $flashError,
        ]);
        break;

    case 5:
        install_view('step5', [
            'step'        => 5,
            'admin'       => $state['admin'],
            'flash_error' => $flashError,
        ]);
        break;

    case 6:
        $siteId = (int) ($state['site_id'] ?? 0);
        $site = $siteId > 0 ? Site::find($siteId) : null;

        install_view('step6', [
            'step'        => 6,
            'site'        => $site,
            'snippet'     => $site === null ? '' : Site::trackerSnippet($site),
            'timezones'   => Site::timezoneOptions(),
            'tracker_url' => asset('tracker.js'),
            'flash_error' => $flashError,
        ]);
        break;

    case 7:
    default:
        install_view('step7', [
            'step'          => 7,
            'install_path'  => PB_ROOT . '/install',
            'installer_still_present' => Security::installerPresent(),
            'dashboard'     => root_url(''),
            'sites_url'     => root_url('') . (Config::get('app.pretty_urls', true) ? '/sites' : '/index.php?r=sites'),
            'cron_url'      => root_url('api/cron.php') . '?secret=' . rawurlencode(Settings::cronSecret()),
            'flash_success' => $flashSuccess,
        ]);
        break;
}
