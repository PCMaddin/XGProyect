<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Instances\InstanceContext;
use App\Services\Instances\InstanceRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * With several universes, the one chosen in the lobby (cookie) becomes the
 * request's database before the session starts (sessions live in the
 * universe's database). A link sent by mail names its universe in the
 * query (?universe=slug), which wins over the cookie and is remembered like
 * a choice in the lobby. Without a valid choice the player goes to the
 * lobby. A single universe (no registry) passes untouched.
 */
class SelectInstance
{
    public const COOKIE = 'xgp_universe';

    /** paths that work without a universe */
    private const WITHOUT_UNIVERSE = ['universes', 'universes/*', 'stripe/*', 'legal/*'];

    public function __construct(
        private InstanceRegistry $registry,
        private InstanceContext $context,
    ) {
    }

    /**
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->registry->enabled() || $request->is(...self::WITHOUT_UNIVERSE)) {
            return $next($request);
        }

        $linked = $request->query(InstanceContext::QUERY);
        $slug = is_string($linked) && $linked !== '' ? $linked : $request->cookie(self::COOKIE);
        $instance = is_string($slug) ? $this->registry->find($slug) : null;

        if ($instance === null || !$instance->isOpen()) {
            return redirect()->route('lobby.index');
        }

        $this->context->activate($instance);
        $response = $next($request);

        if ($slug === $linked) {
            $response->headers->setCookie(cookie()->forever(self::COOKIE, $instance->slug));
        }

        return $response;
    }
}
