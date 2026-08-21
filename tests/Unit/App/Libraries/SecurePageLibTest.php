<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries;

use App\Libraries\SecurePageLib;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

#[CoversClass(SecurePageLib::class)]
class SecurePageLibTest extends TestCase
{
    private function validate(mixed $value): mixed
    {
        $instance = (new ReflectionClass(SecurePageLib::class))->newInstanceWithoutConstructor();

        return (new ReflectionMethod(SecurePageLib::class, 'validate'))->invoke($instance, $value);
    }

    public function testScriptIsNeutralisedAndHtmlIsEncoded(): void
    {
        $this->assertSame('blockedalert', $this->validate('scriptalert'));
        $this->assertSame('&lt;b&gt;hi&lt;/b&gt;', $this->validate('<b>hi</b>'));
    }

    public function testNestedArraysKeepTheirKeys(): void
    {
        // the legacy version renumbered nested arrays; keys must now survive
        $result = $this->validate([
            'name' => '<x>',
            'coords' => ['galaxy' => '1', 'system' => '<i>'],
        ]);

        $this->assertSame([
            'name' => '&lt;x&gt;',
            'coords' => ['galaxy' => '1', 'system' => '&lt;i&gt;'],
        ], $result);
    }

    public function testNonScalarLeafBecomesEmptyString(): void
    {
        $this->assertSame('', $this->validate(null));
    }
}
