<?php
/**
 * Widget: visitor log.
 *
 * One row per session: when it started (in the website's time zone), how long
 * it lasted, how many pages were seen, where the visitor came from and - when
 * the administrator enabled IP storage in the settings - the address itself.
 * Clicking a row expands the pages of that session.
 *
 * The list reads raw visits, so it only covers the retention window; the
 * widget says so instead of pretending the period was empty.
 *
 * @package PlotBird
 */
?>
<section data-widget="visitors" x-show="isVisible('visitors')"
         class="pb-widget pb-card col-span-12 overflow-hidden">
    <div class="pb-card-header">
        <div>
            <h2 class="pb-card-title"><?= e(__('widget.visitors.title')) ?></h2>
            <p class="text-xs text-slate-400">
                <span x-text="fmtNumber(payload.visits.total)"></span>
                <span x-text="payload.visits.total === 1 ? '<?= e(__('widget.visitors.session_one')) ?>' : '<?= e(__('widget.visitors.session_many')) ?>'"></span>
                ·
                <span x-text="payload.visits.timezone"></span>
                <template x-if="payload.visits.truncated">
                    <span>
                        · <?= e(__('widget.visitors.showing')) ?>
                        <span x-text="fmtNumber(payload.visits.limit)"></span>
                    </span>
                </template>
            </p>
        </div>
        <span class="pb-drag-handle pb-btn-ghost !px-2" title="<?= e(__('widgets.drag')) ?>">
            <svg class="h-4 w-4 opacity-50" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M7 4.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM16 4.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"/>
            </svg>
        </span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50/80 text-[11px] uppercase tracking-wide text-slate-400 dark:bg-slate-900/40">
                <tr>
                    <th class="px-5 py-2.5 font-semibold"><?= e(__('widget.visitors.time')) ?></th>
                    <th class="px-5 py-2.5 font-semibold"><?= e(__('widget.visitors.duration')) ?></th>
                    <th class="px-5 py-2.5 font-semibold"><?= e(__('widget.visitors.pages')) ?></th>
                    <th class="px-5 py-2.5 font-semibold"><?= e(__('widget.visitors.entry')) ?></th>
                    <th class="px-5 py-2.5 font-semibold"><?= e(__('widget.visitors.source')) ?></th>
                    <th class="px-5 py-2.5 font-semibold"><?= e(__('widget.visitors.geo')) ?></th>
                    <th class="px-5 py-2.5 font-semibold"><?= e(__('widget.visitors.device')) ?></th>
                    <th x-show="payload.visits.show_ip" class="px-5 py-2.5 font-semibold"><?= e(__('widget.visitors.ip')) ?></th>
                    <th class="px-5 py-2.5"></th>
                </tr>
            </thead>
            <template x-for="row in payload.visits.rows" :key="row.id">
                <tbody>
                    <tr class="pb-table-row cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-700/40"
                        @click="toggleVisit(row.id)">
                            <td class="whitespace-nowrap px-5 py-2.5 tabular-nums text-slate-600 dark:text-slate-300"
                                x-text="row.time"></td>
                            <td class="whitespace-nowrap px-5 py-2.5 text-slate-600 dark:text-slate-300">
                                <span x-text="fmtDuration(row.duration)"></span>
                                <span x-show="row.bounce" class="pb-badge ml-1 bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300"
                                      title="<?= e(__('metric.bounce')) ?>"><?= e(__('widget.visitors.bounce_short')) ?></span>
                            </td>
                            <td class="px-5 py-2.5 tabular-nums text-slate-600 dark:text-slate-300" x-text="row.pageviews"></td>
                            <td class="max-w-[16rem] px-5 py-2.5">
                                <span class="block truncate text-slate-700 dark:text-slate-200" x-text="row.entry"></span>
                                <template x-if="row.exit !== row.entry">
                                    <span class="block truncate text-[11px] text-slate-400">
                                        → <span x-text="row.exit"></span>
                                    </span>
                                </template>
                            </td>
                            <td class="max-w-[12rem] px-5 py-2.5">
                                <span class="block truncate text-slate-500 dark:text-slate-400"
                                      x-text="row.referrer === '' ? '<?= e(__('dimension.direct')) ?>' : row.referrer"></span>
                            </td>
                            <td class="px-5 py-2.5">
                                <span class="flex items-center gap-2">
                                    <img :src="row.flag_url" alt="" width="20" height="15" loading="lazy" class="pb-flag">
                                    <span class="truncate text-slate-500 dark:text-slate-400"
                                          x-text="row.country_name || '<?= e(__('dimension.unknown')) ?>'"></span>
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-5 py-2.5 text-slate-500 dark:text-slate-400">
                                <span x-text="[row.device, row.os, row.browser].filter(Boolean).join(' · ')"></span>
                            </td>
                            <td x-show="payload.visits.show_ip"
                                class="whitespace-nowrap px-5 py-2.5 font-mono text-xs text-slate-500 dark:text-slate-400"
                                x-text="row.ip || '—'"></td>
                            <td class="px-5 py-2.5 text-right">
                                <svg class="inline h-4 w-4 text-slate-300 transition-transform dark:text-slate-600"
                                     :class="openVisit === row.id ? 'rotate-90' : ''"
                                     viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M7.2 5.2a1 1 0 011.4 0l4.3 4.3a1 1 0 010 1.4l-4.3 4.3a1 1 0 11-1.4-1.4L10.8 10 7.2 6.6a1 1 0 010-1.4z" clip-rule="evenodd"/>
                                </svg>
                            </td>
                        </tr>

                        <tr x-show="openVisit === row.id" x-cloak>
                            <td colspan="9" class="bg-slate-50/70 px-5 py-3 dark:bg-slate-900/40">
                                <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                    <?= e(__('widget.visitors.session_pages')) ?>
                                    <span class="ml-2 font-mono normal-case text-slate-400"
                                          x-text="'<?= e(__('widget.visitors.visitor')) ?> ' + row.visitor"></span>
                                </p>
                                <p x-show="visitLoading" class="text-xs text-slate-400"><?= e(__('widget.visitors.loading')) ?></p>
                                <ol class="space-y-1.5">
                                    <template x-for="(page, index) in visitPages" :key="index">
                                        <li class="flex items-baseline gap-3 text-xs">
                                            <span class="w-24 shrink-0 tabular-nums text-slate-400" x-text="page.time"></span>
                                            <span class="truncate text-slate-600 dark:text-slate-300" x-text="page.path"></span>
                                            <span class="truncate text-slate-400" x-text="page.title"></span>
                                        </li>
                                    </template>
                                </ol>
                                <p x-show="!visitLoading && visitPages.length === 0" class="text-xs text-slate-400">
                                    <?= e(__('widget.visitors.no_pages')) ?>
                                </p>
                            </td>
                        </tr>
                </tbody>
            </template>
        </table>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-3 dark:border-slate-700/60">
        <p class="text-[11px] text-slate-400">
            <?= e(__('widget.visitors.retention_hint')) ?>
        </p>
        <button type="button" class="pb-btn-ghost !text-xs"
                x-show="payload.visits.truncated" x-cloak
                @click="loadMoreVisits()">
            <?= e(__('widget.visitors.show_more')) ?>
        </button>
    </div>

    <p x-show="payload.visits.rows.length === 0"
       class="px-5 py-8 text-center text-sm text-slate-400 dark:text-slate-500">
        <?= e(__('widget.visitors.empty')) ?>
    </p>
</section>
