<?php
/**
 * PlotBird Analytics - website management.
 *
 * Super admins see and manage every site; clients see only the sites assigned
 * to them and can do nothing but read their statistics.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird\Controller;

use PlotBird\Goal;
use PlotBird\I18n;
use PlotBird\ShareLink;
use PlotBird\Site;
use PlotBird\Stats;
use PlotBird\User;

final class SitesController extends BaseController
{
    /**
     * GET /sites - the list with the tracker snippet for each site.
     */
    public function index(): void
    {
        $user  = $this->guardLogin();
        $sites = $this->visibleSites();
        $isAdmin = $user['role'] === User::ROLE_SUPER_ADMIN;

        // Attach the share links and the last hit so the page needs no extra calls.
        $details = [];
        foreach ($sites as $site) {
            $details[(int) $site['id']] = [
                'snippet'     => Site::trackerSnippet($site),
                'share_links' => ShareLink::forSite((int) $site['id']),
                'last_hit'    => Stats::lastHitAt((int) $site['id']),
                'assigned'    => Site::assignedUserIds((int) $site['id']),
                'goals'       => Goal::forSite((int) $site['id']),
            ];
        }

        // A freshly created public link is shown once, in a dialog the operator
        // closes by hand: a flash message disappears before the URL is copied.
        $createdShare = null;
        if (isset($_SESSION['share_created']) && is_array($_SESSION['share_created'])) {
            $createdShare = $_SESSION['share_created'];
            unset($_SESSION['share_created']);
        }

        $this->render('sites', [
            'page_title'    => I18n::translate('sites.title'),
            'site_list'     => $sites,
            'details'       => $details,
            'clients'       => $isAdmin ? $this->clientList() : [],
            'timezones'     => Site::timezoneOptions(),
            'is_admin'      => $isAdmin,
            'created_share' => $createdShare,
        ]);
    }

    /**
     * POST /sites/save - create or update one site.
     */
    public function save(): void
    {
        $user = $this->guardAdmin();
        $this->guardCsrf();

        $id      = post_int('id');
        $name    = post('name');
        $domain  = post('domain');
        $tz      = post('timezone', 'UTC');

        if ($name === '') {
            $this->flash('error', I18n::translate('sites.error_name_required'));
            $this->redirectTo('sites');
        }

        $trackerSettings = [
            'exclude_paths' => $this->linesToArray(post('exclude_paths')),
            'exclude_ips'   => $this->linesToArray(post('exclude_ips')),
            'allowed_hosts' => $this->linesToArray(post('allowed_hosts')),
            'respect_dnt'   => post_bool('respect_dnt'),
            'track_query'   => post_bool('track_query'),
            'exclude_bots'  => post_bool('exclude_bots'),
        ];

        if ($id > 0) {
            $existing = Site::find($id);
            if ($existing === null) {
                $this->flash('error', I18n::translate('sites.error_not_found'));
                $this->redirectTo('sites');
            }
            Site::update($id, [
                'name'             => $name,
                'domain'           => $domain,
                'timezone'         => $tz,
                'is_active'        => post_bool('is_active'),
                'tracker_settings' => $trackerSettings,
            ]);
            $this->audit('site_updated', 'site', $id, ['name' => $name]);
            $this->flash('success', I18n::translate('sites.saved'));
        } else {
            $id = Site::create([
                'name'             => $name,
                'domain'           => $domain,
                'timezone'         => $tz,
                'created_by'       => (int) $user['id'],
                'tracker_settings' => $trackerSettings,
            ]);
            $this->audit('site_created', 'site', $id, ['name' => $name]);
            $this->flash('success', I18n::translate('sites.created'));
        }

        // Assignment of clients is only meaningful for a super admin.
        $assigned = $_POST['users'] ?? [];
        if (is_array($assigned)) {
            Site::assignUsers($id, array_map('intval', $assigned));
        }

        $this->redirectTo('sites', ['site' => $id]);
    }

    /**
     * POST /sites/delete
     */
    public function delete(): void
    {
        $this->guardAdmin();
        $this->guardCsrf();

        $id = post_int('id');
        $site = $id > 0 ? Site::find($id) : null;
        if ($site === null) {
            $this->flash('error', I18n::translate('sites.error_not_found'));
            $this->redirectTo('sites');
        }

        // Deleting is destructive and irreversible: require the site name typed
        // back, exactly as the UI instructs.
        if (post('confirm') !== (string) $site['name']) {
            $this->flash('error', I18n::translate('sites.error_confirm'));
            $this->redirectTo('sites', ['site' => $id]);
        }

        Site::delete($id);
        $this->audit('site_deleted', 'site', $id, ['name' => $site['name']]);
        $this->flash('success', I18n::translate('sites.deleted'));
        $this->redirectTo('sites');
    }

    /**
     * POST /sites/share - create a public link.
     */
    public function createShare(): void
    {
        $this->guardLogin();
        $this->guardCsrf();

        $site = $this->requireSite();
        $label = post('label');
        $expires = post('expires_at');

        $created = ShareLink::create((int) $site['id'], $expires === '' ? null : $expires, $label);
        $this->audit('share_created', 'site', (int) $site['id'], ['label' => $label]);

        // The raw token exists only in this response, so it has to survive the
        // redirect. A flash message is the wrong carrier: it fades away on its
        // own while the operator is still copying the URL.
        $_SESSION['share_created'] = [
            'site_id' => (int) $site['id'],
            'url'     => ShareLink::urlFor(['token_hint' => ''], (string) $created['token']),
            'label'   => $label,
        ];

        $this->redirectTo('sites', ['site' => (int) $site['id']]);
    }

    /**
     * POST /sites/share/revoke
     */
    public function revokeShare(): void
    {
        $this->guardLogin();
        $this->guardCsrf();

        $site = $this->requireSite();
        $id   = post_int('link_id');

        if (ShareLink::revoke($id, (int) $site['id'])) {
            $this->audit('share_revoked', 'site', (int) $site['id'], ['link' => $id]);
            $this->flash('success', I18n::translate('share.revoked'));
        } else {
            $this->flash('error', I18n::translate('share.error_revoke'));
        }
        $this->redirectTo('sites', ['site' => (int) $site['id']]);
    }

    /**
     * POST /sites/check-tracker - has this website sent anything yet?
     *
     * The same question the installer asks on its last step, so the same
     * component drives both: a site that was set up weeks ago can be re-checked
     * from the Websites page without leaving it.
     */
    public function checkTracker(): void
    {
        $this->guardLogin();
        $this->guardCsrf();

        $siteId = post_int('site_id');
        $site = null;
        foreach ($this->visibleSites() as $candidate) {
            if ((int) $candidate['id'] === $siteId) {
                $site = $candidate;
                break;
            }
        }

        if ($site === null) {
            json_out([
                'ok'       => false,
                'tracking' => false,
                'last_hit' => null,
                'message'  => I18n::translate('sites.error_not_found'),
            ], 404);
        }

        $last = Stats::lastHitAt($siteId);

        json_out([
            'ok'       => $last !== null,
            'tracking' => $last !== null,
            'site_id'  => $siteId,
            'last_hit' => $last,
            'message'  => $last === null
                ? I18n::translate('sites.tracker_waiting')
                : I18n::translate('sites.tracker_ok', ['time' => (string) $last]),
        ]);
    }

    /**
     * POST /sites/share/delete - remove a link from the list for good.
     *
     * Revoking keeps the row (so the counter and the history stay honest);
     * deleting is what keeps the panel readable after a few months.
     */
    public function deleteShare(): void
    {
        $this->guardLogin();
        $this->guardCsrf();

        $site = $this->requireSite();
        $linkId = post_int('link_id');

        if ($linkId <= 0 || !ShareLink::delete($linkId, (int) $site['id'])) {
            $this->flash('error', I18n::translate('share.error_delete'));
        } else {
            $this->audit('share_deleted', 'site', (int) $site['id'], ['link' => $linkId]);
            $this->flash('success', I18n::translate('share.deleted'));
        }

        $this->redirectTo('sites', ['site' => (int) $site['id']]);
    }

    /**
     * POST /sites/goals - create a conversion goal.
     */
    public function createGoal(): void
    {
        $this->guardAdmin();
        $this->guardCsrf();
        $site = $this->requireSite();

        $result = Goal::create((int) $site['id'], [
            'name'        => post('name'),
            'type'        => post('type', Goal::TYPE_PAGE),
            'match_value' => post('match_value'),
        ]);

        if (!$result['ok']) {
            $this->flash('error', I18n::translate('goals.error_' . $result['error']));
        } else {
            $this->audit('goal_created', 'site', (int) $site['id'], ['goal' => $result['id']]);
            $this->flash('success', I18n::translate('goals.created'));
        }

        $this->redirectTo('sites', ['site' => (int) $site['id']]);
    }

    /**
     * POST /sites/goals/update - rename a goal or switch it off.
     */
    public function updateGoal(): void
    {
        $this->guardAdmin();
        $this->guardCsrf();
        $site = $this->requireSite();

        $goalId = post_int('goal_id');
        $goal = $goalId > 0 ? Goal::find($goalId) : null;
        if ($goal === null || (int) $goal['site_id'] !== (int) $site['id']) {
            $this->flash('error', I18n::translate('goals.error_not_found'));
            $this->redirectTo('sites', ['site' => (int) $site['id']]);
        }

        $result = Goal::update($goalId, [
            'name'      => post('name', (string) $goal['name']),
            'is_active' => post_bool('is_active'),
        ]);

        if (!$result['ok']) {
            $this->flash('error', I18n::translate('goals.error_' . $result['error']));
        } else {
            $this->audit('goal_updated', 'site', (int) $site['id'], ['goal' => $goalId]);
            $this->flash('success', I18n::translate('goals.saved'));
        }

        $this->redirectTo('sites', ['site' => (int) $site['id']]);
    }

    /**
     * POST /sites/goals/delete - remove a goal and its history.
     */
    public function deleteGoal(): void
    {
        $this->guardAdmin();
        $this->guardCsrf();
        $site = $this->requireSite();

        $goalId = post_int('goal_id');
        $goal = $goalId > 0 ? Goal::find($goalId) : null;
        if ($goal === null || (int) $goal['site_id'] !== (int) $site['id']) {
            $this->flash('error', I18n::translate('goals.error_not_found'));
            $this->redirectTo('sites', ['site' => (int) $site['id']]);
        }

        Goal::delete($goalId);
        $this->audit('goal_deleted', 'site', (int) $site['id'], ['goal' => $goalId, 'name' => $goal['name']]);
        $this->flash('success', I18n::translate('goals.deleted'));
        $this->redirectTo('sites', ['site' => (int) $site['id']]);
    }

    /**
     * All client accounts, for the assignment checkboxes.
     *
     * @return list<array<string,mixed>>
     */
    private function clientList(): array
    {
        $clients = [];
        foreach (User::all() as $user) {
            if ($user['role'] === User::ROLE_CLIENT) {
                $clients[] = $user;
            }
        }
        return $clients;
    }

    /**
     * Turn a textarea into a clean list of non-empty lines.
     *
     * @return list<string>
     */
    private function linesToArray(string $value): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
        $result = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $result[] = mb_substr($line, 0, 190, 'UTF-8');
            }
        }
        return array_values(array_unique($result));
    }
}
