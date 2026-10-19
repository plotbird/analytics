<?php
/**
 * Installer step 5: the super administrator account.
 *
 * @var array<string,mixed> $admin
 *
 * @package PlotBird
 */

use PlotBird\Auth;
use PlotBird\Security;
?>
<div class="pb-card">
    <div class="pb-card-header">
        <h2 class="pb-card-title"><?= e(__('install.admin_title')) ?></h2>
    </div>

    <form method="post" action="<?= e(root_url('install/') . '?action=save-admin') ?>" class="space-y-4 p-5">
        <?= Security::csrfField() ?>

        <p class="text-sm text-slate-500 dark:text-slate-400"><?= e(__('install.admin_body')) ?></p>

        <div>
            <label class="pb-label" for="admin-email"><?= e(__('install.admin_email')) ?></label>
            <input class="pb-input" type="email" id="admin-email" name="email" required autofocus
                   autocomplete="username" value="<?= e((string) $admin['email']) ?>" placeholder="you@example.com">
        </div>

        <div>
            <label class="pb-label" for="admin-name"><?= e(__('install.admin_name')) ?></label>
            <input class="pb-input" id="admin-name" name="name" maxlength="120"
                   autocomplete="name" value="<?= e((string) $admin['name']) ?>">
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="pb-label" for="admin-password"><?= e(__('install.admin_password')) ?></label>
                <input class="pb-input" type="password" id="admin-password" name="password" required
                       minlength="<?= (int) Auth::MIN_PASSWORD_LENGTH ?>" autocomplete="new-password">
            </div>
            <div>
                <label class="pb-label" for="admin-password-repeat"><?= e(__('users.password_repeat')) ?></label>
                <input class="pb-input" type="password" id="admin-password-repeat" name="password_repeat" required
                       autocomplete="new-password">
            </div>
        </div>
        <p class="text-[11px] text-slate-400">
            <?= e(__('install.admin_password_hint', ['count' => Auth::MIN_PASSWORD_LENGTH])) ?>
        </p>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4 dark:border-slate-700">
            <a href="<?= e(root_url('install/') . '?step=4&stage=done') ?>" class="pb-btn-ghost"><?= e(__('common.back')) ?></a>
            <button type="submit" class="pb-btn-primary"><?= e(__('install.continue')) ?></button>
        </div>
    </form>
</div>
