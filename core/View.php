<?php
/**
 * PlotBird Analytics - template rendering.
 *
 * Templates are plain PHP files that receive their data as local variables and
 * are always escaped with e() at the point of output. There is no template
 * language to learn and no compilation step to maintain.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class View
{
    /** @var array<string,mixed> Data shared with every template. */
    private static array $shared = [];

    /**
     * Make a value available to all templates of this request.
     *
     * @param mixed $value
     */
    public static function share(string $key, $value): void
    {
        self::$shared[$key] = $value;
    }

    /**
     * Render a template into the layout and echo the result.
     *
     * @param array<string,mixed> $data
     */
    public static function render(string $template, array $data = [], ?string $layout = 'layout'): void
    {
        $content = self::capture($template, $data);

        if ($layout === null) {
            echo $content;
            return;
        }

        echo self::capture($layout, array_merge($data, ['content' => $content]));
    }

    /**
     * Render a template and return it as a string.
     *
     * @param array<string,mixed> $data
     */
    public static function capture(string $template, array $data = []): string
    {
        $file = self::resolve($template);
        if ($file === null) {
            return '<!-- missing template: ' . e($template) . ' -->';
        }

        $variables = array_merge(self::$shared, $data);
        // Templates are trusted code and receive exactly what they were given.
        extract($variables, EXTR_SKIP);

        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    /**
     * Include another template from inside a template.
     *
     * @param array<string,mixed> $data
     */
    public static function partial(string $template, array $data = []): void
    {
        echo self::capture($template, $data);
    }

    /**
     * The standard error page, usable even when no layout is available.
     *
     * The layout needs the same variables a controller would provide, so they
     * are assembled here; without them PHP would emit "undefined variable"
     * notices while rendering an error page, which is exactly the moment when
     * the logs should stay clean.
     */
    public static function renderError(int $status, string $title, string $message): void
    {
        try {
            $user = Auth::user();

            self::render('error', [
                'status'      => $status,
                'title'       => $title,
                'message'     => $message,
                'page_title'  => $title,
                'page_assets' => [],
                'user'        => $user,
                'sites'       => [],
                'current_site' => null,
                'locales'     => I18n::available(),
                'locale'      => I18n::current(),
                'app_name'    => (string) config('app.name', 'PlotBird Analytics'),
                'app_version' => PB_VERSION,
                'installer_present' => Security::installerPresent(),
                'flash_success' => null,
                'flash_error'   => null,
                'current_path'  => Security::currentPath(),
            ]);
        } catch (\Throwable $e) {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html><head><meta charset="utf-8"><title>' . e((string) $status) . '</title></head>'
                . '<body style="font-family:system-ui,sans-serif;padding:3rem;text-align:center">'
                . '<h1>' . e((string) $status) . '</h1><p>' . e($title) . '</p><p>' . e($message) . '</p>'
                . '</body></html>';
        }
    }

    /**
     * Resolve a template name to a file path.
     *
     * "dashboard"        -> /templates/dashboard.php
     * "install/step2"    -> /install/views/step2.php
     * "install"          -> /install/views/layout.php is NOT implied; use
     *                       "install/layout" explicitly.
     */
    public static function resolve(string $template): ?string
    {
        $template = trim($template, '/');
        if ($template === '' || strpos($template, '..') !== false) {
            return null;
        }

        if (strpos($template, 'install/') === 0) {
            $file = PB_ROOT . '/install/views/' . substr($template, strlen('install/')) . '.php';
        } else {
            $file = PB_ROOT . '/templates/' . $template . '.php';
        }

        return is_file($file) ? $file : null;
    }
}
