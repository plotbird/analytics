<?php
/**
 * PlotBird Analytics - hit collection.
 *
 * Turns one POST from tracker.js into a pageview row plus a visit row, without
 * cookies: a visitor is identified for a single day by
 * md5(IP + User-Agent + salt + date), which is enough for unique counts and
 * sessions and useless for following anybody around the web.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class Collector
{
    /** Hard ceiling for the request body, to keep a hostile client cheap. */
    public const MAX_PAYLOAD_BYTES = 4096;

    /** Default per-IP request budget for the collection endpoint. */
    public const DEFAULT_RATE_LIMIT = 120;

    /**
     * Record one hit.
     *
     * @param array<string,mixed>  $site     Site row.
     * @param array<string,mixed>  $payload  Decoded tracker payload.
     * @param array<string,mixed>  $settings Site tracker settings.
     * @return array{status:string,reason:string,visit_id:int,pageview_id:int,country:string}
     */
    public static function collect(array $site, array $payload, array $settings): array
    {
        $siteId   = (int) $site['id'];
        $rawIp    = client_ip();
        $userAgent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512, 'UTF-8');

        $anonymize = Settings::getBool('anonymize_ip', true);
        $ip = $anonymize ? self::anonymizeIp($rawIp) : $rawIp;

        // Storing the address is an explicit opt-in: it turns anonymous
        // statistics into personal data, so it is off unless the administrator
        // enabled it in the settings (and is warned about the consequences).
        $storedIp = Settings::getBool('store_ip', false) ? mb_substr($rawIp, 0, 45, 'UTF-8') : '';

        // ---- 1. Filters that can drop the hit entirely ---------------------
        if (BotDetector::isDatacenter($rawIp, $settings)) {
            return self::ignored('excluded_ip');
        }
        if (Settings::getBool('exclude_bots', true) && (bool) ($settings['exclude_bots'] ?? true)
            && BotDetector::isBot($userAgent)) {
            return self::ignored('bot');
        }

        $path = normalize_path((string) ($payload['p'] ?? $payload['path'] ?? '/'));
        if (($settings['track_query'] ?? false) !== true) {
            $path = explode('?', $path)[0];
        }
        if (BotDetector::isExcludedPath($path, $settings)) {
            return self::ignored('excluded_path');
        }

        // Do-Not-Track, when the site opted in.
        if (Settings::getBool('respect_dnt', false) || ($settings['respect_dnt'] ?? false) === true) {
            $dnt = (string) ($payload['dnt'] ?? '');
            if ($dnt === '1' || strtolower($dnt) === 'yes') {
                return self::ignored('dnt');
            }
        }

        // ---- 2. Derive the dimensions once --------------------------------
        $now         = now_utc();
        $visitorHash = self::visitorHash($ip, $userAgent);
        $referrerUrl = mb_substr((string) ($payload['r'] ?? ''), 0, 512, 'UTF-8');
        $referrer    = referrer_domain($referrerUrl);
        // A visit that starts on the same host is a direct visit, not a referral.
        if ($referrer !== '' && self::isSelfReferrer($referrer, $site, $payload)) {
            $referrer    = '';
            $referrerUrl = '';
        }

        $country = GeoIP::lookup($ip);
        $agent   = UserAgent::parse($userAgent);
        $title   = mb_substr(trim((string) ($payload['t'] ?? '')), 0, 255, 'UTF-8');
        $screen  = UserAgent::screen((string) ($payload['s'] ?? ''));
        $language = UserAgent::language((string) ($payload['l'] ?? ''));

        $db = Database::instance();

        // ---- 3. Custom events take a different path ----------------------
        // An event is not a page view: it must not increment page or session
        // counters, only land in `events` so goals can count it.
        $eventName = Goal::normalizeEvent((string) ($payload['e'] ?? ''));
        if ($eventName !== '') {
            return self::recordEvent($db, $siteId, $eventName, $path, $visitorHash, $country, $agent, $now);
        }

        $db->beginTransaction();

        try {
            $timeout = Settings::sessionTimeoutSeconds();
            $cutoff  = gmdate('Y-m-d H:i:s', time() - $timeout);

            $visit = $db->one(
                'SELECT * FROM ' . $db->table('visits') . '
                 WHERE `site_id` = :sid AND `visitor_hash` = :vh AND `last_seen_at` >= :cutoff
                 ORDER BY `id` DESC LIMIT 1',
                [':sid' => $siteId, ':vh' => $visitorHash, ':cutoff' => $cutoff]
            );

            if ($visit === null) {
                $sessionKey = md5($visitorHash . '|' . $now . '|' . random_hex(4));
                $visitId = $db->insert('visits', [
                    'site_id'         => $siteId,
                    'session_key'     => $sessionKey,
                    'visitor_hash'    => $visitorHash,
                    'started_at'      => $now,
                    'last_seen_at'    => $now,
                    'duration'        => 0,
                    'pageviews'       => 1,
                    'is_bounce'       => 1,
                    'entry_path'      => $path,
                    'exit_path'       => $path,
                    'referrer_domain' => $referrer,
                    'referrer_url'    => $referrerUrl,
                    'country'         => $country,
                    'city'            => '',
                    'device'          => $agent['device'],
                    'os'              => $agent['os'],
                    'browser'         => $agent['browser'],
                    'screen'          => $screen,
                    'language'        => $language,
                    'ip'              => $storedIp,
                ]);
            } else {
                $visitId    = (int) $visit['id'];
                $sessionKey = (string) $visit['session_key'];
                $started = (int) strtotime((string) $visit['started_at'] . ' UTC');
                $pageviews = (int) $visit['pageviews'] + 1;

                $db->update('visits', [
                    'last_seen_at' => $now,
                    'duration'     => max(0, time() - $started),
                    'pageviews'    => $pageviews,
                    'is_bounce'    => $pageviews > 1 ? 0 : 1,
                    'exit_path'    => $path,
                    // Fill in dimensions that were unknown when the visit began.
                    'country'      => (string) $visit['country'] !== '' ? $visit['country'] : $country,
                ], '`id` = :id', [':id' => $visitId]);
            }

            $pageviewId = $db->insert('pageviews', [
                'site_id'         => $siteId,
                'visit_id'        => $visitId,
                'session_key'     => $sessionKey,
                'visitor_hash'    => $visitorHash,
                'path'            => $path,
                'title'           => $title,
                'referrer_domain' => $referrer,
                'referrer_url'    => $referrerUrl,
                'country'         => $country,
                'city'            => '',
                'device'          => $agent['device'],
                'os'              => $agent['os'],
                'browser'         => $agent['browser'],
                'screen'          => $screen,
                'language'        => $language,
                'is_bot'          => 0,
                'created_at'      => $now,
                'created_date'    => gmdate('Y-m-d'),
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            pb_log('collect failed: ' . $e->getMessage(), 'error');
            return self::ignored('storage_error');
        }

        return [
            'status'      => 'ok',
            'reason'      => '',
            'visit_id'    => $visitId,
            'pageview_id' => $pageviewId,
            'country'     => $country,
        ];
    }

    /**
     * Record a custom event.
     *
     * The event is attached to the visitor's open visit when there is one, but
     * a missing visit is not an error: goals are counted per daily pseudonym,
     * and `visit_id` is only a convenience for drilling down later.
     *
     * @param array<string,mixed> $agent
     * @return array{status:string,reason:string,visit_id:int,pageview_id:int,event_id:int,event:string,country:string}
     */
    private static function recordEvent(
        Database $db,
        int $siteId,
        string $eventName,
        string $path,
        string $visitorHash,
        string $country,
        array $agent,
        string $now
    ): array {
        $timeout = Settings::sessionTimeoutSeconds();
        $cutoff  = gmdate('Y-m-d H:i:s', time() - $timeout);

        $visitId = $db->value(
            'SELECT `id` FROM ' . $db->table('visits') . '
             WHERE `site_id` = :sid AND `visitor_hash` = :vh AND `last_seen_at` >= :cutoff
             ORDER BY `id` DESC LIMIT 1',
            [':sid' => $siteId, ':vh' => $visitorHash, ':cutoff' => $cutoff],
            null
        );

        try {
            $id = $db->insert('events', [
                'site_id'      => $siteId,
                'visit_id'     => $visitId === null ? null : (int) $visitId,
                'visitor_hash' => $visitorHash,
                'name'         => $eventName,
                'path'         => $path,
                'country'      => $country,
                'device'       => (string) ($agent['device'] ?? ''),
                'created_at'   => $now,
                'created_date' => substr($now, 0, 10),
            ]);
        } catch (\Throwable $e) {
            pb_log('event storage failed: ' . $e->getMessage(), 'error');
            return [
                'status' => 'ignored', 'reason' => 'storage_error', 'visit_id' => 0,
                'pageview_id' => 0, 'event_id' => 0, 'event' => $eventName, 'country' => $country,
            ];
        }

        return [
            'status'      => 'ok',
            'reason'      => '',
            'visit_id'    => $visitId === null ? 0 : (int) $visitId,
            'pageview_id' => 0,
            'event_id'    => $id,
            'event'       => $eventName,
            'country'     => $country,
        ];
    }

    /**
     * The daily, salted visitor pseudonym.
     *
     * The date makes it impossible to recognise a returning visitor across
     * days - which is exactly the intent (GDPR: no long term profile).
     */
    public static function visitorHash(string $ip, string $userAgent): string
    {
        $salt = (string) Config::get('security.salt', 'plotbird');
        return md5($ip . '|' . $userAgent . '|' . $salt . '|' . gmdate('Y-m-d'));
    }

    /**
     * Zero the host part of an address, keeping the network prefix that is
     * relevant for country-level geolocation.
     */
    public static function anonymizeIp(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }

        if (strlen($packed) === 4) {
            // IPv4: keep /24.
            return (string) inet_ntop(substr($packed, 0, 3) . "\x00");
        }

        // IPv6: keep /48.
        return (string) inet_ntop(substr($packed, 0, 6) . str_repeat("\x00", 10));
    }

    /**
     * Whether a referrer points at the tracked site itself (an internal
     * navigation is not a traffic source).
     *
     * @param array<string,mixed> $site
     * @param array<string,mixed> $payload
     */
    private static function isSelfReferrer(string $referrerDomain, array $site, array $payload): bool
    {
        $domain = strtolower(trim((string) ($site['domain'] ?? '')));
        if ($domain === '') {
            // Without a configured domain, fall back to the host the tracker
            // reported, if it sent one.
            $host = strtolower((string) ($payload['h'] ?? ''));
            if ($host === '') {
                return false;
            }
            return $referrerDomain === $host;
        }

        $domain = preg_replace('/^www\./', '', $domain) ?? $domain;
        return $referrerDomain === $domain || substr($referrerDomain, -strlen('.' . $domain)) === '.' . $domain;
    }

    /**
     * Standard "nothing was recorded" answer.
     *
     * @return array{status:string,reason:string,visit_id:int,pageview_id:int,country:string}
     */
    private static function ignored(string $reason): array
    {
        return ['status' => 'ignored', 'reason' => $reason, 'visit_id' => 0, 'pageview_id' => 0, 'country' => ''];
    }
}
