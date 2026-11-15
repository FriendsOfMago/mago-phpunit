<?php

/**
 * @file
 * Doubles of several interfaces at once.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\TestCase;

final class IntersectionMocksTest extends TestCase
{
    /**
     * The double has the methods of every interface and of MockObject.
     */
    public function testMock(): void
    {
        $double = $this->createMockForIntersectionOfInterfaces([ThingLabelInterface::class, Clock::class]);
        $double->expects($this->once())->method('now')->willReturn(1);
        $double->label();
        $double->now();
        $this->takesClock($double);
    }

    /**
     * A stub has the methods of every interface.
     */
    public function testStub(): void
    {
        $double = $this->createStubForIntersectionOfInterfaces(['Corpus\ThingLabelInterface', Clock::class]);
        $double->label();
        $double->now();
        // @mago-expect analysis:non-existent-method
        $double->expects($this->once());
    }

    /**
     * A name string can start with a backslash.
     */
    public function testLeadingBackslash(): void
    {
        $double = $this->createStubForIntersectionOfInterfaces(['\\Corpus\\ThingLabelInterface', '\\Corpus\\Clock']);
        $double->label();
        $this->takesClock($double);
    }

    /**
     * A stub of one class doesn't take expectations either.
     */
    public function testStubOfOneClass(): void
    {
        $stub = $this->createStub(Clock::class);
        // @mago-expect analysis:non-existent-method
        $stub->expects($this->once());
    }

    /**
     * A list built at runtime gets the return type declared by PHPUnit.
     *
     * @param list<class-string> $interfaces
     */
    public function testDynamic(array $interfaces): void
    {
        $double = $this->createMockForIntersectionOfInterfaces($interfaces);
        // @mago-expect analysis:non-existent-method
        $double->now();
    }

    private function takesClock(Clock $clock): void {}
}
