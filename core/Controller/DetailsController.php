<?php
/**
 * PlotBird Analytics - drill-down for one dimension value.
 *
 * The dashboard lists show the largest values; this page answers the next
 * question: "and what is inside that country / browser / page". It reads the raw
 * page views, because only a raw row carries every dimension at once.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird\Controller;

use PlotBird\I18n;
use PlotBird\Stats;

final class DetailsController extends BaseController
{
    /**
     * GET /details
     */
    public function show(): void
    {
        $this->guardLogin();
        $site = $this->requireSite();

        $dimension = (string) ($_GET['dimension'] ?? '');
        $value     = (string) ($_GET['value'] ?? '');

        $column = Stats::filterColumn($dimension);

        // An empty value is legitimate: a direct visit has an empty source, and
        // an unknown browser is stored as an empty string.
        if ($column === null || mb_strlen($value) > 512) {
            $this->flash('error', I18n::translate('error.not_found_title'));
            $this->redirectTo('dashboard');
        }

        $params = [
            'preset' => (string) ($_GET['preset'] ?? 'last7'),
            'from'   => (string) ($_GET['from'] ?? ''),
            'to'     => (string) ($_GET['to'] ?? ''),
        ];

        $period = Stats::resolvePeriod($site, $params['preset'], $params['from'], $params['to']);
        $siteId = (int) $site['id'];

        $summary = Stats::filteredSummary($siteId, $period['from'], $period['to'], $column, $value);

        $breakdowns = [];
        foreach (['path', 'referrer', 'country', 'device', 'os', 'browser', 'title', 'language', 'screen'] as $dimensionKey) {
            // A breakdown of the same dimension the page is filtered by would
            // print the value once and say nothing.
            if ($dimensionKey === $dimension) {
                continue;
            }
            $breakdowns[$dimensionKey] = Stats::filteredTop(
                $siteId,
                $period['from'],
                $period['to'],
                $column,
                $value,
                $dimensionKey,
                15
            );
        }

        $this->render('details', [
            'page_title'   => I18n::translate('details.title') . ' · ' . $value,
            'site'         => $site,
            'period'       => $period,
            'dimension'    => $dimension,
            'value'        => $value,
            'summary'      => $summary,
            'series'       => Stats::filteredSeries($site, $period['from'], $period['to'], $column, $value),
            'breakdowns'   => $breakdowns,
            'dimension_label' => match ($dimension) {
                'path'     => I18n::translate('metric.path'),
                'referrer' => I18n::translate('metric.referrer'),
                'country'  => I18n::translate('widget.geo.title'),
                'device'   => I18n::translate('widget.devices.device'),
                'os'       => I18n::translate('widget.devices.os'),
                'browser'  => I18n::translate('widget.devices.browser'),
                'title'    => I18n::translate('metric.path'),
                'language' => I18n::translate('nav.language'),
                'screen'   => I18n::translate('metric.value'),
                default    => $dimension,
            },
            'page_assets'  => [],
        ]);
    }
}
