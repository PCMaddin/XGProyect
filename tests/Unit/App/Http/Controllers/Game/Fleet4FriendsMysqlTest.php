<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Core\Enumerators\MissionsEnumerator as Missions;
use App\Http\Controllers\Game\Fleet4Controller;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\MysqlTestCase;

/**
 * The last fleet step checks the friendship missions itself, as the form
 * can be posted with any mission and group: holding only at an alliance
 * member's or buddy's planet, joining only an ACS group one was invited to.
 */
#[CoversClass(Fleet4Controller::class)]
class Fleet4FriendsMysqlTest extends MysqlTestCase
{
    /**
     * Regression: two players without an alliance counted as allies.
     */
    public function testHoldingNeedsAnAllianceOrABuddy(): void
    {
        $this->assertFalse($this->stayAllowed(['id' => 1, 'ally_id' => 0], ['planet_user_id' => 2, 'ally_id' => 0]));
        $this->assertFalse($this->stayAllowed(['id' => 1, 'ally_id' => 3], ['planet_user_id' => 2, 'ally_id' => 4]));
        $this->assertTrue($this->stayAllowed(['id' => 1, 'ally_id' => 3], ['planet_user_id' => 2, 'ally_id' => 3]));

        DB::table('buddys')->insert(['buddy_sender' => 1, 'buddy_receiver' => 2, 'buddy_status' => 1, 'buddy_request_text' => '']);
        $this->assertTrue($this->stayAllowed(['id' => 1, 'ally_id' => 0], ['planet_user_id' => 2, 'ally_id' => 0]));
    }

    /**
     * Regression: any group whose number and target were known could be
     * joined, without an invitation.
     */
    public function testAnAcsGroupNeedsAnInvitation(): void
    {
        $group = DB::table('acs')->insertGetId([
            'acs_name' => 'AG1', 'acs_owner' => 2, 'acs_galaxy' => 1, 'acs_system' => 2, 'acs_planet' => 3, 'acs_planet_type' => 1,
        ]);
        DB::table('acs_members')->insert(['acs_group_id' => $group, 'acs_user_id' => 2]);
        session(['fleet_data' => ['target' => ['galaxy' => 1, 'system' => 2, 'planet' => 3, 'type' => 1, 'group' => $group, 'acs_target' => 'g1s2p3t1']]]);

        $this->assertFalse($this->acsValid(1));

        DB::table('acs_members')->insert(['acs_group_id' => $group, 'acs_user_id' => 1]);
        $this->assertTrue($this->acsValid(1));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $target
     */
    private function stayAllowed(array $user, array $target): bool
    {
        $controller = app(Fleet4Controller::class);
        (new ReflectionProperty(Fleet4Controller::class, 'user'))->setValue($controller, $user);
        (new ReflectionProperty(Fleet4Controller::class, 'planet'))->setValue($controller, ['planet_user_id' => $user['id']]);
        (new ReflectionProperty(Fleet4Controller::class, 'targetData'))->setValue($controller, $target);

        $allowed = (new ReflectionMethod(Fleet4Controller::class, 'isStayAllowed'))->invoke($controller);
        $this->assertIsBool($allowed);

        return $allowed;
    }

    private function acsValid(int $userId): bool
    {
        $controller = app(Fleet4Controller::class);
        (new ReflectionProperty(Fleet4Controller::class, 'user'))->setValue($controller, ['id' => $userId]);
        (new ReflectionProperty(Fleet4Controller::class, 'cleanInput'))->setValue($controller, ['mission' => Missions::ACS]);

        $valid = (new ReflectionMethod(Fleet4Controller::class, 'validateAcs'))->invoke($controller);
        $this->assertIsBool($valid);

        return $valid;
    }
}
