<?php
/**
 * Shared <head> contents.
 *
 * Included by every layout (application, guest, share, installer) so that the
 * theme bootstrap, the Tailwind configuration and the self-hosted Inter font
 * are defined exactly once.
 *
 * @var string $page_title
 * @var string $app_name
 * @var string $locale
 * @var list<string> $page_assets
 *
 * @package PlotBird
 */

use PlotBird\View;

$page_title  = $page_title ?? '';
$app_name    = $app_name ?? 'PlotBird Analytics';
$locale      = $locale ?? 'en';
$page_assets = $page_assets ?? [];
$pwa         = $pwa ?? true;
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($page_title !== '' ? $page_title . ' · ' . $app_name : $app_name) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e(asset('img/logo.svg')) ?>">
<?php if ($pwa): ?>
    <link rel="manifest" href="<?= e(root_url('manifest.php')) ?>">
    <link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
    <meta name="theme-color" content="#0284c7">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="<?= e($app_name) ?>">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
<?php endif; ?>
<!-- Tailwind is compiled ahead of time and committed: no runtime compiler. -->
<link rel="stylesheet" href="<?= e(asset('css/tailwind.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<?php if (in_array('map', $page_assets, true)): ?>
    <link rel="stylesheet" href="<?= e(asset('vendor/jsvectormap.min.css')) ?>">
<?php endif; ?>

<script>
    // Applied before the first paint so the theme never flashes.
    (function () {
        try {
            var stored = localStorage.getItem('pb-theme');
            var dark = stored ? stored === 'dark'
                : window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (dark) document.documentElement.classList.add('dark');
        } catch (e) {}
    })();
</script>
