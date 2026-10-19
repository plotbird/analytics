<?php
/**
 * Installer step 4: create the schema and write config.php, then optionally
 * import the bundled IP geolocation database.
 *
 * @var string $stage            Empty, or "done" after the schema was created.
 * @var array<string,mixed> $database
 * @var bool $db_ready
 * @var bool $geoip_base
 * @var int $geoip_count
 *
 * @package PlotBird
 */

use PlotBird\Security;
?>

<?php if ($stage !== 'done'): ?>

    <div class="pb-card">
        <div class="pb-card-header">
            <h2 class="pb-card-title"><?= e(__('install.schema_title')) ?></h2>
        </div>

        <div class="space-y-4 p-5">
            <p class="text-sm text-slate-500 dark:text-slate-400"><?= e(__('install.schema_body')) ?></p>

            <dl class="grid gap-3 rounded-xl bg-slate-50/70 p-4 text-sm sm:grid-cols-2 dark:bg-slate-900/40">
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-400"><?= e(__('install.db_host')) ?></dt>
                    <dd class="font-mono text-slate-700 dark:text-slate-200">
                        <?= e((string) $database['host']) ?>:<?= (int) $database['port'] ?>
                    </dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-400"><?= e(__('install.db_name')) ?></dt>
                    <dd class="font-mono text-slate-700 dark:text-slate-200"><?= e((string) $database['name']) ?></dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-400"><?= e(__('install.db_user')) ?></dt>
                    <dd class="font-mono text-slate-700 dark:text-slate-200"><?= e((string) $database['user']) ?></dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-400"><?= e(__('install.db_prefix')) ?></dt>
                    <dd class="font-mono text-slate-700 dark:text-slate-200"><?= e((string) $database['prefix']) ?></dd>
                </div>
            </dl>

            <p class="rounded-xl bg-amber-50 px-3.5 py-2.5 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                <?= e(__('install.schema_warning')) ?>
            </p>

            <form method="post" action="<?= e(root_url('install/') . '?action=install-schema') ?>"
                  class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4 dark:border-slate-700">
                <?= Security::csrfField() ?>
                <a href="<?= e(root_url('install/') . '?step=3') ?>" class="pb-btn-ghost"><?= e(__('common.back')) ?></a>
                <button type="submit" class="pb-btn-primary"><?= e(__('install.schema_create')) ?></button>
            </form>
        </div>
    </div>

<?php else: ?>

    <div class="space-y-4">
        <div class="pb-card p-5">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-sm font-bold text-white">✓</span>
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white"><?= e(__('install.schema_done_title')) ?></h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400"><?= e(__('install.schema_done_body')) ?></p>
                    <p class="mt-2 font-mono text-xs text-slate-400">config/config.php</p>
                </div>
            </div>
        </div>

        <div class="pb-card" x-data="pbGeoIpImport()">
            <div class="pb-card-header">
                <h2 class="pb-card-title"><?= e(__('install.geoip_title')) ?></h2>
                <span class="pb-badge <?= $geoip_count > 0
                    ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                    : 'bg-slate-100 text-slate-500 dark:bg-slate-700/60 dark:text-slate-400' ?>">
                    <?= e($geoip_count > 0 ? __('settings.geoip_ready') : __('install.optional')) ?>
                </span>
            </div>

            <div class="space-y-4 p-5">
                <p class="text-sm text-slate-500 dark:text-slate-400"><?= e(__('install.geoip_body')) ?></p>

                <?php if ($geoip_base): ?>
                    <p class="text-xs text-slate-400">
                        <?= e(__('install.geoip_base_date', ['date' => $geoip_base_date !== '' ? $geoip_base_date : '—'])) ?>
                        · <?= e(__('install.geoip_takes')) ?>
                    </p>
                <?php endif; ?>

                <?php if (!$geoip_base): ?>
                    <p class="rounded-xl bg-amber-50 px-3.5 py-2.5 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                        <?= e(__('install.geoip_missing')) ?>
                    </p>
                <?php endif; ?>

                <template x-if="running">
                    <div>
                        <div class="pb-bar-track">
                            <div class="pb-bar" :style="'width:' + percent + '%'"></div>
                        </div>
                        <p class="mt-1.5 text-xs text-slate-400">
                            <?= e(__('settings.geoip_importing')) ?> <span x-text="percent + '%'"></span>
                        </p>
                    </div>
                </template>

                <p x-show="done" x-cloak class="rounded-xl bg-emerald-50 px-3.5 py-2.5 text-xs text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">
                    <?= e(__('settings.geoip_done')) ?> <span x-text="fmt"></span>
                </p>
                <p x-show="message" x-cloak class="rounded-xl bg-rose-50 px-3.5 py-2.5 text-xs text-rose-800 dark:bg-rose-500/10 dark:text-rose-300">
                    <?= e(__('settings.geoip_error')) ?> <span x-text="message"></span>
                </p>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" class="pb-btn-primary" x-show="!done"
                            @click="start()" :disabled="running || !<?= $geoip_base ? 'true' : 'false' ?>">
                        <span x-show="!running"><?= e(__('install.geoip_import_now')) ?></span>
                        <span x-show="running" x-cloak><?= e(__('settings.geoip_importing')) ?> <span x-text="percent + '%'"></span></span>
                    </button>

                    <a href="<?= e(root_url('install/') . '?step=5') ?>"
                       class="text-xs text-slate-400 transition-colors hover:text-slate-600 dark:hover:text-slate-300">
                        <?= e(__('install.geoip_skip')) ?>
                    </a>
                </div>
                <p class="text-[11px] text-slate-400"><?= e(__('install.geoip_skip_hint')) ?></p>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4 dark:border-slate-700/60">
                <a href="<?= e(root_url('install/') . '?step=4') ?>" class="pb-btn-ghost"><?= e(__('common.back')) ?></a>
                <a href="<?= e(root_url('install/') . '?step=5') ?>" class="pb-btn-primary"><?= e(__('install.continue')) ?></a>
            </div>
        </div>
    </div>

    <script>
        // Point the shared chunked-import component at the installer endpoint.
        window.PB = window.PB || {};
        window.PB.endpoints = window.PB.endpoints || {};
        window.PB.endpoints.geoip = <?= json_encode(root_url('install/') . '?action=geoip', JSON_UNESCAPED_SLASHES) ?>;
    </script>

<?php endif; ?>
