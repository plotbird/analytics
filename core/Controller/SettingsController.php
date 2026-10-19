<?php
/**
 * PlotBird Analytics - settings, maintenance and diagnostics.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird\Controller;

use PlotBird\Aggregator;
use PlotBird\Audit;
use PlotBird\Auth;
use PlotBird\Config;
use PlotBird\Database;
use PlotBird\GeoIP;
use PlotBird\I18n;
use PlotBird\Migrator;
use PlotBird\RateLimiter;
use PlotBird\Settings;

final class SettingsController extends BaseController
{
    /**
     * The recommended, always-current DB-IP Lite country database.
     */
    private const GEOIP_UPDATE_URL = 'https://download.db-ip.com/free/dbip-country-lite-%s.csv.gz';

    /**
     * GET /settings
     */
    public function index(): void
    {
        $this->guardAdmin();

        $db = Database::instance();

        $this->render('settings', [
            'page_title'   => I18n::translate('settings.title'),
            'settings'     => Settings::all(),
            'geoip_count'  => GeoIP::count(),
            'geoip_ready'  => GeoIP::isImported(),
            'geoip_base'   => GeoIP::baseAvailable(),
            'geoip_updated' => (string) Settings::get('geoip_updated_at', ''),
            'cron_url'     => self::cronUrl(),
            'audit'        => Audit::recent(40),
            'system'       => [
                'php'        => PHP_VERSION,
                'mysql'      => $db->serverVersion(),
                'mariadb'    => $db->isMariaDb(),
                'prefix'     => $db->prefix(),
                'version'    => PB_VERSION,
                'schema'     => PB_SCHEMA_VERSION,
                'timezone'   => date_default_timezone_get(),
                'data_writable' => pb_dir_writable(PB_ROOT . '/data'),
                'debug'      => (bool) Config::get('app.debug', false),
            ],
            'retention_range' => [7, 3650],
            'migrator'     => Migrator::status(),
            'store_ip'     => Settings::getBool('store_ip', false),
            'geoip_base_date' => GeoIP::baseAvailable()
                ? gmdate('Y-m-d', (int) @filemtime(GeoIP::bundledFile()))
                : '',
        ]);
    }

    /**
     * POST /settings/save
     */
    public function save(): void
    {
        $this->guardAdmin();
        $this->guardCsrf();

        $locale = post('default_locale', I18n::FALLBACK);
        if (I18n::isAvailable($locale)) {
            Settings::set('default_locale', $locale);
        }

        $retention = post_int('retention_days', 365);
        Settings::set('retention_days', max(7, min(3650, $retention)));

        $timeout = post_int('session_timeout', 30);
        Settings::set('session_timeout', max(1, min(240, $timeout)));

        Settings::set('anonymize_ip', post_bool('anonymize_ip'));
        Settings::set('respect_dnt', post_bool('respect_dnt'));
        Settings::set('exclude_bots', post_bool('exclude_bots'));

        // Storing visitor IP addresses turns pseudonymous statistics into
        // personal data. It is off by default and every change is audited.
        $previousStoreIp = Settings::getBool('store_ip', false);
        $storeIp = post_bool('store_ip');
        Settings::set('store_ip', $storeIp);

        if ($previousStoreIp !== $storeIp) {
            $this->audit($storeIp ? 'store_ip_enabled' : 'store_ip_disabled', 'settings', '');
        }

        $this->audit('settings_updated', 'settings', '');
        $this->flash('success', I18n::translate('settings.saved'));
        $this->redirectTo('settings');
    }

    /**
     * POST /settings/password - the signed-in user changes their own password.
     */
    public function password(): void
    {
        $this->guardLogin();
        $this->guardCsrf();

        $result = Auth::changeOwnPassword(
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['password_repeat'] ?? '')
        );

        if (!$result['ok']) {
            $this->flash('error', I18n::translate('settings.password_error_' . $result['error']));
        } else {
            $this->flash('success', I18n::translate('settings.password_saved'));
        }
        $this->redirectTo('settings');
    }

    /**
     * POST /settings/geoip - chunked import of the IP database.
     *
     * The browser calls this repeatedly with the byte offset returned by the
     * previous call, so a 30 MB CSV can be imported on a hosting with a 30
     * second execution limit.
     */
    public function geoip(): void
    {
        $this->guardAdmin();
        $this->guardCsrf();

        // The endpoint is chosen with ?r=settings/geoip; the body carries the
        // phase of the chunked import.
        $action = post('phase', post('action', 'status'));

        if ($action === 'status') {
            json_out([
                'ok'      => true,
                'count'   => GeoIP::count(),
                'ready'   => GeoIP::isImported(),
                'base'    => GeoIP::baseAvailable(),
                'updated' => (string) Settings::get('geoip_updated_at', ''),
            ]);
        }

        if ($action === 'prepare') {
            $prepared = GeoIP::prepareCsv();
            if (!$prepared['ok']) {
                json_out(['ok' => false, 'error' => $prepared['error']], 200);
            }
            json_out([
                'ok'      => true,
                'bytes'   => $prepared['bytes'],
                'offset'  => 0,
                'percent' => 0,
            ]);
        }

        if ($action === 'chunk') {
            $offset = max(0, post_int('offset'));
            $result = GeoIP::importChunk(GeoIP::csvFile(), $offset);

            if (!$result['ok']) {
                json_out(['ok' => false, 'error' => $result['error']], 200);
            }

            $percent = $result['bytes'] > 0 ? min(100, (int) round($result['offset'] / $result['bytes'] * 100)) : 100;

            json_out([
                'ok'       => true,
                'done'     => $result['done'],
                'offset'   => $result['offset'],
                'bytes'    => $result['bytes'],
                'imported' => $result['imported'],
                'percent'  => $percent,
                'count'    => $result['done'] ? GeoIP::count() : 0,
            ]);
        }

        if ($action === 'download') {
            $url = post('url');
            if ($url === '') {
                $url = sprintf(self::GEOIP_UPDATE_URL, gmdate('Y-m'));
            }
            $download = GeoIP::download($url);
            if (!$download['ok']) {
                json_out(['ok' => false, 'error' => $download['error']], 200);
            }
            $this->audit('geoip_downloaded', 'settings', '', ['bytes' => $download['bytes']]);
            json_out(['ok' => true, 'bytes' => $download['bytes']]);
        }

        json_out(['ok' => false, 'error' => 'unknown_action'], 400);
    }

    /**
     * POST /settings/maintenance - housekeeping actions.
     */
    public function maintenance(): void
    {
        $this->guardAdmin();
        $this->guardCsrf();

        $action = post('action');

        switch ($action) {
            case 'aggregate':
                $sites = \PlotBird\Site::all();
                $total = 0;
                foreach ($sites as $site) {
                    $total += Aggregator::refresh($site);
                }
                $this->flash('success', I18n::translate('settings.maintenance_aggregated', ['days' => $total]));
                break;

            case 'purge':
                $result = Aggregator::purgeRaw();
                $this->audit('raw_purged', 'settings', '', $result);
                $this->flash('success', I18n::translate('settings.maintenance_purged', [
                    'pageviews' => $result['pageviews'],
                    'visits'    => $result['visits'],
                ]));
                break;

            case 'rate_limits':
                RateLimiter::garbageCollect(0);
                $this->flash('success', I18n::translate('settings.maintenance_rate_limits'));
                break;

            case 'migrate':
                $migration = Migrator::run();
                if ($migration['failed'] !== []) {
                    $failed = array_key_first($migration['failed']);
                    $this->audit('migration_failed', 'settings', (string) $failed);
                    $this->flash('error', I18n::translate('settings.migration_failed', [
                        'file'  => (string) $failed,
                        'error' => (string) $migration['failed'][$failed],
                    ]));
                } elseif ($migration['applied'] === []) {
                    $this->flash('success', I18n::translate('settings.migration_none'));
                } else {
                    $this->audit('migrated', 'settings', '', ['applied' => $migration['applied']]);
                    $this->flash('success', I18n::translate('settings.migration_done', [
                        'count' => count($migration['applied']),
                    ]));
                }
                break;

            case 'cron_secret':
                Settings::set('cron_secret', random_hex(24));
                $this->audit('cron_secret_rotated', 'settings', '');
                $this->flash('success', I18n::translate('settings.maintenance_cron_rotated'));
                break;

            default:
                $this->flash('error', I18n::translate('settings.maintenance_unknown'));
        }

        $this->redirectTo('settings');
    }

    /**
     * The URL to give a cron service.
     */
    public static function cronUrl(): string
    {
        return root_url('api/cron.php') . '?secret=' . rawurlencode(Settings::cronSecret());
    }
}
