<?php
/**
 * Settings and maintenance.
 *
 * @var array<string,string> $settings
 * @var int $geoip_count
 * @var bool $geoip_ready
 * @var bool $geoip_base
 * @var string $geoip_updated
 * @var string $cron_url
 * @var list<array<string,mixed>> $audit
 * @var array<string,mixed> $system
 * @var array{0:int,1:int} $retention_range
 *
 * @package PlotBird
 */

use PlotBird\Security;

$isAdmin = \PlotBird\Auth::isSuperAdmin();
?>
<div class="space-y-5" x-data="{ copied: '' }">

    <div>
        <h1 class="text-lg font-semibold tracking-tight text-slate-900 dark:text-white"><?= e(__('settings.title')) ?></h1>
        <p class="text-xs text-slate-500 dark:text-slate-400"><?= e(__('settings.subtitle')) ?></p>
    </div>

    <?php if ($installer_present ?? false): ?>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-500/30 dark:bg-amber-500/10">
            <h2 class="text-sm font-semibold text-amber-900 dark:text-amber-200">
                <?= e(__('settings.security_title')) ?>
            </h2>
            <p class="mt-1.5 text-sm text-amber-800 dark:text-amber-200/90">
                <?= e(__('security.install_dir_present')) ?>
            </p>
            <p class="mt-2 font-mono text-xs text-amber-900/80 dark:text-amber-200/80">
                rm -rf <?= e(PB_ROOT) ?>/install
            </p>
            <p class="mt-1 text-xs text-amber-800/80 dark:text-amber-200/70">
                <?= e(__('settings.security_installer_locked')) ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="grid gap-5 xl:grid-cols-2">

        <!-- General ------------------------------------------------------ -->
        <section class="pb-card">
            <div class="pb-card-header">
                <h2 class="pb-card-title"><?= e(__('settings.general')) ?></h2>
            </div>
            <form method="post" action="<?= e(url('settings/save')) ?>" class="space-y-4 p-5">
                <?= Security::csrfField() ?>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="pb-label" for="default_locale"><?= e(__('settings.default_locale')) ?></label>
                        <select class="pb-input" id="default_locale" name="default_locale">
                            <?php foreach ($locales as $code => $meta): ?>
                                <option value="<?= e($code) ?>" <?= $settings['default_locale'] === $code ? 'selected' : '' ?>>
                                    <?= e($meta['native']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="pb-label" for="session_timeout"><?= e(__('settings.session_timeout')) ?></label>
                        <input class="pb-input" type="number" id="session_timeout" name="session_timeout"
                               min="1" max="240" value="<?= (int) $settings['session_timeout'] ?>">
                        <p class="mt-1 text-[11px] text-slate-400"><?= e(__('settings.session_timeout_hint')) ?></p>
                    </div>
                </div>

                <div>
                    <label class="pb-label" for="retention_days"><?= e(__('settings.retention')) ?></label>
                    <input class="pb-input" type="number" id="retention_days" name="retention_days"
                           min="<?= (int) $retention_range[0] ?>" max="<?= (int) $retention_range[1] ?>"
                           value="<?= (int) $settings['retention_days'] ?>">
                    <p class="mt-1 text-[11px] text-slate-400"><?= e(__('settings.retention_hint')) ?></p>
                    <?php if ((int) $settings['retention_days'] > 760): ?>
                        <p class="mt-1.5 rounded-lg bg-amber-50 px-2.5 py-1.5 text-[11px] text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                            <?= e(__('settings.retention_cnil')) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="space-y-2 border-t border-slate-100 pt-4 dark:border-slate-700">
                    <label class="flex items-start gap-2.5 text-sm text-slate-600 dark:text-slate-300">
                        <input type="checkbox" name="anonymize_ip" value="1" <?= $settings['anonymize_ip'] === '1' ? 'checked' : '' ?>
                               class="mt-0.5 h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500 dark:border-slate-600 dark:bg-slate-900">
                        <span>
                            <?= e(__('settings.anonymize_ip')) ?>
                            <span class="block text-[11px] text-slate-400"><?= e(__('settings.anonymize_ip_hint')) ?></span>
                        </span>
                    </label>

                    <label class="flex items-start gap-2.5 text-sm text-slate-600 dark:text-slate-300">
                        <input type="checkbox" name="store_ip" value="1" <?= $store_ip ? 'checked' : '' ?>
                               class="mt-0.5 h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500 dark:border-slate-600 dark:bg-slate-900">
                        <span>
                            <?= e(__('settings.store_ip')) ?>
                            <span class="block text-[11px] text-slate-400"><?= e(__('settings.store_ip_hint')) ?></span>
                        </span>
                    </label>

                    <?php if ($store_ip): ?>
                        <div class="rounded-xl bg-rose-50 px-3.5 py-2.5 text-xs text-rose-800 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/30">
                            <?= e(__('settings.store_ip_warning')) ?>
                        </div>
                    <?php endif; ?>

                    <label class="flex items-start gap-2.5 text-sm text-slate-600 dark:text-slate-300">
                        <input type="checkbox" name="respect_dnt" value="1" <?= $settings['respect_dnt'] === '1' ? 'checked' : '' ?>
                               class="mt-0.5 h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500 dark:border-slate-600 dark:bg-slate-900">
                        <span>
                            <?= e(__('settings.respect_dnt')) ?>
                            <span class="block text-[11px] text-slate-400"><?= e(__('settings.respect_dnt_hint')) ?></span>
                        </span>
                    </label>
                    <label class="flex items-start gap-2.5 text-sm text-slate-600 dark:text-slate-300">
                        <input type="checkbox" name="exclude_bots" value="1" <?= $settings['exclude_bots'] === '1' ? 'checked' : '' ?>
                               class="mt-0.5 h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500 dark:border-slate-600 dark:bg-slate-900">
                        <span>
                            <?= e(__('settings.exclude_bots')) ?>
                            <span class="block text-[11px] text-slate-400"><?= e(__('settings.exclude_bots_hint')) ?></span>
                        </span>
                    </label>
                </div>

                <div class="flex justify-end border-t border-slate-100 pt-4 dark:border-slate-700">
                    <button type="submit" class="pb-btn-primary"><?= e(__('common.save')) ?></button>
                </div>
            </form>
        </section>

        <!-- Own password ------------------------------------------------- -->
        <section class="pb-card">
            <div class="pb-card-header">
                <h2 class="pb-card-title"><?= e(__('settings.password_title')) ?></h2>
            </div>
            <form method="post" action="<?= e(url('settings/password')) ?>" class="space-y-4 p-5">
                <?= Security::csrfField() ?>
                <div>
                    <label class="pb-label" for="current_password"><?= e(__('settings.current_password')) ?></label>
                    <input class="pb-input" type="password" id="current_password" name="current_password"
                           required autocomplete="current-password">
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="pb-label" for="new_password"><?= e(__('settings.new_password')) ?></label>
                        <input class="pb-input" type="password" id="new_password" name="password"
                               required minlength="8" autocomplete="new-password">
                    </div>
                    <div>
                        <label class="pb-label" for="new_password_repeat"><?= e(__('users.password_repeat')) ?></label>
                        <input class="pb-input" type="password" id="new_password_repeat" name="password_repeat"
                               required autocomplete="new-password">
                    </div>
                </div>
                <div class="flex justify-end border-t border-slate-100 pt-4 dark:border-slate-700">
                    <button type="submit" class="pb-btn-primary"><?= e(__('settings.password_save')) ?></button>
                </div>
            </form>
        </section>

        <!-- GeoIP -------------------------------------------------------- -->
        <?php if ($isAdmin): ?>
        <section class="pb-card" x-data="pbGeoIpImport()">
            <div class="pb-card-header">
                <h2 class="pb-card-title"><?= e(__('settings.geoip_title')) ?></h2>
                <span class="pb-badge <?= $geoip_ready
                    ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                    : 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' ?>">
                    <?= e($geoip_ready ? __('settings.geoip_ready') : __('settings.geoip_not_ready')) ?>
                </span>
            </div>
            <div class="space-y-4 p-5">
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400"><?= e(__('settings.geoip_ranges')) ?></dt>
                        <dd class="font-medium tabular-nums text-slate-700 dark:text-slate-200"
                            x-text="done ? fmt : '<?= number_format((float) $geoip_count, 0, '.', ' ') ?>'"></dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400"><?= e(__('settings.geoip_updated')) ?></dt>
                        <dd class="font-medium text-slate-700 dark:text-slate-200">
                            <?= e($geoip_updated !== '' ? $geoip_updated : '—') ?>
                        </dd>
                    </div>
                </dl>

                <p class="text-xs text-slate-400">
                    <?= e(__('settings.geoip_source')) ?>
                    <a class="pb-link" href="https://db-ip.com" target="_blank" rel="noopener noreferrer">DB-IP Lite</a>
                    (CC BY 4.0).
                </p>

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

                <p x-show="message" x-cloak class="rounded-xl bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                    <?= e(__('settings.geoip_error')) ?> <span x-text="message"></span>
                </p>

                <p x-show="done" x-cloak class="rounded-xl bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                    <?= e(__('settings.geoip_done')) ?>
                </p>

                <div class="space-y-3 border-t border-slate-100 pt-4 dark:border-slate-700">
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" class="pb-btn-primary !text-xs" @click="download()" :disabled="running">
                            <?= e(__('settings.geoip_update')) ?>
                        </button>
                        <button type="button" class="pb-btn-ghost !text-xs" @click="start()" :disabled="running">
                            <?= e(__('settings.geoip_reimport')) ?>
                        </button>
                    </div>
                    <p class="text-[11px] text-slate-400"><?= e(__('settings.geoip_update_hint')) ?></p>
                    <p class="text-[11px] text-slate-400">
                        <?= e(__('settings.geoip_reimport_hint', [
                            'date' => $geoip_base_date !== '' ? $geoip_base_date : '—',
                        ])) ?>
                    </p>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Cron --------------------------------------------------------- -->
        <?php if ($isAdmin): ?>
        <section class="pb-card">
            <div class="pb-card-header">
                <h2 class="pb-card-title"><?= e(__('settings.cron_title')) ?></h2>
            </div>
            <div class="space-y-4 p-5">
                <p class="text-sm text-slate-500 dark:text-slate-400"><?= e(__('settings.cron_hint')) ?></p>
                <div class="flex items-center gap-2">
                    <input class="pb-input font-mono !text-xs" readonly value="<?= e($cron_url) ?>"
                           @focus="$event.target.select()">
                    <button type="button" class="pb-btn-ghost !text-xs shrink-0"
                            @click="copied = <?= e(json_encode($cron_url)) ?>; navigator.clipboard && navigator.clipboard.writeText(copied)">
                        <span x-show="copied !== <?= e(json_encode($cron_url)) ?>"><?= e(__('common.copy')) ?></span>
                        <span x-show="copied === <?= e(json_encode($cron_url)) ?>" x-cloak><?= e(__('common.copied')) ?></span>
                    </button>
                </div>
                <p class="text-xs text-slate-400">
                    <?= e(__('settings.cron_last_run')) ?>
                    <?= e($settings['cron_last_run'] !== '' ? $settings['cron_last_run'] : '—') ?>
                </p>

                <div class="grid gap-2 border-t border-slate-100 pt-4 sm:grid-cols-2 dark:border-slate-700">
                    <?php
                    $actions = [
                        'aggregate'   => 'settings.maintenance_aggregate',
                        'purge'       => 'settings.maintenance_purge',
                        'rate_limits' => 'settings.maintenance_rate_limits',
                        'cron_secret' => 'settings.maintenance_cron_rotate',
                    ];
                    foreach ($actions as $actionKey => $labelKey): ?>
                        <form method="post" action="<?= e(url('settings/maintenance')) ?>">
                            <?= Security::csrfField() ?>
                            <input type="hidden" name="action" value="<?= e($actionKey) ?>">
                            <button type="submit" class="pb-btn-ghost w-full !justify-start !text-xs">
                                <?= e(__($labelKey)) ?>
                            </button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Database schema ---------------------------------------------- -->
        <?php if ($isAdmin): ?>
        <section class="pb-card">
            <div class="pb-card-header">
                <h2 class="pb-card-title"><?= e(__('settings.migrations_title')) ?></h2>
                <span class="pb-badge <?= $migrator['pending'] === []
                    ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                    : 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300' ?>">
                    <?= e($migrator['pending'] === [] ? __('settings.migrations_current') : __('settings.migrations_pending')) ?>
                </span>
            </div>
            <div class="space-y-4 p-5">
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    <?= e(__('settings.migrations_status', [
                        'applied'   => $migrator['applied'],
                        'available' => $migrator['available'],
                    ])) ?>
                </p>

                <?php if ($migrator['pending'] !== []): ?>
                    <div class="rounded-xl bg-sky-50 px-3.5 py-3 dark:bg-sky-500/10">
                        <p class="text-xs font-semibold text-sky-900 dark:text-sky-200">
                            <?= e(__('settings.migrations_list')) ?>
                        </p>
                        <ul class="mt-1.5 space-y-1">
                            <?php foreach ($migrator['pending'] as $pending): ?>
                                <li class="font-mono text-xs text-sky-800 dark:text-sky-300"><?= e($pending) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <p class="text-[11px] text-slate-400">
                    <?= e(__('settings.migrations_last')) ?>
                    <?= e($migrator['last'] !== '' ? $migrator['last'] : '—') ?>
                </p>

                <form method="post" action="<?= e(url('settings/maintenance')) ?>"
                      class="flex justify-end border-t border-slate-100 pt-4 dark:border-slate-700">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="migrate">
                    <button type="submit" class="pb-btn-primary !text-xs" <?= $migrator['pending'] === [] ? 'disabled' : '' ?>>
                        <?= e(__('settings.migrations_apply')) ?>
                    </button>
                </form>
            </div>
        </section>
        <?php endif; ?>

        <!-- System ------------------------------------------------------- -->
        <section class="pb-card xl:col-span-2">
            <div class="pb-card-header">
                <h2 class="pb-card-title"><?= e(__('settings.system_title')) ?></h2>
            </div>
            <div class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                <?php
                $items = [
                    ['PHP', (string) $system['php']],
                    ['MySQL', (string) $system['mysql'] . ($system['mariadb'] ? ' (MariaDB)' : '')],
                    ['PlotBird', (string) $system['version'] . ' / schema ' . (int) $system['schema']],
                    ['Table prefix', (string) $system['prefix']],
                    ['data/ writable', $system['data_writable'] ? __('common.yes') : __('common.no')],
                    ['PHP timezone', (string) $system['timezone']],
                    ['Debug mode', $system['debug'] ? __('common.on') : __('common.off')],
                    ['GeoIP ranges', number_format((float) $geoip_count, 0, '.', ' ')],
                ];
                foreach ($items as $item): ?>
                    <div class="rounded-xl bg-slate-50/70 px-3.5 py-3 dark:bg-slate-900/40">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400"><?= e($item[0]) ?></p>
                        <p class="mt-0.5 truncate text-sm font-medium text-slate-700 dark:text-slate-200"><?= e((string) $item[1]) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Audit -------------------------------------------------------- -->
        <?php if ($isAdmin && $audit !== []): ?>
        <section class="pb-card xl:col-span-2 overflow-hidden">
            <div class="pb-card-header">
                <h2 class="pb-card-title"><?= e(__('settings.audit_title')) ?></h2>
            </div>
            <div class="pb-scroll max-h-80 overflow-y-auto">
                <table class="w-full text-left text-xs">
                    <thead class="sticky top-0 bg-slate-50/95 text-slate-400 uppercase tracking-wide backdrop-blur dark:bg-slate-900/90">
                        <tr>
                            <th class="px-5 py-2.5 font-semibold"><?= e(__('settings.audit_time')) ?></th>
                            <th class="px-5 py-2.5 font-semibold"><?= e(__('settings.audit_user')) ?></th>
                            <th class="px-5 py-2.5 font-semibold"><?= e(__('settings.audit_action')) ?></th>
                            <th class="px-5 py-2.5 font-semibold"><?= e(__('settings.audit_entity')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($audit as $entry): ?>
                            <tr class="pb-table-row">
                                <td class="px-5 py-2 whitespace-nowrap text-slate-400"><?= e((string) $entry['created_at']) ?></td>
                                <td class="px-5 py-2 text-slate-500 dark:text-slate-400">
                                    <?= e((string) ($entry['user_email'] ?? '—')) ?>
                                </td>
                                <td class="px-5 py-2 font-medium text-slate-700 dark:text-slate-200">
                                    <?= e((string) $entry['action']) ?>
                                </td>
                                <td class="px-5 py-2 text-slate-400">
                                    <?= e((string) $entry['entity']) ?>
                                    <?= $entry['entity_id'] !== '' ? '#' . e((string) $entry['entity_id']) : '' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>
    </div>
</div>
