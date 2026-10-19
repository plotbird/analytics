<?php
/**
 * PlotBird Analytics - public share links.
 *
 * A share link is a read-only window into one site's dashboard for people who
 * have no account. Only a SHA-256 digest of the token is stored, so a database
 * dump cannot be turned into working links; the raw token is shown exactly once,
 * right after it is created.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class ShareLink
{
    /** Length of the random token in bytes (48 hex characters). */
    private const TOKEN_BYTES = 24;

    /**
     * Create a link and return the raw token (the only time it exists in clear).
     *
     * @return array{id:int,token:string}
     */
    public static function create(int $siteId, ?string $expiresAt = null, string $label = ''): array
    {
        $token = random_hex(self::TOKEN_BYTES);
        $db    = Database::instance();

        $id = $db->insert('share_links', [
            'site_id'    => $siteId,
            'token_hash' => hash('sha256', $token),
            'token_hint' => substr($token, 0, 8),
            'label'      => mb_substr(trim($label), 0, 120, 'UTF-8'),
            'created_by' => Auth::id(),
            'created_at' => now_utc(),
            'expires_at' => self::normalizeExpiry($expiresAt),
        ]);

        return ['id' => $id, 'token' => $token];
    }

    /**
     * Resolve a raw token to its link row, or null when it is unknown, revoked
     * or expired. Valid use is recorded.
     *
     * @return array<string,mixed>|null
     */
    public static function resolve(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) < 16 || strlen($token) > 128) {
            return null;
        }

        $db  = Database::instance();
        $row = $db->one(
            'SELECT * FROM ' . $db->table('share_links') . ' WHERE `token_hash` = :hash',
            [':hash' => hash('sha256', $token)]
        );

        if ($row === null) {
            return null;
        }
        if ($row['revoked_at'] !== null && $row['revoked_at'] !== '') {
            return null;
        }
        if ($row['expires_at'] !== null && $row['expires_at'] !== '' && strtotime((string) $row['expires_at'] . ' UTC') < time()) {
            return null;
        }

        // Record usage, but do not let a hot link write on every request.
        $lastUsed = (string) ($row['last_used_at'] ?? '');
        if ($lastUsed === '' || strtotime($lastUsed . ' UTC') < time() - 60) {
            $db->run(
                'UPDATE ' . $db->table('share_links') . ' SET `hits` = `hits` + 1, `last_used_at` = :now WHERE `id` = :id',
                [':now' => now_utc(), ':id' => (int) $row['id']]
            );
        }

        return $row;
    }

    /**
     * All links of a site, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public static function forSite(int $siteId): array
    {
        $db = Database::instance();
        return $db->all(
            'SELECT sl.*, u.`email` AS creator_email
             FROM ' . $db->table('share_links') . ' sl
             LEFT JOIN ' . $db->table('users') . ' u ON u.`id` = sl.`created_by`
             WHERE sl.`site_id` = :sid
             ORDER BY sl.`id` DESC',
            [':sid' => $siteId]
        );
    }

    /**
     * Public URL of a link - a fresh one from create(), or a stored row.
     *
     * @param array<string,mixed> $row
     */
    public static function urlFor(array $row, ?string $rawToken = null): string
    {
        if ($rawToken !== null) {
            return url('share/' . rawurlencode($rawToken));
        }
        // Only the hint survives, which is enough to recognise a link but not
        // to use it. Callers that need a working URL must use the raw token.
        return url('share/' . rawurlencode((string) $row['token_hint']));
    }

    /**
     * Revoke a link: it stops resolving immediately but stays visible in the
     * list so that the administrator can see what happened.
     */
    public static function revoke(int $id, int $siteId): bool
    {
        $db = Database::instance();
        $affected = $db->update(
            'share_links',
            ['revoked_at' => now_utc()],
            '`id` = :id AND `site_id` = :sid AND `revoked_at` IS NULL',
            [':id' => $id, ':sid' => $siteId]
        );
        return $affected > 0;
    }

    /**
     * Delete a link permanently.
     */
    public static function delete(int $id, int $siteId): bool
    {
        $db = Database::instance();
        return $db->delete('share_links', '`id` = :id AND `site_id` = :sid', [':id' => $id, ':sid' => $siteId]) > 0;
    }

    /**
     * A human readable status for the table in the UI.
     *
     * @param array<string,mixed> $row
     */
    public static function status(array $row): string
    {
        if (($row['revoked_at'] ?? null) !== null && $row['revoked_at'] !== '') {
            return 'revoked';
        }
        if (($row['expires_at'] ?? null) !== null && $row['expires_at'] !== ''
            && strtotime((string) $row['expires_at'] . ' UTC') < time()) {
            return 'expired';
        }
        return 'active';
    }

    /**
     * The expiry date as shown in the table: a bare date, or an em dash when
     * the link never expires. Printing the stored DATETIME would show
     * "23:59:59", which only ever means "end of that day".
     *
     * @param array<string,mixed> $row
     */
    public static function expiryLabel(array $row): string
    {
        $value = (string) ($row['expires_at'] ?? '');
        if ($value === '') {
            return '—';
        }
        return substr($value, 0, 10);
    }

    /**
     * Accept either an empty value or a YYYY-MM-DD date and return a DATETIME.
     */
    private static function normalizeExpiry(?string $expiresAt): ?string
    {
        $expiresAt = trim((string) $expiresAt);
        if ($expiresAt === '') {
            return null;
        }
        $timestamp = strtotime($expiresAt . ' 23:59:59 UTC');
        if ($timestamp === false) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
