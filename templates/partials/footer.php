<?php
/**
 * Shared footer.
 *
 * Included by every layout so the product name, the tagline and the source
 * link stay identical everywhere. The tagline differs per layout (the public
 * share page says "shared dashboard" instead of "cookie-free analytics"),
 * which is why it is passed in.
 *
 * @var string      $app_name
 * @var string      $app_version
 * @var string|null $tagline  Already translated; defaults to footer.cookie_free.
 *
 * @package PlotBird
 */

$tagline = $tagline ?? __('footer.cookie_free');
$repository = repository_url();
?>
<footer class="mt-auto border-t border-slate-200/70 bg-white/70 px-4 py-4 text-center text-xs text-slate-400 sm:px-6 dark:border-slate-700/60 dark:bg-slate-900/50 dark:text-slate-500">
    <div class="mx-auto flex max-w-[1600px] flex-wrap items-center justify-center gap-x-2 gap-y-1">
        <span><?= e($app_name) ?> <?= e($app_version) ?></span>
        <span aria-hidden="true">·</span>
        <span><?= e($tagline) ?></span>
        <span aria-hidden="true">·</span>
        <a href="<?= e($repository) ?>" target="_blank" rel="noopener noreferrer"
           class="inline-flex items-center gap-1.5 transition-colors hover:text-sky-600 dark:hover:text-sky-400">
            <svg class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0016 8c0-4.42-3.58-8-8-8z"/>
            </svg>
            <span>Open Source</span>
        </a>
    </div>
</footer>
