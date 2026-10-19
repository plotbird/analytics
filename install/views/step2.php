<?php
/**
 * Installer step 2: environment check.
 *
 * @var list<array{label:string,ok:bool,required:bool,detail:string}> $checks
 * @var bool $can_continue
 *
 * @package PlotBird
 */
?>
<div class="pb-card overflow-hidden">
    <div class="pb-card-header">
        <h2 class="pb-card-title"><?= e(__('install.requirements_title')) ?></h2>
        <span class="pb-badge <?= $can_continue
            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
            : 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' ?>">
            <?= e($can_continue ? __('install.requirements_ok') : __('install.requirements_failed')) ?>
        </span>
    </div>

    <ul class="divide-y divide-slate-100 dark:divide-slate-700/60">
        <?php foreach ($checks as $check): ?>
            <li class="flex items-start gap-3 px-5 py-3">
                <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold text-white
                    <?= $check['ok'] ? 'bg-emerald-500' : ($check['required'] ? 'bg-rose-500' : 'bg-amber-500') ?>">
                    <?= $check['ok'] ? '✓' : '!' ?>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-slate-700 dark:text-slate-200"><?= e($check['label']) ?></p>
                    <p class="text-xs text-slate-400"><?= e($check['detail']) ?></p>
                </div>
                <?php if (!$check['required']): ?>
                    <span class="pb-badge bg-slate-100 text-slate-500 dark:bg-slate-700/60 dark:text-slate-400">
                        <?= e(__('install.optional')) ?>
                    </span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4 dark:border-slate-700/60">
        <a href="<?= e(root_url('install/') . '?step=1') ?>" class="pb-btn-ghost"><?= e(__('common.back')) ?></a>

        <div class="flex items-center gap-3">
            <?php if (!$can_continue): ?>
                <p class="text-xs text-rose-600 dark:text-rose-400"><?= e(__('install.requirements_hint')) ?></p>
            <?php endif; ?>
            <?php if ($can_continue): ?>
                <a href="<?= e(root_url('install/') . '?step=3') ?>" class="pb-btn-primary"><?= e(__('install.continue')) ?></a>
            <?php else: ?>
                <a href="<?= e(root_url('install/') . '?step=2') ?>" class="pb-btn-ghost"><?= e(__('install.recheck')) ?></a>
            <?php endif; ?>
        </div>
    </div>
</div>
