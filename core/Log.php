<?php
/**
 * PlotBird Analytics - logging.
 *
 * A tiny wrapper so that the bootstrap error handlers can log before (and
 * independently of) the rest of the application. Writes to /data/logs, daily
 * files, never throws.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class Log
{
    /** @var bool Guard against recursive logging when the disk is full. */
    private static bool $inWrite = false;

    public static function error(string $message): void
    {
        self::write('error', $message);
    }

    public static function info(string $message): void
    {
        self::write('app', $message);
    }

    public static function security(string $message): void
    {
        self::write('security', $message);
    }

    private static function write(string $channel, string $message): void
    {
        if (self::$inWrite) {
            return;
        }
        self::$inWrite = true;

        try {
            $dir = PB_ROOT . '/data/logs';
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return;
            }
            $file = $dir . '/' . $channel . '-' . gmdate('Y-m-d') . '.log';

            // Rotate: never let one file grow past ~5 MB.
            if (is_file($file) && filesize($file) > 5 * 1024 * 1024) {
                @rename($file, $file . '.1');
            }

            $line = sprintf(
                "[%s] %s %s\n",
                gmdate('Y-m-d H:i:s'),
                (string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
                str_replace(["\r", "\n"], ' ', $message)
            );
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Logging must never break a request.
        } finally {
            self::$inWrite = false;
        }
    }
}
