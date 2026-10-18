<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lobby;

use App\Http\Middleware\SelectInstance;
use App\Services\Instances\Instance;
use App\Services\Instances\InstanceContext;
use App\Services\Instances\InstanceRegistry;
use App\Services\Instances\SeasonResults;
use App\Services\Premium\DarkMatterShop;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The lobby: the server's universes, newest first, with their mode and
 * players; choosing one remembers it in a cookie and opens its home page.
 */
class LobbyController extends BaseController
{
    public function __construct(
        private InstanceRegistry $registry,
        private InstanceContext $context,
        private SeasonResults $results,
    ) {
    }

    public function index(): View | RedirectResponse
    {
        if (!$this->registry->enabled()) {
            return redirect()->route('home.index');
        }

        return view('lobby.index', [
            'universes' => array_map(fn (Instance $instance): array => [
                'slug' => $instance->slug,
                'name' => $instance->name,
                'mode' => $instance->mode,
                'open' => $instance->isOpen(),
                'opened_at' => $instance->openedAt,
                'ends_at' => $instance->endsAt,
                'hall_of_fame' => $instance->isOpen() ? [] : $this->hallOfFame($instance),
            ] + $this->facts($instance), $this->registry->all()),
            'chosen' => request()->cookie(SelectInstance::COOKIE),
        ]);
    }

    public function choose(string $slug): RedirectResponse
    {
        $instance = $this->registry->find($slug);

        if ($instance === null || !$instance->isOpen()) {
            return redirect()->route('lobby.index');
        }

        return redirect()->route('home.index')->withCookie(cookie()->forever(SelectInstance::COOKIE, $instance->slug));
    }

    /**
     * The rankings frozen when the season ended; none if they cannot be read.
     *
     * @return array<string, list<array{rank: int, name: string, alliance_tag: string|null, points: float}>>
     */
    private function hallOfFame(Instance $instance): array
    {
        try {
            return $this->results->forUniverse($instance->slug);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * The players of a universe (bots not counted) and how dark matter is
     * paid there; no players (null) if its database cannot be read.
     *
     * @return array{players: int|null, payments: list<string>}
     */
    private function facts(Instance $instance): array
    {
        try {
            return $this->context->runIn($instance, static fn (): array => [
                'players' => DB::table('users')->where('user_is_bot', false)->count(),
                'payments' => $instance->mode === Instance::PAY2WIN ? app(DarkMatterShop::class)->methods() : [],
            ]);
        } catch (Throwable $e) {
            report($e);

            return ['players' => null, 'payments' => []];
        }
    }
}
