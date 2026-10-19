<?php
/**
 * PlotBird Analytics - file based rate limiter.
 *
 * Shared hostings rarely offer Redis or Memcached, and writing a row to MySQL
 * on every tracked hit would be wasteful, so counters live in small JSON files
 * under /data/ratelimit guarded by flock().
 *
 * If no dedicated directory accepts writes, the limiter falls back to the
 * system temp directory and finally disables itself - it never writes into the
 * project root, and it never fails a request because of its own bookkeeping.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class RateLimiter
{
    /** @var string|null Resolved storage directory, "" when nothing works. */
    private static ?string $directory = null;

    /** @var bool Whether the directory was already resolved. */
    private static bool $resolved = false;

    /**
     * Resolve (and create) the directory that holds the counters.
     *
     * @return string|null Null when no writable location exists.
     */
    public static function directory(): ?string
    {
        if (self::$resolved) {
            return self::$directory === '' ? null : self::$directory;
        }
        self::$resolved = true;

        $candidates = [PB_ROOT . '/data/ratelimit'];

        $temp = sys_get_temp_dir();
        if ($temp !== '') {
            $candidates[] = rtrim($temp, '/\\') . '/plotbird-ratelimit';
        }

        foreach ($candidates as $candidate) {
            if (!pb_dir_writable($candidate)) {
                continue;
            }
            // Keep the directory from being listed over HTTP.
            $guard = rtrim($candidate, '/\\') . '/index.html';
            if (!is_file($guard)) {
                @file_put_contents($guard, '');
            }
            self::$directory = $candidate;
            return $candidate;
        }

        self::$directory = '';
        pb_log('rate limiter: no writable directory, request limits are not enforced', 'error');
        return null;
    }

    /**
     * Consume one unit from a bucket.
     *
     * @param string $bucket Logical name, e.g. "collect" or "login".
     * @param string $key    Identity, usually a hashed IP or a user id.
     * @return array{allowed:bool,count:int,limit:int,remaining:int,retry_after:int}
     */
    public static function hit(string $bucket, string $key, int $limit, int $windowSeconds): array
    {
        $now   = time();
        $file  = self::file($bucket, $key);
        $state = ['start' => $now, 'count' => 0];
        $allowed = true;

        // Without storage the limiter cannot count: allow the request rather
        // than break the site.
        if ($file === null) {
            return ['allowed' => true, 'count' => 0, 'limit' => $limit, 'remaining' => $limit, 'retry_after' => 0];
        }

        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return ['allowed' => true, 'count' => 0, 'limit' => $limit, 'remaining' => $limit, 'retry_after' => 0];
        }

        try {
            if (flock($handle, LOCK_EX)) {
                $raw = stream_get_contents($handle);
                $decoded = json_decode((string) $raw, true);
                if (is_array($decoded) && isset($decoded['start'], $decoded['count'])) {
                    $state = ['start' => (int) $decoded['start'], 'count' => (int) $decoded['count']];
                }

                // Roll the window over when it expired.
                if ($now - $state['start'] >= $windowSeconds) {
                    $state = ['start' => $now, 'count' => 0];
                }

                $allowed = $state['count'] < $limit;
                if ($allowed) {
                    $state['count']++;
                }

                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, (string) json_encode($state));
                fflush($handle);
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }

        $elapsed     = max(0, $now - $state['start']);
        $retryAfter  = max(0, $windowSeconds - $elapsed);

        return [
            'allowed'     => $allowed,
            'count'       => $state['count'],
            'limit'       => $limit,
            'remaining'   => max(0, $limit - $state['count']),
            'retry_after' => $retryAfter,
        ];
    }

    /**
     * Inspect a bucket without consuming anything.
     *
     * @return array{allowed:bool,count:int,limit:int,remaining:int,retry_after:int}
     */
    public static function check(string $bucket, string $key, int $limit, int $windowSeconds): array
    {
        $file = self::file($bucket, $key);
        $now  = time();
        $state = ['start' => $now, 'count' => 0];

        if ($file !== null && is_file($file)) {
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (is_array($decoded) && isset($decoded['start'], $decoded['count'])) {
                $state = ['start' => (int) $decoded['start'], 'count' => (int) $decoded['count']];
            }
        }

        if ($now - $state['start'] >= $windowSeconds) {
            $state = ['start' => $now, 'count' => 0];
        }

        return [
            'allowed'     => $state['count'] < $limit,
            'count'       => $state['count'],
            'limit'       => $limit,
            'remaining'   => max(0, $limit - $state['count']),
            'retry_after' => max(0, $windowSeconds - ($now - $state['start'])),
        ];
    }

    /**
     * Drop a bucket (e.g. after a successful login).
     */
    public static function clear(string $bucket, string $key): void
    {
        $file = self::file($bucket, $key);
        if ($file !== null && is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Delete counters older than $maxAge. Called opportunistically - roughly
     * once in a hundred hits - so the directory cannot grow forever.
     */
    public static function garbageCollect(int $maxAge = 172800): void
    {
        $dir = self::directory();
        if ($dir === null || !is_dir($dir)) {
            return;
        }
        $threshold = time() - $maxAge;
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $threshold) {
                @unlink($file);
            }
        }
    }

    /**
     * Filesystem path of a bucket, hashed so that the key never appears on
     * disk. Null when the limiter has nowhere to write.
     */
    private static function file(string $bucket, string $key): ?string
    {
        $dir = self::directory();
        if ($dir === null) {
            return null;
        }
        $safe = preg_replace('/[^a-z0-9_-]/i', '', $bucket) ?: 'bucket';
        return rtrim($dir, '/\\') . '/' . $safe . '-' . hash('sha256', $key) . '.json';
    }
}
