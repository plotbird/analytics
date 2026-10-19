<?php
/**
 * Widget: countries, with the map and the list on separate tabs.
 *
 * @package PlotBird
 */
?>
<section data-widget="geo" x-data="{ geoView: 'map' }" x-show="isVisible('geo')"
         class="pb-widget pb-card col-span-12 xl:col-span-6 overflow-hidden">
    <div class="pb-card-header flex-wrap gap-3">
        <div>
            <h2 class="pb-card-title"><?= e(__('widget.geo.title')) ?></h2>
            <p class="text-xs text-slate-400">
                <?= e(__('widget.geo.hint')) ?>
                <span x-text="' · ' + payload.period.from + ' → ' + payload.period.to"></span>
            </p>
        </div>

        <div class="flex items-center gap-3">
            <div class="pb-tabs" role="tablist">
                <button type="button" class="pb-tab" role="tab" :aria-selected="geoView === 'map'"
                        @click="geoView = 'map'; window.dispatchEvent(new CustomEvent('pb:map-resize'))"><?= e(__('widget.geo.tab_map')) ?></button>
                <button type="button" class="pb-tab" role="tab" :aria-selected="geoView === 'list'"
                        @click="geoView = 'list'"><?= e(__('widget.geo.tab_list')) ?></button>
            </div>

            <span class="pb-drag-handle pb-btn-ghost !px-2" title="<?= e(__('widgets.drag')) ?>">
                <svg class="h-4 w-4 opacity-50" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M7 4.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM16 4.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm0 5.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"/>
                </svg>
            </span>
        </div>
    </div>

    <div class="p-5">
        <div x-show="geoView === 'map'">
            <div id="pb-world-map"
                 class="pb-chart pb-map w-full overflow-hidden rounded-xl bg-slate-50/60 dark:bg-slate-900/40"></div>
        </div>

        <div x-show="geoView === 'list'" x-cloak>
            <ul class="space-y-2.5">
                <template x-for="row in payload.top.country" :key="row.value">
                    <li class="grid grid-cols-[1fr_auto_auto] items-center gap-3">
                        <a class="pb-row-link min-w-0 px-1 py-1"
                           <?= ($can_drill ?? false) ? ':href="detailUrl(\'country\', row.value)"' : '' ?>
                           :title="'<?= e(__('details.title')) ?>: ' + (row.name || row.value)">
                            <div class="flex items-center gap-2">
                                <img :src="row.flag_url" alt="" width="20" height="15" loading="lazy" class="pb-flag">
                                <span class="truncate text-sm text-slate-700 dark:text-slate-200" x-text="row.name"></span>
                            </div>
                            <div class="pb-bar-track mt-1.5">
                                <div class="pb-bar" :style="'width:' + widthPct(row, payload.top.country)"></div>
                            </div>
                        </a>
                        <span class="text-right text-sm font-medium tabular-nums text-slate-700 dark:text-slate-200"
                              x-text="fmtNumber(row.visitors)"></span>
                        <span class="w-14 text-right text-xs tabular-nums text-slate-400"
                              x-text="sharePct(row, payload.top.country)"></span>
                    </li>
                </template>
            </ul>

            <p x-show="!payload.top.country.length" class="py-6 text-center text-sm text-slate-400 dark:text-slate-500">
                <?= e(__('widget.geo.empty')) ?>
            </p>
        </div>
    </div>
</section>
