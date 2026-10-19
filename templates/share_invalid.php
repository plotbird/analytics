<?php
/**
 * Shown when a share link is unknown, revoked or expired. The three cases look
 * identical on purpose.
 *
 * @package PlotBird
 */
?>
<div class="pb-card p-8 text-center">
    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400 dark:bg-slate-700/60 dark:text-slate-400">
        <svg class="h-6 w-6" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M10 2a4 4 0 00-4 4v1H5.5A1.5 1.5 0 004 8.5v7A1.5 1.5 0 005.5 17h9a1.5 1.5 0 001.5-1.5v-7A1.5 1.5 0 0014.5 7H14V6a4 4 0 00-4-4zm2 5V6a2 2 0 10-4 0v1h4z" clip-rule="evenodd"/>
        </svg>
    </div>
    <h1 class="mt-4 text-lg font-semibold text-slate-900 dark:text-white"><?= e(__('share.invalid_title')) ?></h1>
    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400"><?= e(__('share.invalid_body')) ?></p>
</div>
