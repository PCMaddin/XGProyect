<?php

declare(strict_types=1);

namespace Tests\Unit\App\Models;

use App\Models\Ban;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(Ban::class)]
class BanTest extends TestCase
{
    public function testUntilIsCastToAUnixTimestamp(): void
    {
        $ban = new Ban();
        $ban->until = 1_800_000_000;

        // the 'timestamp' cast yields an int, not a Carbon instance: reading
        // ->until->timestamp (as the login did) crashed with an ErrorException
        $this->assertSame(1_800_000_000, $ban->until);
    }

    public function testATemporaryBanInThePastHasExpired(): void
    {
        $ban = new Ban();
        $ban->until = time() - 60;

        $this->assertTrue($ban->hasExpired());
    }

    public function testATemporaryBanInTheFutureHasNotExpired(): void
    {
        $ban = new Ban();
        $ban->until = time() + 3600;

        $this->assertFalse($ban->hasExpired());
    }

    public function testAPermanentBanNeverExpires(): void
    {
        $ban = new Ban();
        $ban->until = null;

        $this->assertFalse($ban->hasExpired());
    }
}
