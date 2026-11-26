<?php

/**
 * @file
 * Return values whose class has a parent that isn't known, and closures.
 * A class with an unknown parent may still be an instance of the returned
 * class. A closure is an object of the `Closure` class.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\TestCase;

interface Registry
{
    public function owner(): Owner;

    public function total(): int;

    public function factory(): \Closure;

    public function handler(): callable;
}

// @mago-expect analysis:non-existent-class-like
class Unrooted extends \Corpus\Missing\Base {}

final class MockReturnHierarchyTest extends TestCase
{
    /**
     * The parent of `Unrooted` isn't known, and may implement `Owner`.
     */
    public function testUnknownParent(): void
    {
        $stub = $this->createStub(Registry::class);
        $stub->method('owner')->willReturn($this->createStub(Unrooted::class));
        $stub->method('owner')->willReturnOnConsecutiveCalls($this->createStub(Unrooted::class));
        $this->addToAssertionCount(1);
    }

    /**
     * PHPUnit takes a closure as an object, so it doesn't fit an `int`.
     */
    public function testClosures(): void
    {
        $stub = $this->createStub(Registry::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('total')->willReturn(static fn(): int => 1);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('total')->willReturn(strlen(...));
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('factory')->willReturn(1);
        $stub->method('factory')->willReturn(static fn(): int => 1);
        $stub->method('handler')->willReturn(1);
    }
}
