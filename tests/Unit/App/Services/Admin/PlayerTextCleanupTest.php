<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Admin;

use App\Libraries\Alliance\Ranks;
use App\Models\User;
use App\Services\Admin\PlayerTextCleanup;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

#[CoversClass(PlayerTextCleanup::class)]
class PlayerTextCleanupTest extends DatabaseTestCase
{
    public function testADryRunListsTheChangesWithoutWriting(): void
    {
        $this->seedTexts();

        $changes = (new PlayerTextCleanup())->run(true);

        $this->assertCount(7, $changes); // 2 player names, alliance name, tag, homepage and ranks, 1 planet name
        $this->assertSame('<svg/onload=x>', DB::table('users')->where('id', 2)->value('name'));
    }

    public function testStoredTextsAreCleaned(): void
    {
        $this->seedTexts();

        (new PlayerTextCleanup())->run(false);

        $this->assertSame(['Good1', 'svgonloadx', 'Player3'], DB::table('users')->orderBy('id')->pluck('name')->all());

        $alliance = DB::table('alliance')->where('alliance_id', 1)->first();
        $this->assertNotNull($alliance);
        $this->assertSame('bEvil/b', $alliance->alliance_name);
        $this->assertSame('A1', $alliance->alliance_tag);
        $this->assertSame('', $alliance->alliance_web);
        $this->assertSame('https://xgp.test/logo.png', $alliance->alliance_image);
        $this->assertIsString($alliance->alliance_ranks);
        $this->assertSame(['Boss', 'Newcomer'], array_column((new Ranks($alliance->alliance_ranks))->getAllRanksAsArray(), 'rank'));

        $this->assertSame(['Home', 'script'], DB::table('planets')->orderBy('planet_id')->pluck('planet_name')->all());

        $this->assertSame([], (new PlayerTextCleanup())->run(true), 'nothing left to clean');
    }

    public function testTheCommandRuns(): void
    {
        $this->seedTexts();

        $command = $this->artisan('xgp:clean-player-texts', ['--dry-run' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->expectsOutputToContain('Would change 7 value(s).')->assertExitCode(0)->run();

        $this->assertSame('<svg/onload=x>', DB::table('users')->where('id', 2)->value('name'));
    }

    private function seedTexts(): void
    {
        foreach (['Good1', '<svg/onload=x>', 'a<b'] as $i => $name) {
            $user = new User(['name' => $name, 'email' => 'user' . $i . '@example.com', 'password' => 'secret', 'home_planet_id' => $i + 1, 'current_planet' => $i + 1]);
            $user->save();
        }

        DB::table('alliance')->insert([
            'alliance_id' => 1,
            'alliance_name' => '<b>Evil</b>',
            'alliance_tag' => 'X"Y',
            'alliance_web' => 'javascript://x%0Aalert(1)',
            'alliance_image' => 'https://xgp.test/logo.png',
            'alliance_ranks' => '[{"rank":"<i>Boss</i>","rights":{}},{"rank":"Newcomer","rights":{}}]',
        ]);

        DB::table('planets')->insert([
            ['planet_id' => 1, 'planet_user_id' => 1, 'planet_name' => 'Home'],
            ['planet_id' => 2, 'planet_user_id' => 2, 'planet_name' => '<script>'],
        ]);
    }
}
