<?php
declare(strict_types=1);

final class View
{
    public static function render(string $view, array $data = []): void
    {
        $viewPath = base_path('views/' . $view . '.php');

        if (!is_file($viewPath)) {
            throw new RuntimeException("View [{$view}] not found.");
        }

        extract($data, EXTR_SKIP);

        ob_start();
        require $viewPath;
        $content = ob_get_clean();

        require base_path('views/layout.php');
    }

    public static function partial(string $view, array $data = []): void
    {
        $viewPath = base_path('views/' . $view . '.php');

        if (!is_file($viewPath)) {
            throw new RuntimeException("View partial [{$view}] not found.");
        }

        extract($data, EXTR_SKIP);
        require $viewPath;
    }

    public static function partialToString(string $view, array $data = []): string
    {
        ob_start();
        self::partial($view, $data);

        return (string) ob_get_clean();
    }
}
