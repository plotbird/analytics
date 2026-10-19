<?php
/**
 * Installer step 7: done. The one thing left is deleting /install.
 *
 * @var string $install_path
 * @var bool $installer_still_present
 * @var string $dashboard
 * @var string $cron_url
 *
 * @package PlotBird
 */
?>
<div class="space-y-4">
    <div class="pb-card p-6 text-center">
        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-400 to-emerald-600 text-white shadow-lg shadow-emerald-500/20">
            <svg class="h-7 w-7" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4l3.8 3.8 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd"/>
            </svg>
        </div>

        <h2 class="mt-4 text-lg font-semibold tracking-tight text-slate-900 dark:text-white">
            <?= e(__('install.done_title')) ?>
        </h2>
        <p class="mx-auto mt-2 max-w-lg text-sm text-slate-500 dark:text-slate-400">
            <?= e(__('install.done_body')) ?>
        </p>

        <div class="mt-6 flex flex-wrap items-center justify-center gap-2">
            <a href="<?= e($dashboard) ?>" class="pb-btn-primary"><?= e(__('install.go_dashboard')) ?></a>
            <a href="<?= e(rtrim($dashboard, '/')) ?>" class="pb-btn-ghost" target="_blank" rel="noopener noreferrer">
                <?= e(__('install.open_new_tab')) ?>
            </a>
        </div>
    </div>

    <div class="pb-card border-2 border-rose-200 dark:border-rose-500/40">
        <div class="pb-card-header">
            <h2 class="pb-card-title !text-rose-700 dark:!text-rose-300"><?= e(__('install.remove_title')) ?></h2>
        </div>
        <div class="space-y-3 p-5">
            <p class="text-sm text-slate-600 dark:text-slate-300"><?= e(__('install.remove_body')) ?></p>

            <div class="rounded-xl bg-slate-900 p-3.5">
                <code class="text-xs text-slate-100">rm -rf <?= e($install_path) ?></code>
            </div>

            <p class="text-xs text-slate-400">
                <?= e(__('install.remove_ftp')) ?>
            </p>

            <p class="rounded-xl bg-emerald-50 px-3.5 py-2.5 text-xs text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">
                <?= e(__('install.remove_locked')) ?>
            </p>
        </div>
    </div>

    <div class="pb-card">
        <div class="pb-card-header">
            <h2 class="pb-card-title"><?= e(__('install.next_steps_title')) ?></h2>
        </div>
        <ul class="space-y-3 p-5 text-sm text-slate-600 dark:text-slate-300">
            <li class="flex gap-3">
                <span class="text-sky-500">1.</span>
                <span><?= e(__('install.next_steps_tracker')) ?></span>
            </li>
            <li class="flex gap-3">
                <span class="text-sky-500">2.</span>
                <span>
                    <?= e(__('install.next_steps_cron')) ?>
                    <code class="mt-1 block break-all rounded-lg bg-slate-100 px-2.5 py-1.5 font-mono text-[11px] text-slate-600 dark:bg-slate-900/60 dark:text-slate-300"><?= e($cron_url) ?></code>
                </span>
            </li>
            <li class="flex gap-3">
                <span class="text-sky-500">3.</span>
                <span><?= e(__('install.next_steps_users')) ?></span>
            </li>
        </ul>
    </div>
</div>
