<?php

/**
 * @file
 * Doubles configured with a method missing from the mocked class.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\TestCase;

final class MockMethodsTest extends TestCase
{
    public function testMock(): void
    {
        $mock = $this->createMock(Thing::class);
        $mock->method('onlyOnThing')->willReturn(null);
        // @mago-expect analysis:phpunit/mock-undefined-method
        $mock->method('notOnThing');
        // @mago-expect analysis:phpunit/mock-undefined-method
        $mock->expects($this->once())->method('notOnThing');
        $mock->expects($this->once())->method('onlyOnThing');
    }

    public function testStub(): void
    {
        $stub = $this->createStub(Thing::class);
        // @mago-expect analysis:phpunit/mock-undefined-method
        $stub->method('notOnThing');
    }

    /**
     * A method of any interface of an intersection double counts.
     */
    public function testIntersection(): void
    {
        $double = $this->createMockForIntersectionOfInterfaces([ThingLabelInterface::class, Clock::class]);
        $double->method('label');
        $double->method('now');
        // @mago-expect analysis:phpunit/mock-undefined-method
        $double->method('neither');
    }

    /**
     * PHPUnit doesn't double `__call()` or `@method` methods.
     */
    public function testMagic(): void
    {
        // @mago-expect analysis:phpunit/mock-undefined-method
        $this->createMock(Magic::class)->method('anything');
        // @mago-expect analysis:phpunit/mock-undefined-method
        $this->createMock(Tagged::class)->method('label');
    }

    /**
     * A final, private or protected method can't be replaced. A static one
     * can be configured.
     */
    public function testUnreplaceable(): void
    {
        $mock = $this->createMock(Parts::class);
        // @mago-expect analysis:phpunit/mock-undefined-method
        $mock->method('sealed');
        // @mago-expect analysis:phpunit/mock-undefined-method
        $mock->method('inner');
        // @mago-expect analysis:phpunit/mock-undefined-method
        $mock->method('hidden');
        $mock->method('make');
        $mock->method('');
    }

    /**
     * A name that is only known when the code runs isn't checked.
     */
    public function testDynamic(string $name): void
    {
        $this->createMock(Thing::class)->method($name);
    }

    /**
     * Methods from a parent class, a parent interface or a trait count.
     */
    public function testInherited(): void
    {
        // A comment with addMethods in it doesn't change the checks.
        $this->createMock(SpecialThing::class)->method('onlyOnThing');
        $this->createMock(LabelledClock::class)->method('now');
        $this->createStub(Greeter::class)->method('greet');
        // @mago-expect analysis:phpunit/mock-undefined-method
        $this->createMock(Greeter::class)->method('__construct');
    }
}
