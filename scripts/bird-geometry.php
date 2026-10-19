<?php
/**
 * PlotBird mark geometry, in the 64x64 grid used by the SVG logo.
 *
 * Traced from the original artwork and simplified to a handful of control
 * points per contour; each loop is a closed Catmull-Rom spline. The last loop
 * is the bird's eye, which must be punched out with the even-odd rule.
 *
 * Source of truth for scripts/make-icons.php and assets/img/logo*.svg.
 *
 * @return list<list<array{0:float,1:float}>>
 */

declare(strict_types=1);

return [
    // loop 0
    [
        [48.15, 5.96],
        [52.44, 6.29],
        [56.73, 7.93],
        [60.02, 10.9],
        [62, 11.56],
        [62, 13.21],
        [59.69, 13.21],
        [55.41, 15.19],
        [52.77, 17.82],
        [49.47, 24.09],
        [23.43, 36.62],
        [9.91, 42.55],
        [7.93, 42.55],
        [9.58, 31.34],
        [29.03, 21.45],
        [32.66, 18.15],
        [38.92, 10.24],
        [44.2, 6.95],
    ],
    // loop 1
    [
        [47.82, 27.38],
        [48.81, 27.38],
        [48.81, 29.03],
        [47.16, 34.64],
        [44.86, 38.59],
        [40.57, 42.88],
        [14.2, 54.75],
        [6.29, 58.04],
        [4.64, 58.04],
        [8.26, 49.8],
        [10.9, 45.85],
    ],
    // loop 2
    [
        [3.65, 9.25],
        [18.15, 9.25],
        [20.79, 9.91],
        [22.77, 10.9],
        [27.71, 15.85],
        [29.36, 18.81],
        [20.46, 22.44],
        [11.23, 22.11],
        [8.26, 19.8],
        [2, 10.57],
        [2.33, 9.58],
    ],
    // loop 3
    [
        [50.13, 10.24],
        [48.81, 10.9],
        [48.48, 12.22],
        [49.14, 13.21],
        [50.79, 13.54],
        [51.78, 12.88],
        [51.78, 10.9],
    ],
];
