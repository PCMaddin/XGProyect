<?php

declare(strict_types=1);

namespace App\Services\Operators;

use App\Libraries\StatisticsLibrary;
use App\Models\User;
use App\Services\Account\AccountEraser;
use App\Services\RegistrationService;
use App\Services\SettingsService;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Players for a load test (xgp:loadtest:seed): registered like everyone
 * else, then built up (LoadTestWorld). They share one password and an
 * address at loadtest.invalid, which no mail reaches; xgp:loadtest:clear
 * deletes them again.
 */
class LoadTestPlayers
{
    public const DOMAIN = 'loadtest.invalid';
    private const CHUNK = 500;

    public function __construct(
        private RegistrationService $registration,
        private LoadTestWorld $world,
        private SettingsService $settings,
        private AccountEraser $eraser,
    ) {
    }

    /**
     * Players who are not admins, bots or load-test players: none may be
     * there, the load test is for an empty universe.
     */
    public function realPlayers(): int
    {
        return DB::table('users')
            ->where(static fn ($query) => $query->whereNull('authlevel')->orWhere('authlevel', 0))
            ->where('user_is_bot', 0)
            ->where('email', 'not like', '%@' . self::DOMAIN)
            ->count();
    }

    public function count(): int
    {
        return DB::table('users')->where('email', 'like', '%@' . self::DOMAIN)->count();
    }

    /**
     * Adds players up to $total (the numbers go on after the ones already
     * there), all with $password; returns how many were added.
     *
     * @param callable(int): void|null $progress called after each player
     */
    public function seed(int $total, string $password, int $now, ?callable $progress = null): int
    {
        $real = $this->realPlayers();

        if ($real > 0) {
            throw new RuntimeException('This universe has ' . $real . ' real players. Seed a universe of its own (xgp:instance:create loadtest …).');
        }

        // nobody else signs up while the test players are there
        $this->settings->write('reg_invite_only', '1');

        $ids = $this->register($this->count() + 1, $total, $progress);

        if ($ids === []) {
            return 0;
        }

        DB::table('users')->where('email', 'like', '%@' . self::DOMAIN)->update(['password' => Hash::make($password)]);
        DB::table('users')->whereIn('id', $ids)->update(['email_verified_at' => date('Y-m-d H:i:s', $now), 'onlinetime' => $now]);

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $this->world->develop($chunk, $now);
        }

        app(StatisticsLibrary::class)->makeStats();

        return count($ids);
    }

    /**
     * Deletes every load-test player as a deleted account is deleted (their
     * alliances go with the last member).
     *
     * @param callable(int): void|null $progress
     */
    public function clear(?callable $progress = null): int
    {
        $ids = DB::table('users')->where('email', 'like', '%@' . self::DOMAIN)->pluck('id');

        foreach ($ids as $id) {
            $this->eraser->erase(is_numeric($id) ? (int) $id : 0);

            if ($progress !== null) {
                $progress(1);
            }
        }

        return $ids->count();
    }

    /**
     * @param callable(int): void|null $progress
     *
     * @return list<int>
     */
    private function register(int $first, int $total, ?callable $progress): array
    {
        // each player's own hash would cost 70 ms; the real one is set afterwards
        $hasher = Hash::driver();
        $rounds = config('hashing.bcrypt.rounds');

        if ($hasher instanceof BcryptHasher) {
            $hasher->setRounds(4);
        }

        $ids = [];

        try {
            for ($number = $first; $number <= $total; $number++) {
                $user = $this->registration->register(['name' => 'load' . $number, 'email' => 'load' . $number . '@' . self::DOMAIN, 'password' => bin2hex(random_bytes(8))]);

                if (!$user instanceof User) {
                    throw new RuntimeException('Player ' . $number . ' could not be registered; the log has the reason.');
                }

                $ids[] = $user->id;

                if ($progress !== null) {
                    $progress(1);
                }
            }
        } finally {
            if ($hasher instanceof BcryptHasher) {
                $hasher->setRounds(is_numeric($rounds) ? (int) $rounds : 12);
            }
        }

        return $ids;
    }
}
