<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Premium;

use App\Libraries\Premium\Premium;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;
use Xgp\App\Core\Entity\PremiumEntity;

#[CoversClass(Premium::class)]
class PremiumTest extends TestCase
{
    public function testWrapsRowsIntoEntities(): void
    {
        $premium = new Premium([
            ['premium_user_id' => 1, 'premium_dark_matter' => 500],
        ]);

        $this->assertCount(1, $premium->getPremium());
        $this->assertSame(500, $premium->getCurrentPremium()->getPremiumDarkMatter());
    }

    public function testGetCurrentPremiumOnEmptySetReturnsAnEmptyEntity(): void
    {
        $premium = new Premium([]);

        // legacy indexed [0] on an empty array here
        $this->assertInstanceOf(PremiumEntity::class, $premium->getCurrentPremium());
    }
}
