<?php

/**
 * @file
 * The `TestCase` methods that create stubs, such as `returnValue()`. PHPUnit
 * 12 removes them.
 */

declare(strict_types=1);

// Corpus: PHPUnit 11 and earlier.
namespace Corpus;

use PHPUnit\Framework\TestCase;

interface Helped
{
    public function name(int $id): string;
}

final class MockStubHelpersTest extends TestCase
{
    public function testHelpers(): void
    {
        $stub = $this->createStub(Helped::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->will($this->returnValue(5));
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->will($this->onConsecutiveCalls('a', 5));
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->will($this->returnCallback(fn(): int => 1));
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->will($this->returnArgument(0));
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->will($this->returnValueMap([[1, 2]]));
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnOnConsecutiveCalls($this->returnSelf());
    }

    public function testHelpersFit(): void
    {
        $stub = $this->createStub(Helped::class);
        $stub->method('name')->will($this->returnValue('a'));
        $stub->method('name')->will($this->onConsecutiveCalls('a', 'b'));
        $this->assertSame('a', $stub->name(1));
    }
}
