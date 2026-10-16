<?php

declare(strict_types=1);

namespace Tests\Unit\App\Exceptions;

use App\Exceptions\Handler;
use App\Exceptions\HaltWithView;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;

/**
 * A message page ended with HaltWithView is no error: it is shown, not logged.
 */
#[CoversClass(Handler::class)]
class HandlerTest extends TestCase
{
    public function testAMessagePageIsNotReported(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        $this->assertInstanceOf(Handler::class, $handler);

        $this->assertFalse($handler->shouldReport(new HaltWithView(View::make('welcome'))));
        $this->assertTrue($handler->shouldReport(new RuntimeException('a real error')));
    }
}
