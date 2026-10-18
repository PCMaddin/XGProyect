<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Alliance;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The players an admin form offers in a list (owner of a planet or an
 * alliance, founder, player to ban), and the alliances: at most LIMIT by
 * name, starting with what the admin typed into the search (?find=). The
 * forms listed every player: with a million of them a page of some 50 MB
 * that PHP could not build.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class PlayerChoices
{
    public const LIMIT = 500;

    /**
     * @param (Closure(Builder<User>): mixed)|null $scope narrows the players (for instance not banned)
     * @param int                                  $keep  a player always offered (the current owner)
     *
     * @return array{players: Collection<int, User>, more: bool, find: string}
     */
    public function choose(?Closure $scope = null, int $keep = 0): array
    {
        $find = $this->find();
        $query = User::query()->select('users.id', 'users.name');

        if ($scope !== null) {
            $scope($query);
        }

        if ($find !== '') {
            // the name index serves a search from the start of the name
            $query->where('users.name', 'like', addcslashes($find, '%_\\') . '%');
        }

        $players = $query->orderBy('users.name')->limit(self::LIMIT + 1)->get();
        $more = $players->count() > self::LIMIT;
        $players = $players->take(self::LIMIT);

        if ($keep > 0 && !$players->contains('id', $keep)) {
            $kept = User::query()->select('id', 'name')->find($keep);

            if ($kept instanceof User) {
                $players->prepend($kept);
            }
        }

        return ['players' => $players, 'more' => $more, 'find' => $find];
    }

    /**
     * @param int $keep an alliance always offered (the player's)
     *
     * @return array{alliances: Collection<int, Alliance>, more: bool, find: string}
     */
    public function alliances(int $keep = 0): array
    {
        $find = $this->find();
        $query = Alliance::query()->select('alliance_id', 'alliance_name', 'alliance_tag');

        if ($find !== '') {
            $query->where('alliance_name', 'like', addcslashes($find, '%_\\') . '%');
        }

        $alliances = $query->orderBy('alliance_name')->limit(self::LIMIT + 1)->get();
        $more = $alliances->count() > self::LIMIT;
        $alliances = $alliances->take(self::LIMIT);

        if ($keep > 0 && !$alliances->contains('alliance_id', $keep)) {
            $kept = Alliance::query()->select('alliance_id', 'alliance_name', 'alliance_tag')->find($keep);

            if ($kept instanceof Alliance) {
                $alliances->prepend($kept);
            }
        }

        return ['alliances' => $alliances, 'more' => $more, 'find' => $find];
    }

    private function find(): string
    {
        $find = request()->query('find');

        return is_string($find) ? trim($find) : '';
    }
}
