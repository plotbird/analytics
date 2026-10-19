<?php
/**
 * Widget: devices, operating systems and browsers, one at a time behind tabs.
 *
 * @package PlotBird
 */

/**
 * Renders one of the three breakdowns.
 *
 * @param string $dimensionKey Key inside payload.breakdown and the filter name.
 * @param string $emptyKey     Locale key of the empty state.
 */
$renderBreakdown = static function (string $dimensionKey, string $emptyKey): void {
    ?>
    <div>
        <ul class="space-y-2.5">
            <template x-for="row in payload.breakdown.<?= e($dimensionKey) ?>" :key="row.value">
                <li>
                    <a class="pb-row-link px-1 py-1"
                       <?= ($can_drill ?? false) ? ':href="detailUrl(\'' . e($dimensionKey) . '\', row.value)"' : '' ?>>
                        <div class="flex items-center justify-between gap-2 text-sm">
                            <span class="flex min-w-0 items-center gap-2">
                                <template x-if="brandIconUrl(row.value)">
                                    <span class="pb-icon">
                                        <img class="pb-brand" :src="brandIconUrl(row.value)" :alt="row.value" loading="lazy" width="16" height="16">
                                    </span>
                                </template>
                                <template x-if="!brandIconUrl(row.value) && deviceGlyph(row.value).length">
                                    <span class="pb-icon pb-icon-plain">
                                        <svg class="pb-brand" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                            <path :d="deviceGlyph(row.value)[0]"></path>
                                        </svg>
                                    </span>
                                </template>
                                <template x-if="!brandIconUrl(row.value) && !deviceGlyph(row.value).length">
                                    <span class="pb-mono"
                                          :style="'background:' + monogram(row.value).color"
                                          x-text="monogram(row.value).label"></span>
                                </template>
                                <span class="truncate text-slate-700 dark:text-slate-200"
                                      x-text="row.value === '' ? '<?= e(__('dimension.unknown')) ?>' : row.value"></span>
                            </span>
                            <span class="shrink-0 tabular-nums text-slate-500 dark:text-slate-400"
                                  x-text="fmtNumber(row.visitors)"></span>
                        </div>
                        <div class="pb-bar-track mt-1.5">
                            <div class="pb-bar" :style="'width:' + widthPct(row, payload.breakdown.<?= e($dimensionKey) ?>)"></div>
                        </div>
                    </a>
                </li>
            </template>
        </ul>
        <p x-show="!payload.breakdown.<?= e($dimensionKey) ?>.length"
           class="py-4 text-sm text-slate-400 dark:text-slate-500"><?= e(__($emptyKey)) ?></p>
    </div>
    <?php
};
?>
<section data-widget="devices" x-data="{ devView: 'device' }" x-show="isVisible('devices')"
         class="pb-widget pb-card col-span-12 xl:col-span-6 overflow-hidden">
    <div class="pb-card-header flex-wrap gap-3">
        <div>
            <h2 class="pb-card-title"><?= e(__('widget.devices.title')) ?></h2>
            <p class="text-xs text-slate-400">
                <span x-text="payload.period.from + ' → ' + payload.period.to"></span>
            </p>
        </div>

        <div class="flex items-center gap-3">
            <div class="pb-tabs" role="tablist">
                <button type="button" class="pb-tab" role="tab" :aria-selected="devView === 'device'"
                        @click="devView = 'device'"><?= e(__('widget.devices.device')) ?></button>
                <button type="button" class="pb-tab" role="tab" :aria-selected="devView === 'os'"
                        @click="devView = 'os'"><?= e(__('widget.devices.os')) ?></button>
                <button type="button" class="pb-tab" role="tab" :aria-selected="devView === 'browser'"
                        @click="devView = 'browser'"><?= e(__('widget.devices.browser')) ?></button>
            </div>

            <span class="pb-drag-handle pb-btn-ghost !px-2" title="<?= e(__('widgets.drag')) ?>">
                <svg class="h-4 w-4 opacity-50" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M7 4.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM16 4.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"/>
                </svg>
            </span>
        </div>
    </div>

    <div class="p-5">
        <div x-show="devView === 'device'"><?php $renderBreakdown('device', 'widget.devices.empty'); ?></div>
        <div x-show="devView === 'os'" x-cloak><?php $renderBreakdown('os', 'widget.devices.empty'); ?></div>
        <div x-show="devView === 'browser'" x-cloak><?php $renderBreakdown('browser', 'widget.devices.empty'); ?></div>
    </div>
</section>
