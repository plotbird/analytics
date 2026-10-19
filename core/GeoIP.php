<?php
/**
 * PlotBird Analytics - IP geolocation from a local database.
 *
 * No external API is called for a tracked hit: ranges are imported once into
 * `geoip_ranges` and resolved with a single indexed lookup. The bundled base is
 * the free DB-IP Lite country database (CC BY 4.0), which is why a fresh
 * installation works offline and out of the box.
 *
 * CSV format expected (the DB-IP Lite country format):
 *     1.0.0.0,1.0.0.255,AU
 * GeoLite2 style files with a header row and quoted fields are also accepted.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class GeoIP
{
    /** Rows inserted per chunk; keeps a shared-hosting request well under its time limit. */
    public const CHUNK_ROWS = 4000;

    /** @var array<string,string> Per-request memo of IP => country code. */
    private static array $memo = [];

    /**
     * Directory holding the CSV base and its decompressed copy.
     */
    public static function directory(): string
    {
        return PB_ROOT . '/data/geoip';
    }

    /**
     * The bundled, gzipped base shipped with the project.
     */
    public static function bundledFile(): string
    {
        return self::directory() . '/dbip-country-lite.csv.gz';
    }

    /**
     * Plain CSV used for chunked imports (gzip streams cannot be seeked).
     */
    public static function csvFile(): string
    {
        return self::directory() . '/dbip-country-lite.csv';
    }

    /**
     * Whether a usable base is present on disk.
     */
    public static function baseAvailable(): bool
    {
        return is_file(self::bundledFile());
    }

    /**
     * Number of ranges currently in the database.
     */
    public static function count(): int
    {
        try {
            return (int) Database::instance()->value('SELECT COUNT(*) FROM ' . Database::instance()->table('geoip_ranges'), [], 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Whether the ranges table has been populated.
     */
    public static function isImported(): bool
    {
        return self::count() > 0;
    }

    /**
     * Decompress the bundled base into a seekable CSV, if not already done.
     *
     * @return array{ok:bool,csv:string,bytes:int,error:string}
     */
    public static function prepareCsv(?string $source = null): array
    {
        $source = $source ?? self::bundledFile();
        $target = self::csvFile();

        if (!is_file($source)) {
            return ['ok' => false, 'csv' => '', 'bytes' => 0, 'error' => 'missing_source'];
        }

        // Reuse an up-to-date decompressed copy.
        if (is_file($target) && filemtime($target) >= filemtime($source) && filesize($target) > 0) {
            return ['ok' => true, 'csv' => $target, 'bytes' => (int) filesize($target), 'error' => ''];
        }

        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'csv' => '', 'bytes' => 0, 'error' => 'not_writable'];
        }

        $input = self::openReadable($source);
        if ($input === null) {
            return ['ok' => false, 'csv' => '', 'bytes' => 0, 'error' => 'cannot_read_source'];
        }

        $output = @fopen($target . '.part', 'wb');
        if ($output === false) {
            self::closeStream($input);
            return ['ok' => false, 'csv' => '', 'bytes' => 0, 'error' => 'not_writable'];
        }

        $bytes = 0;
        while (!self::streamEof($input)) {
            $chunk = self::streamRead($input, 262144);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $bytes += strlen($chunk);
            fwrite($output, $chunk);
        }
        fclose($output);
        self::closeStream($input);

        if ($bytes === 0) {
            @unlink($target . '.part');
            return ['ok' => false, 'csv' => '', 'bytes' => 0, 'error' => 'empty_source'];
        }

        @rename($target . '.part', $target);
        return ['ok' => true, 'csv' => $target, 'bytes' => (int) $bytes, 'error' => ''];
    }

    /**
     * Import one chunk of the CSV.
     *
     * The first call ($offset === 0) truncates the table, so a failed import
     * cannot leave a half-merged range set behind: the table is simply empty
     * until the next attempt.
     *
     * @return array{ok:bool,done:bool,offset:int,bytes:int,imported:int,error:string}
     */
    public static function importChunk(string $csvPath, int $offset = 0, int $maxRows = self::CHUNK_ROWS): array
    {
        if (!is_file($csvPath)) {
            return ['ok' => false, 'done' => false, 'offset' => $offset, 'bytes' => 0, 'imported' => 0, 'error' => 'missing_csv'];
        }

        $totalBytes = (int) filesize($csvPath);
        $handle     = @fopen($csvPath, 'rb');
        if ($handle === false) {
            return ['ok' => false, 'done' => false, 'offset' => $offset, 'bytes' => 0, 'imported' => 0, 'error' => 'cannot_open'];
        }

        $db = Database::instance();

        if ($offset === 0) {
            // TRUNCATE is a DDL statement and cannot be rolled back, but it makes
            // the import orders of magnitude faster than DELETE on InnoDB.
            $db->pdo()->exec('TRUNCATE TABLE ' . $db->table('geoip_ranges'));
        } else {
            fseek($handle, $offset);
        }

        $insert = $db->pdo()->prepare(
            'INSERT IGNORE INTO ' . $db->table('geoip_ranges') . ' (`family`, `start_bin`, `end_bin`, `country_code`)
             VALUES (?, UNHEX(?), UNHEX(?), ?)'
        );

        $imported = 0;
        $first    = true;
        $db->beginTransaction();

        try {
            while ($imported < $maxRows && ($line = fgets($handle)) !== false) {
                if ($first) {
                    // Skip a GeoLite2 style header row on the very first line.
                    if (stripos($line, 'network') !== false || stripos($line, 'geoname') !== false) {
                        $first = false;
                        continue;
                    }
                    $first = false;
                }

                $row = self::parseLine($line);
                if ($row === null) {
                    continue;
                }

                $insert->execute([
                    $row['family'],
                    bin2hex((string) $row['start']),
                    bin2hex((string) $row['end']),
                    $row['country'],
                ]);
                $imported++;
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            fclose($handle);
            pb_log('GeoIP import failed: ' . $e->getMessage(), 'error');
            return ['ok' => false, 'done' => false, 'offset' => $offset, 'bytes' => 0, 'imported' => 0, 'error' => 'sql'];
        }

        $newOffset = (int) ftell($handle);
        $done      = feof($handle) || $newOffset >= $totalBytes;
        fclose($handle);

        if ($done) {
            Settings::set('geoip_count', self::count());
            Settings::set('geoip_updated_at', now_utc());
        }

        return [
            'ok'       => true,
            'done'     => $done,
            'offset'   => $newOffset,
            'bytes'    => $totalBytes,
            'imported' => $imported,
            'error'    => '',
        ];
    }

    /**
     * Import a whole file in one go (used by CLI-ish contexts and small files).
     *
     * @return array{ok:bool,imported:int,error:string}
     */
    public static function importAll(string $csvPath): array
    {
        $offset = 0;
        $total  = 0;
        $guard  = 0;
        do {
            $result = self::importChunk($csvPath, $offset);
            if (!$result['ok']) {
                return ['ok' => false, 'imported' => $total, 'error' => $result['error']];
            }
            $total  = $result['offset'] === 0 ? $total : $total + $result['imported'];
            $offset = $result['offset'];
            $guard++;
        } while (!$result['done'] && $guard < 1000);

        return ['ok' => true, 'imported' => $total, 'error' => ''];
    }

    /**
     * Country code for an IP address, or an empty string when unknown.
     *
     * The lookup finds the last range that starts at or before the address and
     * then checks that the address is not past its end.
     */
    public static function lookup(string $ip): string
    {
        if (isset(self::$memo[$ip])) {
            return self::$memo[$ip];
        }
        if (count(self::$memo) > 2000) {
            self::$memo = [];
        }

        $family = ip_family($ip);
        $binary = ip_to_binary($ip);
        if ($family === 0 || $binary === '') {
            return self::$memo[$ip] = '';
        }

        try {
            $db = Database::instance();
            // The address travels as hex and is turned back into bytes by the
            // server. PDO binds strings in the connection charset (utf8mb4), and
            // a packed address contains bytes such as 0xFF that are not valid
            // UTF-8: MySQL silently reduces such a parameter, so the comparison
            // against VARBINARY matches nothing. Hex is plain ASCII and behaves
            // the same on every server.
            $row = $db->one(
                'SELECT `country_code`, `end_bin`, `start_bin` FROM ' . $db->table('geoip_ranges') . '
                 WHERE `family` = :family AND `start_bin` <= UNHEX(:ip)
                 ORDER BY `start_bin` DESC LIMIT 1',
                [':family' => $family, ':ip' => bin2hex($binary)]
            );
        } catch (\Throwable $e) {
            return self::$memo[$ip] = '';
        }

        if (getenv('PB_GEOIP_TRACE') !== false && getenv('PB_GEOIP_TRACE') !== '') {
            // Opt-in diagnostics: what did we ask for and what answered? Wrapped
            // so that a broken log path can never take a collection down.
            try {
                file_put_contents(
                    PB_ROOT . '/data/logs/geoip-trace.log',
                    sprintf(
                        "%s ip=%s family=%d hex=%s remote=%s xff=%s trusted=%s configured=%s row=%s\n",
                        now_utc(),
                        $ip,
                        $family,
                        bin2hex($binary),
                        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                        (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
                        Security::trustsProxy() ? 'yes' : 'no',
                        json_encode(Config::get('security.trusted_proxies', '')),
                        ($row === null ? 'none' : json_encode([
                            'start'   => bin2hex((string) $row['start_bin']),
                            'end'     => bin2hex((string) $row['end_bin']),
                            'country' => (string) $row['country_code'],
                        ]))
                    ),
                    FILE_APPEND
                );
            } catch (\Throwable $e) {
                // Diagnostics must not be able to break the request.
            }
        }

        if ($row === null) {
            return self::$memo[$ip] = '';
        }
        if (strcmp((string) $row['end_bin'], $binary) < 0) {
            return self::$memo[$ip] = '';
        }

        return self::$memo[$ip] = strtoupper((string) $row['country_code']);
    }

    /**
     * Resolve many addresses at once, keeping the per-request memo warm.
     *
     * @param list<string> $ips
     * @return array<string,string>
     */
    public static function lookupMany(array $ips): array
    {
        $result = [];
        foreach (array_unique($ips) as $ip) {
            $result[$ip] = self::lookup((string) $ip);
        }
        return $result;
    }

    /**
     * Download a fresh base from a URL, replacing the bundled one.
     *
     * Uses cURL when available (it follows redirects and honours a timeout) and
     * falls back to the streams wrapper.
     *
     * @return array{ok:bool,bytes:int,error:string}
     */
    public static function download(string $url, ?string $destination = null): array
    {
        $destination = $destination ?? self::bundledFile();
        $dir = dirname($destination);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'bytes' => 0, 'error' => 'not_writable'];
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['ok' => false, 'bytes' => 0, 'error' => 'bad_scheme'];
        }

        $temp = $destination . '.part';

        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            $file   = @fopen($temp, 'wb');
            if ($handle === false || $file === false) {
                return ['ok' => false, 'bytes' => 0, 'error' => 'not_writable'];
            }
            curl_setopt_array($handle, [
                CURLOPT_FILE           => $file,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => 300,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_FAILONERROR    => true,
                CURLOPT_USERAGENT      => 'PlotBird Analytics/' . PB_VERSION,
            ]);
            $ok        = curl_exec($handle);
            $httpCode  = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error     = curl_error($handle);
            curl_close($handle);
            fclose($file);

            if ($ok === false || $httpCode !== 200) {
                @unlink($temp);
                return ['ok' => false, 'bytes' => 0, 'error' => $error !== '' ? $error : ('http_' . $httpCode)];
            }
        } else {
            $context = stream_context_create([
                'http' => ['timeout' => 300, 'follow_location' => 1, 'user_agent' => 'PlotBird Analytics/' . PB_VERSION],
                'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $data = @file_get_contents($url, false, $context);
            if ($data === false || $data === '') {
                @unlink($temp);
                return ['ok' => false, 'bytes' => 0, 'error' => 'download_failed'];
            }
            if (@file_put_contents($temp, $data) === false) {
                return ['ok' => false, 'bytes' => 0, 'error' => 'not_writable'];
            }
        }

        $size = (int) @filesize($temp);
        if ($size < 1024) {
            @unlink($temp);
            return ['ok' => false, 'bytes' => 0, 'error' => 'too_small'];
        }

        @rename($temp, $destination);
        // Force a fresh decompression of the updated base.
        if ($destination === self::bundledFile() && is_file(self::csvFile())) {
            @unlink(self::csvFile());
        }

        return ['ok' => true, 'bytes' => $size, 'error' => ''];
    }

    /**
     * Parse one CSV line into a range row.
     *
     * @return array{family:int,start:string,end:string,country:string}|null
     */
    private static function parseLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        $parts = str_getcsv($line, ',', '"', '\\');
        if (count($parts) < 3) {
            return null;
        }

        $start = trim((string) $parts[0], " \t\"");
        $end   = trim((string) $parts[1], " \t\"");
        $code  = strtoupper(trim((string) $parts[2], " \t\""));

        // GeoLite2 CSV uses CIDR notation in the first column.
        if (strpos($start, '/') !== false) {
            $range = self::cidrToRange($start);
            if ($range === null) {
                return null;
            }
            [$start, $end] = $range;
        }

        $family = ip_family($start);
        if ($family === 0 || $family !== ip_family($end)) {
            return null;
        }
        if (strlen($code) !== 2 || !ctype_alpha($code)) {
            $code = '';
        }

        return [
            'family'  => $family,
            'start'   => ip_to_binary($start),
            'end'     => ip_to_binary($end),
            'country' => $code,
        ];
    }

    /**
     * Expand a CIDR block into its first and last address.
     *
     * @return array{0:string,1:string}|null
     */
    private static function cidrToRange(string $cidr): ?array
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return null;
        }
        $ip   = $parts[0];
        $bits = (int) $parts[1];

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        $length = strlen($packed) * 8;
        if ($bits < 0 || $bits > $length) {
            return null;
        }

        $mask = str_repeat("\xff", intdiv($bits, 8));
        if ($bits % 8 !== 0) {
            $mask .= chr((0xff << (8 - $bits % 8)) & 0xff);
        }
        $mask = str_pad($mask, strlen($packed), "\x00");

        $start  = $packed & $mask;
        $invert = ~$mask;
        $end    = $packed | $invert;

        return [(string) inet_ntop($start), (string) inet_ntop($end)];
    }

    /**
     * Open a plain or gzipped CSV for reading.
     *
     * @return resource|null
     */
    private static function openReadable(string $path)
    {
        if (substr($path, -3) === '.gz') {
            if (!function_exists('gzopen')) {
                return null;
            }
            $handle = @gzopen($path, 'rb');
            return $handle === false ? null : $handle;
        }
        $handle = @fopen($path, 'rb');
        return $handle === false ? null : $handle;
    }

    /**
     * @param resource $handle
     */
    private static function streamRead($handle, int $length): string
    {
        // gzread()/fread() are distinguished by the resource type.
        $data = @gzread($handle, $length);
        if ($data === false) {
            $data = @fread($handle, $length);
        }
        return is_string($data) ? $data : '';
    }

    /**
     * @param resource $handle
     */
    private static function streamEof($handle): bool
    {
        return (bool) @gzeof($handle);
    }

    /**
     * @param resource $handle
     */
    private static function closeStream($handle): void
    {
        @gzclose($handle);
    }
}
