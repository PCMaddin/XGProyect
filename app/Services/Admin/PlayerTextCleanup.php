<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Libraries\Alliance\Ranks;
use App\Models\Alliance;
use App\Models\Planets;
use App\Models\User;
use App\Support\Concerns\ReadsMixedValues;
use Illuminate\Support\Facades\DB;

/**
 * One-off cleanup of player texts stored before the XSS fixes: names, tags,
 * addresses and rank names that the current input rules would reject, and
 * that are output unescaped in places.
 *
 * Player messages are left alone (they mix player text with intended HTML
 * and are deleted after a week anyway); alliance texts, application texts,
 * buddy requests and fleet shortcuts are escaped on output.
 *
 * @phpstan-type Change array{table: string, id: int, column: string, from: string, to: string}
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class PlayerTextCleanup
{
    use ReadsMixedValues;

    private const FORBIDDEN = '/[<>"\'&\\\\]/';

    private const SAFE_URL = '#^https?://[^\s"\'<>]+$#i';

    /**
     * Find the texts to clean and, unless $dryRun, store the cleaned ones.
     *
     * @return list<Change>
     */
    public function run(bool $dryRun): array
    {
        $changes = [...$this->playerNames(), ...$this->alliances(), ...$this->planetNames()];

        if (!$dryRun) {
            DB::transaction(function () use ($changes): void {
                foreach ($changes as $change) {
                    DB::table($change['table'])
                        ->where($this->keyOf($change['table']), $change['id'])
                        ->update([$change['column'] => $change['to']]);
                }
            });
        }

        return $changes;
    }

    /**
     * Names must be ASCII letters and digits (as at the registration).
     *
     * @return list<Change>
     */
    private function playerNames(): array
    {
        $changes = [];
        $taken = User::query()->pluck('name')->map(fn (mixed $name): string => strtolower(self::asString($name)))->flip()->all();

        foreach (User::query()->get(['id', 'name']) as $user) {
            if (preg_match('/^[a-zA-Z0-9]{3,20}$/', $user->name) === 1) {
                continue;
            }

            $name = substr((string) preg_replace('/[^a-zA-Z0-9]/', '', $user->name), 0, 20);

            if (strlen($name) < 3 || isset($taken[strtolower($name)])) {
                $name = 'Player' . $user->id;
            }

            $taken[strtolower($name)] = true;
            $changes[] = self::change('users', $user->id, 'name', $user->name, $name);
        }

        return $changes;
    }

    /**
     * Names and tags without < > " ' & \, plain http(s) addresses, rank
     * names without tags.
     *
     * @return list<Change>
     */
    private function alliances(): array
    {
        $changes = [];
        $names = Alliance::query()->pluck('alliance_name')->map(fn (mixed $name): string => strtolower(self::asString($name)))->flip()->all();
        $tags = Alliance::query()->pluck('alliance_tag')->map(fn (mixed $tag): string => strtolower(self::asString($tag)))->flip()->all();

        foreach (Alliance::query()->get() as $alliance) {
            $id = $alliance->alliance_id;

            $name = self::label((string) $alliance->alliance_name, $names, 30, 'Alliance' . $id);
            $tag = self::label((string) $alliance->alliance_tag, $tags, 8, 'A' . $id);
            $web = self::url((string) $alliance->alliance_web);
            $image = self::url((string) $alliance->alliance_image);
            $ranks = self::ranks((string) $alliance->alliance_ranks);

            foreach (['alliance_name' => $name, 'alliance_tag' => $tag, 'alliance_web' => $web, 'alliance_image' => $image, 'alliance_ranks' => $ranks] as $column => $clean) {
                if ($clean !== null) {
                    $changes[] = self::change('alliance', $id, $column, (string) $alliance->$column, $clean);
                }
            }
        }

        return $changes;
    }

    /**
     * @return list<Change>
     */
    private function planetNames(): array
    {
        $changes = [];

        foreach (Planets::query()->get(['planet_id', 'planet_name']) as $planet) {
            if (preg_match(self::FORBIDDEN, $planet->planet_name) !== 1) {
                continue;
            }

            $name = trim((string) preg_replace(self::FORBIDDEN, '', $planet->planet_name));
            $changes[] = self::change('planets', $planet->planet_id, 'planet_name', $planet->planet_name, $name !== '' ? $name : 'Planet');
        }

        return $changes;
    }

    /**
     * The cleaned name or tag, or null when it is fine.
     *
     * @param array<string, mixed> $taken the lower-case names in use, updated
     */
    private static function label(string $value, array &$taken, int $maxLength, string $fallback): ?string
    {
        if (preg_match(self::FORBIDDEN, $value) !== 1) {
            return null;
        }

        $clean = substr(trim((string) preg_replace(self::FORBIDDEN, '', $value)), 0, $maxLength);

        if (strlen($clean) < 3 || isset($taken[strtolower($clean)])) {
            $clean = $fallback;
        }

        $taken[strtolower($clean)] = true;

        return $clean;
    }

    /**
     * '' for an address that is not plain http(s), or null when it is fine.
     */
    private static function url(string $value): ?string
    {
        return $value === '' || preg_match(self::SAFE_URL, $value) === 1 ? null : '';
    }

    /**
     * The ranks with tags stripped from their names, or null when no name
     * had any.
     */
    private static function ranks(string $json): ?string
    {
        $ranks = new Ranks($json);
        $changed = false;

        foreach ($ranks->getAllRanksAsArray() as $id => $rank) {
            $name = trim(strip_tags($rank['rank']));

            if ($name !== $rank['rank']) {
                $ranks->editRankNameById($id, $name !== '' ? $name : 'Rank ' . $id);
                $changed = true;
            }
        }

        return $changed ? $ranks->getAllRanksAsJsonString() : null;
    }

    private function keyOf(string $table): string
    {
        return match ($table) {
            'users' => 'id',
            'alliance' => 'alliance_id',
            default => 'planet_id',
        };
    }

    /**
     * @return Change
     */
    private static function change(string $table, int $id, string $column, string $from, string $clean): array
    {
        return ['table' => $table, 'id' => $id, 'column' => $column, 'from' => $from, 'to' => $clean];
    }
}
