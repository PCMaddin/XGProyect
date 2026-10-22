<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Services\Operators\ErrorMonitor;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        // a message page, not an error; it filled the log and the admin's
        // error list
        HaltWithView::class,
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->renderable(function (HaltWithView $e) {
            return response($e->getView());
        });

        // every universe's errors, grouped, for the operators (/operator)
        $this->reportable(function (Throwable $e): void {
            app(ErrorMonitor::class)->record($e, time());
        });
    }
}
