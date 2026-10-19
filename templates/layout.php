<?php
/**
 * Application layout: the header, the navigation, the flash messages and the
 * script tags every signed-in page needs.
 *
 * @var string $content            Rendered page body.
 * @var array|null $user           Signed-in user row.
 * @var list<array<string,mixed>> $sites
 * @var array|null $current_site
 * @var array<string,array<string,string>> $locales
 * @var string $locale
 * @var string $app_name
 * @var string $app_version
 * @var bool $installer_present
 * @var string|null $flash_success
 * @var string|null $flash_error
 * @var string $page_title
 * @var list<string> $page_assets  Optional: chart, map, sortable.
 *
 * @package PlotBird
 */

use PlotBird\Auth;
use PlotBird\View;

$page_title = $page_title ?? '';
$page_assets = $page_assets ?? [];
$isAdmin = Auth::isSuperAdmin();
$currentPath = $current_path ?? '';
?>
<!doctype html>
<html lang="<?= e($locale) ?>" class="h-full">
<head>
    <?php View::partial('partials/head', [
        'page_title'  => $page_title,
        'app_name'    => $app_name,
        'locale'      => $locale,
        'page_assets' => $page_assets,
    ]); ?>
</head>
<body class="flex min-h-screen flex-col bg-slate-50 text-slate-800 antialiased dark:bg-slate-900 dark:text-slate-200"
      x-data="pbShell()"
      x-cloak>

<header class="sticky top-0 z-30 border-b border-slate-200/70 bg-white/85 backdrop-blur dark:border-slate-700/60 dark:bg-slate-900/85">
    <div class="mx-auto flex h-16 max-w-[1600px] items-center gap-3 px-4 sm:px-6">

        <a href="<?= e(url('dashboard')) ?>" class="flex shrink-0 items-center gap-2.5">
            <img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="32" height="32" class="h-8 w-8">
            <span class="hidden text-[15px] font-semibold tracking-tight text-slate-900 sm:block dark:text-white">
                <?= e($app_name) ?>
            </span>
        </a>

        <?php if (!empty($sites)): ?>
            <div class="relative ml-1" x-data="{ open: false }" @keydown.escape.window="open = false">
                <button type="button" @click="open = !open"
                        class="pb-btn-ghost !px-3 max-w-[16rem] truncate"
                        :aria-expanded="open.toString()" aria-haspopup="listbox">
                    <span class="inline-block h-2 w-2 shrink-0 rounded-full bg-emerald-500"></span>
                    <span class="truncate">
                        <?= e($current_site['name'] ?? __('sites.select_site')) ?>
                    </span>
                    <svg class="h-4 w-4 shrink-0 opacity-60" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
                    </svg>
                </button>
                <div x-show="open" x-transition.origin.top.left @click.outside="open = false"
                     class="absolute left-0 z-40 mt-2 w-72 overflow-hidden rounded-2xl bg-white p-1.5 shadow-xl ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700"
                     role="listbox">
                    <div class="pb-scroll max-h-72 overflow-y-auto">
                        <?php foreach ($sites as $site): ?>
                            <?php $isCurrent = (int) ($current_site['id'] ?? 0) === (int) $site['id']; ?>
                            <a href="<?= e(url('dashboard', ['site' => (int) $site['id']])) ?>"
                               class="flex items-center justify-between gap-2 rounded-xl px-3 py-2 text-sm <?= $isCurrent ? 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300' : 'text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-700/60' ?>">
                                <span class="truncate"><?= e($site['name']) ?></span>
                                <?php if ($isCurrent): ?>
                                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4l3.8 3.8 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd"/>
                                    </svg>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($isAdmin): ?>
                        <a href="<?= e(url('sites')) ?>"
                           class="mt-1 block rounded-xl border-t border-slate-100 px-3 py-2 text-sm font-medium text-sky-600 hover:bg-slate-50 dark:border-slate-700 dark:text-sky-400 dark:hover:bg-slate-700/60">
                            <?= e(__('sites.manage')) ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <nav class="ml-auto hidden items-center gap-1 md:flex">
            <a href="<?= e(url('dashboard')) ?>"
               class="pb-btn-ghost <?= $currentPath === 'dashboard' || $currentPath === '' ? '!text-sky-600 dark:!text-sky-400' : '' ?>">
                <?= e(__('nav.dashboard')) ?>
            </a>
            <a href="<?= e(url('sites')) ?>"
               class="pb-btn-ghost <?= $currentPath === 'sites' ? '!text-sky-600 dark:!text-sky-400' : '' ?>">
                <?= e($isAdmin ? __('nav.sites') : __('nav.my_sites')) ?>
            </a>
            <?php if ($isAdmin): ?>
                <a href="<?= e(url('users')) ?>"
                   class="pb-btn-ghost <?= $currentPath === 'users' ? '!text-sky-600 dark:!text-sky-400' : '' ?>">
                    <?= e(__('nav.users')) ?>
                </a>
                <a href="<?= e(url('settings')) ?>"
                   class="pb-btn-ghost <?= $currentPath === 'settings' ? '!text-sky-600 dark:!text-sky-400' : '' ?>">
                    <?= e(__('nav.settings')) ?>
                </a>
            <?php endif; ?>
        </nav>

        <div class="ml-auto flex items-center gap-1 md:ml-2">
            <button type="button" @click="toggleTheme()"
                    class="pb-btn-ghost !px-2.5" title="<?= e(__('nav.toggle_theme')) ?>">
                <svg x-show="!dark" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4.2 2.3a1 1 0 011.4 0 1 1 0 010 1.4l-.7.7a1 1 0 11-1.4-1.4l.7-.7zM18 9a1 1 0 110 2h-1a1 1 0 110-2h1zM5.1 4.3a1 1 0 010 1.4l-.7.7A1 1 0 013 5l.7-.7a1 1 0 011.4 0zM3 9a1 1 0 110 2H2a1 1 0 110-2h1zm2.1 6.7a1 1 0 011.4-1.4l.7.7a1 1 0 01-1.4 1.4l-.7-.7zM10 15a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zm4.2-1.3a1 1 0 011.4 1.4l-.7.7a1 1 0 01-1.4-1.4l.7-.7zM10 6a4 4 0 100 8 4 4 0 000-8z"/>
                </svg>
                <svg x-show="dark" x-cloak class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M17.3 12.3A7.5 7.5 0 017.7 2.7a7.5 7.5 0 109.6 9.6z"/>
                </svg>
            </button>

            <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
                <button type="button" @click="open = !open" class="pb-btn-ghost !px-2.5"
                        title="<?= e(__('nav.language')) ?>" :aria-expanded="open.toString()">
                    <?= country_flag_img($locales[$locale]['country'] ?? 'xx') ?>
                    <span class="hidden text-xs font-semibold uppercase sm:inline"><?= e($locale) ?></span>
                </button>
                <div x-show="open" x-transition.origin.top.right @click.outside="open = false"
                     class="absolute right-0 z-40 mt-2 w-56 overflow-hidden rounded-2xl bg-white p-1.5 shadow-xl ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
                    <?php foreach ($locales as $code => $meta): ?>
                        <a href="<?= e(url('locale/' . $code, ['next' => '/' . $currentPath])) ?>"
                           class="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm <?= $code === $locale ? 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300' : 'text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-700/60' ?>">
                            <?= country_flag_img($meta['country'] ?? 'xx') ?>
                            <span><?= e($meta['native']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($user !== null): ?>
                <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
                    <button type="button" @click="open = !open" class="pb-btn-ghost !px-2.5"
                            :aria-expanded="open.toString()">
                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-gradient-to-br from-sky-400 to-sky-600 text-xs font-semibold text-white">
                            <?= e(mb_strtoupper(mb_substr((string) ($user['name'] !== '' ? $user['name'] : $user['email']), 0, 1, 'UTF-8'), 'UTF-8')) ?>
                        </span>
                        <svg class="h-4 w-4 opacity-60" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
                        </svg>
                    </button>
                    <div x-show="open" x-transition.origin.top.right @click.outside="open = false"
                         class="absolute right-0 z-40 mt-2 w-64 overflow-hidden rounded-2xl bg-white p-1.5 shadow-xl ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
                        <div class="px-3 py-2">
                            <p class="truncate text-sm font-semibold text-slate-900 dark:text-white">
                                <?= e($user['name'] !== '' ? $user['name'] : $user['email']) ?>
                            </p>
                            <p class="truncate text-xs text-slate-500 dark:text-slate-400"><?= e($user['email']) ?></p>
                            <span class="pb-badge mt-2 bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">
                                <?= e($user['role'] === 'super_admin' ? __('users.role_admin') : __('users.role_client')) ?>
                            </span>
                        </div>
                        <div class="my-1 border-t border-slate-100 dark:border-slate-700"></div>
                        <a href="<?= e(url('settings')) ?>"
                           class="block rounded-xl px-3 py-2 text-sm text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-700/60">
                            <?= e(__('nav.settings')) ?>
                        </a>
                        <template x-if="canInstall">
                            <button type="button" @click="installApp(); open = false"
                                    class="block w-full rounded-xl px-3 py-2 text-left text-sm text-sky-700 hover:bg-sky-50 dark:text-sky-300 dark:hover:bg-sky-500/10">
                                <?= e(__('nav.install_app')) ?>
                            </button>
                        </template>
                        <form method="post" action="<?= e(url('logout')) ?>">
                            <?= \PlotBird\Security::csrfField() ?>
                            <button type="submit"
                                    class="block w-full rounded-xl px-3 py-2 text-left text-sm text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10">
                                <?= e(__('nav.logout')) ?>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</header>

<?php if (!empty($pending_migrations)): ?>
    <div class="border-b border-sky-200 bg-sky-50 px-4 py-2.5 text-sm text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-200">
        <div class="mx-auto flex max-w-[1600px] flex-wrap items-center gap-x-3 gap-y-2 sm:px-6">
            <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 2a8 8 0 100 16 8 8 0 000-16zm1 4a1 1 0 10-2 0v4a1 1 0 00.4.8l2.5 2a1 1 0 101.2-1.6L11 9.5V6z" clip-rule="evenodd"/>
            </svg>
            <span class="min-w-0 flex-1">
                <?= e(__('migrations.banner', ['count' => count($pending_migrations)])) ?>
            </span>
            <form method="post" action="<?= e(url('settings/maintenance')) ?>">
                <?= \PlotBird\Security::csrfField() ?>
                <input type="hidden" name="action" value="migrate">
                <button type="submit" class="pb-btn-primary !py-1.5 !text-xs"><?= e(__('migrations.apply')) ?></button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($installer_present && $isAdmin): ?>
    <div class="border-b border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
        <div class="mx-auto flex max-w-[1600px] items-start gap-2.5 sm:px-6">
            <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M8.5 2.9a1.7 1.7 0 013 0l6.1 10.6a1.7 1.7 0 01-1.5 2.5H3.9a1.7 1.7 0 01-1.5-2.5L8.5 2.9zM10 7a1 1 0 011 1v3a1 1 0 11-2 0V8a1 1 0 011-1zm0 7.2a1.1 1.1 0 100-2.2 1.1 1.1 0 000 2.2z" clip-rule="evenodd"/>
            </svg>
            <p><?= e(__('security.install_dir_present')) ?></p>
        </div>
    </div>
<?php endif; ?>

<main class="mx-auto w-full max-w-[1600px] flex-1 px-4 py-6 sm:px-6">
    <?= $content ?>
</main>

<?php View::partial('partials/footer', [
    'app_name'    => $app_name,
    'app_version' => $app_version,
    'tagline'     => __('footer.cookie_free'),
]); ?>

<div class="pointer-events-none fixed inset-x-0 top-20 z-50 flex flex-col items-center gap-2 px-4">
    <?php if (!empty($flash_success)): ?>
        <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 6000)" x-show="show"
             x-transition.opacity
             class="pointer-events-auto pb-fade-in max-w-xl rounded-2xl bg-emerald-600 px-4 py-3 text-sm font-medium text-white shadow-lg">
            <?= e($flash_success) ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($flash_error)): ?>
        <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 9000)" x-show="show"
             x-transition.opacity
             class="pointer-events-auto pb-fade-in max-w-xl rounded-2xl bg-rose-600 px-4 py-3 text-sm font-medium text-white shadow-lg">
            <?= e($flash_error) ?>
        </div>
    <?php endif; ?>
</div>

<script>
    // Merge, do not replace: the page-specific view rendered above may already
    // have added its own entries to window.PB.endpoints. Assigning a fresh
    // object here would silently discard them.
    window.PB = window.PB || {};
    window.PB.basePath = <?= json_encode((string) config('app.base_path', ''), JSON_UNESCAPED_SLASHES) ?>;
    window.PB.locale = <?= json_encode($locale) ?>;
    window.PB.csrf = <?= json_encode(\PlotBird\Security::csrfToken()) ?>;
    window.PB.endpoints = Object.assign({}, window.PB.endpoints || {}, {
        stats: <?= json_encode(root_url('api/stats.php'), JSON_UNESCAPED_SLASHES) ?>,
        serviceWorker: <?= json_encode(root_url('sw.js'), JSON_UNESCAPED_SLASHES) ?>,
        collect: <?= json_encode(root_url('api/collect.php'), JSON_UNESCAPED_SLASHES) ?>,
        geoip: <?= json_encode(root_url('index.php') . '?r=settings/geoip', JSON_UNESCAPED_SLASHES) ?>,
        settingsSave: <?= json_encode(url('settings/save'), JSON_UNESCAPED_SLASHES) ?>,
        details: <?= json_encode(url('details'), JSON_UNESCAPED_SLASHES) ?>,
        export: <?= json_encode(url('export'), JSON_UNESCAPED_SLASHES) ?>
    });
    window.PB.decimalSeparator = <?= json_encode(\PlotBird\I18n::decimalSeparator()) ?>;
    window.PB.thousandsSeparator = <?= json_encode(\PlotBird\I18n::thousandsSeparator()) ?>;
</script>

<script src="<?= e(asset('vendor/alpine.min.js')) ?>" defer></script>
<?php if (in_array('chart', $page_assets, true)): ?>
    <script src="<?= e(asset('vendor/chart.umd.min.js')) ?>"></script>
<?php endif; ?>
<?php if (in_array('map', $page_assets, true)): ?>
    <script src="<?= e(asset('vendor/jsvectormap.min.js')) ?>"></script>
    <script src="<?= e(asset('vendor/jsvectormap.world.js')) ?>"></script>
<?php endif; ?>
<?php if (in_array('brands', $page_assets, true)): ?>
    <script src="<?= e(asset('js/brands.js')) ?>"></script>
<?php endif; ?>
<?php if (in_array('sortable', $page_assets, true)): ?>
    <script src="<?= e(asset('vendor/sortable.min.js')) ?>"></script>
<?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
