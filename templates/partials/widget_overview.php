<?php
/**
 * Widget: overview cards (pageviews, visitors, sessions, average time,
 * bounce rate) with a comparison against the previous period.
 *
 * @package PlotBird
 */

/**
 * Renders one metric card.
 *
 * @param string $key      Payload key inside "summary".
 * @param string $titleKey Locale key of the label.
 * @param string $hintKey  Locale key of the hint under the value.
 * @param string $format   number|duration|percent
 */
$renderCard = static function (string $key, string $titleKey, string $format, string $deltaKey = ''): void {
    $valueExpression = match ($format) {
        'duration' => 'fmtDuration(payload.summary.' . $key . ')',
        'percent'  => 'fmtPercent(payload.summary.' . $key . ', 1)',
        default    => 'fmtNumber(payload.summary.' . $key . ')',
    };
    ?>
    <div class="rounded-2xl bg-slate-50/70 p-4 ring-1 ring-slate-100 dark:bg-slate-900/40 dark:ring-slate-700/50">
        <div class="flex items-start justify-between gap-2">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400"><?= e(__($titleKey)) ?></p>
            <?php if ($deltaKey !== ''): ?>
                <span class="pb-badge"
                      :class="{
                          'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300': delta('<?= e($deltaKey) ?>').direction === 'up',
                          'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300': delta('<?= e($deltaKey) ?>').direction === 'down',
                          'bg-slate-100 text-slate-500 dark:bg-slate-700/60 dark:text-slate-400': delta('<?= e($deltaKey) ?>').direction === 'flat',
                          'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300': delta('<?= e($deltaKey) ?>').direction === 'new'
                      }"
                      x-text="delta('<?= e($deltaKey) ?>').text"></span>
            <?php endif; ?>
        </div>
        <p class="mt-2 text-2xl font-bold tracking-tight text-slate-900 dark:text-white"
           x-text="<?= e($valueExpression) ?>">0</p>
    </div>
    <?php
};
?>
<section data-widget="overview" x-show="isVisible('overview')"
         class="pb-widget pb-card col-span-12 overflow-hidden">
    <div class="pb-card-header">
        <div>
            <h2 class="pb-card-title"><?= e(__('widget.overview.title')) ?></h2>
            <p class="text-xs text-slate-400">
                <?= e(__('widget.overview.compare')) ?>
                <span x-text="payload.previous.period.from + ' → ' + payload.previous.period.to"></span>
            </p>
        </div>
        <span class="pb-drag-handle pb-btn-ghost !px-2" title="<?= e(__('widgets.drag')) ?>">
            <svg class="h-4 w-4 opacity-50" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M7 4.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM16 4.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"/>
            </svg>
        </span>
    </div>

    <div class="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2 xl:grid-cols-5">
        <?php
        $renderCard('pageviews', 'metric.pageviews', 'number', 'pageviews');
        $renderCard('visitors', 'metric.unique_visitors', 'number', 'visitors');
        $renderCard('sessions', 'metric.sessions', 'number', 'sessions');
        $renderCard('avg_duration', 'metric.avg_time', 'duration', 'avg_duration');
        $renderCard('bounce_rate', 'metric.bounce_rate', 'percent', 'bounce_rate');
        ?>
    </div>

    <!-- Sparklines ------------------------------------------------------- -->
    <div class="grid grid-cols-1 gap-4 border-t border-slate-100 px-5 py-4 sm:grid-cols-3 dark:border-slate-700/60">
        <?php foreach ([['pageviews', 'metric.pageviews', '#0ea5e9'], ['visitors', 'metric.unique_visitors', '#22d3ee'], ['sessions', 'metric.sessions', '#6366f1']] as $spark): ?>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400"><?= e(__($spark[1])) ?></p>
                <svg class="mt-1 h-7 w-full" viewBox="0 0 100 28" preserveAspectRatio="none" aria-hidden="true">
                    <path :d="sparkPath(payload.sparklines.<?= e($spark[0]) ?>)"
                          fill="none" stroke="<?= e($spark[2]) ?>" stroke-width="1.6"
                          stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"></path>
                </svg>
            </div>
        <?php endforeach; ?>
    </div>
</section>
