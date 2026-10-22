<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries;

use App\Libraries\Users;
use App\Models\User;
use App\Services\RegistrationService;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * A deleted account leaves its alliance: a member just goes, the founder
 * hands the alliance on, the last one takes it along. On MariaDB in strict
 * mode: the alliance used to be dissolved with an empty string for a
 * number, which failed, so the account was not deleted at all.
 */
#[CoversClass(Users::class)]
class AllianceOnDeleteMysqlTest extends MysqlTestCase
{
    /** rank 0 without rights, rank 1 the right hand */
    private const RANKS = '[{"rank":"Member","rights":{"9":0}},{"rank":"Right hand","rights":{"9":1}}]';

    /** @var list<int> founder, member, right hand */
    private array $players;

    private int $alliance;

    protected function setUp(): void
    {
        parent::setUp();

        config(['INSTALLED' => 'true', 'SECRETWORD' => 'test-secret']);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);

        $this->players = array_map(fn (string $name): int => $this->register($name), ['Founder', 'Member', 'Second']);
        $this->alliance = DB::table('alliance')->insertGetId([
            'alliance_name' => 'Alliance', 'alliance_tag' => 'AL', 'alliance_owner' => $this->players[0], 'alliance_register_time' => 1,
            'alliance_ranks' => '[]', 'alliance_description' => '', 'alliance_text' => '', 'alliance_request' => '',
        ]);
        DB::table('alliance_statistics')->insert(['alliance_statistic_alliance_id' => $this->alliance]);
        foreach ($this->players as $order => $id) {
            DB::table('users')->where('id', $id)->update(['ally_id' => $this->alliance, 'ally_rank_id' => 0, 'ally_register_time' => 100 + $order]);
        }
    }

    public function testAMemberLeavesAndTheAllianceStays(): void
    {
        $this->deleteAccount($this->players[1]);

        $this->assertSame($this->players[0], $this->owner());
        $this->assertSame(2, DB::table('users')->where('ally_id', $this->alliance)->count());
    }

    public function testTheFounderHandsTheAllianceToTheRightHand(): void
    {
        DB::table('alliance')->where('alliance_id', $this->alliance)->update(['alliance_ranks' => self::RANKS]);
        DB::table('users')->where('id', $this->players[2])->update(['ally_rank_id' => 1]);

        $this->deleteAccount($this->players[0]);

        $this->assertSame($this->players[2], $this->owner());
    }

    public function testWithoutARightHandTheLongestMemberTakesOver(): void
    {
        DB::table('users')->where('id', $this->players[2])->update(['ally_register_time' => 50]);

        $this->deleteAccount($this->players[0]);

        $this->assertSame($this->players[2], $this->owner());
        $this->assertSame(2, DB::table('users')->where('ally_id', $this->alliance)->count());
    }

    public function testTheLastMemberTakesTheAllianceAlongAndApplicantsAreFree(): void
    {
        $applicant = $this->register('Applicant');
        DB::table('users')->where('id', $applicant)->update(['ally_request' => $this->alliance, 'ally_request_text' => 'Let me in']);

        foreach ($this->players as $id) {
            $this->deleteAccount($id);
        }

        $this->assertSame(0, DB::table('alliance')->count());
        $this->assertSame(0, DB::table('alliance_statistics')->count());
        $this->assertEquals([0, ''], [DB::table('users')->where('id', $applicant)->value('ally_request'), DB::table('users')->where('id', $applicant)->value('ally_request_text')]);
    }

    private function deleteAccount(int $id): void
    {
        (new Users())->deleteUser($id);
        $this->assertNull(DB::table('users')->find($id));
    }

    private function owner(): int
    {
        $owner = DB::table('alliance')->where('alliance_id', $this->alliance)->value('alliance_owner');
        $this->assertIsNumeric($owner);

        return (int) $owner;
    }

    private function register(string $name): int
    {
        $user = app(RegistrationService::class)->register(['name' => $name, 'email' => strtolower($name) . '@example.com', 'password' => 'secret-password']);
        $this->assertInstanceOf(User::class, $user);

        return $user->id;
    }
}
