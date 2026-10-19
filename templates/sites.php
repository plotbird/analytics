<?php
/**
 * Website management: create, configure, share and delete tracked sites.
 *
 * @var list<array<string,mixed>> $site_list
 * @var array<int,array<string,mixed>> $details
 * @var list<array<string,mixed>> $clients
 * @var array<string,string> $timezones
 * @var bool $is_admin
 *
 * @package PlotBird
 */

use PlotBird\Security;
use PlotBird\ShareLink;

// Data for the Alpine component: the editable fields of every site.
$sitesForJs = [];
foreach ($site_list as $site) {
    $settings = \PlotBird\Site::trackerSettings($site);
    $sitesForJs[] = [
        'id'       => (int) $site['id'],
        'name'     => (string) $site['name'],
        'domain'   => (string) $site['domain'],
        'timezone' => (string) $site['timezone'],
        'is_active' => (int) $site['is_active'] === 1,
        'users'    => $details[(int) $site['id']]['assigned'] ?? [],
        'settings' => [
            'exclude_paths' => implode("\n", (array) ($settings['exclude_paths'] ?? [])),
            'exclude_ips'   => implode("\n", (array) ($settings['exclude_ips'] ?? [])),
            'allowed_hosts' => implode("\n", (array) ($settings['allowed_hosts'] ?? [])),
            'respect_dnt'   => (bool) ($settings['respect_dnt'] ?? false),
            'track_query'   => (bool) ($settings['track_query'] ?? false),
            'exclude_bots'  => (bool) ($settings['exclude_bots'] ?? true),
        ],
    ];
}
?>

<div x-data="pbSites(<?= e(json_encode($sitesForJs, JSON_UNESCAPED_UNICODE)) ?>, <?= e(json_encode(array_map(static fn ($c) => ['id' => (int) $c['id'], 'email' => (string) $c['email']], $clients), JSON_UNESCAPED_UNICODE)) ?>)">

    <div class="mb-5 flex flex-wrap items-center gap-3">
        <div>
            <h1 class="text-lg font-semibold tracking-tight text-slate-900 dark:text-white"><?= e(__('sites.title')) ?></h1>
            <p class="text-xs text-slate-500 dark:text-slate-400"><?= e(__('sites.subtitle')) ?></p>
        </div>
        <?php if ($is_admin): ?>
            <button type="button" class="pb-btn-primary ml-auto" @click="openCreate()">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z"/>
                </svg>
                <?= e(__('sites.add')) ?>
            </button>
        <?php endif; ?>
    </div>

    <?php if ($site_list === []): ?>
        <div class="pb-card p-10 text-center">
            <p class="text-sm text-slate-500 dark:text-slate-400"><?= e(__('sites.empty')) ?></p>
            <?php if ($is_admin): ?>
                <button type="button" class="pb-btn-primary mt-4" @click="openCreate()"><?= e(__('sites.add_first')) ?></button>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="space-y-4">
        <?php foreach ($site_list as $site): ?>
            <?php
            $siteId  = (int) $site['id'];
            $detail  = $details[$siteId] ?? [];
            $snippet = (string) ($detail['snippet'] ?? '');
            $lastHit = $detail['last_hit'] ?? null;
            $links   = $detail['share_links'] ?? [];
            ?>
            <section class="pb-card overflow-hidden" x-data="{ code: false, details: false }">
                <div class="pb-card-header flex-wrap">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="truncate text-sm font-semibold text-slate-800 dark:text-slate-100"><?= e($site['name']) ?></h2>
                            <?php if ((int) $site['is_active'] !== 1): ?>
                                <span class="pb-badge bg-slate-100 text-slate-600 dark:bg-slate-700/60 dark:text-slate-300">
                                    <?= e(__('sites.inactive')) ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($lastHit === null): ?>
                                <span class="pb-badge bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                                    <?= e(__('sites.no_data')) ?>
                                </span>
                            <?php else: ?>
                                <span class="pb-badge bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                                    <?= e(__('sites.last_hit')) ?> <?= e($lastHit) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <p class="mt-0.5 truncate text-xs text-slate-400">
                            <?= e($site['domain'] !== '' ? $site['domain'] : __('sites.no_domain')) ?>
                            · <?= e($site['timezone']) ?>
                            · ID <code class="text-[11px]"><?= e($site['public_id']) ?></code>
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <a href="<?= e(url('dashboard', ['site' => $siteId])) ?>" class="pb-btn-ghost !text-xs">
                            <?= e(__('sites.open_dashboard')) ?>
                        </a>
                        <button type="button" class="pb-btn-ghost !text-xs" :class="code ? '!bg-slate-100 dark:!bg-slate-700/60' : ''"
                                @click="code = !code">
                            <?= e(__('sites.tracker_code')) ?>
                        </button>
                        <button type="button" class="pb-btn-ghost !text-xs" :class="details ? '!bg-slate-100 dark:!bg-slate-700/60' : ''"
                                @click="details = !details">
                            <span x-show="!details"><?= e(__('sites.details_show')) ?></span>
                            <span x-show="details" x-cloak><?= e(__('sites.details_hide')) ?></span>
                        </button>
                        <?php if ($is_admin): ?>
                            <button type="button" class="pb-btn-ghost !text-xs" @click="openEdit(<?= $siteId ?>)">
                                <?= e(__('common.settings')) ?>
                            </button>
                            <button type="button" class="pb-btn-ghost !text-xs !text-rose-600 dark:!text-rose-400"
                                    @click="askDelete(<?= $siteId ?>)">
                                <?= e(__('common.delete')) ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tracker snippet ---------------------------------------- -->
                <div x-show="code" x-cloak x-transition class="border-b border-slate-100 bg-slate-50/60 p-5 dark:border-slate-700/60 dark:bg-slate-900/30">
                    <p class="pb-label"><?= e(__('sites.snippet_hint')) ?></p>
                    <div class="flex flex-wrap items-start gap-3">
                        <pre class="pb-scroll min-w-0 flex-1 overflow-x-auto rounded-xl bg-slate-900 p-3.5 text-xs leading-relaxed text-slate-100"><code><?= e($snippet) ?></code></pre>
                        <div class="flex flex-col gap-2"
                             x-data="pbTrackerCheck(<?= $siteId ?>, <?= e(json_encode(url('sites/check-tracker'))) ?>)">
                            <button type="button" class="pb-btn-primary !text-xs" @click="copy(<?= e(json_encode($snippet)) ?>)">
                                <span x-show="copied !== <?= e(json_encode($snippet)) ?>"><?= e(__('common.copy')) ?></span>
                                <span x-show="copied === <?= e(json_encode($snippet)) ?>" x-cloak><?= e(__('common.copied')) ?></span>
                            </button>
                            <button type="button" class="pb-btn-ghost !text-xs" @click="check()" :disabled="checking">
                                <span x-show="!checking"><?= e(__('sites.check_tracker')) ?></span>
                                <span x-show="checking" x-cloak><?= e(__('sites.checking')) ?></span>
                            </button>
                            <span class="text-xs text-slate-400"><?= e(__('sites.tracker_size_note')) ?></span>
                            <p x-show="message" x-cloak x-text="message"
                               :class="ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'"
                               class="max-w-56 text-xs"></p>
                        </div>
                    </div>
                </div>

                <!-- Details: public links and goals, collapsed by default -->
                <div x-show="details" x-cloak x-transition class="border-t border-slate-100 dark:border-slate-700/60">
                <div class="p-5">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                            <?= e(__('share.title')) ?>
                        </p>
                        <form method="post" action="<?= e(url('sites/share')) ?>" class="ml-auto flex flex-wrap items-end gap-2">
                            <?= Security::csrfField() ?>
                            <input type="hidden" name="site_id" value="<?= $siteId ?>">
                            <div>
                                <label class="pb-label !mb-1 !text-[10px]" for="share-label-<?= $siteId ?>"><?= e(__('share.label')) ?></label>
                                <input type="text" id="share-label-<?= $siteId ?>" name="label"
                                       class="pb-input !w-44 !py-1.5 !text-xs"
                                       placeholder="<?= e(__('share.label_placeholder')) ?>" maxlength="120">
                            </div>
                            <div>
                                <label class="pb-label !mb-1 !text-[10px]" for="share-expires-<?= $siteId ?>"><?= e(__('share.expires_date')) ?></label>
                                <input type="date" id="share-expires-<?= $siteId ?>" name="expires_at"
                                       class="pb-input !w-40 !py-1.5 !text-xs">
                            </div>
                            <button type="submit" class="pb-btn-primary !py-1.5 !text-xs"><?= e(__('share.create')) ?></button>
                        </form>
                    </div>
                    <p class="mt-2 text-[11px] text-slate-400"><?= e(__('share.expires_hint')) ?></p>

                    <?php if (empty($links)): ?>
                        <p class="mt-3 text-xs text-slate-400"><?= e(__('share.empty')) ?></p>
                    <?php else: ?>
                        <div class="mt-3 overflow-x-auto">
                            <table class="w-full text-left text-xs">
                                <thead class="text-slate-400">
                                    <tr>
                                        <th class="pb-1.5 pr-3 font-semibold uppercase tracking-wide"><?= e(__('share.label')) ?></th>
                                        <th class="pb-1.5 pr-3 font-semibold uppercase tracking-wide"><?= e(__('share.token')) ?></th>
                                        <th class="pb-1.5 pr-3 font-semibold uppercase tracking-wide"><?= e(__('share.created')) ?></th>
                                        <th class="pb-1.5 pr-3 font-semibold uppercase tracking-wide"><?= e(__('share.expires_at')) ?></th>
                                        <th class="pb-1.5 pr-3 font-semibold uppercase tracking-wide"><?= e(__('share.hits')) ?></th>
                                        <th class="pb-1.5 pr-3 font-semibold uppercase tracking-wide"><?= e(__('share.status')) ?></th>
                                        <th class="pb-1.5"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($links as $link): ?>
                                        <?php $status = ShareLink::status($link); ?>
                                        <tr class="pb-table-row">
                                            <td class="py-2 pr-3 text-slate-600 dark:text-slate-300">
                                                <?= e($link['label'] !== '' ? $link['label'] : '—') ?>
                                            </td>
                                            <td class="py-2 pr-3 font-mono text-[11px] text-slate-400">
                                                <?= e((string) $link['token_hint']) ?>…
                                            </td>
                                            <td class="py-2 pr-3 text-slate-500"><?= e((string) $link['created_at']) ?></td>
                                            <td class="py-2 pr-3 text-slate-500"><?= e(ShareLink::expiryLabel($link)) ?></td>
                                            <td class="py-2 pr-3 tabular-nums text-slate-500"><?= (int) $link['hits'] ?></td>
                                            <td class="py-2 pr-3">
                                                <span class="pb-badge <?= $status === 'active'
                                                    ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                                                    : 'bg-slate-100 text-slate-500 dark:bg-slate-700/60 dark:text-slate-400' ?>">
                                                    <?= e(__('share.status_' . $status)) ?>
                                                </span>
                                            </td>
                                            <td class="py-2 text-right">
                                                <div class="flex items-center justify-end gap-1">
                                                    <?php if ($status === 'active'): ?>
                                                        <form method="post" action="<?= e(url('sites/share/revoke')) ?>">
                                                            <?= Security::csrfField() ?>
                                                            <input type="hidden" name="site_id" value="<?= $siteId ?>">
                                                            <input type="hidden" name="link_id" value="<?= (int) $link['id'] ?>">
                                                            <button type="submit" class="pb-btn-ghost !py-1 !text-xs !text-rose-600 dark:!text-rose-400">
                                                                <?= e(__('share.revoke')) ?>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="post" action="<?= e(url('sites/share/delete')) ?>">
                                                            <?= Security::csrfField() ?>
                                                            <input type="hidden" name="site_id" value="<?= $siteId ?>">
                                                            <input type="hidden" name="link_id" value="<?= (int) $link['id'] ?>">
                                                            <button type="submit" class="pb-btn-ghost !py-1 !text-xs">
                                                                <?= e(__('common.delete')) ?>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-2 text-[11px] text-slate-400"><?= e(__('share.token_hint_explained')) ?></p>
                    <?php endif; ?>
                </div>

                <?php if ($is_admin): ?>
                    <?php $goals = $detail['goals'] ?? []; ?>
                    <div class="border-t border-slate-100 p-5 dark:border-slate-700/60">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                <?= e(__('goals.title')) ?>
                            </p>
                            <form method="post" action="<?= e(url('sites/goals')) ?>" class="ml-auto flex flex-wrap items-end gap-2">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="site_id" value="<?= $siteId ?>">
                                <div>
                                    <label class="pb-label !mb-1 !text-[10px]" for="goal-name-<?= $siteId ?>"><?= e(__('goals.name')) ?></label>
                                    <input class="pb-input !w-40 !py-1.5 !text-xs" id="goal-name-<?= $siteId ?>"
                                           name="name" maxlength="120" required
                                           placeholder="<?= e(__('goals.name_placeholder')) ?>">
                                </div>
                                <div>
                                    <label class="pb-label !mb-1 !text-[10px]" for="goal-type-<?= $siteId ?>"><?= e(__('goals.type')) ?></label>
                                    <select class="pb-input !w-36 !py-1.5 !text-xs" id="goal-type-<?= $siteId ?>" name="type">
                                        <option value="page"><?= e(__('goals.type_page')) ?></option>
                                        <option value="event"><?= e(__('goals.type_event')) ?></option>
                                    </select>
                                </div>
                                <div>
                                    <label class="pb-label !mb-1 !text-[10px]" for="goal-value-<?= $siteId ?>"><?= e(__('goals.target')) ?></label>
                                    <input class="pb-input !w-44 !py-1.5 !text-xs !font-mono" id="goal-value-<?= $siteId ?>"
                                           name="match_value" maxlength="191" required
                                           placeholder="<?= e(__('goals.target_placeholder')) ?>">
                                </div>
                                <button type="submit" class="pb-btn-primary !py-1.5 !text-xs"><?= e(__('goals.add')) ?></button>
                            </form>
                        </div>
                        <p class="mt-1.5 text-[11px] text-slate-400"><?= e(__('goals.hint')) ?></p>

                        <?php if ($goals === []): ?>
                            <p class="mt-3 text-xs text-slate-400"><?= e(__('goals.empty')) ?></p>
                        <?php else: ?>
                            <div class="mt-3 overflow-x-auto">
                                <table class="w-full text-left text-xs">
                                    <thead class="text-slate-400">
                                        <tr>
                                            <th class="pb-1.5 pr-3 font-semibold uppercase tracking-wide"><?= e(__('goals.name')) ?></th>
                                            <th class="pb-1.5 pr-3 font-semibold uppercase tracking-wide"><?= e(__('goals.type')) ?></th>
                                            <th class="pb-1.5 pr-3 font-semibold uppercase tracking-wide"><?= e(__('goals.target')) ?></th>
                                            <th class="pb-1.5 pr-3 font-semibold uppercase tracking-wide"><?= e(__('goals.status')) ?></th>
                                            <th class="pb-1.5"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($goals as $goal): ?>
                                            <?php $goalActive = (int) $goal['is_active'] === 1; ?>
                                            <tr class="pb-table-row">
                                                <td class="py-2 pr-3 text-slate-600 dark:text-slate-300"><?= e($goal['name']) ?></td>
                                                <td class="py-2 pr-3">
                                                    <span class="pb-badge bg-slate-100 text-slate-600 dark:bg-slate-700/60 dark:text-slate-300">
                                                        <?= e($goal['type'] === 'event' ? __('goals.type_event') : __('goals.type_page')) ?>
                                                    </span>
                                                </td>
                                                <td class="py-2 pr-3 font-mono text-[11px] text-slate-500 dark:text-slate-400">
                                                    <?= e($goal['match_value']) ?>
                                                </td>
                                                <td class="py-2 pr-3">
                                                    <span class="pb-badge <?= $goalActive
                                                        ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                                                        : 'bg-slate-100 text-slate-500 dark:bg-slate-700/60 dark:text-slate-400' ?>">
                                                        <?= e($goalActive ? __('goals.active') : __('goals.inactive')) ?>
                                                    </span>
                                                </td>
                                                <td class="py-2">
                                                    <div class="flex items-center justify-end gap-1.5">
                                                        <form method="post" action="<?= e(url('sites/goals/update')) ?>">
                                                            <?= Security::csrfField() ?>
                                                            <input type="hidden" name="site_id" value="<?= $siteId ?>">
                                                            <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                                                            <input type="hidden" name="name" value="<?= e($goal['name']) ?>">
                                                            <input type="hidden" name="is_active" value="<?= $goalActive ? '0' : '1' ?>">
                                                            <button type="submit" class="pb-btn-ghost !py-1 !text-xs">
                                                                <?= e($goalActive ? __('goals.disable') : __('goals.enable')) ?>
                                                            </button>
                                                        </form>
                                                        <form method="post" action="<?= e(url('sites/goals/delete')) ?>">
                                                            <?= Security::csrfField() ?>
                                                            <input type="hidden" name="site_id" value="<?= $siteId ?>">
                                                            <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                                                            <button type="submit" class="pb-btn-ghost !py-1 !text-xs !text-rose-600 dark:!text-rose-400">
                                                                <?= e(__('common.delete')) ?>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                </div><!-- /details -->
            </section>
        <?php endforeach; ?>
    </div>

    <!-- Create / edit modal ---------------------------------------------- -->
    <div x-show="modal === 'site'" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 backdrop-blur-sm">
        <div class="pb-card pb-fade-in my-8 w-full max-w-2xl" @click.outside="close()">
            <div class="pb-card-header">
                <h2 class="pb-card-title" x-text="form.id ? '<?= e(__('sites.edit')) ?>' : '<?= e(__('sites.add')) ?>'"></h2>
                <button type="button" class="pb-btn-ghost !px-2" @click="close()" aria-label="<?= e(__('common.close')) ?>">&times;</button>
            </div>

            <form method="post" action="<?= e(url('sites/save')) ?>" class="max-h-[70vh] space-y-4 overflow-y-auto p-5">
                <?= Security::csrfField() ?>
                <input type="hidden" name="id" :value="form.id">

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="pb-label" for="site-name"><?= e(__('sites.name')) ?></label>
                        <input class="pb-input" id="site-name" name="name" required maxlength="120" x-model="form.name">
                    </div>
                    <div>
                        <label class="pb-label" for="site-domain"><?= e(__('sites.domain')) ?></label>
                        <input class="pb-input" id="site-domain" name="domain" maxlength="190"
                               placeholder="example.com" x-model="form.domain">
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="pb-label" for="site-tz"><?= e(__('sites.timezone')) ?></label>
                        <select class="pb-input" id="site-tz" name="timezone" x-model="form.timezone">
                            <?php foreach ($timezones as $value => $label): ?>
                                <option value="<?= e($value) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <label class="flex items-end gap-2 pb-2.5 text-sm text-slate-600 dark:text-slate-300">
                        <input type="checkbox" name="is_active" value="1" x-model="form.is_active"
                               class="h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500 dark:border-slate-600 dark:bg-slate-900">
                        <?= e(__('sites.active')) ?>
                    </label>
                </div>

                <div class="border-t border-slate-100 pt-4 dark:border-slate-700">
                    <p class="pb-label"><?= e(__('sites.tracker_settings')) ?></p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="pb-label" for="site-exclude-paths"><?= e(__('sites.exclude_paths')) ?></label>
                            <textarea class="pb-input font-mono !text-xs" id="site-exclude-paths" name="exclude_paths" rows="3"
                                      placeholder="/admin&#10;/checkout*" x-model="form.exclude_paths"></textarea>
                        </div>
                        <div>
                            <label class="pb-label" for="site-exclude-ips"><?= e(__('sites.exclude_ips')) ?></label>
                            <textarea class="pb-input font-mono !text-xs" id="site-exclude-ips" name="exclude_ips" rows="3"
                                      placeholder="203.0.113.0/24" x-model="form.exclude_ips"></textarea>
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="pb-label" for="site-hosts"><?= e(__('sites.allowed_hosts')) ?></label>
                        <textarea class="pb-input font-mono !text-xs" id="site-hosts" name="allowed_hosts" rows="2"
                                  placeholder="example.com&#10;*.example.com" x-model="form.allowed_hosts"></textarea>
                        <p class="mt-1 text-[11px] text-slate-400"><?= e(__('sites.allowed_hosts_hint')) ?></p>
                    </div>

                    <div class="mt-4 grid gap-2 sm:grid-cols-3">
                        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <input type="checkbox" name="respect_dnt" value="1" x-model="form.respect_dnt"
                                   class="h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500 dark:border-slate-600 dark:bg-slate-900">
                            <?= e(__('sites.respect_dnt')) ?>
                        </label>
                        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <input type="checkbox" name="track_query" value="1" x-model="form.track_query"
                                   class="h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500 dark:border-slate-600 dark:bg-slate-900">
                            <?= e(__('sites.track_query')) ?>
                        </label>
                        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <input type="checkbox" name="exclude_bots" value="1" x-model="form.exclude_bots"
                                   class="h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500 dark:border-slate-600 dark:bg-slate-900">
                            <?= e(__('sites.exclude_bots')) ?>
                        </label>
                    </div>
                </div>

                <?php if ($is_admin && $clients !== []): ?>
                    <div class="border-t border-slate-100 pt-4 dark:border-slate-700">
                        <p class="pb-label"><?= e(__('sites.assigned_clients')) ?></p>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <?php foreach ($clients as $client): ?>
                                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                    <input type="checkbox" name="users[]" value="<?= (int) $client['id'] ?>"
                                           x-model.number="form.users"
                                           class="h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500 dark:border-slate-600 dark:bg-slate-900">
                                    <?= e($client['email']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="flex items-center justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-700">
                    <button type="button" class="pb-btn-ghost" @click="close()"><?= e(__('common.cancel')) ?></button>
                    <button type="submit" class="pb-btn-primary"><?= e(__('common.save')) ?></button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($created_share['url'])): ?>
        <!-- A new public link is shown exactly once. Closing it is a decision,
             not a timeout: the token cannot be recovered afterwards. -->
        <div x-data="{ open: true }" x-show="open" x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-sm">
            <div class="pb-card pb-fade-in w-full max-w-lg p-5" role="dialog" aria-modal="true"
                 aria-labelledby="share-created-title">
                <h2 id="share-created-title" class="text-base font-semibold text-slate-900 dark:text-white">
                    <?= e(__('share.created_title')) ?>
                </h2>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400"><?= e(__('share.created')) ?></p>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <input type="text" readonly x-ref="shareUrl" @focus="$el.select()"
                           value="<?= e((string) $created_share['url']) ?>"
                           class="pb-input min-w-0 flex-1 !font-mono !text-xs"
                           aria-label="<?= e(__('share.created_title')) ?>">
                    <button type="button" class="pb-btn-primary !text-xs" @click="copy($refs.shareUrl.value)">
                        <span x-show="copied !== $refs.shareUrl.value"><?= e(__('common.copy')) ?></span>
                        <span x-show="copied === $refs.shareUrl.value" x-cloak><?= e(__('common.copied')) ?></span>
                    </button>
                </div>

                <?php if (!empty($created_share['label'])): ?>
                    <p class="mt-2 text-[11px] text-slate-400"><?= e((string) $created_share['label']) ?></p>
                <?php endif; ?>

                <div class="mt-4 flex justify-end">
                    <button type="button" class="pb-btn-ghost" @click="open = false"><?= e(__('common.close')) ?></button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Delete modal ----------------------------------------------------- -->
    <div x-show="modal === 'delete'" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-sm">
        <div class="pb-card pb-fade-in w-full max-w-md p-5">
            <h2 class="text-base font-semibold text-slate-900 dark:text-white"><?= e(__('sites.delete_title')) ?></h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                <?= e(__('sites.delete_warning')) ?>
            </p>
            <p class="mt-3 rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                <span x-text="deleteTarget ? deleteTarget.name : ''"></span>
            </p>

            <form method="post" action="<?= e(url('sites/delete')) ?>" class="mt-4 space-y-3">
                <?= Security::csrfField() ?>
                <input type="hidden" name="id" :value="deleteTarget ? deleteTarget.id : 0">
                <div>
                    <label class="pb-label"><?= e(__('sites.delete_confirm')) ?></label>
                    <input class="pb-input" name="confirm" required autocomplete="off"
                           :placeholder="deleteTarget ? deleteTarget.name : ''">
                </div>
                <div class="flex items-center justify-end gap-2">
                    <button type="button" class="pb-btn-ghost" @click="close()"><?= e(__('common.cancel')) ?></button>
                    <button type="submit" class="pb-btn-danger"><?= e(__('common.delete')) ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
