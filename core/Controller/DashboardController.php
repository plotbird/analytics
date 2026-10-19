<?php
/**
 * PlotBird Analytics - the dashboard page.
 *
 * The page is rendered server side with a complete payload, so the first paint
 * already shows real numbers; afterwards Alpine.js refreshes widgets through
 * /api/stats.php when the site or the period changes.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird\Controller;

use PlotBird\I18n;
use PlotBird\Stats;
use PlotBird\StatsPayload;
use PlotBird\Widgets;

final class DashboardController extends BaseController
{
    /**
     * GET / and GET /dashboard
     */
    public function index(): void
    {
        $user = $this->guardLogin();
        $site = $this->selectedSite();

        if ($site === null) {
            $this->render('dashboard_empty', [
                'page_title' => I18n::translate('dashboard.title'),
                'can_manage' => $user['role'] === 'super_admin',
            ]);
            return;
        }

        $params = [
            'preset' => (string) ($_GET['preset'] ?? 'last7'),
            'from'   => (string) ($_GET['from'] ?? ''),
            'to'     => (string) ($_GET['to'] ?? ''),
        ];

        $payload = StatsPayload::build($site, $params);

        $this->render('dashboard', [
            'page_title'  => I18n::translate('dashboard.title'),
            'payload'     => $payload,
            'widgets'     => Widgets::CATALOG,
            'layout'      => Widgets::defaultLayout(),
            'presets'     => Stats::PRESETS,
            'site'        => $site,
            'page_assets' => ['chart', 'map', 'sortable', 'brands'],
        ]);
    }
}
