<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game;

use App\Models\User;
use App\Services\Admin\BotService;
use App\Services\Game\AllianceService;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * The alliance operations the alliance page and the bots share: founding
 * (ranks, statistics, the founder as member), applying, withdrawing and
 * answering, which only answers an application to the alliance.
 */
#[CoversClass(AllianceService::class)]
class AllianceServiceMysqlTest extends MysqlTestCase
{
    private AllianceService $alliances;

    /** @var list<int> */
    private array $users;

    protected function setUp(): void
    {
        parent::setUp();

        config(['version.files' => '4.0.0']);
        $this->seed(OptionsTableSeeder::class);
        $this->assertSame(3, app(BotService::class)->createBots(3, 1, 1));

        $this->alliances = app(AllianceService::class);
        $this->users = array_values(User::orderBy('id')->pluck('id')->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all());
    }

    public function testFoundingMakesTheFounderTheFirstMember(): void
    {
        [$founder] = $this->users;

        $allyId = $this->alliances->found($founder, 'Testers', 'TST', 1000);

        $alliance = DB::table('alliance')->where('alliance_id', $allyId)->first();
        $this->assertNotNull($alliance);
        $this->assertSame('TST', $alliance->alliance_tag);
        $this->assertEquals($founder, $alliance->alliance_owner);
        $this->assertEquals(0, $alliance->alliance_request_notallow);
        $this->assertStringContainsString('"rights"', (string) $alliance->alliance_ranks);
        $this->assertTrue(DB::table('alliance_statistics')->where('alliance_statistic_alliance_id', $allyId)->exists());
        $this->assertEquals($allyId, User::findOrFail($founder)->ally_id);
        $this->assertTrue($this->alliances->tagTaken('TST'));
        $this->assertTrue($this->alliances->nameTaken('Testers'));
        $this->assertFalse($this->alliances->tagTaken('OTHER'));

        // open for applications
        $this->alliances->openForApplications($allyId);
        $this->assertEquals(1, DB::table('alliance')->where('alliance_id', $allyId)->value('alliance_request_notallow'));
    }

    public function testAnApplicationIsAcceptedOrDeclined(): void
    {
        [$founder, $applicant, $other] = $this->users;
        $allyId = $this->alliances->found($founder, 'Testers', 'TST', 1000);

        $this->alliances->apply($applicant, $allyId, 'Let me in', 2000);
        $this->alliances->apply($other, $allyId, 'Me too', 2000);
        $this->assertEquals($allyId, User::findOrFail($applicant)->ally_request);

        $this->assertTrue($this->alliances->answer($applicant, $allyId, true));
        $this->assertTrue($this->alliances->answer($other, $allyId, false));

        $accepted = User::findOrFail($applicant);
        $this->assertEquals($allyId, $accepted->ally_id);
        $this->assertEquals(0, $accepted->ally_request);
        $declined = User::findOrFail($other);
        $this->assertEquals(0, $declined->ally_id);
        $this->assertEquals(0, $declined->ally_request);
    }

    /**
     * Regression: the answer took any user id, so an alliance admin could
     * pull any player into the alliance or, declining, out of theirs.
     */
    public function testOnlyAnApplicationToTheAllianceIsAnswered(): void
    {
        [$founder, $member, $stranger] = $this->users;
        $mine = $this->alliances->found($founder, 'Mine', 'MINE', 1000);
        $theirs = $this->alliances->found($member, 'Theirs', 'THRS', 1000);

        // a member of another alliance is neither pulled in nor thrown out
        $this->assertFalse($this->alliances->answer($member, $mine, true));
        $this->assertFalse($this->alliances->answer($member, $mine, false));
        $this->assertEquals($theirs, User::findOrFail($member)->ally_id);

        // nor is a player who applied elsewhere
        $this->alliances->apply($stranger, $theirs, 'Hi', 2000);
        $this->assertFalse($this->alliances->answer($stranger, $mine, true));
        $this->assertEquals(0, User::findOrFail($stranger)->ally_id);
        $this->assertEquals($theirs, User::findOrFail($stranger)->ally_request);

        $this->alliances->withdraw($stranger);
        $this->assertEquals(0, User::findOrFail($stranger)->ally_request);
    }
}
