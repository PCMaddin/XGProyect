<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Enumerators\AllianceRanksEnumerator as AllianceRanks;
use Xgp\App\Core\Enumerators\SwitchIntEnumerator as SwitchInt;
use Xgp\App\Libraries\Alliance\Ranks;

/**
 * Session-backed current-user/-planet singleton. On construction it validates
 * the session, loads the user and active planet, and runs the resource/queue
 * tick. Ported from the legacy class; the session-derived SQL is now bound.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.Superglobals")
 */
class Users
{
    use PreparesLegacySql;

    /** @var array<string, mixed> */
    private array $userData = [];

    /** @var array<string, mixed> */
    private array $planetData = [];

    private static ?Users $instance = null;

    public function __construct()
    {
        if (!self::isSessionSet()) {
            return;
        }

        $this->setUserData();
        $this->setPlanet();
        $this->setPlanetData();

        UpdatesLibrary::updatePlanetResources($this->userData, $this->planetData, time());
        UpdatesLibrary::updateBuildingsQueue($this->planetData, $this->userData);
        UpdatesLibrary::updateResearchQueue($this->planetData, $this->userData);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserData(): array
    {
        return $this->userData;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPlanetData(): array
    {
        return $this->planetData;
    }

    public function deleteUser(int $userId): void
    {
        $userRow = DB::selectOne($this->prepareSql('SELECT `ally_id` FROM `' . USERS . '` WHERE `id` = ?;'), [$userId]);
        $userData = $userRow !== null ? (array) $userRow : [];

        if (self::asInt($userData['ally_id'] ?? 0) !== 0) {
            $this->handleAllianceOnDelete(self::asInt($userData['ally_id'] ?? 0));
        }

        $this->deleteUserRows($userId);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function isOnVacations(array $user): bool
    {
        return self::asInt($user['preference_vacation_mode'] ?? 0) > 0;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function isInactive(array $user): bool
    {
        return self::asInt($user['onlinetime'] ?? 0) < (time() - ONE_WEEK);
    }

    private function handleAllianceOnDelete(int $allyId): void
    {
        $allianceRow = DB::selectOne(
            $this->prepareSql(
                'SELECT a.`alliance_id`, a.`alliance_ranks`,
                    (SELECT COUNT(id) AS `ally_members` FROM `' . USERS . '` WHERE `ally_id` = ?) AS `ally_members`
                FROM `' . ALLIANCE . '` AS a
                WHERE a.`alliance_id` = ?;'
            ),
            [$allyId, $allyId]
        );
        $alliance = $allianceRow !== null ? (array) $allianceRow : [];
        $allianceId = self::asInt($alliance['alliance_id'] ?? 0);

        $ranksRaw = $alliance['alliance_ranks'] ?? null;
        $hasRanks = self::asInt($alliance['ally_members'] ?? 0) > 1 && is_string($ranksRaw) && $ranksRaw !== '';

        if (!$hasRanks) {
            $this->deleteAllianceById($allianceId);

            return;
        }

        $newOwnerRank = $this->findRightHandRank(new Ranks($ranksRaw));

        if ($newOwnerRank === null) {
            $this->deleteAllianceById($allianceId);

            return;
        }

        DB::statement(
            $this->prepareSql(
                'UPDATE `' . ALLIANCE . '` SET `alliance_owner` = (
                    SELECT `id` FROM `' . USERS . '`
                    WHERE `ally_rank_id` = ? AND `ally_id` = ? LIMIT 1
                ) WHERE `alliance_id` = ?;'
            ),
            [$newOwnerRank, $allianceId, $allianceId]
        );
    }

    private function findRightHandRank(Ranks $ranks): ?int
    {
        foreach ($ranks->getAllRanksAsArray() as $id => $rank) {
            $rights = is_array($rank) && isset($rank['rights']) && is_array($rank['rights']) ? $rank['rights'] : [];

            if (($rights[AllianceRanks::RIGHT_HAND] ?? null) == SwitchInt::on) {
                return self::asInt($id);
            }
        }

        return null;
    }

    private function deleteUserRows(int $userId): void
    {
        DB::statement(
            $this->prepareSql(
                'DELETE p,b,d,s FROM ' . PLANETS . ' AS p
                INNER JOIN ' . BUILDINGS . ' AS b ON b.building_planet_id = p.`planet_id`
                INNER JOIN ' . DEFENSES . ' AS d ON d.defense_planet_id = p.`planet_id`
                INNER JOIN ' . SHIPS . ' AS s ON s.ship_planet_id = p.`planet_id`
                WHERE `planet_user_id` = ?;'
            ),
            [$userId]
        );
        DB::statement($this->prepareSql('DELETE FROM ' . MESSAGES . ' WHERE `message_sender` = ? OR `message_receiver` = ?;'), [$userId, $userId]);
        DB::statement($this->prepareSql('DELETE FROM ' . BUDDY . ' WHERE `buddy_sender` = ? OR `buddy_receiver` = ?;'), [$userId, $userId]);
        DB::statement(
            $this->prepareSql(
                'DELETE r,f,n,p,pr,s,u FROM ' . USERS . ' AS u
                INNER JOIN ' . RESEARCH . ' AS r ON r.research_user_id = u.id
                LEFT JOIN ' . FLEETS . ' AS f ON f.fleet_owner = u.id
                LEFT JOIN ' . NOTES . ' AS n ON n.note_owner = u.id
                INNER JOIN ' . PREMIUM . ' AS p ON p.premium_user_id = u.id
                INNER JOIN ' . PREFERENCES . ' AS pr ON pr.preference_user_id = u.id
                INNER JOIN ' . USERS_STATISTICS . ' AS s ON s.user_statistic_user_id = u.id
                WHERE u.`id` = ?;'
            ),
            [$userId]
        );
    }

    private static function isSessionSet(): bool
    {
        return (bool) session('user_id', false) && (bool) session('user_password', false);
    }

    private function deleteAllianceById(int $allianceId): void
    {
        DB::statement(
            $this->prepareSql(
                'DELETE ass, a FROM ' . ALLIANCE . ' AS a
                INNER JOIN ' . ALLIANCE_STATISTICS . ' AS ass ON ass.alliance_statistic_alliance_id = a.alliance_id
                WHERE a.`alliance_id` = ?;'
            ),
            [$allianceId]
        );

        DB::statement(
            $this->prepareSql(
                'UPDATE `' . USERS . "` SET
                    `ally_id` = '0', `ally_request` = '0', `ally_request_text` = '',
                    `ally_register_time` = '', `ally_rank_id` = '0'
                WHERE `ally_id` = ?;"
            ),
            [$allianceId]
        );
    }

    private function setUserData(): void
    {
        $userId = self::asInt(session('user_id'));
        $userRow = DB::selectOne(
            $this->prepareSql(
                'SELECT
                    u.*, pre.*, pr.*,
                    usul.user_statistic_total_rank, usul.user_statistic_total_points, r.*,
                    a.alliance_name,
                    (SELECT COUNT(`message_id`) AS `new_message`
                        FROM `' . MESSAGES . '`
                        WHERE `message_receiver` = u.`id` AND `message_read` = 0) AS `new_message`
                FROM `' . USERS . '` AS u
                INNER JOIN `' . PREFERENCES . '` AS pr ON pr.preference_user_id = u.id
                INNER JOIN `' . USERS_STATISTICS . '` AS usul ON usul.user_statistic_user_id = u.id
                INNER JOIN `' . PREMIUM . '` AS pre ON pre.premium_user_id = u.id
                INNER JOIN `' . RESEARCH . '` AS r ON r.research_user_id = u.id
                LEFT JOIN `' . ALLIANCE . '` AS a ON a.alliance_id = u.ally_id
                WHERE u.`id` = ?
                LIMIT 1;'
            ),
            [$userId]
        );
        $userRow = $userRow !== null ? (array) $userRow : [];

        $this->displayLoginErrors($userRow);

        DB::statement(
            $this->prepareSql(
                'UPDATE ' . USERS . ' SET
                    `onlinetime` = ?, `current_page` = ?, `lastip` = ?, `agent` = ?
                WHERE `id` = ? LIMIT 1;'
            ),
            [
                time(),
                self::asString($_SERVER['REQUEST_URI'] ?? ''),
                self::asString($_SERVER['REMOTE_ADDR'] ?? ''),
                self::asString($_SERVER['HTTP_USER_AGENT'] ?? ''),
                $userId,
            ]
        );

        $this->userData = $userRow;
    }

    /**
     * @param array<string, mixed> $userRow
     */
    private function displayLoginErrors(array $userRow): void
    {
        // Preserve the legacy comparison semantics exactly — these are the
        // session/auth integrity gates.
        if (($userRow['id'] ?? null) != session('user_id')) {
            Functions::redirect(SYSTEM_ROOT);
        }

        if (Auth::id() !== session('user_id')) {
            Functions::redirect(SYSTEM_ROOT);
        }

        $expected = self::asString($userRow['password'] ?? '') . '-' . self::asString(config('SECRETWORD'));

        if (!Hash::check($expected, self::asString(session('user_password')))) {
            Functions::redirect(SYSTEM_ROOT);
        }
    }

    private function setPlanetData(): void
    {
        $planetId = self::asInt($this->userData['current_planet'] ?? 0);
        $adminLevel = app(SettingsService::class)->getInt('stat_admin_level');

        $row = DB::selectOne(
            $this->prepareSql(
                'SELECT p.*, b.*, d.*, s.*,
                m.planet_id AS moon_id, m.planet_name AS moon_name, m.planet_image AS moon_image,
                m.planet_destroyed AS moon_destroyed,
                (SELECT COUNT(user_statistic_user_id) AS stats_users
                    FROM `' . USERS_STATISTICS . '` AS s
                    INNER JOIN ' . USERS . ' AS u ON u.id = s.user_statistic_user_id
                    WHERE u.`authlevel` <= ?) AS stats_users
                FROM ' . PLANETS . ' AS p
                INNER JOIN ' . BUILDINGS . ' AS b ON b.building_planet_id = p.`planet_id`
                INNER JOIN ' . DEFENSES . ' AS d ON d.defense_planet_id = p.`planet_id`
                INNER JOIN ' . SHIPS . ' AS s ON s.ship_planet_id = p.`planet_id`
                LEFT JOIN ' . PLANETS . ' AS m ON m.planet_id = (SELECT mp.`planet_id`
                    FROM ' . PLANETS . ' AS mp
                    WHERE (mp.planet_galaxy=p.planet_galaxy AND mp.planet_system=p.planet_system
                        AND mp.planet_planet=p.planet_planet AND mp.planet_type=3))
                WHERE p.`planet_id` = ?;'
            ),
            [$adminLevel, $planetId]
        );

        $this->planetData = $row !== null ? (array) $row : [];
    }

    private function setPlanet(): void
    {
        $select = isset($_GET['cp']) ? self::asInt($_GET['cp']) : 0;
        $restore = isset($_GET['re']) ? self::asInt($_GET['re']) : 0;

        if ($restore !== 0 || $select === 0) {
            return;
        }

        $ownerId = self::asInt($this->userData['id'] ?? 0);
        $ownedRow = DB::selectOne(
            $this->prepareSql(
                'SELECT `planet_id` FROM ' . PLANETS . '
                WHERE `planet_id` = ? AND `planet_user_id` = ? AND `planet_destroyed` = 0;'
            ),
            [$select, $ownerId]
        );

        if ($ownedRow === null) {
            return;
        }

        $this->userData['current_planet'] = $select;
        DB::statement(
            $this->prepareSql('UPDATE ' . USERS . ' SET `current_planet` = ? WHERE `id` = ?;'),
            [$select, $ownerId]
        );
    }

    private static function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
