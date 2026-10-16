<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\BattleEngine\Utils;

/**
 * Renders one of the engine's report views (Views/*.html) with `$this` bound
 * to the rendering object and the given variables in scope.
 */
trait RendersView
{
    /**
     * @param array<string, mixed> $variables
     */
    private function renderView(string $view, array $variables = []): string
    {
        $render = function (string $file, array $variables): void {
            extract($variables);
            require $file;
        };

        ob_start();

        try {
            $render(OPBEPATH . 'Views' . DIRECTORY_SEPARATOR . $view, $variables);
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }
}
