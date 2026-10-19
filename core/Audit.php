<?php
/**
 * PlotBird Analytics - administrative audit trail.
 *
 * Every action that changes who can see what is recorded, so a shared
 * installation stays accountable. IP addresses are stored hashed.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class Audit
{
    /**
     * Record an action.
     *
     * @param array<string,mixed> $meta
     */
    public static function log(string $action, string $entity = '', $entityId = '', array $meta = []): void
    {
        try {
            $db = Database::instance();
            $db->insert('audit_log', [
                'user_id'    => Auth::id(),
                'action'     => mb_substr($action, 0, 60, 'UTF-8'),
                'entity'     => mb_substr($entity, 0, 40, 'UTF-8'),
                'entity_id'  => mb_substr((string) $entityId, 0, 64, 'UTF-8'),
                'meta'       => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
                'ip_hash'    => Security::hashIp(client_ip()),
                'created_at' => now_utc(),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never break the action it describes.
            pb_log('audit failed: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Most recent entries, for the settings page.
     *
     * @return list<array<string,mixed>>
     */
    public static function recent(int $limit = 50): array
    {
        $db = Database::instance();
        $limit = max(1, min(500, $limit));

        return $db->all(
            'SELECT a.*, u.`email` AS user_email FROM ' . $db->table('audit_log') . ' a
             LEFT JOIN ' . $db->table('users') . ' u ON u.`id` = a.`user_id`
             ORDER BY a.`id` DESC LIMIT ' . $limit
        );
    }
}
