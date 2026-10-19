<?php
/**
 * PlotBird Analytics - bot detection for the collection endpoint.
 *
 * Filtering happens server side, on the User-Agent, before anything is written
 * to the database: the tracker cannot be trusted to do it (and an attacker
 * certainly will not).
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class BotDetector
{
    /**
     * Substrings that identify automated traffic. Lower-case; matched against
     * a lower-cased User-Agent.
     *
     * @var list<string>
     */
    private const SIGNATURES = [
        // Search engines
        'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider', 'yandexbot',
        'sogou', 'exabot', 'facebot', 'facebookexternalhit', 'ia_archiver', 'mj12bot',
        'ahrefsbot', 'semrushbot', 'dotbot', 'petalbot', 'seznam', 'applebot',
        // SEO / analytics crawlers
        'screaming frog', 'rogerbot', 'megaindex', 'linkdexbot', 'blexbot', 'serpstatbot',
        'dataforseo', 'seokicks', 'sistrix', 'majestic', 'uptime', 'pingdom',
        'statuscake', 'site24x7', 'newrelicpinger', 'nagios', 'zabbix', 'datadog',
        'better uptime', 'hetrixtools', 'freshping', 'checkhost', 'monitoring',
        // Libraries and command line tools that are not real visitors
        'curl/', 'wget/', 'python-requests', 'python-urllib', 'aiohttp', 'httpx',
        'go-http-client', 'java/', 'okhttp', 'libwww-perl', 'apachebench', 'ab/', 'httpclient',
        'axios/', 'node-fetch', 'guzzle', 'postmanruntime', 'insomnia',
        // Headless browsers / automation
        'headlesschrome', 'headless', 'phantomjs', 'puppeteer', 'playwright',
        'selenium', 'htmlunit', 'electron/', 'slimerjs', 'zombie',
        // Generic tokens
        'bot', 'crawl', 'spider', 'scraper', 'archiver', 'validator', 'preview',
        'fetcher', 'monitor', 'analyzer', 'scanner', 'indexer', 'syndication',
        'embedly', 'quora link preview', 'whatsapp', 'telegrambot', 'slackbot',
        'discordbot', 'skypeuripreview', 'bitlybot', 'vkshare', 'nuzzel',
        'google web preview', 'google page speed', 'lighthouse',
    ];

    /**
     * Substrings that prove the client is a browser: used to avoid throwing away
     * real visitors whose User-Agent happens to contain a signature (for
     * example "MyBot" in a game browser, or a UA with "preview" in a product
     * name).
     *
     * @var list<string>
     */
    private const BROWSER_TOKENS = ['mozilla/', 'opera/', 'webkit', 'gecko', 'presto'];

    /**
     * Whether a User-Agent looks like automation.
     */
    public static function isBot(string $userAgent): bool
    {
        $ua = strtolower(trim($userAgent));

        // No User-Agent at all is almost always a script.
        if ($ua === '') {
            return true;
        }

        foreach (self::SIGNATURES as $signature) {
            if (strpos($ua, $signature) === false) {
                continue;
            }
            // "bot" and friends are substrings of innocent words ("robot"
            // names, "bottom", ...): require that the client does not also
            // present itself as a browser.
            if (self::looksLikeBrowser($ua) && self::isShortSignature($signature)) {
                continue;
            }
            return true;
        }

        return false;
    }

    /**
     * Whether the runtime environment of a request is a known data centre, in
     * which case the hit is dropped as well.
     *
     * Implemented as a small, explicit list: this is a best-effort extra layer,
     * not a substitute for the User-Agent filter.
     *
     * @param array<string,mixed> $settings Site tracker settings.
     */
    public static function isDatacenter(string $ip, array $settings = []): bool
    {
        // A site may declare its own blocked ranges.
        /** @var list<string> $blocked */
        $blocked = array_filter(array_map('strval', (array) ($settings['exclude_ips'] ?? [])));
        foreach ($blocked as $cidr) {
            if (Security::ipInCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Signatures that are safe on their own ("googlebot") versus ones that need
     * the browser check ("bot", "preview", "monitor").
     */
    private static function isShortSignature(string $signature): bool
    {
        return in_array($signature, ['bot', 'crawl', 'spider', 'preview', 'monitor', 'validator', 'fetcher', 'analyzer', 'scanner', 'indexer'], true);
    }

    /**
     * Whether the UA carries the tokens that every real browser sends.
     */
    private static function looksLikeBrowser(string $lowercaseUserAgent): bool
    {
        foreach (self::BROWSER_TOKENS as $token) {
            if (strpos($lowercaseUserAgent, $token) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a path is excluded by the site's tracker settings.
     *
     * Supports a trailing "*" wildcard, e.g. "/admin*".
     *
     * @param array<string,mixed> $settings
     */
    public static function isExcludedPath(string $path, array $settings): bool
    {
        /** @var list<string> $excluded */
        $excluded = array_filter(array_map('strval', (array) ($settings['exclude_paths'] ?? [])));
        if ($excluded === []) {
            return false;
        }

        $candidate = '/' . ltrim(explode('?', $path)[0], '/');

        foreach ($excluded as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') {
                continue;
            }
            if ($pattern[0] !== '/') {
                $pattern = '/' . $pattern;
            }

            if (substr($pattern, -1) === '*') {
                $prefix = rtrim(substr($pattern, 0, -1), '/');
                if ($prefix === '') {
                    return true;
                }
                if (strpos($candidate, $prefix) === 0) {
                    return true;
                }
                continue;
            }

            if ($candidate === rtrim($pattern, '/') || $candidate === $pattern) {
                return true;
            }
        }

        return false;
    }
}
