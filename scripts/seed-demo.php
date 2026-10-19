<?php
/**
 * PlotBird Analytics - demo traffic generator.
 *
 * Fills a database with plausible traffic so a fresh installation is not empty:
 * useful for screenshots, for a public demo instance, and for looking at the
 * dashboard with numbers that behave like a real site.
 *
 * Everything it writes is synthetic. It never touches an existing website
 * unless that website has no traffic at all, or unless --force is given.
 *
 *   php scripts/seed-demo.php --days=30 --sessions=220 --site=demo.example.com
 *
 * Options
 *   --days=30        How many past days to fill.
 *   --sessions=220   Approximate sessions per weekday.
 *   --site=...       Domain of the demo website (created when missing).
 *   --name=...       Display name of the demo website.
 *   --timezone=...   Time zone of the demo website (default Europe/Moscow).
 *   --force          Write even when the website already has traffic.
 *   --wipe           Delete the demo website's existing traffic first.
 *
 * The script is safe to run on a production database only in the sense that it
 * refuses to mix with existing data: a demo website gets its own row, and its
 * traffic is generated from scratch.
 *
 * @package PlotBird
 */

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

use PlotBird\Aggregator;
use PlotBird\Config;
use PlotBird\Database;
use PlotBird\Goal;
use PlotBird\Site;

Config::assertInstalled();

/** Read a --name=value option. */
function seed_option(string $name, ?string $default = null): ?string
{
    foreach ($_SERVER['argv'] ?? [] as $argument) {
        if (strpos($argument, '--' . $name . '=') === 0) {
            return substr($argument, strlen($name) + 3);
        }
        if ($argument === '--' . $name) {
            return '';
        }
    }
    return $default;
}

/** Whether a bare flag such as --force was passed. */
function seed_flag(string $name): bool
{
    foreach ($_SERVER['argv'] ?? [] as $argument) {
        if ($argument === '--' . $name) {
            return true;
        }
    }
    return false;
}

$days     = max(1, min(365, (int) seed_option('days', '30')));
$sessions = max(10, min(5000, (int) seed_option('sessions', '220')));
$domain   = (string) seed_option('site', 'demo.example.com');
$name     = (string) seed_option('name', 'Demo Blog');
$timezone = (string) seed_option('timezone', 'Europe/Moscow');
$force    = seed_flag('force');
$wipe     = seed_flag('wipe');

if ($domain === '') {
    fwrite(STDERR, "A demo website needs a domain: --site=demo.example.com\n");
    exit(1);
}

$db = Database::instance();

// ---------------------------------------------------------------------------
// The demo website.
// ---------------------------------------------------------------------------
$site = $db->one('SELECT * FROM ' . $db->table('sites') . ' WHERE `domain` = :domain LIMIT 1', [
    ':domain' => $domain,
]);

if ($site === null) {
    $siteId = Site::create([
        'name'       => $name !== '' ? $name : $domain,
        'domain'     => $domain,
        'timezone'   => $timezone,
        // Created outside a request, so there is no signed-in user; the first
        // administrator is the only sensible owner for demo data.
        'created_by' => 1,
    ]);
    if ($siteId <= 0) {
        fwrite(STDERR, "Could not create the demo website.\n");
        exit(1);
    }
    $site = $db->one('SELECT * FROM ' . $db->table('sites') . ' WHERE `id` = :id', [':id' => $siteId]);
    echo "created website #{$siteId} {$domain}\n";
}

if ($site === null) {
    fwrite(STDERR, "The demo website could not be loaded.\n");
    exit(1);
}

$siteId   = (int) $site['id'];
$sites    = $db->table('sites');
$pageviewsTable = $db->table('pageviews');
$visitsTable    = $db->table('visits');
$eventsTable    = $db->table('events');

$existing = (int) $db->value(
    'SELECT COUNT(*) FROM ' . $pageviewsTable . ' WHERE `site_id` = :id',
    [':id' => $siteId]
);

if ($existing > 0 && !$force && !$wipe) {
    fwrite(
        STDERR,
        "Website #{$siteId} ({$domain}) already has {$existing} pageviews.\n"
        . "Refusing to mix demo traffic with real data. Use --wipe to replace it\n"
        . "or --force to add on top.\n"
    );
    exit(2);
}

if ($wipe) {
    $db->run('DELETE FROM ' . $pageviewsTable . ' WHERE `site_id` = :id', [':id' => $siteId]);
    $db->run('DELETE FROM ' . $visitsTable . ' WHERE `site_id` = :id', [':id' => $siteId]);
    $db->run('DELETE FROM ' . $eventsTable . ' WHERE `site_id` = :id', [':id' => $siteId]);
    $db->run('DELETE FROM ' . $db->table('stats_daily') . ' WHERE `site_id` = :id', [':id' => $siteId]);
    $db->run('DELETE FROM ' . $db->table('stats_dim') . ' WHERE `site_id` = :id', [':id' => $siteId]);
    $db->run('DELETE FROM ' . $db->table('goal_stats') . ' WHERE `site_id` = :id', [':id' => $siteId]);
    echo "cleared the demo website's existing traffic\n";
}

// ---------------------------------------------------------------------------
// Goals. A demo without conversions does not show what the product does, so the
// generator creates the two kinds of goal the tracker supports.
// ---------------------------------------------------------------------------
$existingGoals = (int) $db->value(
    'SELECT COUNT(*) FROM ' . $db->table('goals') . ' WHERE `site_id` = :id',
    [':id' => $siteId]
);

if ($existingGoals === 0) {
    $demoGoals = [
        ['name' => 'Подписка на рассылку', 'type' => 'event', 'match_value' => 'signup'],
        ['name' => 'Просмотр цен',        'type' => 'event', 'match_value' => 'pricing_view'],
        ['name' => 'Заявка с контактов',  'type' => 'page',  'match_value' => '/contact/'],
    ];
    foreach ($demoGoals as $goal) {
        $result = Goal::create($siteId, $goal);
        if ($result['ok']) {
            echo "goal created: {$goal['name']}\n";
        }
    }
}

// ---------------------------------------------------------------------------
// Distributions. Weights are cumulative-free: pick() walks them in order.
// ---------------------------------------------------------------------------
$paths = [
    '/'                       => 30,
    '/blog/php-analytics/'    => 12,
    '/blog/shared-hosting/'   => 9,
    '/pricing/'               => 7,
    '/docs/installation/'     => 6,
    '/blog/cookieless/'       => 5,
    '/blog/'                  => 5,
    '/docs/'                  => 4,
    '/docs/tracking-code/'    => 4,
    '/about/'                 => 3,
    '/blog/mysql-tuning/'     => 3,
    '/changelog/'             => 2,
    '/contact/'               => 2,
    '/docs/goals/'            => 2,
    '/blog/backup-strategy/'  => 2,
    '/newsletter/'            => 1,
];

$titles = [
    '/'                     => 'PlotBird - analytics for shared hosting',
    '/blog/php-analytics/'  => 'Analytics in plain PHP: how it works',
    '/blog/shared-hosting/' => 'Running analytics on €3 hosting',
    '/pricing/'             => 'Pricing',
    '/docs/installation/'   => 'Installation - PlotBird docs',
    '/blog/cookieless/'     => 'Cookie-free tracking without fingerprinting',
    '/blog/'                => 'Blog',
    '/docs/'                => 'Documentation',
    '/docs/tracking-code/'  => 'Adding the tracking code',
    '/about/'               => 'About',
    '/blog/mysql-tuning/'   => 'Ten MySQL settings for small analytics',
    '/changelog/'           => 'Changelog',
    '/contact/'             => 'Contact',
    '/docs/goals/'          => 'Goals and events',
    '/blog/backup-strategy/' => 'Backups you will actually restore',
    '/newsletter/'          => 'Newsletter',
];

$referrers = [
    ''                  => 34, // direct
    'google.com'        => 22,
    'yandex.ru'         => 9,
    'habr.com'          => 7,
    'twitter.com'       => 6,
    'reddit.com'        => 5,
    'news.ycombinator.com' => 4,
    'github.com'        => 3,
    'linkedin.com'      => 3,
    'duckduckgo.com'    => 2,
    'bing.com'          => 2,
    't.me'              => 2,
    'vk.com'            => 1,
];

$referrerUrls = [
    'google.com'           => 'https://www.google.com/search?q=plotbird+analytics',
    'yandex.ru'            => 'https://yandex.ru/search/?text=аналитика+на+php',
    'habr.com'             => 'https://habr.com/ru/articles/analytics-shared-hosting/',
    'twitter.com'          => 'https://twitter.com/plotbird/status/1',
    'reddit.com'           => 'https://reddit.com/r/selfhosted/comments/plotbird/',
    'news.ycombinator.com' => 'https://news.ycombinator.com/item?id=1',
    'github.com'           => 'https://github.com/plotbird/analytics',
    'linkedin.com'         => 'https://www.linkedin.com/posts/plotbird',
    'duckduckgo.com'       => 'https://duckduckgo.com/?q=self+hosted+analytics',
    'bing.com'             => 'https://www.bing.com/search?q=analytics+php',
    't.me'                 => 'https://t.me/plotbird',
    'vk.com'               => 'https://vk.com/plotbird',
];

$countries = [
    'RU' => 26, 'DE' => 13, 'US' => 12, 'UA' => 7, 'KZ' => 6, 'BY' => 4,
    'NL' => 4, 'GB' => 4, 'FR' => 4, 'PL' => 3, 'IN' => 3, 'BR' => 2,
    'ES' => 2, 'IT' => 2, 'CA' => 2, 'TR' => 2, 'CZ' => 1, 'SE' => 1,
    'AT' => 1, 'CH' => 1,
];

$cities = [
    'RU' => ['Moscow', 'Saint Petersburg', 'Novosibirsk', 'Yekaterinburg'],
    'DE' => ['Berlin', 'Munich', 'Hamburg'],
    'US' => ['San Francisco', 'New York', 'Austin'],
    'UA' => ['Kyiv', 'Lviv'],
    'KZ' => ['Almaty', 'Astana'],
    'BY' => ['Minsk'],
    'NL' => ['Amsterdam'],
    'GB' => ['London'],
    'FR' => ['Paris', 'Lyon'],
    'PL' => ['Warsaw'],
    'IN' => ['Mumbai', 'Bangalore'],
    'BR' => ['Sao Paulo'],
    'ES' => ['Madrid'],
    'IT' => ['Milan'],
    'CA' => ['Toronto'],
    'TR' => ['Istanbul'],
    'CZ' => ['Prague'],
    'SE' => ['Stockholm'],
    'AT' => ['Vienna'],
    'CH' => ['Zurich'],
];

$devices = ['desktop' => 61, 'mobile' => 34, 'tablet' => 5];

$oses = [
    'Windows' => 39, 'Android' => 22, 'iOS' => 15, 'macOS' => 13,
    'Linux' => 8, 'Chrome OS' => 2, 'Unknown' => 1,
];

$browsers = [
    'Chrome'  => 51, 'Safari' => 20, 'Firefox' => 11, 'Edge' => 8,
    'Opera'   => 4, 'Yandex' => 4, 'Samsung Internet' => 2,
];

$screens = [
    'desktop' => ['1920x1080', '2560x1440', '1440x900', '1536x864', '1366x768'],
    'mobile'  => ['390x844', '393x873', '360x800', '414x896'],
    'tablet'  => ['820x1180', '768x1024'],
];

$languages = [
    'ru-RU' => 30, 'en-US' => 26, 'de-DE' => 11, 'en-GB' => 6,
    'uk-UA' => 6, 'fr-FR' => 5, 'es-ES' => 4, 'pl-PL' => 3,
    'pt-BR' => 3, 'nl-NL' => 2, 'it-IT' => 2, 'tr-TR' => 2,
];

/** Pick one key from a weighted map. */
function pick_weighted(array $weights): string
{
    $total = array_sum($weights);
    $roll  = mt_rand(1, $total);
    $sum   = 0;
    foreach ($weights as $key => $weight) {
        $sum += $weight;
        if ($roll <= $sum) {
            return (string) $key;
        }
    }
    $keys = array_keys($weights);
    return (string) $keys[0];
}

/** Pick one value from a plain list. */
function pick_one(array $list): string
{
    return (string) $list[mt_rand(0, count($list) - 1)];
}

/** A random hex identifier, the shape the tracker produces. */
function random_hash(): string
{
    return md5(random_bytes(16));
}

$visitInsert = $db->pdo()->prepare(
    'INSERT INTO ' . $visitsTable . ' (`site_id`, `session_key`, `visitor_hash`, `started_at`, `last_seen_at`,
        `duration`, `pageviews`, `is_bounce`, `entry_path`, `exit_path`, `referrer_domain`, `referrer_url`,
        `country`, `city`, `device`, `os`, `browser`, `screen`, `language`, `ip`)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$pageviewInsert = $db->pdo()->prepare(
    'INSERT INTO ' . $pageviewsTable . ' (`site_id`, `visit_id`, `session_key`, `visitor_hash`, `path`, `title`,
        `referrer_domain`, `referrer_url`, `country`, `city`, `device`, `os`, `browser`, `screen`, `language`,
        `is_bot`, `created_at`, `created_date`)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$eventInsert = $db->pdo()->prepare(
    'INSERT INTO ' . $eventsTable . ' (`site_id`, `visit_id`, `visitor_hash`, `name`, `path`, `country`,
        `device`, `created_at`, `created_date`)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$zone = new DateTimeZone($timezone);
$utc  = new DateTimeZone('UTC');

// Pseudonyms that already appeared, so a share of the audience returns.
$visitorPool = [];

$visitsWritten   = 0;
$pageviewsWritten = 0;
$eventsWritten   = 0;

$db->beginTransaction();

try {
    for ($dayOffset = $days - 1; $dayOffset >= 0; $dayOffset--) {
        // Local date for this day, walked from the oldest to today.
        $localDate = new DateTimeImmutable('today', $zone);
        $localDate = $localDate->modify('-' . $dayOffset . ' days');
        $weekday   = (int) $localDate->format('N');
        $isWeekend = $weekday >= 6;

        // Weekly rhythm, a gentle growth trend and a little noise.
        $factor = $isWeekend ? 0.62 : 1.0;
        $factor *= 0.82 + 0.36 * (($days - $dayOffset) / max(1, $days));
        $factor *= 0.85 + (mt_rand(0, 30) / 100);

        $daySessions = (int) round($sessions * $factor);

        for ($i = 0; $i < $daySessions; $i++) {
            // Interesting hours: a morning peak, a lunch dip, an evening peak.
            $hourRoll = mt_rand(1, 100);
            if ($hourRoll <= 8) {
                $hour = mt_rand(0, 6);
            } elseif ($hourRoll <= 45) {
                $hour = mt_rand(7, 12);
            } elseif ($hourRoll <= 70) {
                $hour = mt_rand(13, 17);
            } elseif ($hourRoll <= 95) {
                $hour = mt_rand(18, 23);
            } else {
                $hour = mt_rand(0, 23);
            }

            $startedLocal = $localDate->setTime($hour, mt_rand(0, 59), mt_rand(0, 59));
            $startedUtc   = $startedLocal->setTimezone($utc);

            $device  = pick_weighted($devices);
            $os      = pick_weighted($oses);
            $browser = pick_weighted($browsers);
            $screen  = pick_one($screens[$device] ?? ['1920x1080']);
            $country = pick_weighted($countries);
            $city    = pick_one($cities[$country] ?? ['']);
            $language = pick_weighted($languages);
            $referrer = pick_weighted($referrers);
            $referrerUrl = $referrer === '' ? '' : ($referrerUrls[$referrer] ?? '');

            // How deep the visit went, and how long it lasted.
            $depthRoll = mt_rand(1, 100);
            if ($depthRoll <= 41) {
                $depth = 1;
            } elseif ($depthRoll <= 70) {
                $depth = mt_rand(2, 3);
            } elseif ($depthRoll <= 90) {
                $depth = mt_rand(4, 6);
            } else {
                $depth = mt_rand(7, 11);
            }

            $secondsPerPage = [1 => 0, 2 => 28, 3 => 46, 4 => 65, 5 => 80];
            $duration = $depth === 1
                ? mt_rand(0, 25)
                : ($secondsPerPage[$depth] ?? 90) + mt_rand(0, 120);

            $entryPool = ['/', '/blog/php-analytics/', '/blog/shared-hosting/', '/pricing/',
                          '/docs/installation/', '/blog/cookieless/', '/blog/', '/docs/'];
            $entry = $depth === 1 ? pick_one($entryPool) : pick_one([$entryPool[0], $entryPool[1], $entryPool[2]]);

            $sessionKey  = random_hash();
            $visitorHash = random_hash();
            // Returning visitors: reuse a pseudonym that appeared before, the way
            // a real audience keeps coming back. The pool is kept in memory so a
            // session does not cost a random sort over the whole table.
            if ($visitorPool !== [] && mt_rand(1, 100) <= 22) {
                $visitorHash = $visitorPool[array_rand($visitorPool)];
            }
            $visitorPool[] = $visitorHash;
            if (count($visitorPool) > 4000) {
                $visitorPool = array_slice($visitorPool, -2000);
            }

            $pathList = [$entry];
            for ($p = 1; $p < $depth; $p++) {
                // Deeper pages are the article ones more often than the root.
                $pathList[] = $p === $depth - 1
                    ? pick_weighted(['/pricing/' => 3, '/contact/' => 2, '/docs/' => 3,
                                     '/blog/' => 3, '/newsletter/' => 1, '/' => 2])
                    : pick_weighted($paths);
            }

            $lastSeenUtc = $startedUtc->modify('+' . $duration . ' seconds');

            $visitInsert->execute([
                $siteId,
                $sessionKey,
                $visitorHash,
                $startedUtc->format('Y-m-d H:i:s'),
                $lastSeenUtc->format('Y-m-d H:i:s'),
                $duration,
                $depth,
                $depth === 1 ? 1 : 0,
                $entry,
                $pathList[$depth - 1],
                $referrer,
                $referrerUrl,
                $country,
                $city,
                $device,
                $os,
                $browser,
                $screen,
                $language,
                '',
            ]);
            $visitId = (int) $db->pdo()->lastInsertId();
            $visitsWritten++;

            $elapsed = 0;
            foreach ($pathList as $index => $path) {
                $step = $index === 0 ? 0 : mt_rand(8, 45);
                $elapsed += $step;
                $atUtc = $startedUtc->modify('+' . $elapsed . ' seconds');

                $pageviewInsert->execute([
                    $siteId,
                    $visitId,
                    $sessionKey,
                    $visitorHash,
                    $path,
                    $titles[$path] ?? '',
                    $index === 0 ? $referrer : '',
                    $index === 0 ? $referrerUrl : '',
                    $country,
                    $city,
                    $device,
                    $os,
                    $browser,
                    $screen,
                    $language,
                    0,
                    $atUtc->format('Y-m-d H:i:s'),
                    $atUtc->format('Y-m-d'),
                ]);
                $pageviewsWritten++;
            }

            // Conversions: a newsletter signup and a few trial clicks.
            if (mt_rand(1, 100) <= 7) {
                $eventInsert->execute([
                    $siteId,
                    $visitId,
                    $visitorHash,
                    'signup',
                    '/newsletter/',
                    $country,
                    $device,
                    $startedUtc->modify('+' . ($duration + 5) . ' seconds')->format('Y-m-d H:i:s'),
                    $startedUtc->format('Y-m-d'),
                ]);
                $eventsWritten++;
            }
            if (mt_rand(1, 100) <= 11) {
                $eventInsert->execute([
                    $siteId,
                    $visitId,
                    $visitorHash,
                    'pricing_view',
                    '/pricing/',
                    $country,
                    $device,
                    $startedUtc->modify('+' . ($duration + 3) . ' seconds')->format('Y-m-d H:i:s'),
                    $startedUtc->format('Y-m-d'),
                ]);
                $eventsWritten++;
            }
        }
    }

    $db->commit();
} catch (Throwable $error) {
    $db->rollBack();
    fwrite(STDERR, 'Generation failed: ' . $error->getMessage() . "\n");
    exit(1);
}

echo "wrote {$visitsWritten} sessions, {$pageviewsWritten} pageviews, {$eventsWritten} events\n";

// ---------------------------------------------------------------------------
// Roll the raw rows up so the dashboard has something to read.
// ---------------------------------------------------------------------------
// refresh() deliberately stops after a few days so one cron run cannot time out
// on a shared host. A bulk import needs every day, so walk them explicitly.
$siteZone = (string) $site['timezone'];
$earliest = Aggregator::earliestRawDate($siteId, $siteZone);
$today    = Aggregator::todayIn($siteZone);
$refreshed = 0;

if ($earliest !== null) {
    $cursor = $earliest;
    while ($cursor <= $today) {
        if (Aggregator::aggregateDay($site, $cursor)) {
            $refreshed++;
        }
        $cursor = Aggregator::addDays($cursor, 1);
    }
}

echo "aggregated {$refreshed} day(s)\n";
echo "done: open the dashboard for website #{$siteId} ({$domain})\n";
