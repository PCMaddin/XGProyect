<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Core\GameObjects\GameObjectRegistry;
use App\Services\FormatService;
use App\Services\Game\Formulas\DevelopmentsService;
use App\Services\Game\Formulas\FormulasService;
use App\Support\Concerns\ReadsMixedValues;
use App\Core\Enumerators\BuildingsEnumerator as Buildings;
use App\Core\Enumerators\ResearchEnumerator as Research;
use App\Helpers\StringsHelper;

/**
 * The tear down part of the technology details of a building.
 */
class TearDownService
{
    use ReadsMixedValues;

    public function __construct(
        private GameObjectRegistry $registry,
        private FormatService $formatService,
        private DevelopmentsService $developmentsService,
        private FormulasService $formulasService,
    ) {
    }

    /**
     * What tearing down one level costs and takes; null for research,
     * units, the terraformer and the moon base, and for buildings not built.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     *
     * @return array<string, mixed>|null
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function section(int $elementId, string $elementName, array $user, array $planet): ?array
    {
        if ($elementId >= 100 || in_array($elementId, [33, 41], true)) {
            return null;
        }

        $resourceKey = $this->registry->get($elementId)->getName();

        if (self::asInt($planet[$resourceKey] ?? 0) <= 0) {
            return null;
        }

        $techBonus = '';
        $ionTechnologyLevel = self::asInt($user[$this->registry->get(Research::RESEARCH_IONIC_TECHNOLOGY)->getName()] ?? 0);
        $ionTechnologyPercentage = $this->formulasService->getIonTechnologyBonus($ionTechnologyLevel) * 100;

        if ($ionTechnologyPercentage > 0) {
            $techBonus = StringsHelper::parseReplacements(
                __('game/technologydetails.in_ion_tech_bonus'),
                [$this->formatService->colorGreen('-' . $ionTechnologyPercentage . '%')]
            );
        }

        $tearDownResources = $this->developmentsService->developmentPrice(
            $elementId,
            self::asInt($planet[$resourceKey] ?? 0),
            true,
            true,
            $ionTechnologyLevel
        );
        $tearDownTime = $this->developmentsService->tearDownTime(
            $elementId,
            self::asInt($planet[$resourceKey] ?? 0),
            self::asInt($planet[$this->registry->get(Buildings::BUILDING_ROBOT_FACTORY)->getName()] ?? 0),
            self::asInt($planet[$this->registry->get(Buildings::BUILDING_NANO_FACTORY)->getName()] ?? 0)
        );

        return [
            'url' => 'game.php?page=' . $this->developmentsService->setBuildingPage($elementId) . '&cmd=destroy&building=' . $elementId,
            'label' => StringsHelper::parseReplacements(
                __('game/technologydetails.in_destroy'),
                [$elementName]
            ),
            'ionBonusHtml' => $techBonus,
            'resources' => [
                ['label' => __('game/global.metal'), 'value' => $this->formatService->prettyNumber((int) ($tearDownResources['metal'] ?? 0))],
                ['label' => __('game/global.crystal'), 'value' => $this->formatService->prettyNumber((int) ($tearDownResources['crystal'] ?? 0))],
                ['label' => __('game/global.deuterium'), 'value' => $this->formatService->prettyNumber((int) ($tearDownResources['deuterium'] ?? 0))],
            ],
            'durationLabel' => __('game/technologydetails.in_dest_durati'),
            'duration' => $this->formatService->prettyTime($tearDownTime),
            'costsLabel' => __('game/technologydetails.in_needed'),
        ];
    }
}
