<?php
/**
 * PlotBird Analytics - controller base class.
 *
 * Provides the pieces every page needs: authentication guards, CSRF checks,
 * flash messages, site selection and the data the layout expects.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird\Controller;

use PlotBird\Audit;
use PlotBird\Auth;
use PlotBird\I18n;
use PlotBird\Migrator;
use PlotBird\Security;
use PlotBird\Site;
use PlotBird\User;
use PlotBird\View;

abstract class BaseController
{
    /** @var array<string,mixed>|null Cached signed-in user. */
    protected ?array $user = null;

    /** @var list<array<string,mixed>>|null Cached list of visible sites. */
    protected ?array $sites = null;

    /**
     * The signed-in user, loaded on first use.
     *
     * Controllers must use this instead of touching $this->user directly: the
     * property is only filled by context(), which runs while rendering, and a
     * controller that needs the user before that would otherwise see null.
     *
     * @return array<string,mixed>|null
     */
    protected function currentUser(): ?array
    {
        if ($this->user === null) {
            $this->user = Auth::user();
        }
        return $this->user;
    }

    /**
     * The sites the signed-in user may open, loaded on first use.
     *
     * @return list<array<string,mixed>>
     */
    protected function visibleSites(): array
    {
        if ($this->sites === null) {
            $user = $this->currentUser();
            $this->sites = $user === null ? [] : Site::forUser($user);
        }
        return $this->sites;
    }

    /**
     * Everything a template can count on: the user, their sites, the selected
     * site, the language list and the security notices.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    protected function context(array $data = []): array
    {
        $user  = $this->currentUser();
        $sites = $this->visibleSites();

        $currentSite = $data['site'] ?? null;
        if (!is_array($currentSite)) {
            $currentSite = $this->selectedSite();
        }

        return array_merge([
            'user'          => $user,
            'sites'         => $sites,
            'current_site'  => $currentSite,
            'locales'       => I18n::available(),
            'locale'        => I18n::current(),
            'app_name'      => (string) config('app.name', 'PlotBird Analytics'),
            'app_version'   => PB_VERSION,
            'installer_present' => Security::installerPresent(),
            // Only administrators are told about a pending schema update, and
            // only they see the banner that offers to apply it.
            'pending_migrations' => ($user !== null && $user['role'] === User::ROLE_SUPER_ADMIN)
                ? Migrator::pending()
                : [],
            'flash_success' => flash('success'),
            'flash_error'   => flash('error'),
            'current_path'  => Security::currentPath(),
        ], $data);
    }

    /**
     * Render a page inside the application layout.
     *
     * @param array<string,mixed> $data
     */
    protected function render(string $template, array $data = [], string $layout = 'layout'): void
    {
        View::render($template, $this->context($data), $layout);
    }

    /**
     * The site the request refers to: ?site=<id> when allowed, otherwise the
     * first site the user can see.
     *
     * @return array<string,mixed>|null
     */
    protected function selectedSite(): ?array
    {
        $sites = $this->visibleSites();

        $requested = (int) ($_GET['site'] ?? $_POST['site_id'] ?? 0);
        if ($requested > 0) {
            foreach ($sites as $candidate) {
                if ((int) $candidate['id'] === $requested) {
                    return $candidate;
                }
            }
            // A site the user may not see is treated as "no site", never as an
            // error page: that would leak the existence of other sites.
        }

        return $sites === [] ? null : $sites[0];
    }

    /**
     * Resolve a site from the request and make sure the user may open it.
     *
     * @return array<string,mixed>
     */
    protected function requireSite(): array
    {
        $site = $this->selectedSite();
        if ($site === null) {
            $this->flash('error', I18n::translate('sites.none_selected'));
            $this->redirectTo('sites');
        }
        return $site;
    }

    /**
     * Require a signed-in user.
     */
    protected function guardLogin(): array
    {
        Auth::requireLogin();
        $user = $this->currentUser();
        if ($user === null) {
            // requireLogin() already redirected; this satisfies static analysis.
            $this->redirectTo('login');
        }
        return $user;
    }

    /**
     * Require a super administrator.
     */
    protected function guardAdmin(): array
    {
        Auth::requireSuperAdmin();
        $user = $this->currentUser();
        if ($user === null || $user['role'] !== User::ROLE_SUPER_ADMIN) {
            $this->redirectTo('dashboard');
        }
        return $user;
    }

    /**
     * Abort unless the POST carried a valid CSRF token.
     */
    protected function guardCsrf(): void
    {
        Security::requireCsrf();
    }

    /**
     * Store a flash message.
     */
    protected function flash(string $type, string $message): void
    {
        flash($type, $message);
    }

    /**
     * Redirect to an application path and stop.
     *
     * @param array<string,string|int> $params
     */
    protected function redirectTo(string $path, array $params = []): void
    {
        redirect(url($path, $params));
    }

    /**
     * Record an administrative action.
     *
     * @param array<string,mixed> $meta
     */
    protected function audit(string $action, string $entity = '', $entityId = '', array $meta = []): void
    {
        Audit::log($action, $entity, $entityId, $meta);
    }
}
