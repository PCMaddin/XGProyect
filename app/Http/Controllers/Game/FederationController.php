<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Services\FormatService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Entity\FleetEntity;
use Xgp\App\Libraries\Functions;
use Xgp\App\Libraries\Game\AcsFleets;
use Xgp\App\Libraries\Game\Fleets;
use Xgp\App\Libraries\Users;

/**
 * ACS (federation) management: create the attack group for a fleet and
 * invite/remove members from the player's buddy list.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class FederationController extends BaseController
{
    use PreparesLegacySql;

    public const REDIRECT_TARGET = 'game.php?page=fleet1';

    /** @var array<string, mixed> */
    private array $user = [];

    private Fleets $fleets;

    private int $membersCount = 0;

    private string $message = '';

    public function __construct(private FormatService $formatService)
    {
    }

    public function __invoke(Request $request): View
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Fleet));

        $this->user = Users::getInstance()->getUserData();

        $this->setUpFleets();
        $this->runAction($request);

        return $this->buildPage($request);
    }

    private function setUpFleets(): void
    {
        $userId = $this->userInt('id');

        $rows = $userId > 0 ? array_map(
            fn (object $row): array => get_object_vars($row),
            DB::select(
                $this->prepareSql('SELECT f.* FROM `' . FLEETS . '` f WHERE f.`fleet_owner` = ?;'),
                [$userId]
            )
        ) : [];

        $this->fleets = new Fleets($rows, $userId);
    }

    private function runAction(Request $request): void
    {
        $fleetId = $request->integer('fleet');

        if ($request->has('add') && $request->has('friends_list')) {
            $this->addAcsMember($request->integer('friends_list'), $fleetId);
        }

        if ($request->has('remove') && $request->has('members_list')) {
            $this->removeAcsMember($request->integer('members_list'), $fleetId);
        }

        if ($request->has('search') && $request->has('addtogroup')) {
            $this->searchUser($this->asString($request->post('addtogroup')), $fleetId);
        }

        if ($request->has('save') && $request->has('name_acs')) {
            $this->saveAcsName($this->asString($request->post('name_acs')), $fleetId);
        }
    }

    private function buildPage(Request $request): View
    {
        $group = $this->resolveGroup($request);
        $groupId = $this->asInt($group->getFirstAcs()->getAcsFleetId());

        $members = $this->buildMembersList($groupId);
        $buddies = $this->buildBuddiesList($groupId);

        return view('fleet.fleet_federation_view', [
            'acs_code' => $this->asString($group->getFirstAcs()->getAcsFleetName()),
            'buddies_list' => $buddies,
            'members_list' => $members,
            'invited_count' => $this->membersCount,
            'add_error_messages' => $this->message,
        ]);
    }

    private function addAcsMember(int $member, int $fleetId): void
    {
        if ($member <= 0 || $fleetId <= 0) {
            return;
        }

        $ownFleet = $this->fleets->getOwnValidFleetById($fleetId);

        if ($ownFleet === null) {
            return;
        }

        $group = $this->asInt($ownFleet->getFleetGroup());
        $acs = $this->getAcsDataByGroupId($group);

        if ($this->asInt($acs['acs_members'] ?? 0) >= 5 || $member === $this->userInt('id')) {
            return;
        }

        DB::insert(
            $this->prepareSql('INSERT INTO `' . ACS_MEMBERS . '` SET `acs_group_id` = ?, `acs_user_id` = ?;'),
            [$group, $member]
        );

        Functions::sendMessage(
            $member,
            $this->userInt('id'),
            0,
            5,
            $this->userStr('name'),
            (string) __('game/fleet.fl_acs_invitation_title'),
            (string) __('game/fleet.fl_player') . $this->userStr('name') . __('game/fleet.fl_acs_invitation_message')
        );
    }

    private function removeAcsMember(int $member, int $fleetId): void
    {
        if ($member <= 0 || $fleetId <= 0) {
            return;
        }

        $ownFleet = $this->fleets->getOwnValidFleetById($fleetId);

        if ($ownFleet === null) {
            return;
        }

        $group = $this->asInt($ownFleet->getFleetGroup());
        $acs = $this->getAcsDataByGroupId($group);

        if ($this->asInt($acs['acs_members'] ?? 0) < 1 || $member === $this->userInt('id')) {
            return;
        }

        DB::delete(
            $this->prepareSql('DELETE FROM `' . ACS_MEMBERS . '` WHERE `acs_group_id` = ? AND `acs_user_id` = ?;'),
            [$group, $member]
        );
    }

    private function searchUser(string $username, int $fleetId): void
    {
        if ($username === '') {
            return;
        }

        $row = DB::selectOne(
            $this->prepareSql(
                'SELECT u.`id`
                FROM `' . USERS . '` u
                WHERE u.`name` = ?
                    AND u.`id` NOT IN (
                        SELECT acs.`acs_user_id` FROM `' . ACS_MEMBERS . '` acs WHERE acs.`acs_group_id` = ?
                    );'
            ),
            [$username, $fleetId]
        );

        $userId = is_object($row) ? $this->asInt(get_object_vars($row)['id'] ?? 0) : 0;

        if ($userId > 0 && $userId !== $this->userInt('id')) {
            $this->addAcsMember($userId, $fleetId);

            $this->message = $this->formatService->customColor(
                (string) __('game/fleet.fl_player') . ' ' . $username . ' ' . __('game/fleet.fl_add_to_attack'),
                'lime'
            );

            return;
        }

        $this->message = $this->formatService->colorRed(
            (string) __('game/fleet.fl_player') . ' ' . $username . ' ' . __('game/fleet.fl_dont_exist')
        );
    }

    private function saveAcsName(string $acsName, int $fleetId): void
    {
        $length = strlen($acsName);

        if ($length < 3 || $length > 20 || $fleetId <= 0) {
            return;
        }

        $ownFleet = $this->fleets->getOwnValidFleetById($fleetId);

        if ($ownFleet === null) {
            return;
        }

        $acs = $this->getAcsDataByGroupId($this->asInt($ownFleet->getFleetGroup()));

        DB::update(
            $this->prepareSql(
                'UPDATE `' . ACS . '` acs SET acs.`acs_name` = ? WHERE acs.`acs_id` = ? AND acs.`acs_owner` = ?;'
            ),
            [$acsName, $this->asInt($acs['acs_id'] ?? 0), $this->userInt('id')]
        );
    }

    private function resolveGroup(Request $request): AcsFleets
    {
        $fleetId = $request->integer('fleet');

        if ($fleetId <= 0) {
            $this->redirectToStart();
        }

        $ownFleet = $this->fleets->getOwnValidFleetById($fleetId);

        if ($ownFleet === null) {
            $this->redirectToStart();
        }

        $groupId = $this->asInt($ownFleet->getFleetGroup());

        if ($groupId <= 0) {
            $groupId = $this->createGroup($ownFleet);
        }

        return new AcsFleets([$this->getAcsDataByGroupId($groupId)], $this->userInt('id'));
    }

    private function createGroup(FleetEntity $ownFleet): int
    {
        $acsCode = 'AG' . mt_rand(100000, 999999999);

        return DB::transaction(function () use ($acsCode, $ownFleet): int {
            DB::insert(
                $this->prepareSql(
                    'INSERT INTO `' . ACS . '` SET `acs_name` = ?, `acs_owner` = ?, `acs_galaxy` = ?,
                        `acs_system` = ?, `acs_planet` = ?, `acs_planet_type` = ?;'
                ),
                [
                    $acsCode,
                    $this->asInt($ownFleet->getFleetOwner()),
                    $this->asInt($ownFleet->getFleetEndGalaxy()),
                    $this->asInt($ownFleet->getFleetEndSystem()),
                    $this->asInt($ownFleet->getFleetEndPlanet()),
                    $this->asInt($ownFleet->getFleetEndType()),
                ]
            );

            $groupId = (int) DB::getPdo()->lastInsertId();

            DB::update(
                $this->prepareSql('UPDATE `' . FLEETS . '` SET `fleet_group` = ? WHERE `fleet_id` = ?;'),
                [$groupId, $this->asInt($ownFleet->getFleetId())]
            );

            DB::insert(
                $this->prepareSql('INSERT INTO `' . ACS_MEMBERS . '` SET `acs_group_id` = ?, `acs_user_id` = ?;'),
                [$groupId, $this->asInt($ownFleet->getFleetOwner())]
            );

            return $groupId;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function getAcsDataByGroupId(int $groupId): array
    {
        if ($groupId <= 0) {
            return [];
        }

        $row = DB::selectOne(
            $this->prepareSql(
                'SELECT acs.*,
                    (SELECT COUNT(*) FROM `' . ACS_MEMBERS . '` am WHERE am.`acs_group_id` = acs.`acs_id`) AS `acs_members`
                FROM `' . ACS . '` acs
                WHERE acs.`acs_id` = ?;'
            ),
            [$groupId]
        );

        return is_object($row) ? get_object_vars($row) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildBuddiesList(int $groupId): array
    {
        $userId = $this->userInt('id');

        $buddies = array_map(
            fn (object $row): array => get_object_vars($row),
            DB::select(
                $this->prepareSql(
                    'SELECT DISTINCT u.`id`, u.`name`
                    FROM `' . BUDDY . '` AS b
                    LEFT JOIN `' . USERS . '` AS u ON ((u.`id` = b.`buddy_sender`) OR (u.`id` = b.`buddy_receiver`))
                    WHERE (b.`buddy_sender` = ? OR b.`buddy_receiver` = ?)
                        AND b.`buddy_status` = 1
                        AND u.`id` NOT IN (
                            SELECT acs.`acs_user_id` FROM `' . ACS_MEMBERS . '` acs WHERE acs.`acs_group_id` = ?
                        );'
                ),
                [$userId, $userId, $groupId]
            )
        );

        $list = [];

        foreach ($buddies as $buddy) {
            if ($this->asInt($buddy['id'] ?? 0) !== $userId) {
                $list[] = ['value' => $buddy['id'] ?? 0, 'title' => $buddy['name'] ?? ''];
            }
        }

        return $list;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildMembersList(int $groupId): array
    {
        $members = array_map(
            fn (object $row): array => get_object_vars($row),
            DB::select(
                $this->prepareSql(
                    'SELECT u.`id`, u.`name`
                    FROM `' . ACS_MEMBERS . '` am
                    INNER JOIN `' . USERS . '` u ON u.`id` = am.`acs_user_id`
                    WHERE am.`acs_group_id` = ?;'
                ),
                [$groupId]
            )
        );

        $list = [];

        foreach ($members as $member) {
            $this->membersCount++;
            $list[] = ['value' => $member['id'] ?? 0, 'title' => $member['name'] ?? ''];
        }

        return $list;
    }

    private function redirectToStart(): never
    {
        throw new HttpResponseException(redirect(self::REDIRECT_TARGET));
    }

    private function userInt(string $key): int
    {
        $value = $this->user[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function userStr(string $key): string
    {
        $value = $this->user[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
