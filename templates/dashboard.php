<?php
/**
 * Dashboard page: the widget grid, the period picker and the layout editor.
 *
 * All widget bodies are rendered by Alpine.js from the payload embedded below,
 * so a site or period change never reloads the page.
 *
 * @var array<string,mixed> $payload
 * @var array<string,array<string,mixed>> $widgets
 * @var list<string> $presets
 * @var array<string,mixed> $site
 *
 * @package PlotBird
 */

use PlotBird\View;

$page_assets = ['chart', 'map', 'sortable', 'brands'];

$options = [
    'widgets' => array_keys($widgets),
    'labels'  => [
        'pageviews' => __('metric.pageviews'),
        'visitors'  => __('metric.visitors'),
        'new'       => __('metric.new'),
    ],
    'shareToken' => null,
    'urls'    => [
        'details' => url('details'),
        'export'  => url('export'),
    ],
    'realtime'   => true,
    'visitsLimit' => 50,
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

<div x-data="pbDashboard(<?= e(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>, <?= e(json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>)"
     x-init="init()"
     class="space-y-5">

    <!-- Toolbar ---------------------------------------------------------- -->
    <div class="flex flex-wrap items-center gap-3">
        <div>
            <h1 class="text-lg font-semibold tracking-tight text-slate-900 dark:text-white">
                <?= e($site['name']) ?>
            </h1>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                <span x-text="payload.period.from + ' → ' + payload.period.to"></span>
                · <?= e($site['domain'] !== '' ? $site['domain'] : __('sites.no_domain')) ?>
                · <?= e($site['timezone']) ?>
            </p>
        </div>

        <div class="ml-auto flex flex-wrap items-center gap-2">
            <div class="flex flex-wrap items-center gap-1 rounded-2xl bg-white p-1 shadow-sm ring-1 ring-slate-200 dark:bg-slate-800/60 dark:ring-slate-700">
                <?php foreach (['today', 'yesterday', 'last7', 'last30', 'last90', 'month', 'lastmonth'] as $preset): ?>
                    <button type="button"
                            @click="setPeriod('<?= e($preset) ?>')"
                            :class="preset === '<?= e($preset) ?>' ? 'bg-sky-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-700/60'"
                            class="rounded-xl px-3 py-1.5 text-xs font-medium transition-colors">
                        <?= e($presetLabels[$preset] ?? $preset) ?>
                    </button>
                <?php endforeach; ?>

                <div class="relative" x-data="{ open: false }">
                    <button type="button" @click="open = !open"
                            :class="preset === 'custom' ? 'bg-sky-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-700/60'"
                            class="rounded-xl px-3 py-1.5 text-xs font-medium transition-colors">
                        <?= e(__('period.custom')) ?>
                    </button>
                    <div x-show="open" x-cloak @click.outside="open = false" x-transition.origin.top.right
                         class="absolute right-0 z-40 mt-2 w-72 rounded-2xl bg-white p-4 shadow-xl ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
                        <label class="pb-label"><?= e(__('period.from')) ?></label>
                        <input type="date" class="pb-input mb-3" x-model="from">
                        <label class="pb-label"><?= e(__('period.to')) ?></label>
                        <input type="date" class="pb-input mb-4" x-model="to">
                        <button type="button" class="pb-btn-primary w-full"
                                @click="open = false; setPeriod('custom', from, to)">
                            <?= e(__('period.apply')) ?>
                        </button>
                    </div>
                </div>
            </div>

            <a class="pb-btn-ghost !px-3" :href="exportUrl()" title="<?= e(__('export.hint')) ?>">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M10 2a1 1 0 011 1v7.6l2.3-2.3a1 1 0 011.4 1.4l-4 4a1 1 0 01-1.4 0l-4-4a1 1 0 011.4-1.4L9 10.6V3a1 1 0 011-1zM4 15a1 1 0 100 2h12a1 1 0 100-2H4z"/>
                </svg>
                <span class="hidden sm:inline"><?= e(__('common.export')) ?></span>
            </a>

            <button type="button" class="pb-btn-ghost !px-3" title="<?= e(__('dashboard.refresh')) ?>"
                    @click="reload()" :disabled="loading">
                <svg class="h-4 w-4" :class="loading ? 'animate-spin' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M4.8 3.3a1 1 0 011.4 0l.9.9a7 7 0 019.4 8.6 1 1 0 11-1.9-.6 5 5 0 00-6.9-6.3l.9.9a1 1 0 11-1.4 1.4L4.1 5.1a1 1 0 010-1.4l.7-.4zm10.4 13.4a1 1 0 01-1.4 0l-.9-.9a7 7 0 01-9.4-8.6 1 1 0 111.9.6 5 5 0 006.9 6.3l-.9-.9a1 1 0 011.4-1.4l3.1 3.1a1 1 0 010 1.4l-.7.4z" clip-rule="evenodd"/>
                </svg>
            </button>

            <div class="relative" x-data="{ open: false }">
                <button type="button" class="pb-btn-ghost !px-3" @click="open = !open"
                        title="<?= e(__('widgets.customize')) ?>">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M4 5.5A1.5 1.5 0 015.5 4h2A1.5 1.5 0 019 5.5v2A1.5 1.5 0 017.5 9h-2A1.5 1.5 0 014 7.5v-2zm7 0A1.5 1.5 0 0112.5 4h2A1.5 1.5 0 0116 5.5v2A1.5 1.5 0 0114.5 9h-2A1.5 1.5 0 0111 7.5v-2zm-7 7A1.5 1.5 0 015.5 11h2A1.5 1.5 0 019 12.5v2A1.5 1.5 0 017.5 16h-2A1.5 1.5 0 014 14.5v-2zm7 0A1.5 1.5 0 0112.5 11h2a1.5 1.5 0 011.5 1.5v2a1.5 1.5 0 01-1.5 1.5h-2a1.5 1.5 0 01-1.5-1.5v-2z"/>
                    </svg>
                    <span class="hidden sm:inline"><?= e(__('widgets.customize')) ?></span>
                </button>
                <div x-show="open" x-cloak @click.outside="open = false" x-transition.origin.top.right
                     class="absolute right-0 z-40 mt-2 w-72 rounded-2xl bg-white p-2 shadow-xl ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
                    <p class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
                        <?= e(__('widgets.visibility')) ?>
                    </p>
                    <?php foreach ($widgets as $id => $meta): ?>
                        <label class="flex cursor-pointer items-center justify-between gap-3 rounded-xl px-3 py-2 hover:bg-slate-50 dark:hover:bg-slate-700/50">
                            <span class="text-sm text-slate-700 dark:text-slate-200"><?= e(__($meta['title'])) ?></span>
                            <input type="checkbox" class="h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500 dark:border-slate-600 dark:bg-slate-900"
                                   :checked="isVisible('<?= e($id) ?>')"
                                   @change="toggleWidget('<?= e($id) ?>')">
                        </label>
                    <?php endforeach; ?>
                    <div class="mt-1 border-t border-slate-100 pt-1 dark:border-slate-700">
                        <button type="button" class="pb-btn-ghost w-full !justify-start !text-xs" @click="resetLayout()">
                            <?= e(__('widgets.reset')) ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Error / loading strip -------------------------------------------- -->
    <div x-show="error" x-cloak
         class="rounded-2xl bg-rose-50 px-4 py-2.5 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
        <?= e(__('dashboard.load_error')) ?>
    </div>

    <!-- Widget grid ------------------------------------------------------- -->
    <div x-ref="grid"
         :class="loading ? 'opacity-60' : ''"
         class="pb-grid-sorting grid grid-cols-12 gap-5 transition-opacity">
        <?php
        View::partial('partials/widget_realtime');
        View::partial('partials/widget_overview');
        View::partial('partials/widget_chart');
        View::partial('partials/widget_top_pages', ['can_drill' => true]);
        View::partial('partials/widget_referrers', ['can_drill' => true]);
        View::partial('partials/widget_geo', ['can_drill' => true]);
        View::partial('partials/widget_devices', ['can_drill' => true]);
        View::partial('partials/widget_goals');
        View::partial('partials/widget_visitors');
        ?>
    </div>
</div>
