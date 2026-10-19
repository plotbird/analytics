<?php
/**
 * Widget: devices, operating systems and browsers.
 *
 * @package PlotBird
 */

/**
 * Renders one of the three breakdown columns.
 *
 * @param string $titleKey Locale key of the column heading.
 * @param string $listKey  Key inside payload.breakdown.
 * @param string $emptyKey Locale key of the empty state.
 */
$renderBreakdown = static function (string $titleKey, string $listKey, string $emptyKey): void {
    ?>
    <div>
        <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400"><?= e(__($titleKey)) ?></p>
        <ul class="space-y-2.5">
            <template x-for="row in payload.breakdown.<?= e($listKey) ?>" :key="row.value">
                <li>
                    <div class="flex items-center justify-between gap-2 text-sm">
                        <span class="truncate text-slate-700 dark:text-slate-200"
                              x-text="row.value === '' ? '<?= e(__('dimension.unknown')) ?>' : row.value"></span>
                        <span class="shrink-0 tabular-nums text-slate-500 dark:text-slate-400"
                              x-text="fmtNumber(row.visitors)"></span>
                    </div>
                    <div class="pb-bar-track mt-1.5">
                        <div class="pb-bar" :style="'width:' + widthPct(row, payload.breakdown.<?= e($listKey) ?>)"></div>
                    </div>
                </li>
            </template>
        </ul>
        <p x-show="!payload.breakdown.<?= e($listKey) ?>.length"
           class="py-4 text-sm text-slate-400 dark:text-slate-500"><?= e(__($emptyKey)) ?></p>
    </div>
    <?php
};
?>
<section data-widget="devices" x-show="isVisible('devices')"
         class="pb-widget pb-card col-span-12 xl:col-span-6 overflow-hidden">
    <div class="pb-card-header">
        <h2 class="pb-card-title"><?= e(__('widget.devices.title')) ?></h2>
        <span class="pb-drag-handle pb-btn-ghost !px-2" title="<?= e(__('widgets.drag')) ?>">
            <svg class="h-4 w-4 opacity-50" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M7 4.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM16 4.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"/>
            </svg>
        </span>
    </div>

    <div class="grid grid-cols-1 gap-6 p-5 sm:grid-cols-3">
        <?php
        $renderBreakdown('widget.devices.device', 'device', 'widget.devices.empty');
        $renderBreakdown('widget.devices.os', 'os', 'widget.devices.empty');
        $renderBreakdown('widget.devices.browser', 'browser', 'widget.devices.empty');
        ?>
    </div>
</section>
