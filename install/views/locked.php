<?php
/**
 * Shown when the installer is opened on an installation that already has an
 * administrator. This is what stops somebody from reinstalling over a live
 * instance and taking it over.
 *
 * @var string $dashboard
 *
 * @package PlotBird
 */
?>
<div class="pb-card p-8 text-center">
    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-900 text-white dark:bg-slate-700">
        <svg class="h-7 w-7" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M10 2a4 4 0 00-4 4v1H5.5A1.5 1.5 0 004 8.5v7A1.5 1.5 0 005.5 17h9a1.5 1.5 0 001.5-1.5v-7A1.5 1.5 0 0014.5 7H14V6a4 4 0 00-4-4zm2 5V6a2 2 0 10-4 0v1h4z" clip-rule="evenodd"/>
        </svg>
    </div>

    <h2 class="mt-4 text-lg font-semibold tracking-tight text-slate-900 dark:text-white">
        <?= e(__('install.locked_title')) ?>
    </h2>
    <p class="mx-auto mt-2 max-w-lg text-sm text-slate-500 dark:text-slate-400">
        <?= e(__('install.locked_body')) ?>
    </p>

    <div class="mt-6 flex flex-wrap items-center justify-center gap-2">
        <a href="<?= e($dashboard) ?>" class="pb-btn-primary"><?= e(__('install.go_dashboard')) ?></a>
        <a href="<?= e(url('login')) ?>" class="pb-btn-ghost"><?= e(__('nav.sign_in')) ?></a>
    </div>

    <div class="mt-8 rounded-xl bg-amber-50 px-4 py-3 text-left text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
        <p class="font-semibold"><?= e(__('install.remove_title')) ?></p>
        <p class="mt-1"><?= e(__('install.remove_body')) ?></p>
        <code class="mt-2 block font-mono">rm -rf <?= e(PB_ROOT) ?>/install</code>
    </div>
</div>
