<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\View\View;

/**
 * Ends the request with a view (rendered by the exception handler), for
 * messages shown from deep inside the game code.
 */
class HaltWithView extends Exception
{
    protected View $view;

    public function __construct(View $view)
    {
        $this->view = $view;
    }

    public function getView(): View
    {
        return $this->view;
    }
}
