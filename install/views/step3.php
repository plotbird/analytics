<?php
/**
 * Installer step 3: database connection.
 *
 * The "Test connection" button performs an AJAX call, so a typo is caught
 * before the schema is created.
 *
 * @var array<string,mixed> $database
 * @var bool $pretty_urls
 *
 * @package PlotBird
 */

use PlotBird\Security;

$prettySupported = true;
if (function_exists('apache_get_modules')) {
    /** @var list<string> $modules */
    $modules = apache_get_modules();
    $prettySupported = in_array('mod_rewrite', $modules, true);
}
?>
<div class="pb-card"
     x-data="{
        testing: false,
        result: null,
        error: '',
        test() {
            this.testing = true; this.result = null; this.error = '';
            const body = new FormData(document.getElementById('pb-db-form'));
            body.set('action', 'test-db');
            fetch('<?= e(root_url('install/') . '?action=test-db') ?>', {
                method: 'POST', body: body,
                headers: { 'X-CSRF-Token': PB.csrf, 'X-Requested-With': 'XMLHttpRequest' }
            }).then(r => r.json()).then(d => {
                this.testing = false;
                if (d.ok) { this.result = d; } else { this.error = d.message || d.error; }
            }).catch(() => { this.testing = false; this.error = 'network'; });
        }
     }">
    <div class="pb-card-header">
        <h2 class="pb-card-title"><?= e(__('install.db_title')) ?></h2>
    </div>

    <form method="post" action="<?= e(root_url('install/') . '?action=save-db') ?>" class="space-y-4 p-5" id="pb-db-form">
        <?= Security::csrfField() ?>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label class="pb-label" for="db-host"><?= e(__('install.db_host')) ?></label>
                <input class="pb-input" id="db-host" name="host" required
                       value="<?= e((string) $database['host']) ?>" placeholder="localhost">
            </div>
            <div>
                <label class="pb-label" for="db-port"><?= e(__('install.db_port')) ?></label>
                <input class="pb-input" id="db-port" name="port" type="number" min="1" max="65535"
                       value="<?= (int) $database['port'] ?>">
            </div>
        </div>

        <div>
            <label class="pb-label" for="db-name"><?= e(__('install.db_name')) ?></label>
            <input class="pb-input" id="db-name" name="name" required
                   value="<?= e((string) $database['name']) ?>" placeholder="plotbird">
            <p class="mt-1 text-[11px] text-slate-400"><?= e(__('install.db_name_hint')) ?></p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="pb-label" for="db-user"><?= e(__('install.db_user')) ?></label>
                <input class="pb-input" id="db-user" name="user" required autocomplete="off"
                       value="<?= e((string) $database['user']) ?>">
            </div>
            <div>
                <label class="pb-label" for="db-password"><?= e(__('install.db_password')) ?></label>
                <input class="pb-input" id="db-password" name="password" type="password" autocomplete="new-password"
                       value="<?= e((string) $database['password']) ?>">
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="pb-label" for="db-prefix"><?= e(__('install.db_prefix')) ?></label>
                <input class="pb-input font-mono" id="db-prefix" name="prefix" maxlength="32"
                       value="<?= e((string) $database['prefix']) ?>">
                <p class="mt-1 text-[11px] text-slate-400"><?= e(__('install.db_prefix_hint')) ?></p>
            </div>
            <label class="flex items-start gap-2.5 pt-6 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="pretty_urls" value="1" <?= $pretty_urls ? 'checked' : '' ?>
                       class="mt-0.5 h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500 dark:border-slate-600 dark:bg-slate-900">
                <span>
                    <?= e(__('install.pretty_urls')) ?>
                    <span class="block text-[11px] text-slate-400">
                        <?= e($prettySupported ? __('install.pretty_urls_hint') : __('install.pretty_urls_unknown')) ?>
                    </span>
                </span>
            </label>
        </div>

        <div x-show="result" x-cloak class="rounded-xl bg-emerald-50 px-3.5 py-2.5 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">
            <span x-text="result ? result.message : ''"></span>
        </div>
        <div x-show="error" x-cloak class="rounded-xl bg-rose-50 px-3.5 py-2.5 text-sm text-rose-800 break-words dark:bg-rose-500/10 dark:text-rose-300">
            <?= e(__('install.db_error_connect')) ?> <span x-text="error"></span>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4 dark:border-slate-700">
            <a href="<?= e(root_url('install/') . '?step=2') ?>" class="pb-btn-ghost"><?= e(__('common.back')) ?></a>
            <div class="flex items-center gap-2">
                <button type="button" class="pb-btn-ghost" @click="test()" :disabled="testing">
                    <span x-show="!testing"><?= e(__('install.db_test')) ?></span>
                    <span x-show="testing" x-cloak><?= e(__('install.db_testing')) ?></span>
                </button>
                <button type="submit" class="pb-btn-primary"><?= e(__('install.continue')) ?></button>
            </div>
        </div>
    </form>
</div>
