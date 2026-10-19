<?php
/**
 * PlotBird Analytics - a minimal ZIP writer.
 *
 * Shared hostings often ship PHP without the zip extension, and an export that
 * silently degrades to a single file is a worse answer than writing the archive
 * ourselves. The format needed here is small: stored entries, no compression, no
 * encryption, no ZIP64.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class ZipWriter
{
    /** Local file headers and the entry data. */
    private string $data = '';

    /** Central directory records, one per entry. */
    private string $central = '';

    /** How many entries were added. */
    private int $count = 0;

    /**
     * Add one file.
     *
     * The name must use forward slashes and stay inside the archive.
     */
    public function add(string $name, string $content): void
    {
        $name    = ltrim(str_replace('\\', '/', $name), '/');
        $length  = strlen($content);
        $crc     = crc32($content);
        $offset  = strlen($this->data);
        [$time, $date] = $this->dosTime();

        // Local header: signature, version, flags, method, time, date, crc,
        // compressed size, uncompressed size, name length, extra length.
        $this->data .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $time, $date, $crc, $length, $length, strlen($name), 0);
        $this->data .= $name;
        $this->data .= $content;

        // Central directory record.
        $this->central .= pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            20,
            20,
            0x0800,
            0,
            $time,
            $date,
            $crc,
            $length,
            $length,
            strlen($name),
            0,
            0,
            0,
            0,
            0,
            $offset
        );
        $this->central .= $name;

        $this->count++;
    }

    /**
     * The finished archive.
     */
    public function finish(): string
    {
        $centralOffset = strlen($this->data);
        $centralSize   = strlen($this->central);

        $end = pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            $this->count,
            $this->count,
            $centralSize,
            $centralOffset,
            0
        );

        return $this->data . $this->central . $end;
    }

    /**
     * The current DOS time and date, the format every ZIP reader expects.
     *
     * @return array{0:int,1:int}
     */
    private function dosTime(): array
    {
        $now = getdate();

        $time = ($now['hours'] << 11) | ($now['minutes'] << 5) | intdiv($now['seconds'], 2);
        $date = (($now['year'] - 1980) << 9) | ($now['mon'] << 5) | $now['mday'];

        return [$time, $date];
    }
}
