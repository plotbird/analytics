<?php
/**
 * PlotBird Analytics - front controller.
 *
 * Every human-facing page goes through here. Machine endpoints live next to it
 * in /api (they must stay reachable even when mod_rewrite is unavailable), and
 * the installer has its own entry point in /install.
 *
 * @package PlotBird
 */

declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';

use PlotBird\Config;
use PlotBird\Controller\AuthController;
use PlotBird\Controller\DashboardController;
use PlotBird\Controller\SettingsController;
use PlotBird\Controller\ShareController;
use PlotBird\Controller\SitesController;
use PlotBird\Controller\UsersController;
use PlotBird\I18n;
use PlotBird\Router;
use PlotBird\Security;

/*
 * Not installed yet: hand over to the wizard. The check is repeated inside
 * /install/index.php, so a direct visit there works as well.
 */
if (!Config::isInstalled()) {
    redirect(root_url('install/'));
}

/*
 * A configuration file exists but points at an unreachable database. Showing a
 * clear message beats a blank page with a stack trace.
 */
try {
    PlotBird\Database::instance();
} catch (\Throwable $e) {
    PlotBird\Log::error('database unavailable: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>503</title></head>'
        . '<body style="font-family:system-ui,sans-serif;padding:3rem;text-align:center">'
        . '<h1>503</h1><p>' . e(I18n::translate('error.database_body')) . '</p></body></html>';
    exit;
}

$router = new Router();

$router->get('', [new DashboardController(), 'index']);
$router->get('dashboard', [new DashboardController(), 'index']);

$router->get('login', [new AuthController(), 'showLogin']);
$router->post('login', [new AuthController(), 'login']);
$router->post('logout', [new AuthController(), 'logout']);
$router->get('locale/{locale}', [new AuthController(), 'setLocale']);

$router->get('sites', [new SitesController(), 'index']);
$router->post('sites/save', [new SitesController(), 'save']);
$router->post('sites/delete', [new SitesController(), 'delete']);
$router->post('sites/share', [new SitesController(), 'createShare']);
$router->post('sites/share/revoke', [new SitesController(), 'revokeShare']);
$router->post('sites/share/delete', [new SitesController(), 'deleteShare']);
$router->post('sites/goals', [new SitesController(), 'createGoal']);
$router->post('sites/goals/update', [new SitesController(), 'updateGoal']);
$router->post('sites/goals/delete', [new SitesController(), 'deleteGoal']);
$router->post('sites/check-tracker', [new SitesController(), 'checkTracker']);

$router->get('users', [new UsersController(), 'index']);
$router->get('users/{id}', [new UsersController(), 'show']);
$router->post('users/save', [new UsersController(), 'save']);
$router->post('users/delete', [new UsersController(), 'delete']);

$router->get('settings', [new SettingsController(), 'index']);
$router->post('settings/save', [new SettingsController(), 'save']);
$router->post('settings/password', [new SettingsController(), 'password']);
$router->post('settings/geoip', [new SettingsController(), 'geoip']);
$router->post('settings/maintenance', [new SettingsController(), 'maintenance']);

$router->get('share/{token}', [new ShareController(), 'show']);

// Shortcuts people type by hand.
$router->get('index.php', [new DashboardController(), 'index']);

$router->dispatch(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    Security::currentPath()
);
