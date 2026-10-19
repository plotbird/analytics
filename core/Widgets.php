<?php
/**
 * PlotBird Analytics - dashboard widget catalogue.
 *
 * The single source of truth for which widgets exist, how much room they take
 * in the grid and whether they are visible by default. The dashboard renders
 * from it and the "customise widgets" modal lists it, so the two can never
 * disagree.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class Widgets
{
    /**
     * id => [title key, size, default visibility].
     *
     * Sizes are expressed in grid columns of a 12 column layout:
     *   full   - the whole row
     *   half   - two per row
     *   third  - three per row
     */
    public const CATALOG = [
        'realtime' => [
            'title'   => 'widget.realtime.title',
            'size'    => 'full',
            'default' => true,
        ],
        'overview' => [
            'title'   => 'widget.overview.title',
            'size'    => 'full',
            'default' => true,
        ],
        'chart' => [
            'title'   => 'widget.chart.title',
            'size'    => 'full',
            'default' => true,
        ],
        'top_pages' => [
            'title'   => 'widget.top_pages.title',
            'size'    => 'half',
            'default' => true,
        ],
        'referrers' => [
            'title'   => 'widget.referrers.title',
            'size'    => 'half',
            'default' => true,
        ],
        'geo' => [
            'title'   => 'widget.geo.title',
            'size'    => 'half',
            'default' => true,
        ],
        'devices' => [
            'title'   => 'widget.devices.title',
            'size'    => 'half',
            'default' => true,
        ],
        'visitors' => [
            'title'   => 'widget.visitors.title',
            'size'    => 'full',
            'default' => true,
        ],
        'goals' => [
            'title'   => 'widget.goals.title',
            'size'    => 'half',
            'default' => true,
        ],
    ];

    /**
     * Grid classes per size token.
     *
     * @return array<string,string>
     */
    public static function sizeClasses(): array
    {
        return [
            'full'  => 'col-span-12',
            'half'  => 'col-span-12 xl:col-span-6',
            'third' => 'col-span-12 lg:col-span-6 xl:col-span-4',
        ];
    }

    /**
     * The default layout: every default widget, in catalogue order.
     *
     * @return list<array{id:string,visible:bool}>
     */
    public static function defaultLayout(): array
    {
        $layout = [];
        foreach (self::CATALOG as $id => $meta) {
            $layout[] = ['id' => $id, 'visible' => (bool) $meta['default']];
        }
        return $layout;
    }

    /**
     * Validate a layout coming from the browser (localStorage or a saved
     * profile): unknown ids are dropped, missing ones appended as hidden.
     *
     * @param mixed $layout
     * @return list<array{id:string,visible:bool}>
     */
    public static function sanitize($layout): array
    {
        if (!is_array($layout)) {
            return self::defaultLayout();
        }

        $clean = [];
        $seen  = [];

        foreach ($layout as $entry) {
            $id = '';
            $visible = true;

            if (is_string($entry)) {
                $id = $entry;
            } elseif (is_array($entry)) {
                $id = (string) ($entry['id'] ?? '');
                $visible = (bool) ($entry['visible'] ?? true);
            }

            if ($id === '' || !isset(self::CATALOG[$id]) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $clean[] = ['id' => $id, 'visible' => $visible];
        }

        foreach (self::CATALOG as $id => $meta) {
            if (!isset($seen[$id])) {
                $clean[] = ['id' => $id, 'visible' => (bool) $meta['default']];
            }
        }

        return $clean;
    }
}
