<?php
declare(strict_types=1);

namespace Align;

final class View
{
    /** Renders views/{name}.php inside the main layout (or bare when $layout is null). */
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

    public static function fetch(string $name, array $vars = []): string
    {
        $file = APP_ROOT . '/views/' . $name . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $name");
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }
}
