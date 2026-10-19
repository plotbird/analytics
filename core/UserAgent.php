<?php
/**
 * PlotBird Analytics - User-Agent parsing.
 *
 * Deliberately dependency-free and ordered from most specific to least
 * specific, because the cheaper the collection endpoint is, the more hits a
 * shared hosting can absorb.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class UserAgent
{
    public const DEVICE_DESKTOP = 'desktop';
    public const DEVICE_MOBILE  = 'mobile';
    public const DEVICE_TABLET  = 'tablet';
    public const DEVICE_OTHER   = 'other';

    /**
     * Parse a User-Agent string.
     *
     * @return array{device:string,os:string,browser:string}
     */
    public static function parse(string $userAgent): array
    {
        return [
            'device'  => self::device($userAgent),
            'os'      => self::os($userAgent),
            'browser' => self::browser($userAgent),
        ];
    }

    /**
     * Device class.
     */
    public static function device(string $ua): string
    {
        if ($ua === '') {
            return self::DEVICE_OTHER;
        }

        // Tablets first: an iPad also reports "Mobile" in some browsers, and an
        // Android tablet reports "Android" without "Mobile".
        if (preg_match('/(iPad|Tablet|PlayBook|Silk|Kindle|Nexus 7|Nexus 10|SM-T\d|GT-P\d)/i', $ua) === 1) {
            // "Silk" on a Fire phone is rare; treat as tablet, which is right
            // far more often than not.
            return self::DEVICE_TABLET;
        }
        if (preg_match('/Android/i', $ua) === 1) {
            return preg_match('/Mobile/i', $ua) === 1 ? self::DEVICE_MOBILE : self::DEVICE_TABLET;
        }
        if (preg_match('/(Mobile|iPhone|iPod|Windows Phone|IEMobile|BlackBerry|BB10|Opera Mini|Opera Mobi|Fennec|webOS)/i', $ua) === 1) {
            return self::DEVICE_MOBILE;
        }
        if (preg_match('/(Windows|Macintosh|Mac OS X|Linux|CrOS|X11|FreeBSD|OpenBSD)/i', $ua) === 1) {
            return self::DEVICE_DESKTOP;
        }
        if (preg_match('/(bot|crawler|spider|curl|wget|python|go-http|headless)/i', $ua) === 1) {
            return self::DEVICE_OTHER;
        }

        return self::DEVICE_OTHER;
    }

    /**
     * Operating system, as a short human readable label.
     */
    public static function os(string $ua): string
    {
        if ($ua === '') {
            return '';
        }

        // Order matters: Windows Phone before Windows, iOS before macOS,
        // Android before Linux.
        $patterns = [
            'Windows Phone' => '/Windows Phone/i',
            'Windows'       => '/(Windows NT|Windows)/i',
            'iOS'           => '/(iPhone|iPad|iPod)/i',
            'Android'       => '/Android/i',
            'Chrome OS'     => '/CrOS/i',
            'macOS'         => '/(Macintosh|Mac OS X)/i',
            'KaiOS'         => '/KaiOS/i',
            'Ubuntu'        => '/Ubuntu/i',
            'Fedora'        => '/Fedora/i',
            'Debian'        => '/Debian/i',
            'FreeBSD'       => '/FreeBSD/i',
            'Linux'         => '/(Linux|X11)/i',
        ];

        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $ua) === 1) {
                return $label;
            }
        }
        return '';
    }

    /**
     * Browser, as a short human readable label.
     */
    public static function browser(string $ua): string
    {
        if ($ua === '') {
            return '';
        }

        // The order is essential: Edge, Opera, Samsung and Yandex all carry
        // "Chrome" and "Safari" tokens in their UA strings.
        $patterns = [
            'Edge'             => '/Edg[A-Za-z]?\//i',
            'Opera'            => '/(OPR\/|Opera[ \/])/i',
            'Samsung Internet' => '/SamsungBrowser/i',
            'Yandex'           => '/YaBrowser/i',
            'UC Browser'       => '/UCBrowser/i',
            'Vivaldi'          => '/Vivaldi/i',
            'Brave'            => '/Brave/i',
            'DuckDuckGo'       => '/DuckDuckGo/i',
            'Firefox'          => '/(Firefox|FxiOS)/i',
            'Chrome'           => '/(Chrome|CriOS|Chromium)/i',
            'Safari'           => '/Safari/i',
            'Internet Explorer' => '/(MSIE|Trident)/i',
            'Curl'             => '/curl/i',
            'Wget'             => '/Wget/i',
        ];

        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $ua) === 1) {
                return $label;
            }
        }
        return '';
    }

    /**
     * Best-effort English (or given) language of the browser, e.g. "en".
     */
    public static function language(string $header): string
    {
        $header = trim($header);
        if ($header === '') {
            return '';
        }
        $first = explode(',', $header)[0];
        $first = explode(';', $first)[0];
        $first = strtolower(trim($first));
        if (preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/', $first) === 1) {
            return substr($first, 0, 10);
        }
        return '';
    }

    /**
     * Normalise a screen size string ("1920x1080") and cap its length.
     */
    public static function screen(string $screen): string
    {
        $screen = strtolower(trim($screen));
        if (preg_match('/^(\d{1,5})x(\d{1,5})$/', $screen, $matches) === 1) {
            return (int) $matches[1] . 'x' . (int) $matches[2];
        }
        return '';
    }
}
