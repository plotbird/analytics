<?php
/**
 * PlotBird Analytics - share link controller (public, no account required).
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird\Controller;

use PlotBird\I18n;
use PlotBird\ShareLink;
use PlotBird\Site;
use PlotBird\Stats;
use PlotBird\StatsPayload;
use PlotBird\Widgets;

final class ShareController extends BaseController
{
    /**
     * GET /share/{token}
     *
     * @param array<string,string> $params
     */
    public function show(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $link  = ShareLink::resolve($token);

        // One answer for "unknown", "revoked" and "expired": a stranger must not
        // be able to tell them apart.
        if ($link === null) {
            http_response_code(404);
            $this->render('share_invalid', [
                'page_title' => I18n::translate('share.invalid_title'),
            ], 'layout_guest');
            return;
        }

        $site = Site::find((int) $link['site_id']);
        if ($site === null || (int) $site['is_active'] !== 1) {
            http_response_code(404);
            $this->render('share_invalid', [
                'page_title' => I18n::translate('share.invalid_title'),
            ], 'layout_guest');
            return;
        }

        $params = [
            'preset' => (string) ($_GET['preset'] ?? 'last30'),
            'from'   => (string) ($_GET['from'] ?? ''),
            'to'     => (string) ($_GET['to'] ?? ''),
        ];

        $payload = StatsPayload::build($site, $params);

        $this->render('share', [
            'page_title' => (string) $site['name'],
            'payload'    => $payload,
            'site'       => $site,
            'token'      => $token,
            'expires_at' => $link['expires_at'] ?? null,
            'presets'    => ['today', 'yesterday', 'last7', 'last30', 'last90', 'month', 'lastmonth', 'year'],
            'widgets'    => Widgets::CATALOG,
            'layout_def' => Widgets::defaultLayout(),
            'last_hit'   => Stats::lastHitAt((int) $site['id']),
            'page_assets' => ['chart', 'map', 'brands'],
        ], 'layout_share');
    }
}
