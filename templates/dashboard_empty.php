<?php
/**
 * Dashboard shown when the signed-in account has no website yet.
 *
 * @var bool $can_manage
 *
 * @package PlotBird
 */
?>
<div class="mx-auto max-w-2xl py-16 text-center">
    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-sky-400 to-sky-600 text-white shadow-lg shadow-sky-500/20">
        <svg class="h-7 w-7" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path d="M10 2a1 1 0 011 1v6h6a1 1 0 110 2h-6v6a1 1 0 11-2 0v-6H3a1 1 0 110-2h6V3a1 1 0 011-1z"/>
        </svg>
    </div>
    <h1 class="mt-5 text-xl font-semibold tracking-tight text-slate-900 dark:text-white">
        <?= e(__('dashboard.empty_title')) ?>
    </h1>
    <p class="mx-auto mt-2 max-w-md text-sm text-slate-500 dark:text-slate-400">
        <?= e($can_manage ? __('dashboard.empty_body_admin') : __('dashboard.empty_body_client')) ?>
    </p>

    <?php if ($can_manage): ?>
        <a href="<?= e(url('sites')) ?>" class="pb-btn-primary mt-6">
            <?= e(__('sites.add_first')) ?>
        </a>
    <?php endif; ?>
</div>
