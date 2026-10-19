<?php
/**
 * Widget: traffic sources (referrers), including entry pages.
 *
 * @package PlotBird
 */
?>
<section data-widget="referrers" x-show="isVisible('referrers')" x-data="{ tab: 'referrer' }"
         class="pb-widget pb-card col-span-12 xl:col-span-6 overflow-hidden">
    <div class="pb-card-header">
        <div class="flex items-center gap-1">
            <h2 class="pb-card-title mr-2"><?= e(__('widget.referrers.title')) ?></h2>
            <button type="button" @click="tab = 'referrer'"
                    :class="tab === 'referrer' ? 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300' : 'text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-700/60'"
                    class="rounded-lg px-2 py-1 text-xs font-medium">
                <?= e(__('widget.referrers.sources')) ?>
            </button>
            <button type="button" @click="tab = 'entry'"
                    :class="tab === 'entry' ? 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300' : 'text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-700/60'"
                    class="rounded-lg px-2 py-1 text-xs font-medium">
                <?= e(__('widget.referrers.entry')) ?>
            </button>
        </div>
        <span class="pb-drag-handle pb-btn-ghost !px-2" title="<?= e(__('widgets.drag')) ?>">
            <svg class="h-4 w-4 opacity-50" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M7 4.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM16 4.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"/>
            </svg>
        </span>
    </div>

    <div class="p-5">
        <template x-if="tab === 'referrer'">
            <div>
                <ul class="space-y-2.5">
                    <template x-for="row in payload.top.referrer" :key="row.value">
                        <li class="grid grid-cols-[1fr_auto_auto] items-center gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <a class="pb-row-link truncate px-1 text-sm text-slate-700 dark:text-slate-200"
                                       <?= ($can_drill ?? false) ? ':href="detailUrl(\'referrer\', row.value)"' : '' ?>
                                       :title="'<?= e(__('details.title')) ?>: ' + (row.value || '<?= e(__('dimension.direct')) ?>')"
                                       x-text="row.value === '' ? '<?= e(__('dimension.direct')) ?>' : row.value"></a>
                                </div>
                                <div class="pb-bar-track mt-1.5">
                                    <div class="pb-bar" :style="'width:' + widthPct(row, payload.top.referrer)"></div>
                                </div>
                            </div>
                            <span class="text-right text-sm font-medium tabular-nums text-slate-700 dark:text-slate-200"
                                  x-text="fmtNumber(row.visitors)"></span>
                            <span class="w-14 text-right text-xs tabular-nums text-slate-400"
                                  x-text="sharePct(row, payload.top.referrer)"></span>
                        </li>
                    </template>
                </ul>
                <p x-show="!payload.top.referrer.length" class="py-6 text-center text-sm text-slate-400 dark:text-slate-500">
                    <?= e(__('widget.referrers.empty')) ?>
                </p>
            </div>
        </template>

        <template x-if="tab === 'entry'">
            <div>
                <ul class="space-y-2.5">
                    <template x-for="row in payload.top.entry" :key="row.value">
                        <li class="grid grid-cols-[1fr_auto] items-center gap-3">
                            <div class="min-w-0">
                                <span class="block truncate text-sm text-slate-700 dark:text-slate-200" x-text="row.value"></span>
                                <div class="pb-bar-track mt-1.5">
                                    <div class="pb-bar" :style="'width:' + widthPct(row, payload.top.entry)"></div>
                                </div>
                            </div>
                            <span class="text-right text-sm font-medium tabular-nums text-slate-700 dark:text-slate-200"
                                  x-text="fmtNumber(row.sessions)"></span>
                        </li>
                    </template>
                </ul>
                <p x-show="!payload.top.entry.length" class="py-6 text-center text-sm text-slate-400 dark:text-slate-500">
                    <?= e(__('widget.referrers.empty')) ?>
                </p>
            </div>
        </template>
    </div>
</section>
