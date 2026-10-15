<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy\App\Libraries\Missions;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;
use Xgp\App\Libraries\Missions\Spy;

/**
 * Characterization tests for the espionage report.
 *
 * - generateSpyReport(): compared byte for byte with golden output captured
 *   from the original implementation (tests/Fixtures/spy_reports.json).
 * - reportDepth(): compared with a verbatim copy of the original selection
 *   logic (sequential overwriting ifs, including the overwritten levels).
 */
class SpyTest extends TestCase
{
    /**
     * @return array{target: array<string, mixed>, reports: list<array{string: string, count: int|float}>}
     */
    private static function fixture(): array
    {
        $json = file_get_contents(dirname(__DIR__, 5) . '/Fixtures/spy_reports.json');
        self::assertIsString($json);

        $fixture = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertIsArray($fixture['target']);
        self::assertIsArray($fixture['reports']);

        /** @var array{target: array<string, mixed>, reports: list<array{string: string, count: int|float}>} $fixture */
        return $fixture;
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function modes(): array
    {
        return [
            'resources' => [0, 'TITLE-resources'],
            'fleet' => [1, 'TITLE-fleet'],
            'defenses' => [2, 'TITLE-defenses'],
            'buildings' => [3, 'TITLE-buildings'],
            'research' => [4, 'TITLE-research'],
        ];
    }

    #[DataProvider('modes')]
    public function testGenerateSpyReportMatchesTheOriginalOutput(int $mode, string $title): void
    {
        app()->setLocale('en');
        $fixture = self::fixture();

        $ref = new ReflectionMethod(Spy::class, 'generateSpyReport');
        $report = $ref->invoke(app(Spy::class), $fixture['target'], $mode, $title);

        $this->assertIsArray($report);
        $this->assertSame($fixture['reports'][$mode]['string'], $report['text']);
        $this->assertEquals($fixture['reports'][$mode]['count'], $report['count']);
    }

    /**
     * Verbatim selection logic of the original spyMission(), returning the
     * number of cumulative sections in the message (0 = no report). The
     * original overwrote the levels with 100 / 0 right before comparing them;
     * they are passed in with exactly those values.
     */
    private static function originalDepth(int $spionage_difference, int $LS, int $CurrentSpyLvl, int $TargetSpyLvl): int
    {
        $resources = $fleet = $defense = $buildings = $tech = 0;

        if ($TargetSpyLvl >= $CurrentSpyLvl) {
            $ST = pow($spionage_difference, 2);
            $resources = 1;
            $fleet = $ST + 2;
            $defense = $ST + 3;
            $buildings = $ST + 5;
            $tech = $ST + 7;
        }

        if ($CurrentSpyLvl > $TargetSpyLvl) {
            $ST = pow($spionage_difference, 2) * -1;
            $resources = 1;
            $fleet = $ST + 2;
            $defense = $ST + 3;
            $buildings = $ST + 5;
            $tech = $ST + 7;
        }

        $depth = 0;
        if ($resources <= $LS) {
            $depth = 1;
        }
        if ($fleet <= $LS) {
            $depth = 2;
        }
        if ($defense <= $LS) {
            $depth = 3;
        }
        if ($buildings <= $LS) {
            $depth = 4;
        }
        if ($tech <= $LS) {
            $depth = 5;
        }

        return $depth;
    }

    public function testReportDepthMatchesTheOriginalSelectionLogic(): void
    {
        $ref = new ReflectionMethod(Spy::class, 'reportDepth');

        for ($difference = 0; $difference <= 4; $difference++) {
            for ($probes = 0; $probes <= 12; $probes++) {
                $this->assertSame(
                    self::originalDepth($difference, $probes, 100, 0),
                    $ref->invoke(null, $difference, $probes),
                    "level difference {$difference}, {$probes} probes"
                );
            }
        }
    }
}
