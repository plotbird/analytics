<?php
/**
 * Widget: main traffic chart (pageviews and unique visitors by day or hour).
 *
 * @package PlotBird
 */
?>
<section data-widget="chart" x-show="isVisible('chart')"
         class="pb-widget pb-card col-span-12 overflow-hidden">
    <div class="pb-card-header">
        <div>
            <h2 class="pb-card-title"><?= e(__('widget.chart.title')) ?></h2>
            <p class="text-xs text-slate-400">
                <span x-text="payload.series.granularity === 'hour' ? '<?= e(__('widget.chart.hourly')) ?>' : '<?= e(__('widget.chart.daily')) ?>'"></span>
                · <span x-text="fmtNumber(payload.summary.pageviews) + ' <?= e(__('metric.pageviews_short')) ?>'"></span>
                · <span x-text="fmtNumber(payload.summary.visitors) + ' <?= e(__('metric.visitors_short')) ?>'"></span>
            </p>
        </div>
        <span class="pb-drag-handle pb-btn-ghost !px-2" title="<?= e(__('widgets.drag')) ?>">
            <svg class="h-4 w-4 opacity-50" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M7 4.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM16 4.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"/>
            </svg>
        </span>
    </div>
    <div class="p-5">
        <div class="pb-chart h-[300px]">
            <canvas id="pb-main-chart" aria-label="<?= e(__('widget.chart.title')) ?>" role="img"></canvas>
        </div>
    </div>
</section>
