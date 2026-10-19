<?php
/**
 * Widget: goals (conversions).
 *
 * Shows, per goal, how many visitors and visits converted, the conversion rate
 * against all visitors of the period, and the change against the previous
 * period. When a site has no goals yet the widget explains what a goal is and
 * links to the Websites page instead of showing an empty table.
 *
 * @package PlotBird
 */
?>
<section data-widget="goals" x-show="isVisible('goals')"
         class="pb-widget pb-card col-span-12 xl:col-span-6 overflow-hidden">
    <div class="pb-card-header">
        <div>
            <h2 class="pb-card-title"><?= e(__('widget.goals.title')) ?></h2>
            <p class="text-xs text-slate-400"><?= e(__('widget.goals.hint')) ?></p>
        </div>
        <span class="pb-drag-handle pb-btn-ghost !px-2" title="<?= e(__('widgets.drag')) ?>">
            <svg class="h-4 w-4 opacity-50" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M7 4.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM16 4.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"/>
            </svg>
        </span>
    </div>

    <div class="p-5">
        <ul class="space-y-4">
            <template x-for="goal in payload.goals.rows" :key="goal.id">
                <li>
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="truncate text-sm font-medium text-slate-700 dark:text-slate-200" x-text="goal.name"></span>
                            <span class="pb-badge shrink-0 bg-slate-100 text-slate-500 dark:bg-slate-700/60 dark:text-slate-400"
                                  x-text="goal.type === 'event' ? '<?= e(__('goals.type_event')) ?>' : '<?= e(__('goals.type_page')) ?>'"></span>
                        </div>
                        <div class="flex items-baseline gap-2">
                            <span class="text-lg font-bold tabular-nums text-slate-900 dark:text-white"
                                  x-text="fmtNumber(goal.visitors)"></span>
                            <span class="text-xs text-slate-400">
                                <?= e(__('widget.goals.converted')) ?>
                                <span class="tabular-nums" x-text="fmtPercent(goal.rate, 1)"></span>
                            </span>
                        </div>
                    </div>

                    <div class="mt-1.5 flex items-center gap-2">
                        <div class="pb-bar-track flex-1">
                            <div class="pb-bar" :style="'width:' + (goal.rate > 0 ? Math.max(2, Math.round(goal.rate * 100)) : 0) + '%'"></div>
                        </div>
                        <span class="w-24 shrink-0 text-right text-[11px] tabular-nums text-slate-400"
                              x-text="fmtNumber(goal.conversions) + ' ' + (goal.conversions === 1 ? '<?= e(__('widget.goals.visit_one')) ?>' : '<?= e(__('widget.goals.visit_many')) ?>')"></span>
                    </div>

                    <p class="mt-1 flex items-center gap-1.5 text-[11px] text-slate-400">
                        <code class="truncate rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-500 dark:bg-slate-900/60 dark:text-slate-400"
                              x-text="goal.match_value"></code>
                        <span class="shrink-0" x-text="deltaText(goal.delta)"></span>
                    </p>
                </li>
            </template>
        </ul>

        <!-- No goals yet: explain the feature instead of showing an empty table -->
        <div x-show="!payload.goals.has_goals" x-cloak class="rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-900/40">
            <p class="text-sm text-slate-600 dark:text-slate-300"><?= e(__('widget.goals.empty_title')) ?></p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400"><?= e(__('widget.goals.empty_body')) ?></p>
        </div>
    </div>
</section>
