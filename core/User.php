<?php
/**
 * PlotBird Analytics - user repository.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class User
{
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_CLIENT      = 'client';

    /** Columns that are safe to hand to a template. */
    private const PUBLIC_COLUMNS = '`id`, `email`, `name`, `role`, `locale`, `is_active`, `created_at`, `updated_at`, `last_login_at`';

    /**
     * @return array<string,mixed>|null
     */
    public static function find(int $id): ?array
    {
        $db = Database::instance();
        return $db->one('SELECT ' . self::PUBLIC_COLUMNS . ' FROM ' . $db->table('users') . ' WHERE `id` = :id', [':id' => $id]);
    }

    /**
     * Includes the password hash - only authentication may use this.
     *
     * @return array<string,mixed>|null
     */
    public static function findWithSecret(string $email): ?array
    {
        $db = Database::instance();
        return $db->one(
            'SELECT ' . self::PUBLIC_COLUMNS . ', `password_hash` FROM ' . $db->table('users') . ' WHERE `email` = :email',
            [':email' => mb_strtolower(trim($email), 'UTF-8')]
        );
    }

    /**
     * Every user with the number of sites they can see.
     *
     * @return list<array<string,mixed>>
     */
    public static function all(): array
    {
        $db = Database::instance();
        return $db->all(
            'SELECT u.`id`, u.`email`, u.`name`, u.`role`, u.`locale`, u.`is_active`,
                    u.`created_at`, u.`last_login_at`,
                    (SELECT COUNT(*) FROM ' . $db->table('site_users') . ' su WHERE su.`user_id` = u.`id`) AS site_count
             FROM ' . $db->table('users') . ' u
             ORDER BY u.`role` = \'super_admin\' DESC, u.`email` ASC'
        );
    }

    /**
     * Create a user and return the new id.
     *
     * @param array{email:string,name?:string,password:string,role?:string,locale?:string,is_active?:int} $data
     */
    public static function create(array $data): int
    {
        $db = Database::instance();
        $role = ($data['role'] ?? self::ROLE_CLIENT) === self::ROLE_SUPER_ADMIN
            ? self::ROLE_SUPER_ADMIN
            : self::ROLE_CLIENT;

        return $db->insert('users', [
            'email'         => mb_strtolower(trim($data['email']), 'UTF-8'),
            'password_hash' => Security::hashPassword($data['password']),
            'name'          => trim((string) ($data['name'] ?? '')),
            'role'          => $role,
            'locale'        => (string) ($data['locale'] ?? I18n::FALLBACK),
            'is_active'     => (int) ($data['is_active'] ?? 1),
            'created_at'    => now_utc(),
        ]);
    }

    /**
     * Update the mutable profile fields.
     *
     * @param array<string,mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        $allowed = [];
        if (array_key_exists('name', $data)) {
            $allowed['name'] = trim((string) $data['name']);
        }
        if (array_key_exists('email', $data)) {
            $allowed['email'] = mb_strtolower(trim((string) $data['email']), 'UTF-8');
        }
        if (array_key_exists('role', $data)) {
            $allowed['role'] = $data['role'] === self::ROLE_SUPER_ADMIN ? self::ROLE_SUPER_ADMIN : self::ROLE_CLIENT;
        }
        if (array_key_exists('locale', $data) && I18n::isAvailable((string) $data['locale'])) {
            $allowed['locale'] = (string) $data['locale'];
        }
        if (array_key_exists('is_active', $data)) {
            $allowed['is_active'] = (int) ((bool) $data['is_active']);
        }
        if ($allowed === []) {
            return;
        }
        $allowed['updated_at'] = now_utc();
        Database::instance()->update('users', $allowed, '`id` = :id', [':id' => $id]);
    }

    public static function setPassword(int $id, string $password): void
    {
        Database::instance()->update('users', [
            'password_hash' => Security::hashPassword($password),
            'updated_at'    => now_utc(),
        ], '`id` = :id', [':id' => $id]);
    }

    public static function delete(int $id): void
    {
        Database::instance()->delete('users', '`id` = :id', [':id' => $id]);
    }

    /**
     * How many active super admins exist. Used to refuse deleting the last one.
     */
    public static function countSuperAdmins(): int
    {
        $db = Database::instance();
        return (int) $db->value(
            'SELECT COUNT(*) FROM ' . $db->table('users') . ' WHERE `role` = :role AND `is_active` = 1',
            [':role' => self::ROLE_SUPER_ADMIN],
            0
        );
    }

    /**
     * Sites explicitly assigned to a client.
     *
     * @return list<array<string,mixed>>
     */
    public static function siteIds(int $userId): array
    {
        $db = Database::instance();
        $ids = $db->column('SELECT `site_id` FROM ' . $db->table('site_users') . ' WHERE `user_id` = :id', [':id' => $userId]);
        return array_map('intval', $ids);
    }

    /**
     * Replace the set of sites assigned to a client.
     *
     * @param list<int> $siteIds
     */
    public static function assignSites(int $userId, array $siteIds): void
    {
        $db = Database::instance();
        $db->beginTransaction();
        try {
            $db->delete('site_users', '`user_id` = :id', [':id' => $userId]);
            foreach (array_unique(array_map('intval', $siteIds)) as $siteId) {
                if ($siteId <= 0) {
                    continue;
                }
                $db->insert('site_users', [
                    'site_id'    => $siteId,
                    'user_id'    => $userId,
                    'created_at' => now_utc(),
                ]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Mark a successful sign-in.
     */
    public static function touchLogin(int $id, string $ip): void
    {
        Database::instance()->update('users', [
            'last_login_at' => now_utc(),
            'last_login_ip' => mb_substr($ip, 0, 45, 'UTF-8'),
        ], '`id` = :id', [':id' => $id]);
    }
}
