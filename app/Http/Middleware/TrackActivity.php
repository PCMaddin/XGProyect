<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Security\PlayerTrail;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts a signed-in player's game requests per hour (scripts click at a
 * pace and around the clock no person does).
 */
class TrackActivity
{
    public function __construct(private PlayerTrail $trail)
    {
    }

    /**
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $user = $request->user();
        $id = $user?->getAuthIdentifier();

        if (is_numeric($id)) {
            try {
                $this->trail->activity((int) $id, time());
            } catch (Throwable $e) {
                // counting must never cost the player the page
                report($e);
            }
        }

        return $response;
    }
}
