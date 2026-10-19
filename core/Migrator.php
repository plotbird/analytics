<?php
/**
 * PlotBird Analytics - schema migrations.
 *
 * The project has to be upgradable by uploading files over FTP: there is no
 * SSH on the target hostings, so `bin/console doctrine:migrations:migrate` is
 * not an option. Migrations are therefore plain SQL files in /migrations,
 * applied on demand from the settings page (or by the cron endpoint).
 *
 * Convention, and it matters:
 *   * `install/schema.sql` always describes the CURRENT schema, so a fresh
 *     installation never runs a migration;
 *   * every schema change is added BOTH to schema.sql and as a new file in
 *     /migrations, so existing installations can catch up;
 *   * a fresh installation marks every migration present at install time as
 *     already applied (`baseline()`), because schema.sql already contains it.
 *
 * Migration files are named so that a plain string sort is the correct
 * execution order: 2026_10_17_01_add_visits_ip.sql
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class Migrator
{
    /** Table that records what has been applied. */
    private const TABLE = 'migrations';

    /**
     * Directory holding the .sql migration files.
     */
    public static function directory(): string
    {
        return PB_ROOT . '/migrations';
    }

    /**
     * Ensure the bookkeeping table exists.
     */
    private static function ensureTable(): void
    {
        $db = Database::instance();
        $db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS ' . $db->table(self::TABLE) . ' (
                `filename`   VARCHAR(190) NOT NULL,
                `applied_at` DATETIME     NOT NULL,
                PRIMARY KEY (`filename`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Every migration file on disk, in execution order.
     *
     * @return list<string> File names only.
     */
    public static function available(): array
    {
        $files = glob(self::directory() . '/*.sql') ?: [];
        $names = array_map(static fn (string $path): string => basename($path), $files);
        sort($names, SORT_STRING);
        return array_values($names);
    }

    /**
     * Migration files that have not been applied yet.
     *
     * @return list<string>
     */
    public static function pending(): array
    {
        try {
            self::ensureTable();
            $db = Database::instance();
            $applied = $db->column('SELECT `filename` FROM ' . $db->table(self::TABLE));
            $applied = array_map('strval', $applied);
        } catch (\Throwable $e) {
            pb_log('migrator: cannot read the migrations table: ' . $e->getMessage(), 'error');
            return [];
        }

        return array_values(array_diff(self::available(), $applied));
    }

    /**
     * Whether the schema needs an update.
     */
    public static function hasPending(): bool
    {
        return self::pending() !== [];
    }

    /**
     * Apply every pending migration.
     *
     * @return array{applied:list<string>,failed:array<string,string>}
     */
    public static function run(): array
    {
        $applied = [];
        $failed  = [];

        self::ensureTable();
        $db = Database::instance();

        foreach (self::pending() as $filename) {
            $path = self::directory() . '/' . $filename;
            if (!is_file($path)) {
                continue;
            }

            $sql = (string) file_get_contents($path);
            $sql = str_replace('{{PREFIX}}', $db->prefix(), $sql);

            try {
                $db->executeScript($sql);
                $db->insert(self::TABLE, [
                    'filename'   => $filename,
                    'applied_at' => now_utc(),
                ]);
                $applied[] = $filename;
                pb_log('migration applied: ' . $filename, 'app');
            } catch (\Throwable $e) {
                // Stop at the first failure: later migrations may depend on it.
                $failed[$filename] = $e->getMessage();
                pb_log('migration failed: ' . $filename . ' - ' . $e->getMessage(), 'error');
                break;
            }
        }

        if ($applied !== []) {
            Settings::flush();
            Settings::set('schema_version', PB_SCHEMA_VERSION);
        }

        return ['applied' => $applied, 'failed' => $failed];
    }

    /**
     * Mark every migration currently on disk as applied, without running it.
     *
     * Called right after a fresh installation imported schema.sql, which by
     * convention already describes the newest schema.
     */
    public static function baseline(): int
    {
        self::ensureTable();
        $db = Database::instance();

        $count = 0;
        foreach (self::available() as $filename) {
            $exists = $db->value(
                'SELECT 1 FROM ' . $db->table(self::TABLE) . ' WHERE `filename` = :f LIMIT 1',
                [':f' => $filename],
                false
            );
            if ($exists === false) {
                $db->insert(self::TABLE, ['filename' => $filename, 'applied_at' => now_utc()]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Human readable status for the settings page.
     *
     * @return array{available:int,applied:int,pending:list<string>,last:string}
     */
    public static function status(): array
    {
        self::ensureTable();
        $db = Database::instance();

        $appliedRows = $db->all('SELECT `filename`, `applied_at` FROM ' . $db->table(self::TABLE) . ' ORDER BY `filename` ASC');
        $last = '';
        foreach ($appliedRows as $row) {
            $last = (string) $row['applied_at'];
        }

        return [
            'available' => count(self::available()),
            'applied'   => count($appliedRows),
            'pending'   => self::pending(),
            'last'      => $last,
        ];
    }
}
