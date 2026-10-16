<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Alliance;

use App\Libraries\Alliance\Ranks;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Ranks::class)]
class RanksTest extends TestCase
{
    /** the ranks a new alliance gets (AllianceController) */
    private const DEFAULT_RANKS = '[{"rank":"Founder","rights":{"1":1,"2":1,"3":1,"4":1,"5":1,"6":1,"7":1,"8":1,"9":1}},'
        . '{"rank":"Newcomer","rights":{"1":0,"2":0,"3":0,"4":0,"5":0,"6":0,"7":0,"8":0,"9":0}}]';

    private const NO_RIGHTS = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0];
    private const ALL_RIGHTS = [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1];

    public function testTheStoredJsonSurvivesARoundTrip(): void
    {
        $ranks = new Ranks(self::DEFAULT_RANKS);

        $this->assertSame(self::DEFAULT_RANKS, $ranks->getAllRanksAsJsonString());
        $this->assertSame(
            [
                0 => ['rank' => 'Founder', 'rights' => self::ALL_RIGHTS],
                1 => ['rank' => 'Newcomer', 'rights' => self::NO_RIGHTS],
            ],
            $ranks->getAllRanksAsArray()
        );
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function withoutRanks(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'invalid json' => ['{"rank":'],
            'not a list' => ['"text"'],
        ];
    }

    /**
     * Regression: invalid JSON ended the request with die().
     */
    #[DataProvider('withoutRanks')]
    public function testMissingOrInvalidJsonMeansNoRanks(?string $json): void
    {
        $ranks = new Ranks($json);

        $this->assertSame([], $ranks->getAllRanksAsArray());
        $this->assertNull($ranks->getRankById(0));
    }

    /**
     * Regression: the name was SQL-escaped although it is stored through a
     * bound parameter, so an apostrophe was shown as \'.
     */
    public function testAddNewStripsTagsButDoesNotEscape(): void
    {
        $ranks = new Ranks(self::DEFAULT_RANKS);

        $ranks->addNew("<b>O'Neil</b>");
        $ranks->addNew('<i></i>');

        $this->assertSame(['rank' => "O'Neil", 'rights' => self::NO_RIGHTS], $ranks->getRankById(2));
        $this->assertCount(3, $ranks->getAllRanksAsArray());
    }

    /**
     * Regression: an id past the end was clamped for the check but used
     * unclamped for the write, which created a rank without name.
     */
    public function testEditingOnlyChangesExistingRanks(): void
    {
        $ranks = new Ranks(self::DEFAULT_RANKS);

        $ranks->editRankById(1, self::ALL_RIGHTS);
        $ranks->editRankNameById(1, 'Member');
        $ranks->editRankById(5, self::ALL_RIGHTS);
        $ranks->editRankNameById(5, 'Ghost');

        $this->assertSame(['rank' => 'Member', 'rights' => self::ALL_RIGHTS], $ranks->getRankById(1));
        $this->assertCount(2, $ranks->getAllRanksAsArray());
    }

    public function testEditingRequiresAllNineRights(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Ranks(self::DEFAULT_RANKS))->editRankById(1, [1 => 1]);
    }

    /**
     * Regression: a negative id was clamped to 0 and deleted the founder rank.
     */
    public function testDeletingMovesTheFollowingRanksUpAndIgnoresUnknownIds(): void
    {
        $ranks = new Ranks(self::DEFAULT_RANKS);
        $ranks->addNew('Officer');
        $ranks->addNew('Trader');

        $this->assertFalse($ranks->deleteRankById(-1));
        $this->assertFalse($ranks->deleteRankById(9));
        $this->assertTrue($ranks->deleteRankById(2));

        $this->assertSame(['Founder', 'Newcomer', 'Trader'], array_column($ranks->getAllRanksAsArray(), 'rank'));
        $this->assertSame([0, 1, 2], array_keys($ranks->getAllRanksAsArray()));
    }

    public function testTheFounderAndNewcomerRanksCannotBeDeleted(): void
    {
        $ranks = new Ranks(self::DEFAULT_RANKS);

        $this->assertFalse($ranks->deleteRankById(Ranks::FOUNDER));
        $this->assertFalse($ranks->deleteRankById(Ranks::NEWCOMER));
        $this->assertSame(['Founder', 'Newcomer'], array_column($ranks->getAllRanksAsArray(), 'rank'));
    }

    public function testAnUnknownRankFallsBackToTheNewcomer(): void
    {
        $ranks = new Ranks(self::DEFAULT_RANKS);

        $this->assertSame('Newcomer', $ranks->getRankById(7)['rank'] ?? null);
        $this->assertSame('Founder', $ranks->getRankById(0)['rank'] ?? null);
    }
}
