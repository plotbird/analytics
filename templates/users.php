<?php
/**
 * User management (super admin only).
 *
 * @var list<array<string,mixed>> $users
 * @var array<int,list<int>> $assignments
 * @var list<array<string,mixed>> $sites
 * @var array<string,array<string,string>> $locales
 * @var int $min_password_length
 *
 * @package PlotBird
 */

use PlotBird\Security;
use PlotBird\User;

$usersForJs = array_map(static function (array $user): array {
    return [
        'id'        => (int) $user['id'],
        'email'     => (string) $user['email'],
        'name'      => (string) $user['name'],
        'role'      => (string) $user['role'],
        'locale'    => (string) $user['locale'],
        'is_active' => (int) $user['is_active'],
    ];
}, $users);
?>

<div x-data="pbUsers(<?= e(json_encode($usersForJs, JSON_UNESCAPED_UNICODE)) ?>, <?= e(json_encode($assignments, JSON_UNESCAPED_UNICODE)) ?>)">

    <div class="mb-5 flex flex-wrap items-center gap-3">
        <div>
            <h1 class="text-lg font-semibold tracking-tight text-slate-900 dark:text-white"><?= e(__('users.title')) ?></h1>
            <p class="text-xs text-slate-500 dark:text-slate-400"><?= e(__('users.subtitle')) ?></p>
        </div>
        <button type="button" class="pb-btn-primary ml-auto" @click="openCreate()">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z"/>
            </svg>
            <?= e(__('users.add')) ?>
        </button>
    </div>

    <div class="pb-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50/80 text-xs uppercase tracking-wide text-slate-400 dark:bg-slate-900/40">
                    <tr>
                        <th class="px-5 py-3 font-semibold"><?= e(__('users.email')) ?></th>
                        <th class="px-5 py-3 font-semibold"><?= e(__('users.name')) ?></th>
                        <th class="px-5 py-3 font-semibold"><?= e(__('users.role')) ?></th>
                        <th class="px-5 py-3 font-semibold"><?= e(__('users.sites_count')) ?></th>
                        <th class="px-5 py-3 font-semibold"><?= e(__('users.last_login')) ?></th>
                        <th class="px-5 py-3 font-semibold"><?= e(__('users.status')) ?></th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <?php $isSelf = \PlotBird\Auth::id() === (int) $user['id']; ?>
                        <tr class="pb-table-row">
                            <td class="px-5 py-3">
                                <span class="font-medium text-slate-700 dark:text-slate-200"><?= e($user['email']) ?></span>
                                <?php if ($isSelf): ?>
                                    <span class="pb-badge ml-2 bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">
                                        <?= e(__('users.you')) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-3 text-slate-500 dark:text-slate-400"><?= e($user['name'] !== '' ? $user['name'] : '—') ?></td>
                            <td class="px-5 py-3">
                                <span class="pb-badge <?= $user['role'] === User::ROLE_SUPER_ADMIN
                                    ? 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300'
                                    : 'bg-slate-100 text-slate-600 dark:bg-slate-700/60 dark:text-slate-300' ?>">
                                    <?= e($user['role'] === User::ROLE_SUPER_ADMIN ? __('users.role_admin') : __('users.role_client')) ?>
                                </span>
                            </td>
                            <td class="px-5 py-3 tabular-nums text-slate-500 dark:text-slate-400">
                                <?= $user['role'] === User::ROLE_SUPER_ADMIN ? '∞' : (int) $user['site_count'] ?>
                            </td>
                            <td class="px-5 py-3 text-xs text-slate-400"><?= e((string) ($user['last_login_at'] ?? '—')) ?></td>
                            <td class="px-5 py-3">
                                <span class="pb-badge <?= (int) $user['is_active'] === 1
                                    ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                                    : 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' ?>">
                                    <?= e((int) $user['is_active'] === 1 ? __('users.active') : __('users.blocked')) ?>
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button type="button" class="pb-btn-ghost !text-xs"
                                            @click="openEdit(<?= (int) $user['id'] ?>)">
                                        <?= e(__('common.edit')) ?>
                                    </button>
                                    <?php if (!$isSelf): ?>
                                        <button type="button" class="pb-btn-ghost !text-xs !text-rose-600 dark:!text-rose-400"
                                                @click="askDelete(<?= (int) $user['id'] ?>)">
                                            <?= e(__('common.delete')) ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Create / edit modal ---------------------------------------------- -->
    <div x-show="modal === 'user'" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 backdrop-blur-sm">
        <div class="pb-card pb-fade-in my-8 w-full max-w-xl">
            <div class="pb-card-header">
                <h2 class="pb-card-title" x-text="form.id ? '<?= e(__('users.edit')) ?>' : '<?= e(__('users.add')) ?>'"></h2>
                <button type="button" class="pb-btn-ghost !px-2" @click="close()" aria-label="<?= e(__('common.close')) ?>">&times;</button>
            </div>

            <form method="post" action="<?= e(url('users/save')) ?>" class="max-h-[70vh] space-y-4 overflow-y-auto p-5">
                <?= Security::csrfField() ?>
                <input type="hidden" name="id" :value="form.id">

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="pb-label" for="user-email"><?= e(__('users.email')) ?></label>
                        <input class="pb-input" type="email" id="user-email" name="email" required x-model="form.email">
                    </div>
                    <div>
                        <label class="pb-label" for="user-name"><?= e(__('users.name')) ?></label>
                        <input class="pb-input" id="user-name" name="name" maxlength="120" x-model="form.name">
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="pb-label" for="user-role"><?= e(__('users.role')) ?></label>
                        <select class="pb-input" id="user-role" name="role" x-model="form.role">
                            <option value="client"><?= e(__('users.role_client')) ?></option>
                            <option value="super_admin"><?= e(__('users.role_admin')) ?></option>
                        </select>
                    </div>
                    <div>
                        <label class="pb-label" for="user-locale"><?= e(__('users.locale')) ?></label>
                        <select class="pb-input" id="user-locale" name="locale" x-model="form.locale">
                            <?php foreach ($locales as $code => $meta): ?>
                                <option value="<?= e($code) ?>"><?= e($meta['native']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="pb-label" for="user-password">
                            <?= e(__('users.password')) ?>
                            <span x-show="form.id" x-cloak class="font-normal normal-case text-slate-400">
                                (<?= e(__('users.password_keep')) ?>)
                            </span>
                        </label>
                        <input class="pb-input" type="password" id="user-password" name="password"
                               autocomplete="new-password" minlength="<?= (int) $min_password_length ?>">
                    </div>
                    <div>
                        <label class="pb-label" for="user-password-repeat"><?= e(__('users.password_repeat')) ?></label>
                        <input class="pb-input" type="password" id="user-password-repeat" name="password_repeat"
                               autocomplete="new-password">
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" name="is_active" value="1" x-model="form.is_active"
                           class="h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500 dark:border-slate-600 dark:bg-slate-900">
                    <?= e(__('users.active')) ?>
                </label>

                <?php if ($sites !== []): ?>
                    <div class="border-t border-slate-100 pt-4 dark:border-slate-700">
                        <p class="pb-label"><?= e(__('users.assigned_sites')) ?></p>
                        <div class="pb-scroll grid max-h-52 gap-2 overflow-y-auto pr-1 sm:grid-cols-2">
                            <?php foreach ($sites as $site): ?>
                                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                    <input type="checkbox" name="sites[]" value="<?= (int) $site['id'] ?>"
                                           x-model.number="form.sites"
                                           class="h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500 dark:border-slate-600 dark:bg-slate-900">
                                    <?= e($site['name']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="mt-2 text-[11px] text-slate-400"><?= e(__('users.assigned_sites_hint')) ?></p>
                    </div>
                <?php endif; ?>

                <div class="flex items-center justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-700">
                    <button type="button" class="pb-btn-ghost" @click="close()"><?= e(__('common.cancel')) ?></button>
                    <button type="submit" class="pb-btn-primary"><?= e(__('common.save')) ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete modal ----------------------------------------------------- -->
    <div x-show="modal === 'delete'" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-sm">
        <div class="pb-card pb-fade-in w-full max-w-md p-5">
            <h2 class="text-base font-semibold text-slate-900 dark:text-white"><?= e(__('users.delete_title')) ?></h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400"><?= e(__('users.delete_warning')) ?></p>
            <p class="mt-3 rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                <span x-text="deleteTarget ? deleteTarget.email : ''"></span>
            </p>
            <form method="post" action="<?= e(url('users/delete')) ?>" class="mt-4 flex items-center justify-end gap-2">
                <?= Security::csrfField() ?>
                <input type="hidden" name="id" :value="deleteTarget ? deleteTarget.id : 0">
                <button type="button" class="pb-btn-ghost" @click="close()"><?= e(__('common.cancel')) ?></button>
                <button type="submit" class="pb-btn-danger"><?= e(__('common.delete')) ?></button>
            </form>
        </div>
    </div>
</div>
