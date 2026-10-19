<?php
/**
 * Layout for public share pages: a read-only dashboard without navigation.
 *
 * @var string $content
 * @var array<string,array<string,string>> $locales
 * @var string $locale
 * @var string $app_name
 * @var string $app_version
 * @var array<string,mixed>|null $current_site
 *
 * @package PlotBird
 */

use PlotBird\View;

$page_title  = $page_title ?? '';
$page_assets = $page_assets ?? [];
$locale      = $locale ?? 'en';
$app_name    = $app_name ?? 'PlotBird Analytics';
$locales     = $locales ?? [];
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

<header class="border-b border-slate-200/70 bg-white/85 backdrop-blur dark:border-slate-700/60 dark:bg-slate-900/85">
    <div class="mx-auto flex h-16 max-w-[1600px] items-center gap-3 px-4 sm:px-6">
        <a href="<?= e(url('')) ?>" class="flex shrink-0 items-center gap-2.5">
            <img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="32" height="32" class="h-8 w-8">
            <span class="hidden text-[15px] font-semibold tracking-tight text-slate-900 sm:block dark:text-white">
                <?= e($page_title !== '' ? $page_title : $app_name) ?>
            </span>
        </a>

        <span class="pb-badge bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">
            <?= e(__('share.public_badge')) ?>
        </span>

        <div class="ml-auto flex items-center gap-1">
            <button type="button" @click="toggleTheme()" class="pb-btn-ghost !px-2.5" title="<?= e(__('nav.toggle_theme')) ?>">
                <svg x-show="!dark" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4.2 2.3a1 1 0 011.4 0 1 1 0 010 1.4l-.7.7a1 1 0 11-1.4-1.4l.7-.7zM18 9a1 1 0 110 2h-1a1 1 0 110-2h1zM5.1 4.3a1 1 0 010 1.4l-.7.7A1 1 0 013 5l.7-.7a1 1 0 011.4 0zM3 9a1 1 0 110 2H2a1 1 0 110-2h1zm2.1 6.7a1 1 0 011.4-1.4l.7.7a1 1 0 01-1.4 1.4l-.7-.7zM10 15a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zm4.2-1.3a1 1 0 011.4 1.4l-.7.7a1 1 0 01-1.4-1.4l.7-.7zM10 6a4 4 0 100 8 4 4 0 000-8z"/>
                </svg>
                <svg x-show="dark" x-cloak class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M17.3 12.3A7.5 7.5 0 017.7 2.7a7.5 7.5 0 109.6 9.6z"/>
                </svg>
            </button>

            <?php if (count($locales) > 1): ?>
                <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
                    <button type="button" @click="open = !open" class="pb-btn-ghost !px-2.5">
                        <?= country_flag_img($locales[$locale]['country'] ?? 'xx') ?>
                    </button>
                    <div x-show="open" x-transition.origin.top.right @click.outside="open = false"
                         class="absolute right-0 z-40 mt-2 w-52 overflow-hidden rounded-2xl bg-white p-1.5 shadow-xl ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
                        <?php foreach ($locales as $code => $meta): ?>
                            <a href="<?= e(url('locale/' . $code, ['next' => '/' . current_path()])) ?>"
                               class="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-700/60">
                                <?= country_flag_img($meta['country'] ?? 'xx') ?>
                                <span><?= e($meta['native']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <a href="<?= e(url('login')) ?>" class="pb-btn-ghost"><?= e(__('nav.sign_in')) ?></a>
        </div>
    </div>
</header>

<main class="mx-auto w-full max-w-[1600px] flex-1 px-4 py-6 sm:px-6">
    <?= $content ?>
</main>

<?php View::partial('partials/footer', [
    'app_name'    => $app_name,
    'app_version' => $app_version,
    'tagline'     => __('share.powered_by'),
]); ?>

<script>
    window.PB = {
        basePath: <?= json_encode((string) config('app.base_path', ''), JSON_UNESCAPED_SLASHES) ?>,
        locale: <?= json_encode($locale) ?>,
        csrf: <?= json_encode(\PlotBird\Security::csrfToken()) ?>,
        endpoints: {
            stats: <?= json_encode(root_url('api/stats.php'), JSON_UNESCAPED_SLASHES) ?>,
            serviceWorker: <?= json_encode(root_url('sw.js'), JSON_UNESCAPED_SLASHES) ?>
        },
        decimalSeparator: <?= json_encode(\PlotBird\I18n::decimalSeparator()) ?>,
        thousandsSeparator: <?= json_encode(\PlotBird\I18n::thousandsSeparator()) ?>
    };
</script>
<script src="<?= e(asset('vendor/alpine.min.js')) ?>" defer></script>
<?php if (in_array('chart', $page_assets, true)): ?>
    <script src="<?= e(asset('vendor/chart.umd.min.js')) ?>"></script>
<?php endif; ?>
<?php if (in_array('map', $page_assets, true)): ?>
    <script src="<?= e(asset('vendor/jsvectormap.min.js')) ?>"></script>
    <script src="<?= e(asset('vendor/jsvectormap.world.js')) ?>"></script>
<?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
