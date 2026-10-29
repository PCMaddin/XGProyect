<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Security;

use App\Services\Security\CheatScanner;
use App\Services\Security\PlayerTrail;
use App\Services\Security\Suspicions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * The scan turns the trail into signs for the admin: a shared browser or
 * address, resources pushed to a stronger player, activity around the
 * clock or at a script's pace. Bots never count, and the admin's decision
 * on a sign stays when it is found again.
 */
#[CoversClass(CheatScanner::class)]
#[CoversClass(Suspicions::class)]
#[CoversClass(PlayerTrail::class)]
class CheatScannerTest extends DatabaseTestCase
{
    private const NOW = 1_900_000_000;

    private const ALPHA = 1;
    private const BETA = 2;
    private const GAMMA = 3;
    private const BOT = 4;
    private const DELTA = 5;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::ALPHA => 1_000, self::BETA => 50_000, self::GAMMA => 500, self::BOT => 9_000, self::DELTA => 700] as $id => $points) {
            DB::table('users')->insert(['id' => $id, 'name' => 'P' . $id, 'email' => 'p' . $id . '@example.com', 'password' => 'x', 'user_is_bot' => $id === self::BOT, 'home_planet_id' => $id, 'current_planet' => $id]);
            DB::table('users_statistics')->insert(['user_statistic_user_id' => $id, 'user_statistic_total_points' => $points]);
        }
    }

    public function testASharedBrowserOrAddressIsASign(): void
    {
        $trail = app(PlayerTrail::class);
        $device = str_repeat('a', 32);
        $trail->login(self::ALPHA, '10.0.0.1', 'Firefox', $device, self::NOW - 3600);
        $trail->login(self::BETA, '10.0.0.2', 'Firefox', $device, self::NOW - 1800);
        $trail->login(self::GAMMA, '10.0.0.1', 'Chrome', str_repeat('c', 32), self::NOW - 600);
        // the bot on the same browser, and an old sign-in outside the window
        $trail->login(self::BOT, '10.0.0.9', 'Bot', $device, self::NOW - 60);
        $trail->login(self::DELTA, '10.0.0.2', 'Edge', str_repeat('d', 32), self::NOW - 40 * 86400);

        app(CheatScanner::class)->scan(self::NOW);

        $this->assertSame([
            [self::ALPHA, 'shared_device', self::BETA, 80],
            [self::ALPHA, 'shared_ip', self::GAMMA, 40],
            [self::BETA, 'shared_device', self::ALPHA, 80],
            [self::GAMMA, 'shared_ip', self::ALPHA, 40],
        ], $this->signs());
    }

    public function testTheAddressOfAPublicNetworkIsNoSign(): void
    {
        config(['cheats.shared_max_accounts' => 3]);
        $trail = app(PlayerTrail::class);

        foreach ([self::ALPHA, self::BETA, self::GAMMA, self::DELTA] as $player) {
            $trail->login($player, '100.64.0.1', '', str_repeat((string) $player, 32), self::NOW - 600);
        }

        $trail->login(self::ALPHA, '10.0.0.7', '', str_repeat('1', 32), self::NOW - 300);
        $trail->login(self::BETA, '10.0.0.7', '', str_repeat('2', 32), self::NOW - 300);

        app(CheatScanner::class)->scan(self::NOW);

        // four accounts behind 100.64.0.1: a network, not a sign; the two on 10.0.0.7 are
        $this->assertSame([[self::ALPHA, 'shared_ip', self::BETA, 40], [self::BETA, 'shared_ip', self::ALPHA, 40]], $this->signs());
    }

    public function testResourcesPushedToAStrongerPlayerAreASign(): void
    {
        $trail = app(PlayerTrail::class);
        $trail->transfer(self::ALPHA, self::BETA, 100_000.0, 50_000.0, 0.0, self::NOW - 3600);
        $trail->transfer(self::BETA, self::ALPHA, 20_000.0, 0.0, 0.0, self::NOW - 1800);
        // to a weaker player, too little, and to oneself: no sign
        $trail->transfer(self::BETA, self::GAMMA, 500_000.0, 0.0, 0.0, self::NOW - 1800);
        $trail->transfer(self::GAMMA, self::ALPHA, 50_000.0, 0.0, 0.0, self::NOW - 1800);
        $trail->transfer(self::GAMMA, self::GAMMA, 900_000.0, 0.0, 0.0, self::NOW - 1800);

        app(CheatScanner::class)->scan(self::NOW);

        $this->assertSame([[self::ALPHA, 'pushing', self::BETA, 60], [self::BETA, 'pushed', self::ALPHA, 60]], $this->signs());
        $detail = DB::table('suspicions')->where('kind', 'pushing')->value('detail');
        $this->assertIsString($detail);
        $this->assertSame(['resources' => 130_000], json_decode($detail, true));

        // on the same browser it is a strong sign
        app(PlayerTrail::class)->login(self::ALPHA, '1.1.1.1', '', str_repeat('e', 32), self::NOW - 60);
        app(PlayerTrail::class)->login(self::BETA, '2.2.2.2', '', str_repeat('e', 32), self::NOW - 60);
        app(CheatScanner::class)->scan(self::NOW);
        $this->assertEquals(90, DB::table('suspicions')->where('kind', 'pushing')->value('score'));
    }

    public function testActivityAroundTheClockOrAtAScriptsPaceIsASign(): void
    {
        $trail = app(PlayerTrail::class);
        $firstDay = intdiv(self::NOW, 86400) - 4;

        // Gamma: 22 hours on each of three days, Delta on two only; Alpha: one hour of 1500 requests
        for ($day = 0; $day < 3; $day++) {
            for ($hour = 0; $hour < 22; $hour++) {
                $trail->activity(self::GAMMA, ($firstDay + $day) * 86400 + $hour * 3600);

                if ($day < 2) {
                    $trail->activity(self::DELTA, ($firstDay + $day) * 86400 + $hour * 3600);
                }
            }
        }

        DB::table('activity_hours')->insert(['user_id' => self::ALPHA, 'hour' => intdiv(self::NOW, 3600) - 5, 'requests' => 1500]);
        DB::table('activity_hours')->insert(['user_id' => self::BOT, 'hour' => intdiv(self::NOW, 3600) - 5, 'requests' => 5000]);

        app(CheatScanner::class)->scan(self::NOW);

        $this->assertSame([[self::ALPHA, 'machine_pace', 0, 50], [self::GAMMA, 'around_clock', 0, 50]], $this->signs());

        // a second request in the same hour counts on
        $trail->activity(self::GAMMA, $firstDay * 86400);
        $this->assertEquals(2, DB::table('activity_hours')->where('user_id', self::GAMMA)->where('hour', $firstDay * 24)->value('requests'));
    }

    public function testTheAdminsDecisionStaysAndOldTrailsAreDeleted(): void
    {
        $trail = app(PlayerTrail::class);
        $trail->login(self::ALPHA, '10.0.0.1', '', str_repeat('a', 32), self::NOW - 60);
        $trail->login(self::BETA, '10.0.0.1', '', str_repeat('b', 32), self::NOW - 60);
        app(CheatScanner::class)->scan(self::NOW);

        $suspicions = app(Suspicions::class);
        $id = DB::table('suspicions')->where('user_id', self::BETA)->value('id');
        $this->assertIsNumeric($id);
        $id = (int) $id;
        $this->assertTrue($suspicions->decide($id, Suspicions::EXCLUDED, 'second account', self::NOW));
        $this->assertFalse($suspicions->decide($id, 'banned', '', self::NOW));

        app(CheatScanner::class)->scan(self::NOW + 3600);
        $this->assertSame(Suspicions::EXCLUDED, DB::table('suspicions')->where('id', $id)->value('status'));
        $this->assertSame([self::BETA], $suspicions->excluded());

        $trail->prune(self::NOW);
        $this->assertSame(0, DB::table('login_events')->count());
    }

    public function testOldSignsGoWithTheTrailsAndADecisionKeepsNoEvidence(): void
    {
        $suspicions = app(Suspicions::class);
        $old = self::NOW - 100;
        $suspicions->note(self::ALPHA, 'shared_ip', self::BETA, 50, ['ip' => '10.0.0.1'], $old);
        $suspicions->note(self::BETA, 'shared_ip', self::ALPHA, 50, ['ip' => '10.0.0.1'], $old);
        $suspicions->note(self::GAMMA, 'pushing', self::ALPHA, 80, ['ip' => '10.0.0.2'], $old);
        // seen again at the last scan: still a sign
        $suspicions->note(self::GAMMA, 'machine_pace', 0, 50, ['ip' => '10.0.0.3'], $old);
        $suspicions->note(self::GAMMA, 'machine_pace', 0, 60, ['ip' => '10.0.0.3'], self::NOW);
        $suspicions->decide($this->sign(self::BETA, 'shared_ip'), Suspicions::CLEARED, 'siblings', $old);
        $suspicions->decide($this->sign(self::GAMMA, 'pushing'), Suspicions::EXCLUDED, 'pushing', $old);

        $suspicions->prune(self::NOW);

        $this->assertSame([[self::GAMMA, 'machine_pace', 0, 60], [self::GAMMA, 'pushing', self::ALPHA, 80]], $this->signs());
        $this->assertSame([self::GAMMA], $suspicions->excluded());
        $this->assertSame('[]', DB::table('suspicions')->where('kind', 'pushing')->value('detail'));
        $detail = DB::table('suspicions')->where('kind', 'machine_pace')->value('detail');
        $this->assertIsString($detail);
        $this->assertStringContainsString('10.0.0.3', $detail);
    }

    private function sign(int $user, string $kind): int
    {
        $id = DB::table('suspicions')->where('user_id', $user)->where('kind', $kind)->value('id');
        $this->assertIsNumeric($id);

        return (int) $id;
    }

    /**
     * @return list<list<int|string>> user, kind, other user, score
     */
    private function signs(): array
    {
        return array_values(DB::table('suspicions')->orderBy('user_id')->orderBy('kind')->get()
            ->map(static fn (object $row): array => array_map(static fn (mixed $value): int | string => is_numeric($value) ? (int) $value : (is_string($value) ? $value : ''), [$row->user_id, $row->kind, $row->other_user, $row->score]))->all());
    }
}
