<?php
/**
 * Installer layout: the wizard shell and the step indicator.
 *
 * @var string $content
 * @var int $step
 * @var array<string,array<string,string>> $locales
 * @var string $locale
 *
 * @package PlotBird
 */

use PlotBird\View;

$stepLabels = [
    1 => __('install.step_language'),
    2 => __('install.step_requirements'),
    3 => __('install.step_database'),
    4 => __('install.step_schema'),
    5 => __('install.step_admin'),
    6 => __('install.step_site'),
    7 => __('install.step_finish'),
];
$step = (int) ($step ?? 1);
?>
<!doctype html>
<html lang="<?= e($locale) ?>" class="h-full">
<head>
    <?php View::partial('partials/head', [
        'page_title'  => __('install.title'),
        'app_name'    => 'PlotBird Analytics',
        'locale'      => $locale,
        'page_assets' => [],
        // The installer is temporary: no manifest, no service worker.
        'pwa'         => false,
    ]); ?>
</head>
<body class="min-h-full bg-gradient-to-br from-sky-50 via-slate-50 to-cyan-50 text-slate-800 antialiased dark:from-slate-900 dark:via-slate-900 dark:to-slate-800 dark:text-slate-200"
      x-data="pbShell()"
      x-cloak>

<div class="mx-auto flex min-h-screen max-w-3xl flex-col px-4 py-8 sm:px-6">

    <header class="mb-6 flex flex-wrap items-center gap-3">
        <img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="36" height="36" class="h-9 w-9 rounded-xl">
        <div>
            <h1 class="text-base font-semibold tracking-tight text-slate-900 dark:text-white">
                <?= e(__('install.title')) ?>
            </h1>
            <p class="text-xs text-slate-500 dark:text-slate-400"><?= e(__('install.subtitle')) ?></p>
        </div>

        <div class="ml-auto flex items-center gap-2">
            <button type="button" @click="toggleTheme()" class="pb-btn-ghost !px-2.5" title="<?= e(__('nav.toggle_theme')) ?>">
                <svg x-show="!dark" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4.2 2.3a1 1 0 011.4 0 1 1 0 010 1.4l-.7.7a1 1 0 11-1.4-1.4l.7-.7zM18 9a1 1 0 110 2h-1a1 1 0 110-2h1zM5.1 4.3a1 1 0 010 1.4l-.7.7A1 1 0 013 5l.7-.7a1 1 0 011.4 0zM3 9a1 1 0 110 2H2a1 1 0 110-2h1zm2.1 6.7a1 1 0 011.4-1.4l.7.7a1 1 0 01-1.4 1.4l-.7-.7zM10 15a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zm4.2-1.3a1 1 0 011.4 1.4l-.7.7a1 1 0 01-1.4-1.4l.7-.7zM10 6a4 4 0 100 8 4 4 0 000-8z"/>
                </svg>
                <svg x-show="dark" x-cloak class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M17.3 12.3A7.5 7.5 0 017.7 2.7a7.5 7.5 0 109.6 9.6z"/>
                </svg>
            </button>

            <?php if ($step > 0): ?>
                <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
                    <button type="button" @click="open = !open" class="pb-btn-ghost !px-2.5">
                        <?= country_flag_img($locales[$locale]['country'] ?? 'xx') ?>
                        <span class="text-xs font-semibold uppercase"><?= e($locale) ?></span>
                    </button>
                    <div x-show="open" x-transition.origin.top.right @click.outside="open = false"
                         class="absolute right-0 z-40 mt-2 w-52 overflow-hidden rounded-2xl bg-white p-1.5 shadow-xl ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
                        <?php foreach ($locales as $code => $meta): ?>
                            <a href="<?= e(root_url('install/') . '?action=set-locale&locale=' . urlencode($code) . '&step=' . $step) ?>"
                               class="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm <?= $code === $locale ? 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300' : 'text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-700/60' ?>">
                                <?= country_flag_img($meta['country'] ?? 'xx') ?>
                                <span><?= e($meta['native']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($step > 0): ?>
        <ol class="mb-6 flex flex-wrap items-center gap-x-2 gap-y-2 text-xs">
            <?php foreach ($stepLabels as $number => $label): ?>
                <li class="flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full text-[11px] font-semibold
                        <?= $number < $step
                            ? 'bg-emerald-500 text-white'
                            : ($number === $step ? 'bg-sky-600 text-white' : 'bg-slate-200 text-slate-500 dark:bg-slate-700 dark:text-slate-400') ?>">
                        <?= $number < $step ? '✓' : $number ?>
                    </span>
                    <span class="<?= $number === $step ? 'font-semibold text-slate-700 dark:text-slate-200' : 'text-slate-400' ?>">
                        <?= e($label) ?>
                    </span>
                    <?php if ($number < 7): ?>
                        <span class="mx-1 hidden h-px w-4 bg-slate-300 sm:block dark:bg-slate-600"></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <?php if (!empty($flash_error)): ?>
        <div class="mb-4 rounded-2xl bg-rose-600 px-4 py-3 text-sm font-medium text-white shadow-lg">
            <?= e($flash_error) ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($flash_success)): ?>
        <div class="mb-4 rounded-2xl bg-emerald-600 px-4 py-3 text-sm font-medium text-white shadow-lg">
            <?= e($flash_success) ?>
        </div>
    <?php endif; ?>

    <main class="flex-1">
        <?= $content ?>
    </main>

    <?php View::partial('partials/footer', [
        'app_name'    => 'PlotBird Analytics',
        'app_version' => $app_version ?? '',
        'tagline'     => __('footer.cookie_free'),
    ]); ?>
</div>

<script>
    // Assigned with merge semantics, not replacement: a step view renders above
    // this script and may already have put its own endpoint into
    // window.PB.endpoints (step 4 does). Replacing the object here would wipe it
    // and silently send that request to the fallback URL instead.
    window.PB = window.PB || {};
    window.PB.basePath = <?= json_encode(pb_base_path(), JSON_UNESCAPED_SLASHES) ?>;
    window.PB.locale = <?= json_encode($locale) ?>;
    window.PB.csrf = <?= json_encode(\PlotBird\Security::csrfToken()) ?>;
    window.PB.endpoints = Object.assign({}, window.PB.endpoints || {}, {
        install: <?= json_encode(root_url('install/'), JSON_UNESCAPED_SLASHES) ?>
    });
    window.PB.decimalSeparator = '.';
    window.PB.thousandsSeparator = ' ';
</script>
<script src="<?= e(asset('vendor/alpine.min.js')) ?>" defer></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
