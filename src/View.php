<?php
declare(strict_types=1);

namespace Align;

/**
 * Plain-PHP templates from views/. A view escapes what it prints itself (e(), or json_encode for data attributes).
 *
 * Security assumptions: view and layout names are written in code; fetch() still refuses anything but plain path
 * segments, so a name that ever came from input couldn't include another PHP file. $vars are trusted keys chosen
 * by the caller; their values are data the view must escape.
 */
final class View
{
    /**
     * Renders views/{name}.php inside the main layout (or bare when $layout is null). Inside the client portal the
     * staff layout is never used: the portal's own layout (and its own error page) replace it.
     */
    public static function render(string $name, array $vars = [], ?string $layout = 'layout/main'): void
    {
        // Inside the client portal, never fall back to the staff layout (e.g. 404 and error pages)
        if (defined('IS_PORTAL') && IS_PORTAL && $layout === 'layout/main') {
            $name = $name === 'error' ? 'portal/error' : $name;
            $layout = 'portal/layout';
            $vars += ['pu' => \Align\Portal\PortalAuth::user()];
        }
        $content = self::fetch($name, $vars);
        if ($layout === null) {
            echo $content;
            return;
        }
        echo self::fetch($layout, $vars + ['content' => $content]);
    }

    /**
     * Runs views/{name}.php with $vars as its variables and returns what it printed. Throws InvalidArgumentException
     * for a name that isn't plain segments (letters, digits, _ and -, separated by /), RuntimeException when there's
     * no such view. A view that throws leaves no output buffer behind (2.2.1: its half-drawn page was printed ahead
     * of the error page).
     */
    public static function fetch(string $name, array $vars = []): string
    {
        // Never "..", a leading slash, a backslash or a NUL (2.2.1): views/../anything.php could be included
        if (!preg_match('#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\z#', $name)) {
            throw new \InvalidArgumentException('Invalid view name');
        }
        $file = APP_ROOT . '/views/' . $name . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $name");
        }
        $level = ob_get_level();
        ob_start();
        try {
            self::run($file, $vars);
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    /**
     * Includes the template with only its own variables in scope (2.2.1: fetch()'s locals $name and $file shadowed
     * variables of the same name passed in, so a view asking for $name got the view's own name).
     */
    private static function run(string $__file, array $__vars): void
    {
        extract($__vars, EXTR_SKIP);
        unset($__vars);
        require $__file;
    }
}
