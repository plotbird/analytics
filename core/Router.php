<?php
/**
 * PlotBird Analytics - request router.
 *
 * Patterns may capture segments: '/share/{token}'. The front controller reads
 * the path either from the rewritten REQUEST_URI or from the "?r=" fallback,
 * so the application works with and without mod_rewrite.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class Router
{
    /**
     * @var list<array{method:string,pattern:string,regex:string,handler:callable}>
     */
    private array $routes = [];

    /**
     * @param callable $handler Receives (array $params).
     */
    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    /**
     * @param callable $handler Receives (array $params).
     */
    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $pattern = trim($pattern, '/');
        $regex   = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $matches): string {
                return '(?P<' . $matches[1] . '>[^/]+)';
            },
            $pattern
        );

        $this->routes[] = [
            'method'  => $method,
            'pattern' => $pattern,
            'regex'   => '#^' . $regex . '$#',
            'handler' => $handler,
        ];
    }

    /**
     * Run the first matching route, or render a 404 page.
     */
    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);
        $path   = trim($path, '/');

        // Allow a form to reach a PUT/DELETE style handler over POST.
        if ($method === 'POST') {
            $override = strtoupper((string) ($_POST['_method'] ?? ''));
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        $methodMatched = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }
            $methodMatched = true;
            // A POST route may also answer GET when it is registered as such;
            // otherwise this is a genuine method mismatch.
            if ($route['method'] !== $method && !($route['method'] === 'POST' && $method === 'POST')) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            try {
                ($route['handler'])($params);
            } catch (\Throwable $e) {
                $this->handleException($e);
            }
            return;
        }

        if ($methodMatched) {
            $this->abort(405, I18n::translate('error.method_title'), I18n::translate('error.method_body'));
        }
        $this->abort(404, I18n::translate('error.not_found_title'), I18n::translate('error.not_found_body'));
    }

    /**
     * Render a status page and stop.
     */
    public function abort(int $status, string $title, string $message): void
    {
        http_response_code($status);
        View::renderError($status, $title, $message);
        exit;
    }

    /**
     * Report an unhandled error without leaking internals to the visitor.
     */
    private function handleException(\Throwable $e): void
    {
        pb_log('unhandled: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 'error');

        if (Config::get('app.debug', false)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Unhandled error: ' . $e->getMessage() . "\n" . $e->getTraceAsString();
            return;
        }

        http_response_code(500);
        View::renderError(500, I18n::translate('error.server_title'), I18n::translate('error.server_body'));
    }
}
