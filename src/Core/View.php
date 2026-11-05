<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Rendert PHP-Templates aus templates/. Ausgabe im Template immer mit e().
 */
final class View
{
    public function __construct(private readonly string $templateDir)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = $this->include($template, $data);
        if ($layout === null) {
            return $content;
        }

        return $this->include($layout, [...$data, 'content' => $content]);
    }

    /** @param array<string, mixed> $data */
    private function include(string $template, array $data): string
    {
        if (!preg_match('#^[a-z0-9_/-]+$#i', $template)) {
            throw new \InvalidArgumentException('Ungültiger Template-Name.');
        }
        $file = $this->templateDir . '/' . $template . '.php';
        $render = static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                require $__file;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }

            return (string) ob_get_clean();
        };

        return $render($file, $data);
    }
}
