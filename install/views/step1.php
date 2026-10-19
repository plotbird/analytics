<?php
/**
 * Installer step 1: choose the language.
 *
 * The browser's Accept-Language header has already been applied (see
 * I18n::negotiate); this step lets the administrator override it.
 *
 * @var array<string,array<string,string>> $locales
 * @var string $locale
 *
 * @package PlotBird
 */

use PlotBird\Security;
?>
<div class="pb-card p-6">
    <h2 class="text-base font-semibold text-slate-900 dark:text-white"><?= e(__('install.language_title')) ?></h2>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400"><?= e(__('install.language_body')) ?></p>

    <form method="post" action="<?= e(root_url('install/') . '?action=save-locale') ?>" class="mt-5">
        <?= Security::csrfField() ?>
        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($locales as $code => $meta): ?>
                <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 px-3.5 py-2.5 transition-colors hover:border-sky-400 hover:bg-sky-50/60 has-[:checked]:border-sky-500 has-[:checked]:bg-sky-50 dark:border-slate-700 dark:hover:border-sky-500 dark:hover:bg-sky-500/10 dark:has-[:checked]:bg-sky-500/10">
                    <input type="radio" name="locale" value="<?= e($code) ?>" class="h-4 w-4 text-sky-600 focus:ring-sky-500"
                           <?= $code === $locale ? 'checked' : '' ?>>
                    <?= country_flag_img($meta['country'] ?? 'xx', 'pb-flag pb-flag-lg') ?>
                    <span class="text-sm font-medium text-slate-700 dark:text-slate-200"><?= e($meta['native']) ?></span>
                    <span class="ml-auto text-[11px] uppercase text-slate-400"><?= e($code) ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="mt-6 flex justify-end">
            <button type="submit" class="pb-btn-primary"><?= e(__('install.continue')) ?></button>
        </div>
    </form>
</div>
