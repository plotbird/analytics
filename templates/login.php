<?php
/**
 * Sign-in form.
 *
 * @var string $email
 * @var string $next
 * @var string $notice
 *
 * @package PlotBird
 */

use PlotBird\Security;
?>
<div class="pb-card pb-fade-in p-7">
    <h1 class="text-lg font-semibold tracking-tight text-slate-900 dark:text-white">
        <?= e(__('login.title')) ?>
    </h1>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400"><?= e(__('login.subtitle')) ?></p>

    <?php if (!empty($notice)): ?>
        <div class="mt-4 rounded-xl bg-amber-50 px-3.5 py-2.5 text-xs text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30">
            <?= e($notice) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('login')) ?>" class="mt-6 space-y-4" autocomplete="on">
        <?= Security::csrfField() ?>
        <input type="hidden" name="next" value="<?= e($next ?? '') ?>">

        <div>
            <label class="pb-label" for="email"><?= e(__('login.email')) ?></label>
            <input class="pb-input" type="email" id="email" name="email" required autofocus
                   autocomplete="username" value="<?= e($email ?? '') ?>"
                   placeholder="you@example.com">
        </div>

        <div>
            <label class="pb-label" for="password"><?= e(__('login.password')) ?></label>
            <input class="pb-input" type="password" id="password" name="password" required
                   autocomplete="current-password" placeholder="••••••••">
        </div>

        <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
            <input type="checkbox" name="remember" value="1"
                   class="h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500 dark:border-slate-600 dark:bg-slate-800">
            <?= e(__('login.remember')) ?>
        </label>

        <button type="submit" class="pb-btn-primary w-full"><?= e(__('login.submit')) ?></button>
    </form>

    <p class="mt-6 text-center text-xs text-slate-400 dark:text-slate-500">
        <?= e(__('login.privacy_note')) ?>
    </p>
</div>
