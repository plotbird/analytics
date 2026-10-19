<?php
/**
 * Widget: top pages with progress bars.
 *
 * @package PlotBird
 */
?>
<section data-widget="top_pages" x-show="isVisible('top_pages')"
         class="pb-widget pb-card col-span-12 xl:col-span-6 overflow-hidden">
    <div class="pb-card-header">
        <h2 class="pb-card-title"><?= e(__('widget.top_pages.title')) ?></h2>
        <span class="pb-drag-handle pb-btn-ghost !px-2" title="<?= e(__('widgets.drag')) ?>">
            <svg class="h-4 w-4 opacity-50" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M7 4.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM16 4.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"/>
            </svg>
        </span>
    </div>

    <div class="p-5">
        <div class="mb-2 grid grid-cols-[1fr_auto_auto] gap-3 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
            <span><?= e(__('metric.path')) ?></span>
            <span class="text-right"><?= e(__('metric.pageviews')) ?></span>
            <span class="w-16 text-right"><?= e(__('metric.visitors')) ?></span>
        </div>

        <ul class="space-y-2.5">
            <template x-for="row in payload.top.path" :key="row.value">
                <li class="grid grid-cols-[1fr_auto_auto] items-center gap-3">
                    <div class="min-w-0">
                        <a class="block truncate text-sm text-slate-700 hover:text-sky-600 dark:text-slate-200 dark:hover:text-sky-400"
                           <?= ($can_drill ?? false) ? ':href="detailUrl(\'path\', row.value)"' : '' ?>
                           :title="'<?= e(__('details.title')) ?>: ' + row.value"
                           x-text="row.value"></a>
                        <div class="pb-bar-track mt-1.5">
                            <div class="pb-bar" :style="'width:' + widthPct(row, payload.top.path)"></div>
                        </div>
                    </div>
                    <span class="text-right text-sm font-medium tabular-nums text-slate-700 dark:text-slate-200"
                          x-text="fmtNumber(row.pageviews)"></span>
                    <span class="w-16 text-right text-sm tabular-nums text-slate-400"
                          x-text="fmtNumber(row.visitors)"></span>
                </li>
            </template>
        </ul>

        <p x-show="!payload.top.path.length" class="py-6 text-center text-sm text-slate-400 dark:text-slate-500">
            <?= e(__('widget.top_pages.empty')) ?>
        </p>
    </div>
</section>
