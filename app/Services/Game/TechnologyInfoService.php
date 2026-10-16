<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Core\GameObjects\Defense;
use App\Core\GameObjects\GameObjectRegistry;
use App\Core\GameObjects\Ship;
use App\Libraries\Users;
use App\Services\FormatService;
use App\Support\Concerns\ReadsMixedValues;
use InvalidArgumentException;
use App\Core\Enumerators\BuildingsEnumerator as Buildings;

/**
 * The technology details page of a building, research, ship or defense:
 * description, combat values and rapid fire, the level table, the jump
 * gate and the tear down costs.
 *
 * @phpstan-import-type JumpGateSection from JumpGateService
 */
class TechnologyInfoService
{
    use ReadsMixedValues;

    /** @var array<string, mixed> */
    private array $user = [];

    /** @var array<string, mixed> */
    private array $planet = [];

    private int $elementId = 0;

    public function __construct(
        private GameObjectRegistry $registry,
        private FormatService $formatService,
        private TechnologyTableService $tables,
        private TearDownService $tearDown,
        private JumpGateService $jumpGate,
    ) {
    }

    /**
     * @param array<string, mixed>|null $userData
     * @param array<string, mixed>|null $planetData
     *
     * @return array{summary: array<string, mixed>, detailTable: array<string, mixed>|null, jumpGate: JumpGateSection|null, tearDown: array<string, mixed>|null}
     */
    public function buildViewData(int $elementId, ?array $userData = null, ?array $planetData = null): array
    {
        $this->initializeContext($elementId, $userData, $planetData);

        return [
            'summary' => $this->buildSummarySection(),
            'detailTable' => $this->tables->table($elementId, $this->user, $this->planet),
            'jumpGate' => $elementId === Buildings::BUILDING_JUMP_GATE ? $this->jumpGate->section($this->user, $this->planet) : null,
            'tearDown' => $this->tearDown->section($elementId, $this->getElementName($elementId), $this->user, $this->planet),
        ];
    }

    /**
     * @param array<int, int> $ships
     * @param array<string, mixed>|null $userData
     * @param array<string, mixed>|null $planetData
     *
     * @return array{message: string, color: string}
     */
    public function handleJumpGate(int $elementId, int $targetPlanet, array $ships, ?array $userData = null, ?array $planetData = null): array
    {
        $this->initializeContext($elementId, $userData, $planetData);
        $result = $elementId === Buildings::BUILDING_JUMP_GATE
            ? $this->jumpGate->jump($this->user, $this->planet, $targetPlanet, $ships)
            : ['success' => false, 'message' => self::asString(__('game/technologydetails.in_jump_gate_error_data'))];

        return [
            'message' => $result['message'],
            'color' => $result['success'] ? 'lime' : 'red',
        ];
    }

    /**
     * @param array<string, mixed>|null $userData
     * @param array<string, mixed>|null $planetData
     */
    private function initializeContext(int $elementId, ?array $userData, ?array $planetData): void
    {
        if (!$this->registry->has($elementId)) {
            throw new InvalidArgumentException("Technology info requested for unknown object ID {$elementId}.");
        }

        $this->user = $userData ?? Users::getInstance()->getUserData();
        $this->planet = $planetData ?? Users::getInstance()->getPlanetData();

        $this->elementId = $elementId;
    }

    /**
     * Rapid fire of a ship or defense: target id => shots.
     *
     * @return array<int, int>
     */
    private function rapidFire(int $elementId): array
    {
        $object = $this->registry->has($elementId) ? $this->registry->get($elementId) : null;

        return $object instanceof Ship || $object instanceof Defense ? $object->getRapidFire()->all() : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSummarySection(): array
    {
        if ($this->elementId >= 202 && $this->elementId <= 250) {
            return $this->buildCombatSummary(true);
        }

        if ($this->elementId >= 401 && $this->elementId <= 550) {
            return $this->buildCombatSummary(false);
        }

        return [
            'type' => 'generic',
            'title' => $this->getElementName($this->elementId),
            'imageId' => $this->elementId,
            'description' => $this->getElementDescription($this->elementId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCombatSummary(bool $isShip): array
    {
        $rapidFireItems = [];

        if ($isShip) {
            $rapidFireItems = array_merge($this->buildRapidFireFromItems(), $this->buildRapidFireAgainstItems());
        } elseif ($this->elementId < 500) {
            $rapidFireItems = array_merge($this->buildRapidFireAgainstItems(), $this->buildRapidFireFromItems());
        }

        $object = $this->registry->get($this->elementId);
        $price = $object->getPrice();
        $isUnit = $object instanceof Ship || $object instanceof Defense;

        $stats = [
            [
                'label' => __('game/technologydetails.in_struct_pt'),
                'value' => $this->formatService->prettyNumber($price->getMetal() + $price->getCrystal()),
            ],
            [
                'label' => __('game/technologydetails.in_shield_pt'),
                'value' => $this->formatService->prettyNumber($isUnit ? (int) $object->getShield() : 0),
            ],
            [
                'label' => __('game/technologydetails.in_attack_pt'),
                'value' => $this->formatService->prettyNumber($isUnit ? (int) $object->getAttack() : 0),
            ],
        ];

        if ($isShip && $object instanceof Ship) {
            $stats = [...$stats, ...$this->shipStats($object)];
        }

        return [
            'type' => 'combat',
            'title' => __('game/technologydetails.in_title_head') . ' ' . ($isShip ? __('game/ships.ships') : __('game/defenses.defenses')),
            'nameLabel' => __('game/technologydetails.in_name'),
            'name' => $this->getElementName($this->elementId),
            'imageId' => $this->elementId,
            'description' => $this->getElementDescription($this->elementId),
            'rapidFireItems' => $rapidFireItems,
            'stats' => $stats,
        ];
    }

    /**
     * Speed, capacity and consumption; in yellow the values after the drive
     * upgrade of the small cargo (speed and consumption) and the bomber (speed).
     *
     * @return list<array{label: string, value: string}>
     */
    private function shipStats(Ship $ship): array
    {
        $updatedSpeed = '';
        $updatedConsumption = '';

        if ($this->elementId === 202) {
            $updatedSpeed = ' <font color="yellow">(' . $this->formatService->prettyNumber($ship->getSpeed2()) . ')</font>';
            $updatedConsumption = ' <font color="yellow">(' . $this->formatService->prettyNumber($ship->getConsumption2()) . ')</font>';
        } elseif ($this->elementId === 211) {
            $updatedSpeed = ' <font color="yellow">(' . $this->formatService->prettyNumber($ship->getSpeed2()) . ')</font>';
        }

        return [
            [
                'label' => self::asString(__('game/technologydetails.in_base_speed')),
                'value' => $this->formatService->prettyNumber($ship->getSpeed()) . $updatedSpeed,
            ],
            [
                'label' => self::asString(__('game/technologydetails.in_capacity')),
                'value' => $this->formatService->prettyNumber($ship->getCapacity()) . '&nbsp;' . self::asString(__('game/technologydetails.in_units')),
            ],
            [
                'label' => self::asString(__('game/technologydetails.in_consumption')),
                'value' => $this->formatService->prettyNumber($ship->getConsumption()) . $updatedConsumption,
            ],
        ];
    }

    /**
     * @return array<int, array{label: string, chance: string, shots: string, color: string}>
     */
    private function buildRapidFireAgainstItems(): array
    {
        $items = [];

        for ($type = 200; $type < 500; $type++) {
            $shots = $this->rapidFire($this->elementId)[$type] ?? 0;

            if ($shots <= 1) {
                continue;
            }

            $items[] = $this->buildRapidFireItem(__('game/technologydetails.in_rf_again'), $type, $shots, '#00ff00');
        }

        return $items;
    }

    /**
     * @return array<int, array{label: string, chance: string, shots: string, color: string}>
     */
    private function buildRapidFireFromItems(): array
    {
        $items = [];

        for ($type = 200; $type < 500; $type++) {
            $shots = $this->rapidFire($type)[$this->elementId] ?? 0;

            if ($shots <= 1) {
                continue;
            }

            $items[] = $this->buildRapidFireItem(__('game/technologydetails.in_rf_from'), $type, $shots, '#ff0000');
        }

        return $items;
    }

    /**
     * @return array{label: string, chance: string, shots: string, color: string}
     */
    private function buildRapidFireItem(string $prefix, int $type, int $shots, string $color): array
    {
        return [
            'label' => $prefix . ' ' . $this->getElementName($type),
            'chance' => $this->formatRapidFireChance($shots),
            'shots' => $this->formatService->prettyNumber($shots),
            'color' => $color,
        ];
    }

    private function formatRapidFireChance(int $shots): string
    {
        $chance = (($shots - 1) / $shots) * 100;

        return rtrim(rtrim($this->formatService->floatToString($chance, 2, true), '0'), '.');
    }

    private function getElementName(int $elementId): string
    {
        if ($elementId >= 601) {
            $officiers = __('game/officier.officiers');

            return is_array($officiers) ? ($officiers[$elementId]['name'] ?? '') : '';
        }

        $resourceKey = $this->registry->has($elementId) ? $this->registry->get($elementId)->getName() : '';

        if ($resourceKey === '') {
            return '';
        }

        return (string) __('game/' . $this->getElementTranslationGroup($elementId) . '.' . $resourceKey);
    }

    private function getElementDescription(int $elementId): string
    {
        if ($elementId >= 601) {
            $officiers = __('game/officier.officiers');

            return is_array($officiers) ? ($officiers[$elementId]['description'] ?? '') : '';
        }

        $resourceKey = $this->registry->has($elementId) ? $this->registry->get($elementId)->getName() : '';

        if ($resourceKey === '') {
            return '';
        }

        $infoTranslations = __('game/technologydetails.info');

        return is_array($infoTranslations) ? ($infoTranslations[$resourceKey] ?? '') : '';
    }

    private function getElementTranslationGroup(int $elementId): string
    {
        return match (true) {
            $elementId < 100 => 'constructions',
            $elementId < 200 => 'technologies',
            $elementId < 400 => 'ships',
            default => 'defenses',
        };
    }
}
