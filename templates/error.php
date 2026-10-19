<?php
/**
 * Error page (403/404/405/419/500).
 *
 * @var int $status
 * @var string $title
 * @var string $message
 *
 * @package PlotBird
 */
?>
<div class="mx-auto max-w-lg py-16 text-center">
    <p class="text-6xl font-black tracking-tight text-sky-500/80"><?= e((string) ($status ?? 500)) ?></p>
    <h1 class="mt-4 text-xl font-semibold text-slate-900 dark:text-white"><?= e($title ?? '') ?></h1>
    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400"><?= e($message ?? '') ?></p>
    <div class="mt-7 flex items-center justify-center gap-2">
        <a href="<?= e(url('dashboard')) ?>" class="pb-btn-primary"><?= e(__('common.back_to_dashboard')) ?></a>
        <a href="javascript:history.back()" class="pb-btn-ghost"><?= e(__('common.back')) ?></a>
    </div>
</div>
