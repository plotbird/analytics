<?php
/**
 * Installer step 6 (onboarding): add the first website and hand over the
 * tracker snippet, with a live "is it working?" check.
 *
 * @var array<string,mixed>|null $site
 * @var string $snippet
 * @var array<string,string> $timezones
 * @var string $tracker_url
 *
 * @package PlotBird
 */

use PlotBird\Security;

$createEndpoint = root_url('install/') . '?action=create-site';
$checkEndpoint  = root_url('install/') . '?action=check-tracker';

// Everything Alpine needs. It is passed as one JSON blob that is escaped for
// the HTML attribute context - a raw json_encode() here would terminate the
// attribute at its first double quote and spill the script onto the page.
$onboarding = [
    'siteId'         => (int) ($site['id'] ?? 0),
    'snippet'        => $snippet,
    'createEndpoint' => $createEndpoint,
    'checkEndpoint'  => $checkEndpoint,
];
?>
<div class="space-y-4"
     x-data="pbOnboarding(<?= e(json_encode($onboarding, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>)">

    <div class="pb-card">
        <div class="pb-card-header">
            <h2 class="pb-card-title"><?= e(__('install.site_title')) ?></h2>
            <span class="pb-badge bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">
                <?= e(__('install.step_site')) ?>
            </span>
        </div>

        <div class="space-y-4 p-5">
            <p class="text-sm text-slate-500 dark:text-slate-400"><?= e(__('install.site_body')) ?></p>

            <form id="pb-site-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="create()">
                <?= Security::csrfField() ?>
                <div>
                    <label class="pb-label" for="site-name"><?= e(__('sites.name')) ?></label>
                    <input class="pb-input" id="site-name" name="name" required maxlength="120"
                           placeholder="<?= e(__('install.site_name_placeholder')) ?>">
                </div>
                <div>
                    <label class="pb-label" for="site-domain"><?= e(__('sites.domain')) ?></label>
                    <input class="pb-input" id="site-domain" name="domain" maxlength="190" placeholder="example.com">
                </div>
                <div class="sm:col-span-2">
                    <label class="pb-label" for="site-tz"><?= e(__('sites.timezone')) ?></label>
                    <select class="pb-input" id="site-tz" name="timezone">
                        <?php foreach ($timezones as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $value === 'UTC' ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <button type="submit" class="pb-btn-primary" :disabled="creating">
                        <span x-show="!creating"><?= e(__('install.site_create')) ?></span>
                        <span x-show="creating" x-cloak><?= e(__('install.site_creating')) ?></span>
                    </button>
                    <span class="ml-2 text-xs text-slate-400"><?= e(__('install.site_or_skip')) ?></span>
                </div>
            </form>

            <p x-show="error" x-cloak class="rounded-xl bg-rose-50 px-3.5 py-2.5 text-sm text-rose-800 dark:bg-rose-500/10 dark:text-rose-300">
                <span x-text="error"></span>
            </p>
        </div>
    </div>

    <!-- Tracker code, revealed once a site exists ------------------------ -->
    <div class="pb-card" x-show="siteId > 0" x-cloak x-transition>
        <div class="pb-card-header">
            <h2 class="pb-card-title"><?= e(__('install.snippet_title')) ?></h2>
        </div>
        <div class="space-y-4 p-5">
            <p class="text-sm text-slate-500 dark:text-slate-400"><?= e(__('install.snippet_body')) ?></p>

            <pre class="pb-scroll overflow-x-auto rounded-xl bg-slate-900 p-4 text-xs leading-relaxed text-slate-100"><code x-text="snippet"></code></pre>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="pb-btn-ghost" @click="copy()">
                    <span x-show="!copied"><?= e(__('common.copy')) ?></span>
                    <span x-show="copied" x-cloak><?= e(__('common.copied')) ?></span>
                </button>
                <button type="button" class="pb-btn-primary" @click="check()" :disabled="checking">
                    <span x-show="!checking"><?= e(__('install.check_tracker')) ?></span>
                    <span x-show="checking" x-cloak><?= e(__('install.checking')) ?></span>
                </button>
                <span class="text-xs text-slate-400"><?= e(__('install.tracker_url_hint')) ?></span>
            </div>

            <p x-show="checkMessage" x-cloak
               :class="checkOk ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200'"
               class="rounded-xl px-3.5 py-2.5 text-sm">
                <span x-text="checkMessage"></span>
            </p>
        </div>
    </div>

    <div class="pb-card flex flex-wrap items-center justify-between gap-3 p-5">
        <a href="<?= e(root_url('install/') . '?step=5') ?>" class="pb-btn-ghost"><?= e(__('common.back')) ?></a>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" class="pb-btn-ghost"
                    onclick="document.getElementById('pb-skip-form').submit()">
                <?= e(__('install.skip')) ?>
            </button>
            <form method="post" action="<?= e(root_url('install/') . '?action=finish') ?>" id="pb-finish-form">
                <?= Security::csrfField() ?>
                <button type="submit" class="pb-btn-primary"><?= e(__('install.finish')) ?></button>
            </form>
            <form method="post" action="<?= e(root_url('install/') . '?action=skip-site') ?>" id="pb-skip-form" class="hidden">
                <?= Security::csrfField() ?>
            </form>
        </div>
    </div>
</div>
