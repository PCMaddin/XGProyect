<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\FederationController;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;
use Tests\TestCase;

#[CoversClass(FederationController::class)]
class FederationControllerTest extends TestCase
{
    public function testResolveGroupRedirectsWhenNoFleetIsGiven(): void
    {
        $controller = app(FederationController::class);
        $method = new ReflectionMethod(FederationController::class, 'resolveGroup');

        try {
            $method->invoke($controller, Request::create('/', 'GET'));
            $this->fail('Expected a redirect when the fleet parameter is missing.');
        } catch (HttpResponseException $exception) {
            $this->assertStringContainsString(FederationController::REDIRECT_TARGET, (string) $exception->getResponse()->headers->get('Location'));
        }
    }
}
