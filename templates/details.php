<?php
/**
 * PlotBird Analytics - the drill-down page.
 *
 * @var array<string,mixed> $site
 * @var array<string,mixed> $period
 * @var string $dimension
 * @var string $value
 * @var string $dimension_label
 * @var array<string,int> $summary
 * @var array<string,mixed> $series
 * @var array<string,list<array<string,mixed>>> $breakdowns
 *
 * @package PlotBird
 */

/** Renders one breakdown block. */
$renderBlock = static function (string $title, array $rows, bool $isPath = false): void {
    $max = 1;
    foreach ($rows as $row) {
        $max = max($max, (int) ($row['pageviews'] ?? 0));
    }
    ?>
    <div class="pb-card p-5">
        <h2 class="pb-card-title mb-3"><?= e($title) ?></h2>

        <?php if ($rows === []): ?>
            <p class="py-4 text-sm text-slate-400 dark:text-slate-500"><?= e(__('details.empty')) ?></p>
        <?php else: ?>
            <table class="pb-detail-table">
                <thead>
                    <tr>
                        <th><?= e(__('metric.value')) ?></th>
                        <th class="pb-num"><?= e(__('metric.pageviews')) ?></th>
                        <th class="pb-num"><?= e(__('metric.visitors')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $label = (string) ($row['value'] ?? '');
                        $width = (int) round(((int) ($row['pageviews'] ?? 0)) / $max * 100);
                        ?>
                        <tr>
                            <td>
                                <?php if ($isPath && $label !== ''): ?>
                                    <a class="text-slate-700 hover:text-sky-600 dark:text-slate-200 dark:hover:text-sky-400"
                                       href="<?= e($label) ?>" target="_blank" rel="noopener noreferrer nofollow">
                                        <?= e($label) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-slate-700 dark:text-slate-200">
                                        <?= e($label === '' ? __('dimension.unknown') : $label) ?>
                                    </span>
                                <?php endif; ?>
                                <div class="pb-bar-track mt-1.5">
                                    <div class="pb-bar" style="width: <?= e((string) $width) ?>%"></div>
                                </div>
                            </td>
                            <td class="pb-num text-slate-700 dark:text-slate-200">
                                <?= e(format_int((int) ($row['pageviews'] ?? 0))) ?>
                            </td>
                            <td class="pb-num text-slate-400">
                                <?= e(format_int((int) ($row['visitors'] ?? 0))) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
};

$backUrl = url((string) config('app.pretty_urls', true) ? 'dashboard' : 'dashboard', ['site' => (int) $site['id'], 'preset' => (string) $period['preset']]);
$total = (int) ($summary['pageviews'] ?? 0);
?>

<div class="space-y-5">

    <!-- Header ----------------------------------------------------------- -->
    <div class="flex flex-wrap items-center gap-3">
        <a class="pb-btn-ghost !px-3" href="<?= e($backUrl) ?>">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M12.7 4.3a1 1 0 010 1.4L8.4 10l4.3 4.3a1 1 0 11-1.4 1.4l-5-5a1 1 0 010-1.4l5-5a1 1 0 011.4 0z" clip-rule="evenodd"/>
            </svg>
            <span class="hidden sm:inline"><?= e(__('details.back_to_dashboard')) ?></span>
        </a>

        <div class="min-w-0">
            <h1 class="truncate text-lg font-semibold tracking-tight text-slate-900 dark:text-white">
                <?= e($dimension_label) ?>: <span class="text-sky-600 dark:text-sky-400"><?= e($value === '' ? ($dimension === 'referrer' ? __('dimension.direct') : __('dimension.unknown')) : $value) ?></span>
            </h1>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                <?= e((string) $period['from']) ?> → <?= e((string) $period['to']) ?>
                · <?= e(__('details.filtered_by')) ?>: <?= e($dimension_label) ?>
            </p>
        </div>

        <?php if (in_array($dimension, ['path', 'referrer'], true) && str_starts_with($value, 'http')): ?>
            <a class="ml-auto pb-btn-ghost !px-3" href="<?= e($value) ?>" target="_blank" rel="noopener noreferrer nofollow">
                <?= e(__('details.open_page')) ?>
            </a>
        <?php endif; ?>
    </div>

    <!-- Totals ----------------------------------------------------------- -->
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
        <?php
        $cards = [
            ['metric.pageviews', (int) ($summary['pageviews'] ?? 0)],
            ['metric.visitors', (int) ($summary['visitors'] ?? 0)],
            ['metric.sessions', (int) ($summary['sessions'] ?? 0)],
        ];
        ?>
        <?php foreach ($cards as [$labelKey, $number]): ?>
            <div class="pb-card p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400"><?= e(__($labelKey)) ?></p>
                <p class="mt-1 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    <?= e(format_int($number)) ?>
                </p>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($total === 0): ?>
        <div class="pb-card p-6 text-center text-sm text-slate-500 dark:text-slate-400">
            <?= e(__('details.empty')) ?>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
            <?php
            $renderBlock(__('widget.top_pages.title'), $breakdowns['path'] ?? [], true);
            $renderBlock(__('widget.referrers.title'), $breakdowns['referrer'] ?? []);
            $renderBlock(__('widget.geo.title'), $breakdowns['country'] ?? []);
            $renderBlock(__('widget.devices.device'), $breakdowns['device'] ?? []);
            $renderBlock(__('widget.devices.os'), $breakdowns['os'] ?? []);
            $renderBlock(__('widget.devices.browser'), $breakdowns['browser'] ?? []);
            ?>
        </div>
    <?php endif; ?>
</div>
