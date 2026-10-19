<?php
/**
 * PlotBird Analytics - sign in / sign out / language switching.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird\Controller;

use PlotBird\Auth;
use PlotBird\I18n;
use PlotBird\Security;
use PlotBird\Settings;

final class AuthController extends BaseController
{
    /**
     * The sign-in form.
     */
    public function showLogin(): void
    {
        if (Auth::check()) {
            $this->redirectTo('dashboard');
        }

        // The error is *not* consumed here: the layout renders the flash
        // message, and reading it twice would drop it.
        $this->render('login', [
            'email'      => '',
            'next'       => $this->safeNext((string) ($_GET['next'] ?? '')),
            'notice'     => $this->installerNotice(),
            'page_title' => I18n::translate('login.title'),
        ], 'layout_guest');
    }

    /**
     * Handle the sign-in POST.
     */
    public function login(): void
    {
        $this->guardCsrf();

        $email    = post('email');
        $password = (string) ($_POST['password'] ?? '');
        $next     = $this->safeNext(post('next'));

        if ($email === '' || $password === '') {
            $this->flash('error', I18n::translate('login.error_empty'));
            $this->redirectTo('login', $next === '' ? [] : ['next' => $next]);
        }

        $result = Auth::attempt($email, $password);

        if (!$result['ok']) {
            $message = match ($result['error']) {
                'locked'  => I18n::translate('login.error_locked', ['minutes' => (int) ceil(($result['locked_for'] ?? 0) / 60)]),
                'blocked' => I18n::translate('login.error_blocked'),
                default   => I18n::translate('login.error_credentials'),
            };
            $this->flash('error', $message);
            $this->redirectTo('login', $next === '' ? [] : ['next' => $next]);
        }

        if (post_bool('remember')) {
            Auth::rememberDevice();
        }

        // A non-default interface language is remembered per account.
        $locale = (string) ($result['user']['locale'] ?? '');
        if ($locale !== '' && I18n::isAvailable($locale)) {
            I18n::set($locale);
        }

        $this->flash('success', I18n::translate('login.welcome_back'));
        $this->redirectTo($next === '' ? 'dashboard' : ltrim($next, '/'));
    }

    /**
     * Sign out.
     */
    public function logout(): void
    {
        $this->guardCsrf();
        Auth::logout();
        $this->redirectTo('login');
    }

    /**
     * Switch the interface language and return to the previous page.
     *
     * Changing a language is harmless, so it is allowed over GET; the target is
     * still restricted to a local path.
     */
    public function setLocale(array $params): void
    {
        $locale = (string) ($params['locale'] ?? '');
        if (I18n::isAvailable($locale)) {
            I18n::set($locale);

            // Persist the choice for signed-in users as well.
            $user = Auth::user();
            if ($user !== null) {
                \PlotBird\User::update((int) $user['id'], ['locale' => $locale]);
            }
        }

        $next = $this->safeNext((string) ($_GET['next'] ?? ''));
        $this->redirectTo($next === '' ? (Auth::check() ? 'dashboard' : 'login') : ltrim($next, '/'));
    }

    /**
     * Only accept a same-site absolute path as a redirect target, so that
     * ?next=https://evil.example cannot be used as an open redirect.
     */
    private function safeNext(string $next): string
    {
        $next = trim($next);
        if ($next === '' || $next[0] !== '/' || strpos($next, '//') === 0 || strpos($next, '\\') !== false) {
            return '';
        }
        if (preg_match('/[\x00-\x1F]/', $next) === 1) {
            return '';
        }
        // Strip the application base path if it is present.
        $basePath = trim((string) config('app.base_path', ''), '/');
        if ($basePath !== '' && strpos($next, '/' . $basePath) === 0) {
            $next = substr($next, strlen($basePath) + 1);
        }
        return $next;
    }

    /**
     * A warning shown on the sign-in page while /install still exists.
     */
    private function installerNotice(): string
    {
        if (!Security::installerPresent()) {
            return '';
        }
        if (!Settings::get('installed_at')) {
            return '';
        }
        return I18n::translate('security.install_dir_present');
    }
}
