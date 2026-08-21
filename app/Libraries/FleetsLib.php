<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Services\FormatService;
use App\Services\Game\Formulas\OfficerService;
use App\Services\TimingService;
use Xgp\App\Core\Enumerators\DefensesEnumerator as Defenses;
use Xgp\App\Core\Enumerators\MissionsEnumerator as Missions;
use Xgp\App\Core\Objects;
use Xgp\App\Core\Template;

/**
 * Presentation helpers for in-flight fleets (event rows, hover popups, coord
 * links) plus the fleet-array (de)serialisation. The numeric fleet maths that
 * the legacy class also carried now lives in the injected FleetsService, so
 * only the view-side helpers were ported here.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class FleetsLib
{
    /**
     * @param array<array-key, mixed> $fleetArray
     */
    public static function setFleetShipsArray(array $fleetArray): string
    {
        return serialize($fleetArray);
    }

    /**
     * Fleet arrays are plain ship-id => count maps; both sides are normalised
     * to int so the (legacy) callers can do arithmetic on the counts safely.
     *
     * @return array<int, int>
     */
    public static function getFleetShipsArray(string $fleetArray): array
    {
        // An empty fleet_array is a normal state (no ships); skip the unserialize
        // that would only warn on it.
        if ($fleetArray === '') {
            return [];
        }

        // Refuse any embedded objects so a tampered row cannot instantiate
        // classes on unserialize.
        $ships = unserialize($fleetArray, ['allowed_classes' => false]);

        if (!is_array($ships)) {
            return [];
        }

        $normalised = [];

        foreach ($ships as $shipId => $count) {
            $normalised[self::asInt($shipId)] = self::asInt($count);
        }

        return $normalised;
    }

    /**
     * @param array<string, mixed> $fleetRow
     */
    public static function startLink(array $fleetRow, string $fleetType): string
    {
        return self::coordLink(
            self::asInt($fleetRow['fleet_start_galaxy'] ?? 0),
            self::asInt($fleetRow['fleet_start_system'] ?? 0),
            self::asInt($fleetRow['fleet_start_planet'] ?? 0),
            $fleetType
        );
    }

    /**
     * @param array<string, mixed> $fleetRow
     */
    public static function targetLink(array $fleetRow, string $fleetType): string
    {
        return self::coordLink(
            self::asInt($fleetRow['fleet_end_galaxy'] ?? 0),
            self::asInt($fleetRow['fleet_end_system'] ?? 0),
            self::asInt($fleetRow['fleet_end_planet'] ?? 0),
            $fleetType
        );
    }

    /**
     * @param array<string, mixed> $fleetRow
     */
    public static function fleetResourcesPopup(array $fleetRow, string $text, string $fleetType): string
    {
        $format = app(FormatService::class);
        $total = self::asInt($fleetRow['fleet_resource_metal'] ?? 0)
            + self::asInt($fleetRow['fleet_resource_crystal'] ?? 0)
            + self::asInt($fleetRow['fleet_resource_deuterium'] ?? 0);

        if ($total === 0) {
            return $text;
        }

        $popup = Template::jsReady(Template::render('overview.fleets_popup', [
            'fleet_resource_metal' => $format->prettyNumber(self::asInt($fleetRow['fleet_resource_metal'] ?? 0)),
            'fleet_resource_crystal' => $format->prettyNumber(self::asInt($fleetRow['fleet_resource_crystal'] ?? 0)),
            'fleet_resource_deuterium' => $format->prettyNumber(self::asInt($fleetRow['fleet_resource_deuterium'] ?? 0)),
        ]));

        if ($popup === '') {
            return $text;
        }

        return "<a href='#' onmouseover=\"return overlib('" . strtr($popup, ['"' => ''])
            . '\');" onmouseout="return nd();" class="' . $fleetType . '">' . $text . '</a>';
    }

    /**
     * @param array<string, mixed> $fleetRow
     * @param array<string, mixed> $currentUser
     *
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     */
    public static function fleetShipsPopup(array $fleetRow, string $text, string $fleetType, array $currentUser): string
    {
        $objects = (array) Objects::getInstance()->getObjects();
        $ships = self::getFleetShipsArray(self::asString($fleetRow['fleet_array'] ?? ''));
        $isOwn = self::asInt($fleetRow['fleet_owner'] ?? 0) === self::asInt($currentUser['id'] ?? 0);
        $amount = self::asString($fleetRow['fleet_amount'] ?? '');

        $officer = app(OfficerService::class);
        $espionage = $officer->getMaxEspionage(
            self::asInt($currentUser['research_espionage_technology'] ?? 0),
            $officer->isOfficerActive(self::asInt($currentUser['premium_officier_technocrat'] ?? 0), time())
        );

        $body = '<table width=200>' . self::popupBody($ships, $objects, $isOwn, $espionage, $amount) . '</table>';

        return "<a href='#' onmouseover=\"return overlib('" . $body
            . "');\" onmouseout=\"return nd();\" class=\"" . $fleetType . '">' . $text . '</a>';
    }

    /**
     * @param array<int, int> $ships
     * @param array<int|string, mixed> $objects
     */
    private static function popupBody(array $ships, array $objects, bool $isOwn, int $espionage, string $amount): string
    {
        if (!$isOwn && $espionage < 2) {
            return '<tr><td width=50% align=left><font color=white>' . __('game/events.ev_no_fleet_data') . '</font></td></tr>';
        }

        if (!$isOwn && $espionage < 4) {
            return '<tr><td width=50% align=left><font color=white>' . __('game/events.ev_aproaching') . $amount . __('game/events.ev_ships') . '</font></td></tr>';
        }

        return self::shipsPopupRows($ships, $objects, $isOwn, $espionage, $amount);
    }

    /**
     * @param array<string, mixed> $fleet
     */
    public static function hasResources(array $fleet): bool
    {
        return self::asFloat($fleet['fleet_resource_metal'] ?? 0) !== 0.0
            || self::asFloat($fleet['fleet_resource_crystal'] ?? 0) !== 0.0
            || self::asFloat($fleet['fleet_resource_deuterium'] ?? 0) !== 0.0;
    }

    /**
     * @param array<string, mixed> $fleetRow
     */
    public static function enemyLink(array $fleetRow): string
    {
        $image = Functions::setImage(DPATH . '/img/m.gif');
        $link = app(FormatService::class)->link('game.php?page=chat&playerId=' . self::asInt($fleetRow['fleet_owner'] ?? 0), $image);

        return self::asString($fleetRow['start_planet_user'] ?? '') . ' ' . $link;
    }

    /**
     * @param array<string, mixed> $fleetRow
     * @param array<string, mixed> $currentUser
     *
     * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    public static function flyingFleetsTable(array $fleetRow, int $status, bool $owner, string $label, int $record, array $currentUser, bool $acsOwner = false): string
    {
        $fleetStyle = [
            1 => 'attack', 2 => 'federation', 3 => 'transport', 4 => 'deploy', 5 => 'hold',
            6 => 'espionage', 7 => 'colony', 8 => 'harvest', 9 => 'destroy', 10 => 'missile', 15 => 'transport',
        ];
        $missionType = self::asInt($fleetRow['fleet_mission'] ?? 0);
        $prefix = ($owner || $acsOwner) ? 'own' : '';
        $style = $prefix . ($fleetStyle[$missionType] ?? '');

        $content = $missionType === Missions::MISSILE
            ? ''
            : self::fleetShipsPopup($fleetRow, (string) __('game/events.ev_fleet'), $style, $currentUser);

        [$startId, $targetId] = self::routeLabels($fleetRow, $status, $missionType, $style);
        [$eventString, $time, $rest] = self::eventString($fleetRow, $status, $missionType, $owner, $content, $startId, $targetId, $style);

        $fleetStatus = [0 => 'flight', 1 => 'holding', 2 => 'return'];
        $reference = (string) $record;

        return Template::render('overview.fleets', [
            'fleet_status' => $fleetStatus[$status] ?? '',
            'fleet_prefix' => $prefix,
            'fleet_style' => $fleetStyle[$missionType] ?? '',
            'fleet_javai' => Functions::chronoApplet($label, $reference, $rest, true),
            'fleet_order' => $label . $reference,
            'fleet_descr' => $eventString,
            'fleet_javas' => Functions::chronoApplet($label, $reference, $rest, false),
            'fleet_time' => app(TimingService::class)->formatExtendedDate($time),
        ]);
    }

    /**
     * @param array<int|string, mixed> $ships
     * @param array<int|string, mixed> $objects
     */
    private static function shipsPopupRows(array $ships, array $objects, bool $isOwn, int $espionage, string $amount): string
    {
        $rows = '';

        if (!$isOwn) {
            $rows .= '<tr><td width=100% align=left><font color=white>' . __('game/events.ev_aproaching') . $amount . __('game/events.ev_ships') . ':</font></td></tr>';
        }

        foreach ($ships as $ship => $count) {
            $name = self::asString($objects[$ship] ?? '');
            $pretty = app(FormatService::class)->prettyNumber(self::asInt($count));

            if ($isOwn) {
                $rows .= '<tr><td width=50% align=left><font color=white>' . __('game/ships.' . $name)
                    . ':</font></td><td width=50% align=right><font color=white>' . $pretty . '</font></td></tr>';

                continue;
            }

            if ($espionage >= 4 && $espionage < 8) {
                $rows .= '<tr><td width=50% align=left><font color=white>' . __('game/ships.' . $name) . '</font></td></tr>';

                continue;
            }

            if ($espionage >= 8) {
                $rows .= '<tr><td width=50% align=left><font color=white>' . __('game/ships.' . $name)
                    . ':<font></td><td width=50% align=right><font color=white>' . $pretty . '</font></td></tr>';
            }
        }

        return $rows;
    }

    /**
     * Builds the "from … to …" label pair for a fleet row.
     *
     * @param array<string, mixed> $fleetRow
     *
     * @return array{0: string, 1: string}
     */
    private static function routeLabels(array $fleetRow, int $status, int $missionType, string $style): array
    {
        $startType = self::asInt($fleetRow['fleet_start_type'] ?? 0);
        $targetType = self::asInt($fleetRow['fleet_end_type'] ?? 0);
        $returning = $status === 2;

        $startId = self::originLabel($startType, $returning)
            . self::asString($fleetRow['start_planet_name'] ?? '') . ' '
            . self::startLink($fleetRow, $style);

        $targetLabel = $missionType === Missions::EXPEDITION
            ? (string) __($returning ? 'game/events.ev_from_position' : 'game/events.ev_the_position')
            : self::destinationLabel($targetType, $returning);

        $targetId = $targetLabel . self::asString($fleetRow['target_planet_name'] ?? '') . ' '
            . self::targetLink($fleetRow, $style);

        return [$startId, $targetId];
    }

    private static function originLabel(int $startType, bool $returning): string
    {
        return match (true) {
            $startType === 1 => (string) __($returning ? 'game/events.ev_to_the_planet' : 'game/events.ev_from_the_planet'),
            $startType === 3 => (string) __($returning ? 'game/events.ev_the_moon' : 'game/events.ev_from_the_moon'),
            default => '',
        };
    }

    private static function destinationLabel(int $targetType, bool $returning): string
    {
        if ($returning) {
            return match ($targetType) {
                1 => (string) __('game/events.ev_from_planet'),
                2 => (string) __('game/events.ev_from_debris_field'),
                3 => (string) __('game/events.ev_from_the_moon'),
                default => '',
            };
        }

        return match ($targetType) {
            1 => (string) __('game/events.ev_the_planet'),
            2 => (string) __('game/events.ev_debris_field'),
            3 => (string) __('game/events.ev_to_the_moon'),
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $fleetRow
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private static function eventString(array $fleetRow, int $status, int $missionType, bool $owner, string $content, string $startId, string $targetId, string $style): array
    {
        if ($missionType === Missions::MISSILE) {
            $missiles = self::getFleetShipsArray(self::asString($fleetRow['fleet_array'] ?? ''));
            $count = self::asInt($missiles[Defenses::defense_interplanetary_missile] ?? 0);
            $time = self::asInt($fleetRow['fleet_start_time'] ?? 0);

            $event = (string) __('game/events.ev_missile_attack') . ' ( ' . $count . ' ) '
                . $startId . __('game/events.ev_to') . $targetId . '.';

            return [$event, $time, $time - time()];
        }

        $event = $owner
            ? (string) __('game/events.ev_one_of_your') . $content
            : (string) __('game/events.ev_a') . $content . __('game/events.ev_of') . self::enemyLink($fleetRow);

        [$phrase, $time] = self::statusPhrase($fleetRow, $status, $startId, $targetId);
        $event .= $phrase;

        $missionTypes = (array) __('game/missions.type_mission');
        $event .= self::fleetResourcesPopup($fleetRow, self::asString($missionTypes[$missionType] ?? ''), $style);

        return [$event, $time, $time - time()];
    }

    /**
     * @param array<string, mixed> $fleetRow
     *
     * @return array{0: string, 1: int}
     */
    private static function statusPhrase(array $fleetRow, int $status, string $startId, string $targetId): array
    {
        $mission = (string) __('game/events.ev_with_the_mission_of');

        return match ($status) {
            0 => [
                (string) __('game/events.ev_goes') . $startId . __('game/events.ev_toward') . $targetId . $mission,
                self::asInt($fleetRow['fleet_start_time'] ?? 0),
            ],
            1 => [
                (string) __('game/events.ev_goes') . $startId . __('game/events.ev_to_explore') . $targetId . $mission,
                self::asInt($fleetRow['fleet_end_stay'] ?? 0),
            ],
            2 => [
                (string) __('game/events.ev_comming_back') . $targetId . $startId . $mission,
                self::asInt($fleetRow['fleet_end_time'] ?? 0),
            ],
            default => ['', 0],
        };
    }

    private static function coordLink(int $galaxy, int $system, int $planet, string $fleetType): string
    {
        $format = app(FormatService::class);
        $link = 'game.php?page=galaxy&mode=3&galaxy=' . $galaxy . '&system=' . $system;

        return $format->link($link, $format->prettyCoords($galaxy, $system, $planet), '', $fleetType);
    }

    private static function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function asFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
