<?php
/**
 * PlotBird Analytics - user management (super admin only).
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird\Controller;

use PlotBird\Auth;
use PlotBird\I18n;
use PlotBird\User;

final class UsersController extends BaseController
{
    /**
     * GET /users
     */
    public function index(): void
    {
        $this->guardAdmin();

        $users = User::all();

        // Which sites each account may see, so the edit modal opens pre-filled
        // without an extra request.
        $assignments = [];
        foreach ($users as $user) {
            $assignments[(int) $user['id']] = User::siteIds((int) $user['id']);
        }

        $this->render('users', [
            'page_title' => I18n::translate('users.title'),
            'users'      => $users,
            'assignments' => $assignments,
            'sites'      => $this->visibleSites(),
            'locales'    => I18n::available(),
            'min_password_length' => Auth::MIN_PASSWORD_LENGTH,
        ]);
    }

    /**
     * GET /users/{id} - the edit form, rendered as JSON for the modal.
     *
     * @param array<string,string> $params
     */
    public function show(array $params): void
    {
        $this->guardAdmin();

        $id = (int) ($params['id'] ?? 0);
        $user = User::find($id);
        if ($user === null) {
            json_out(['error' => 'not_found'], 404);
        }
        unset($user['password_hash']);

        json_out([
            'user'  => $user,
            'sites' => User::siteIds($id),
        ]);
    }

    /**
     * POST /users/save - create or update.
     */
    public function save(): void
    {
        $admin = $this->guardAdmin();
        $this->guardCsrf();

        $id       = post_int('id');
        $email    = mb_strtolower(post('email'), 'UTF-8');
        $name     = post('name');
        $role     = post('role') === User::ROLE_SUPER_ADMIN ? User::ROLE_SUPER_ADMIN : User::ROLE_CLIENT;
        $locale   = post('locale', I18n::FALLBACK);
        $active   = post_bool('is_active');
        $password = (string) ($_POST['password'] ?? '');
        $repeat   = (string) ($_POST['password_repeat'] ?? '');

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->flash('error', I18n::translate('users.error_email'));
            $this->redirectTo('users');
        }

        $existing = $id > 0 ? User::find($id) : null;

        if ($id > 0 && $existing === null) {
            $this->flash('error', I18n::translate('users.error_not_found'));
            $this->redirectTo('users');
        }

        // Guard rails around the last administrator.
        if ($existing !== null) {
            $isSelf = (int) $admin['id'] === $id;
            if ($existing['role'] === User::ROLE_SUPER_ADMIN
                && ($role !== User::ROLE_SUPER_ADMIN || !$active)
                && User::countSuperAdmins() <= 1) {
                $this->flash('error', I18n::translate('users.error_last_admin'));
                $this->redirectTo('users');
            }
            if ($isSelf && !$active) {
                $this->flash('error', I18n::translate('users.error_self_block'));
                $this->redirectTo('users');
            }
        }

        if ($id === 0 || $password !== '') {
            $problem = Auth::validatePassword($password, $repeat);
            if ($problem !== '') {
                $this->flash('error', I18n::translate('users.error_password_' . $problem));
                $this->redirectTo('users');
            }
        }

        if ($id === 0) {
            // Emails are unique; catch the clash before MySQL does.
            if (User::findWithSecret($email) !== null) {
                $this->flash('error', I18n::translate('users.error_email_taken'));
                $this->redirectTo('users');
            }
            $id = User::create([
                'email'     => $email,
                'name'      => $name,
                'password'  => $password,
                'role'      => $role,
                'locale'    => $locale,
                'is_active' => $active ? 1 : 0,
            ]);
            $this->audit('user_created', 'user', $id, ['email' => $email, 'role' => $role]);
            $this->flash('success', I18n::translate('users.created'));
        } else {
            $duplicate = User::findWithSecret($email);
            if ($duplicate !== null && (int) $duplicate['id'] !== $id) {
                $this->flash('error', I18n::translate('users.error_email_taken'));
                $this->redirectTo('users');
            }

            User::update($id, [
                'email'     => $email,
                'name'      => $name,
                'role'      => $role,
                'locale'    => $locale,
                'is_active' => $active ? 1 : 0,
            ]);
            if ($password !== '') {
                User::setPassword($id, $password);
            }
            $this->audit('user_updated', 'user', $id, ['email' => $email, 'role' => $role]);
            $this->flash('success', I18n::translate('users.saved'));
        }

        // Site assignment is only stored for clients: a super admin sees all.
        $sites = $_POST['sites'] ?? [];
        User::assignSites($id, is_array($sites) ? array_map('intval', $sites) : []);

        $this->redirectTo('users');
    }

    /**
     * POST /users/delete
     */
    public function delete(): void
    {
        $admin = $this->guardAdmin();
        $this->guardCsrf();

        $id = post_int('id');
        $user = $id > 0 ? User::find($id) : null;

        if ($user === null) {
            $this->flash('error', I18n::translate('users.error_not_found'));
            $this->redirectTo('users');
        }
        if ($id === (int) $admin['id']) {
            $this->flash('error', I18n::translate('users.error_delete_self'));
            $this->redirectTo('users');
        }
        if ($user['role'] === User::ROLE_SUPER_ADMIN && User::countSuperAdmins() <= 1) {
            $this->flash('error', I18n::translate('users.error_last_admin'));
            $this->redirectTo('users');
        }

        User::delete($id);
        $this->audit('user_deleted', 'user', $id, ['email' => $user['email']]);
        $this->flash('success', I18n::translate('users.deleted'));
        $this->redirectTo('users');
    }
}
