<?php
/**
 * PlotBird Analytics - web app manifest (PWA).
 *
 * Served from the project root, not from /assets: a manifest's start_url and
 * scope are resolved against the manifest's own URL, so keeping it here makes
 * "Add to home screen" open the dashboard instead of an asset folder - and it
 * keeps working when the project lives in a sub-directory.
 *
 * @package PlotBird
 */

declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';

header('Content-Type: application/manifest+json; charset=utf-8');
// Long enough to avoid re-fetching on every navigation, short enough that a
// rename in the settings shows up the same day.
header('Cache-Control: public, max-age=3600');

$name = (string) config('app.name', 'PlotBird Analytics');

// The home-screen label: an explicit setting wins, otherwise the first word -
// truncating mid-word ("PlotBird Ana") looks broken on a launcher.
$shortName = trim((string) config('app.short_name', ''));
if ($shortName === '') {
    $firstWord = (string) preg_split('/\s+/', trim($name))[0];
    $shortName = mb_strlen($firstWord, 'UTF-8') <= 12 ? $firstWord : mb_substr($name, 0, 12, 'UTF-8');
}

$base = rtrim(root_url(''), '/') . '/';

$manifest = [
    'name'             => $name,
    'short_name'       => $shortName,
    'description'      => 'Self-hosted, cookie-free web analytics',
    'id'               => $base,
    'start_url'        => url('dashboard'),
    'scope'            => $base,
    'display'          => 'standalone',
    'orientation'      => 'any',
    'background_color' => '#f8fafc',
    'theme_color'      => '#0284c7',
    'icons'            => [
        [
            'src'     => asset('img/icon-192.png'),
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => asset('img/icon-512.png'),
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => asset('img/maskable-512.png'),
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'maskable',
        ],
    ],
    'shortcuts' => [
        [
            'name' => 'Dashboard',
            'url'  => url('dashboard'),
        ],
        [
            'name' => 'Websites',
            'url'  => url('sites'),
        ],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
