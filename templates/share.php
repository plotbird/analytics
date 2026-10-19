<?php
/**
 * Public share page: the dashboard of one site, without an account.
 *
 * @var array<string,mixed> $payload
 * @var array<string,mixed> $site
 * @var string $token
 * @var string|null $expires_at
 * @var list<string> $presets
 * @var array<string,array<string,mixed>> $widgets
 * @var string|null $last_hit
 *
 * @package PlotBird
 */

use PlotBird\View;

$page_assets = ['chart', 'map'];

$options = [
    'widgets'    => array_keys($widgets),
    'labels'     => [
        'pageviews' => __('metric.pageviews'),
        'visitors'  => __('metric.visitors'),
        'new'       => __('metric.new'),
    ],
    'shareToken' => $token,
    'realtime'   => false,
];

$presetLabels = [
    'today'     => __('period.today'),
    'yesterday' => __('period.yesterday'),
    'last7'     => __('period.last7'),
    'last30'    => __('period.last30'),
    'last90'    => __('period.last90'),
    'month'     => __('period.month'),
    'lastmonth' => __('period.lastmonth'),
    'year'      => __('period.year'),
];
?>
<div class="pb-readonly space-y-5"
     x-data="pbDashboard(<?= e(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>, <?= e(json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>)"
     x-init="init()">

    <div class="flex flex-wrap items-center gap-3">
        <div>
            <h1 class="text-lg font-semibold tracking-tight text-slate-900 dark:text-white"><?= e($site['name']) ?></h1>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                <span x-text="payload.period.from + ' → ' + payload.period.to"></span>
                <?php if (!empty($expires_at)): ?>
                    · <?= e(__('share.expires_at')) ?> <?= e((string) $expires_at) ?>
                <?php endif; ?>
            </p>
        </div>

        <div class="ml-auto flex flex-wrap items-center gap-1 rounded-2xl bg-white p-1 shadow-sm ring-1 ring-slate-200 dark:bg-slate-800/60 dark:ring-slate-700">
            <?php foreach (['today', 'yesterday', 'last7', 'last30', 'last90', 'month', 'lastmonth', 'year'] as $preset): ?>
                <button type="button" @click="setPeriod('<?= e($preset) ?>')"
                        :class="preset === '<?= e($preset) ?>' ? 'bg-sky-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-700/60'"
                        class="rounded-xl px-3 py-1.5 text-xs font-medium transition-colors">
                    <?= e($presetLabels[$preset] ?? $preset) ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <div x-cloak x-show="error"
         class="rounded-2xl bg-rose-50 px-4 py-2.5 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
        <?= e(__('dashboard.load_error')) ?>
    </div>

    <div x-ref="grid" :class="loading ? 'opacity-60' : ''"
         class="grid grid-cols-12 gap-5 transition-opacity">
        <?php
        View::partial('partials/widget_overview');
        View::partial('partials/widget_chart');
        View::partial('partials/widget_top_pages');
        View::partial('partials/widget_referrers');
        View::partial('partials/widget_geo');
        View::partial('partials/widget_devices');
        ?>
    </div>
</div>
