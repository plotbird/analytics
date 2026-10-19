<?php
/**
 * Widget: real-time counter (activity of the last five minutes).
 *
 * @package PlotBird
 */
?>
<section data-widget="realtime" x-show="isVisible('realtime')"
         class="pb-widget pb-card col-span-12 overflow-hidden">
    <div class="pb-card-header">
        <div class="flex items-center gap-2.5">
            <div>
                <h2 class="pb-card-title"><?= e(__('widget.realtime.title')) ?></h2>
                <p class="text-xs text-slate-400"><?= e(__('widget.realtime.hint')) ?></p>
            </div>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                <span class="pb-pulse inline-block h-2 w-2 rounded-full bg-emerald-500 text-emerald-500"></span>
                <span x-text="payload.realtime.window"></span>&nbsp;<?= e(__('widget.realtime.minutes')) ?>
            </span>
        </div>
        <span class="pb-drag-handle pb-btn-ghost !px-2" title="<?= e(__('widgets.drag')) ?>">
            <svg class="h-4 w-4 opacity-50" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M7 4.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM16 4.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"/>
            </svg>
        </span>
    </div>

    <div class="flex flex-col gap-5 p-5 lg:flex-row">
        <div class="flex shrink-0 items-center gap-6 lg:w-64">
            <div>
                <p class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white"
                   x-text="fmtNumber(payload.realtime.visitors)">0</p>
                <p class="mt-0.5 text-xs font-medium uppercase tracking-wide text-slate-400">
                    <?= e(__('metric.visitors_now')) ?>
                </p>
            </div>
            <div class="h-12 w-px bg-slate-200 dark:bg-slate-700"></div>
            <div>
                <p class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white"
                   x-text="fmtNumber(payload.realtime.pageviews)">0</p>
                <p class="mt-0.5 text-xs font-medium uppercase tracking-wide text-slate-400">
                    <?= e(__('metric.pageviews')) ?>
                </p>
            </div>
        </div>

        <div class="min-w-0 flex-1">
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
                <?= e(__('widget.realtime.active_pages')) ?>
            </p>
            <ul class="space-y-1.5">
                <template x-for="page in payload.realtime.pages" :key="page.value">
                    <li class="flex items-center justify-between gap-3 text-sm">
                        <span class="truncate text-slate-600 dark:text-slate-300" x-text="page.value"></span>
                        <span class="shrink-0 rounded-lg bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-slate-700/60 dark:text-slate-300"
                              x-text="fmtNumber(page.pageviews)"></span>
                    </li>
                </template>
            </ul>
            <p x-show="!payload.realtime.pages.length"
               class="text-sm text-slate-400 dark:text-slate-500">
                <?= e(__('widget.realtime.empty')) ?>
            </p>
        </div>
    </div>
</section>
