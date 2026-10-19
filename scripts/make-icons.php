<?php
/**
 * Generate the favicon / PWA raster icons from the vector mark.
 *
 * The geometry lives in scripts/bird-geometry.php - the same control points the
 * SVG logo uses. The mark is drawn in brand blue on a transparent background,
 * exactly like assets/img/logo.svg.
 *
 * Two icons must stay opaque, and that is not a style choice:
 *   * a `maskable` icon is cropped by the launcher, so the artwork has to fill
 *     the whole square - a transparent one shows the launcher background through
 *     the safe zone and looks unintentional;
 *   * iOS composites a transparent touch icon onto black.
 *
 * GD has no vector support, so the contours are flattened here and filled with a
 * scanline that honours even-odd, the same rule the SVG declares - that is what
 * keeps the bird's eye a hole instead of a white disc.
 *
 * Usage: php scripts/make-icons.php
 */

declare(strict_types=1);

const OUT = __DIR__ . '/../assets/img';
const BRAND = [2, 132, 199];     // #0284c7
const SUPERSAMPLE = 4;
const CURVE_STEPS = 14;

/** @var list<list<array{0:float,1:float}>> $LOOPS */
$LOOPS = require __DIR__ . '/bird-geometry.php';

/**
 * Flatten the closed Catmull-Rom contours into polylines at a given scale.
 *
 * @param list<list<array{0:float,1:float}>> $loops
 * @return list<list<array{0:float,1:float}>>
 */
function flattenLoops(array $loops, float $scale, float $offsetX, float $offsetY): array
{
    $out = [];

    foreach ($loops as $points) {
        $count = count($points);
        if ($count < 3) {
            continue;
        }
        $flat = [];
        for ($i = 0; $i < $count; $i++) {
            $p0 = $points[($i - 1 + $count) % $count];
            $p1 = $points[$i];
            $p2 = $points[($i + 1) % $count];
            $p3 = $points[($i + 2) % $count];
            for ($s = 0; $s < CURVE_STEPS; $s++) {
                $t = $s / CURVE_STEPS;
                $t2 = $t * $t;
                $t3 = $t2 * $t;
                $x = 0.5 * ((2 * $p1[0]) + (-$p0[0] + $p2[0]) * $t
                    + (2 * $p0[0] - 5 * $p1[0] + 4 * $p2[0] - $p3[0]) * $t2
                    + (-$p0[0] + 3 * $p1[0] - 3 * $p2[0] + $p3[0]) * $t3);
                $y = 0.5 * ((2 * $p1[1]) + (-$p0[1] + $p2[1]) * $t
                    + (2 * $p0[1] - 5 * $p1[1] + 4 * $p2[1] - $p3[1]) * $t2
                    + (-$p0[1] + 3 * $p1[1] - 3 * $p2[1] + $p3[1]) * $t3);
                $flat[] = [$offsetX + $x * $scale, $offsetY + $y * $scale];
            }
        }
        $out[] = $flat;
    }

    return $out;
}

/**
 * Fill the contours with the even-odd rule, so the eye stays a hole.
 *
 * @param list<list<array{0:float,1:float}>> $polylines
 */
function fillEvenOdd(\GdImage $img, array $polylines, int $color): void
{
    $size = imagesx($img);
    $edges = [];

    foreach ($polylines as $flat) {
        $n = count($flat);
        for ($i = 0; $i < $n; $i++) {
            $a = $flat[$i];
            $b = $flat[($i + 1) % $n];
            if ($a[1] !== $b[1]) {
                $edges[] = [$a, $b];
            }
        }
    }

    for ($y = 0; $y < $size; $y++) {
        $yc = $y + 0.5;
        $xs = [];
        foreach ($edges as [$a, $b]) {
            if (($a[1] <= $yc && $b[1] > $yc) || ($b[1] <= $yc && $a[1] > $yc)) {
                $xs[] = $a[0] + ($yc - $a[1]) / ($b[1] - $a[1]) * ($b[0] - $a[0]);
            }
        }
        if (count($xs) < 2) {
            continue;
        }
        sort($xs);
        for ($i = 0; $i + 1 < count($xs); $i += 2) {
            $x0 = (int) ceil($xs[$i] - 0.5);
            $x1 = (int) floor($xs[$i + 1] - 0.5);
            if ($x1 < $x0) {
                continue;
            }
            imageline($img, $x0, $y, $x1, $y, $color);
        }
    }
}

/**
 * Draw the mark.
 *
 * @param list<list<array{0:float,1:float}>> $loops
 * @param array{0:int,1:int,2:int}|null      $background  null = transparent
 * @param array{0:int,1:int,2:int}           $markColor
 */
function drawIcon(array $loops, int $size, ?array $background, array $markColor, float $markScale): \GdImage
{
    $canvas = $size * SUPERSAMPLE;
    $img = imagecreatetruecolor($canvas, $canvas);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));

    if ($background !== null) {
        imagefilledrectangle(
            $img,
            0,
            0,
            $canvas - 1,
            $canvas - 1,
            imagecolorallocate($img, $background[0], $background[1], $background[2])
        );
    }

    imagealphablending($img, true);

    $scale = ($canvas / 64.0) * $markScale;
    $offset = ($canvas - 64.0 * $scale) / 2.0;
    $color = imagecolorallocate($img, $markColor[0], $markColor[1], $markColor[2]);
    fillEvenOdd($img, flattenLoops($loops, $scale, $offset, $offset), $color);

    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $img, 0, 0, 0, 0, $size, $size, $canvas, $canvas);
    imagedestroy($img);

    return $out;
}

$targets = [
    // file, size, background (null = transparent), mark scale
    ['icon-192.png', 192, null, 0.98],
    ['icon-512.png', 512, null, 0.98],
    ['maskable-512.png', 512, [255, 255, 255], 0.62],   // opaque: the launcher crops it
    ['apple-touch-icon.png', 180, [255, 255, 255], 0.72], // opaque: iOS puts it on black
];

foreach ($targets as [$file, $size, $background, $markScale]) {
    $image = drawIcon($LOOPS, $size, $background, BRAND, $markScale);
    $path = OUT . '/' . $file;
    imagepng($image, $path, 9);
    imagedestroy($image);
    printf("%-24s %4d px  %6d bytes\n", $file, $size, filesize($path));
}

// --- preview: a checkerboard proves the transparency -----------------------
$size = 200;
$strip = imagecreatetruecolor(640, 220);
// Checkerboard.
for ($y = 0; $y < 220; $y += 10) {
    for ($x = 0; $x < 640; $x += 10) {
        $light = ((int) ($x / 10) + (int) ($y / 10)) % 2 === 0;
        $color = imagecolorallocate($strip, $light ? 255 : 226, $light ? 255 : 232, $light ? 255 : 240);
        imagefilledrectangle($strip, $x, $y, $x + 9, $y + 9, $color);
    }
}
imagefilledrectangle($strip, 420, 0, 640, 220, imagecolorallocate($strip, 15, 23, 42));

$x = 10;
foreach ([16, 32, 48, 96] as $s) {
    $img = drawIcon($LOOPS, $s, null, BRAND, 0.98);
    imagecopy($strip, $img, $x, 20, 0, 0, $s, $s);
    imagedestroy($img);
    $x += $s + 16;
}
foreach ([16, 32] as $s) {
    $img = drawIcon($LOOPS, $s, null, BRAND, 0.98);
    imagecopy($strip, $img, 440 + ($s === 16 ? 0 : 40), 20, 0, 0, $s, $s);
    imagedestroy($img);
}
foreach (['maskable-512.png', 'apple-touch-icon.png'] as $index => $file) {
    $img = imagecreatefrompng(OUT . '/' . $file);
    $thumb = imagecreatetruecolor(96, 96);
    imagecopyresampled($thumb, $img, 0, 0, 0, 0, 96, 96, imagesx($img), imagesy($img));
    imagecopy($strip, $thumb, 40 + $index * 120, 100, 0, 0, 96, 96);
    imagedestroy($thumb);
    imagedestroy($img);
}
imagepng($strip, __DIR__ . '/../.dev/icon-preview.png', 9);
imagedestroy($strip);

echo "done\n";
