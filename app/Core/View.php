<?php
declare(strict_types=1);

namespace App\Core;

/** Renders app/Views/<view>.php, wrapped in a layout that receives $content. */
final class View
{
    public static function render(string $view, array $data = [], ?string $layout = 'layouts/main'): void
    {
        $content = self::capture($view, $data);
        echo $layout === null ? $content : self::capture($layout, $data + ['content' => $content]);
    }

    /** Views must not use variables named $view or $data (reserved here). */
    public static function capture(string $view, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require APP_PATH . '/Views/' . $view . '.php';

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();   // never leave a half-rendered page above the error page
            throw $e;
        }
    }
}
