<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Services\FormatService;
use App\Services\Game\Formulas\FleetsService;
use Xgp\App\Core\Enumerators\MissionsEnumerator as Missions;
use Xgp\App\Core\Enumerators\PlanetTypesEnumerator;
use Xgp\App\Core\Enumerators\ShipsEnumerator as Ships;
use Xgp\App\Core\Enumerators\UserRanksEnumerator as UserRanks;
use Xgp\App\Core\Objects;
use Xgp\App\Core\Template;
use Xgp\App\Helpers\StringsHelper;
use Xgp\App\Helpers\UrlHelper;
use Xgp\App\Libraries\Functions;

/**
 * Renders a single galaxy-view row (planet / moon / debris / player columns and
 * the per-target action links). Ported from the legacy class; the untyped game
 * state is read through mixed-safe converters and the HTML output is unchanged.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class GalaxyLib
{
    public const PLANET_TYPE = 1;
    public const DEBRIS_TYPE = 2;
    public const MOON_TYPE = 3;

    /** @var array<string, mixed> */
    private array $rowData = [];

    private int $planet = 0;

    /** @var array<int|string, string> */
    private array $resource;

    /** @var array<int, array<string, mixed>> */
    private array $pricelist;

    private NoobsProtectionLib $noob;

    private bool $noPopup = false;

    private FormatService $formatService;

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $currentPlanet
     */
    public function __construct(
        private array $user = [],
        private array $currentPlanet = [],
        private int $galaxy = 0,
        private int $system = 0,
    ) {
        /** @var array<int|string, string> $resource */
        $resource = (array) Objects::getInstance()->getObjects();
        $this->resource = $resource;
        /** @var array<int, array<string, mixed>> $pricelist */
        $pricelist = (array) Objects::getInstance()->getPrice();
        $this->pricelist = $pricelist;
        $this->noob = new NoobsProtectionLib();
        $this->formatService = app(FormatService::class);
    }

    /**
     * @param array<string, mixed> $rowData
     *
     * @return array<string, mixed>
     */
    public function buildRow(array $rowData, int $planet): array
    {
        $this->rowData = $rowData;
        $this->planet = $planet;

        $debrisBlock = $this->debrisBlock();

        $row = [
            'pos' => $planet,
            'planet' => '',
            'planetname' => $this->planetNameBlock(),
            'moon' => '',
            'debris' => is_array($debrisBlock) && $debrisBlock !== [] ? Template::render('galaxy.galaxy_debris_block', $debrisBlock) : '',
            'username' => '',
            'alliance' => '',
            'actions' => '',
        ];

        if (self::asInt($rowData['planet_destroyed'] ?? 0) === 0) {
            $moonBlock = $this->moonBlock();
            $userBlock = $this->usernameBlock();

            $row['planet'] = Template::render('galaxy.galaxy_planet_block', $this->planetBlock());
            $row['moon'] = $moonBlock !== [] ? Template::render('galaxy.galaxy_moon_block', $moonBlock) : '';
            $row['username'] = $this->noPopup ? ($userBlock['status'] ?? '') : Template::render('galaxy.galaxy_username_block', $userBlock);
            $row['alliance'] = Template::render('galaxy.galaxy_alliance_block', $this->allyBlock());
            $row['actions'] = $this->actionsBlock();
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function planetBlock(): array
    {
        // Order matches the legacy $action key declaration, which drives the
        // order the links are concatenated in.
        $links = $this->collectLinks([
            'spy' => !$this->isCurrentPlayer() ? $this->spyLink(self::PLANET_TYPE) : '',
            'phalanx' => $this->isPhalanxActive() ? $this->phalanxLink(self::PLANET_TYPE) : '',
            'attack' => !$this->isCurrentPlayer() ? $this->attackLink(self::PLANET_TYPE) : '',
            'hold_position' => !$this->isCurrentPlayer() && $this->isFriendly() ? $this->holdPositionLink(self::PLANET_TYPE) : '',
            'deploy' => $this->isCurrentPlayer() ? $this->deployLink(self::PLANET_TYPE) : '',
            'transport' => $this->transportLink(self::PLANET_TYPE),
            'missile' => $this->isMissileActive() ? $this->missileLink() : '',
        ]);

        $parse = [
            'name' => self::asString($this->rowData['planet_name'] ?? ''),
            'galaxy' => $this->galaxy,
            'system' => $this->system,
            'planet' => $this->planet,
            'image' => $this->imagePath('planets/small/s_' . self::asString($this->rowData['planet_image'] ?? '') . '.jpg'),
            'links' => $links,
        ];

        return $this->applyLinkOverrides($parse, self::PLANET_TYPE);
    }

    private function planetNameBlock(): string
    {
        if (self::asInt($this->rowData['planet_destroyed'] ?? 0) !== 0) {
            return (string) __('game/galaxy.gl_planet_destroyed');
        }

        $name = self::asString($this->rowData['planet_name'] ?? '');
        $planetName = stripslashes($name);

        if ($this->isPhalanxActive()) {
            $attributes = "onclick=fenster('game.php?page=phalanx&galaxy=" . $this->galaxy .
                '&amp;system=' . $this->system . '&amp;planet=' . $this->planet .
                '&amp;planettype=' . self::PLANET_TYPE . "')";
            $planetName = $this->formatService->link('', $name, 'Phalanx', $attributes);
        }

        $lastUpdate = self::asInt($this->rowData['planet_last_update'] ?? 0);

        if ($lastUpdate > (time() - 59 * 60) && !$this->isCurrentPlayer()) {
            $planetName .= $lastUpdate > (time() - 10 * 60)
                ? '(*)'
                : ' (' . $this->formatService->prettyTimeHour(time() - $lastUpdate) . ')';
        }

        return $planetName;
    }

    /**
     * @return array<string, mixed>
     */
    private function moonBlock(): array
    {
        if (self::asInt($this->rowData['destroyed_moon'] ?? 0) !== 0 || self::asInt($this->rowData['id_luna'] ?? 0) === 0) {
            return [];
        }

        $deathStars = self::asInt($this->currentPlanet[$this->resource[214] ?? ''] ?? 0);
        // Order matches the legacy $action key declaration.
        $links = $this->collectLinks([
            'spy' => !$this->isCurrentPlayer() ? $this->spyLink(self::MOON_TYPE) : '',
            'attack' => !$this->isCurrentPlayer() ? $this->attackLink(self::MOON_TYPE) : '',
            'transport' => $this->transportLink(self::MOON_TYPE),
            'deploy' => $this->isCurrentPlayer() ? $this->deployLink(self::MOON_TYPE) : '',
            'hold_position' => !$this->isCurrentPlayer() && $this->isFriendly() ? $this->holdPositionLink(self::MOON_TYPE) : '',
            'destroy' => !$this->isCurrentPlayer() && $deathStars > 0 ? $this->destroyLink(self::MOON_TYPE) : '',
        ]);

        $parse = [
            'name_moon' => self::asString($this->rowData['name_moon'] ?? ''),
            'galaxy' => $this->galaxy,
            'system' => $this->system,
            'planet' => $this->planet,
            'image' => $this->imagePath('planets/small/s_mond.jpg'),
            'planet_diameter' => $this->formatService->prettyNumber(self::asInt($this->rowData['planet_diameter'] ?? 0)),
            'links' => $links,
        ];

        return $this->applyLinkOverrides($parse, self::MOON_TYPE);
    }

    /**
     * @return array<string, mixed>|string
     */
    private function debrisBlock(): array | string
    {
        $debris = self::asFloat($this->rowData['metal'] ?? 0) + self::asFloat($this->rowData['crystal'] ?? 0);

        if ($debris < DEBRIS_MIN_VISIBLE_SIZE) {
            return '';
        }

        $recyclerStorage = app(FleetsService::class)->getMaxStorage(
            self::asInt($this->pricelist[Ships::ship_recycler]['capacity'] ?? 0),
            self::asInt($this->user['research_hyperspace_technology'] ?? 0)
        );
        $recyclerStorage = $recyclerStorage === 0 ? 1 : $recyclerStorage;

        $recyclersNeeded = (int) ceil($debris / $recyclerStorage);
        $ownRecyclers = self::asInt($this->currentPlanet['ship_recycler'] ?? 0);
        $recyclersSent = $recyclersNeeded < $ownRecyclers ? $recyclersNeeded : $ownRecyclers;

        return [
            'galaxy' => $this->galaxy,
            'system' => $this->system,
            'planet' => $this->planet,
            'image' => $this->imagePath('planets/debris.jpg'),
            'planettype' => self::DEBRIS_TYPE,
            'recsended' => $recyclersSent,
            'planet_debris_metal' => $this->formatService->prettyNumber(self::asInt($this->rowData['metal'] ?? 0)),
            'planet_debris_crystal' => $this->formatService->prettyNumber(self::asInt($this->rowData['crystal'] ?? 0)),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     */
    private function usernameBlock(): array
    {
        $this->noPopup = false;
        $name = self::asString($this->rowData['name'] ?? '');

        if ($this->isCurrentPlayer()) {
            $this->noPopup = true;

            return ['status' => $name];
        }

        $statuses = $this->userStatuses();
        $username = '';
        $userStatus = [];

        foreach ($statuses as $details) {
            if ($username === '') {
                $username = $this->formatService->spanClassElement($name, $details['class']);
            }

            $userStatus[] = $this->formatService->spanClassElement($details['shortcut'], $details['class']);
        }

        $formattedUsername = $userStatus !== []
            ? StringsHelper::parseReplacements('%s (%s)', [$username, join(' ', $userStatus)])
            : null;

        $rank = self::asInt($this->rowData['user_statistic_total_rank'] ?? 0);

        return [
            'status' => $formattedUsername ?? $name,
            'username' => $name,
            'current_rank' => $rank,
            'start' => ((int) floor($rank / 100) * 100) + 1,
            'actions' => $this->usernameActions(),
        ];
    }

    /**
     * The player-status abbreviations (admin / banned / vacation / …) for the
     * hover popup.
     *
     * @return array<string, array{class: string, shortcut: string}>
     *
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     */
    private function userStatuses(): array
    {
        $statuses = [];
        $onlineTime = self::asInt($this->rowData['onlinetime'] ?? 0);

        if (self::asInt($this->rowData['authlevel'] ?? 0) >= UserRanks::GO) {
            $this->noPopup = true;
            $statuses['admin'] = $this->status('a', 'gl_a');
        }

        if (!empty($this->rowData['banned'])) {
            $statuses['banned'] = $this->status('b', 'gl_b');
        }

        if (self::asInt($this->rowData['preference_vacation_mode'] ?? 0) > 0) {
            $statuses['vacation'] = $this->status('v', 'gl_v');
        }

        if ($onlineTime < (time() - ONE_WEEK)) {
            $statuses['inactive'] = $this->status('i', 'gl_i');
        }

        if ($onlineTime < (time() - ONE_DAY * 28)) {
            $statuses['inactive'] = $this->status('I', 'gl_I');
        }

        if (!isset($statuses['admin']) && !isset($statuses['banned'])) {
            $ownPoints = self::asInt($this->user['user_statistic_total_points'] ?? 0);
            $rowPoints = self::asInt($this->rowData['user_statistic_total_points'] ?? 0);

            if ($this->noob->isWeak($ownPoints, $rowPoints)) {
                $statuses['protection'] = $this->status('w', 'gl_w');
            }

            if ($this->noob->isStrong($ownPoints, $rowPoints)) {
                $statuses['protection'] = $this->status('s', 'gl_s');
            }
        }

        return $statuses;
    }

    /**
     * @return array{class: string, shortcut: string}
     */
    private function status(string $key, string $langKey): array
    {
        return [
            'class' => $this->getUserStatusClass($key),
            'shortcut' => (string) __('game/galaxy.' . $langKey),
        ];
    }

    private function usernameActions(): string
    {
        $id = self::asInt($this->rowData['id'] ?? 0);

        $actions = '<td>';
        $actions .= str_replace('"', '\\\'', $this->formatService->link(
            'game.php?page=chat&playerId=' . $id,
            (string) __('game/global.write_message')
        ));
        $actions .= '</td></tr><tr><td>';
        $actions .= str_replace('"', '\\\'', $this->formatService->link(
            'game.php?page=buddies&mode=2&u=' . $id,
            (string) __('game/galaxy.gl_buddy_request')
        ));
        $actions .= '</td></tr><tr>';

        return $actions;
    }

    /**
     * @return array<string, mixed>
     */
    private function allyBlock(): array
    {
        $parse = [
            'alliance_name' => '',
            'ally_members' => '',
            'add' => '',
            'ally_id' => '',
            'web' => '',
            'tag' => '',
        ];

        if (self::asInt($this->rowData['ally_id'] ?? 0) === 0) {
            return $parse;
        }

        $parse['alliance_name'] = str_replace("'", "\'", htmlspecialchars(self::asString($this->rowData['alliance_name'] ?? ''), ENT_COMPAT));
        $parse['ally_members'] = self::asInt($this->rowData['ally_members'] ?? 0);
        $parse['add'] = self::asInt($this->rowData['ally_members'] ?? 0) > 1 ? (string) __('game/galaxy.gl_member_add') : '';
        $parse['ally_id'] = self::asInt($this->rowData['ally_id'] ?? 0);

        if (self::asString($this->rowData['alliance_web'] ?? '') !== '') {
            $webUrl = $this->formatService->link(
                UrlHelper::prepUrl(self::asString($this->rowData['alliance_web'] ?? '')),
                (string) __('game/galaxy.gl_alliance_web_page'),
                '',
                'target="_new"'
            );
            $parse['web'] = '</tr><tr><td>' . str_replace('"', '\\\'', $webUrl) . '</td>';
        }

        $tag = self::asString($this->rowData['alliance_tag'] ?? '');
        $parse['tag'] = self::asInt($this->user['ally_id'] ?? 0) === self::asInt($this->rowData['ally_id'] ?? 0)
            ? '<span class="allymember">' . $tag . '</span>'
            : $tag;

        return $parse;
    }

    private function actionsBlock(): string
    {
        if ($this->isCurrentPlayer()) {
            return '';
        }

        $id = self::asInt($this->rowData['id'] ?? 0);
        $actions = [
            'spy' => ['image' => Functions::setImage(DPATH . 'img/e.gif', (string) __('game/galaxy.gl_spy')), 'attributes' => $this->spyActionAttributes(self::PLANET_TYPE)],
            'write' => ['image' => Functions::setImage(DPATH . 'img/m.gif', (string) __('game/global.write_message')), 'url' => 'game.php?page=chat&playerId=' . $id],
            'buddy' => ['image' => Functions::setImage(DPATH . 'img/b.gif', (string) __('game/galaxy.gl_buddy_request')), 'url' => 'game.php?page=buddies&mode=2&u=' . $id],
            'missile' => ['image' => Functions::setImage(DPATH . 'img/r.gif', (string) __('game/galaxy.gl_missile_attack')), 'url' => 'game.php?page=galaxy&mode=2&galaxy=' . $this->galaxy . '&system=' . $this->system . '&planet=' . $this->planet . '&current=' . self::asInt($this->user['current_planet'] ?? 0)],
        ];

        $available = ['spy', 'write', 'buddy'];

        if ($this->isMissileActive()) {
            $available[] = 'missile';
        }

        if (self::asInt($this->rowData['authlevel'] ?? 0) >= UserRanks::GO) {
            $available = ['write'];
        }

        if (self::asInt($this->rowData['preference_vacation_mode'] ?? 0) > 0) {
            $available = ['write', 'buddy'];
        }

        $links = [];

        foreach ($available as $action) {
            $links[] = isset($actions[$action]['url'])
                ? $this->formatService->link($actions[$action]['url'], $actions[$action]['image'])
                : $this->formatService->link('', $actions[$action]['image'], '', $actions[$action]['attributes']);
        }

        return join('&nbsp;', $links);
    }

    private function attackLink(int $planetType): string
    {
        return $this->fleetLink($planetType, 1, Missions::ATTACK, true);
    }

    private function transportLink(int $planetType): string
    {
        return $this->fleetLink($planetType, 3, Missions::TRANSPORT, false);
    }

    private function deployLink(int $planetType): string
    {
        return $this->fleetLink($planetType, 4, Missions::DEPLOY, false);
    }

    private function holdPositionLink(int $planetType): string
    {
        return $this->fleetLink($planetType, 5, Missions::STAY, false);
    }

    private function destroyLink(int $planetType): string
    {
        return $this->fleetLink($planetType, 9, Missions::DESTROY, false);
    }

    private function spyLink(int $planetType): string
    {
        $attributes = $this->spyActionAttributes($planetType);

        return str_replace('"', '\\\'', $this->formatService->link('', $this->missionName(Missions::SPY), '', $attributes));
    }

    private function spyActionAttributes(int $planetType): string
    {
        return 'onclick="javascript:doit(6, ' . $this->galaxy . ', ' . $this->system . ', ' . $this->planet . ', ' . $planetType . ', ' . self::asInt($this->user['preference_spy_probes'] ?? 0) . '); return false;"';
    }

    private function missileLink(): string
    {
        $url = 'game.php?page=galaxy&mode=2&galaxy=' . $this->galaxy . '&system=' . $this->system . '&planet=' .
            $this->planet . '&current=' . self::asInt($this->user['current_planet'] ?? 0);

        return str_replace('"', '\\\'', $this->formatService->link($url, (string) __('game/galaxy.gl_missile_attack')));
    }

    private function phalanxLink(int $planetType): string
    {
        $attributes = 'onclick=fenster(&#039;game.php?page=phalanx&galaxy=' . $this->galaxy . '&amp;system=' .
            $this->system . '&amp;planet=' . $this->planet . '&amp;planettype=' . $planetType . '&#039;)';

        return str_replace('"', '\\\'', $this->formatService->link('', (string) __('game/galaxy.gl_phalanx'), '', $attributes));
    }

    /**
     * Builds a fleet-dispatch link. The `amp;` separator style differs between
     * attack/destroy and the others, so it is preserved verbatim.
     */
    private function fleetLink(int $planetType, int $mission, int $missionName, bool $ampStyle): string
    {
        $sep = $ampStyle ? '&amp;' : '&';
        $url = 'game.php?page=fleet1&galaxy=' . $this->galaxy . $sep . 'system=' . $this->system . $sep . 'planet=' .
            $this->planet . $sep . 'planettype=' . $planetType . $sep . 'target_mission=' . $mission;

        return str_replace('"', '\\\'', $this->formatService->link($url, $this->missionName($missionName)));
    }

    private function missionName(int $mission): string
    {
        $names = (array) __('game/missions.type_mission');

        return self::asString($names[$mission] ?? '');
    }

    private function isFriendly(): bool
    {
        if (isset($this->rowData['buddys'])) {
            $friends = explode(',', self::asString($this->rowData['buddys']));

            if (in_array((string) self::asInt($this->rowData['id'] ?? 0), $friends, true)) {
                return true;
            }
        }

        $rowAlly = self::asInt($this->rowData['ally_id'] ?? 0);
        $ownAlly = self::asInt($this->user['ally_id'] ?? 0);

        return !(($rowAlly === 0 && $ownAlly === 0) || ($rowAlly !== $ownAlly));
    }

    private function isMissileActive(): bool
    {
        if (self::asInt($this->currentPlanet['defense_interplanetary_missile'] ?? 0) !== 0
            && !$this->isCurrentPlayer()
            && self::asInt($this->rowData['planet_galaxy'] ?? 0) === self::asInt($this->currentPlanet['planet_galaxy'] ?? 0)) {
            return $this->isInRange(Formulas::missileRange(self::asInt($this->user['research_impulse_drive'] ?? 0)));
        }

        return false;
    }

    private function isPhalanxActive(): bool
    {
        if (self::asInt($this->currentPlanet['building_phalanx'] ?? 0) !== 0
            && !$this->isCurrentPlayer()
            && self::asInt($this->rowData['planet_galaxy'] ?? 0) === self::asInt($this->currentPlanet['planet_galaxy'] ?? 0)
            && self::asInt($this->currentPlanet['planet_type'] ?? 0) === PlanetTypesEnumerator::MOON) {
            return $this->isInRange(Formulas::phalanxRange(self::asInt($this->currentPlanet['building_phalanx'] ?? 0)));
        }

        return false;
    }

    private function isInRange(int $range): bool
    {
        $currentSystem = self::asInt($this->currentPlanet['planet_system'] ?? 0);
        $minSystem = max($currentSystem - $range, 1);
        $maxSystem = min($currentSystem + $range, MAX_SYSTEM_IN_GALAXY);

        return $this->system <= $maxSystem && $this->system >= $minSystem;
    }

    private function getUserStatusClass(string $status): string
    {
        return [
            'a' => 'status_abbr_admin',
            's' => 'status_abbr_strong',
            'w' => 'status_abbr_noob',
            'o' => 'status_abbr_outlaw',
            'v' => 'status_abbr_vacation',
            'b' => 'status_abbr_banned',
            'i' => 'status_abbr_inactive',
            'I' => 'status_abbr_longinactive',
            'hp' => 'status_abbr_honorableTarget',
        ][$status] ?? '';
    }

    private function isCurrentPlayer(): bool
    {
        return self::asInt($this->rowData['id'] ?? 0) === self::asInt($this->user['id'] ?? 0);
    }

    /**
     * @param array<string, string> $actions
     */
    private function collectLinks(array $actions): string
    {
        $links = '';

        foreach ($actions as $action) {
            if ($action !== '') {
                $links .= $action . '<br>';
            }
        }

        return $links;
    }

    /**
     * Applies the "no action / GO / vacation" overrides that the planet and moon
     * blocks share.
     *
     * @param array<string, mixed> $parse
     *
     * @return array<string, mixed>
     */
    private function applyLinkOverrides(array $parse, int $planetType): array
    {
        $this->rowData['planet_type'] = $planetType;

        if (Functions::isCurrentPlanet($this->currentPlanet, $this->rowData)) {
            $parse['links'] = (string) __('game/galaxy.gl_no_action');
        }

        if (self::asInt($this->rowData['authlevel'] ?? 0) >= UserRanks::GO && !$this->isCurrentPlayer()) {
            $parse['links'] = $this->transportLink(self::PLANET_TYPE);
        }

        if (self::asInt($this->rowData['preference_vacation_mode'] ?? 0) > 0) {
            $parse['links'] = (string) __('game/galaxy.gl_player_vacation_mode');
        }

        return $parse;
    }

    private function imagePath(string $suffix): string
    {
        return strtr(DPATH, ['\\' => '/']) . $suffix;
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
