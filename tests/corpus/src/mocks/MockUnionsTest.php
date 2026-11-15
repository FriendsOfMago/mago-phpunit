<?php

/**
 * @file
 * Mocks documented as a union of the mocked type and MockObject.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Configures doubles documented with union types, as in older tests.
 */
final class MockUnionsTest extends TestCase
{
    /**
     * A mock, documented as a union.
     *
     * @var \Corpus\Thing|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $thing;

    /**
     * A stub, which doesn't take expectations.
     *
     * @var \Corpus\Thing|\PHPUnit\Framework\MockObject\Stub
     */
    protected $stub;

    /**
     * A mock documented as the mocked type alone.
     *
     * @var \Corpus\Thing
     */
    protected $plain;

    /**
     * A mock of a subclass, documented as a union.
     *
     * @var \Corpus\SpecialThing|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $special;

    /**
     * A mock that may be missing, documented as a union.
     *
     * @var \Corpus\Thing|\PHPUnit\Framework\MockObject\MockObject|null
     */
    protected $maybe;

    /**
     * A mock that may be of an unrelated class, documented as a union.
     *
     * @var \Corpus\Thing|\Corpus\Other|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $either;

    /**
     * Builds the doubles.
     */
    protected function setUp(): void
    {
        $this->thing = $this->createMock(Thing::class);
        $this->stub = $this->createStub(Thing::class);
        $this->plain = $this->createMock(Thing::class);
    }

    /**
     * The MockObject methods work on the mocked half, so the chain keeps its type.
     */
    public function testConfigured(): void
    {
        $this->thing->expects($this->once())->method('onlyOnThing')->willReturn(null);
        $this->thing->method('onlyOnThing')->willReturn(null);
    }

    /**
     * A method missing from the mocked type is still reported as missing.
     */
    public function testUnknownMethod(): void
    {
        // @mago-expect analysis:non-existent-method
        $this->thing->method('notOnThing');
    }

    /**
     * A mock typed as the mocked class alone is reported once per call.
     */
    public function testPlainType(): void
    {
        // @mago-expect analysis:phpunit/mock-call-on-plain-type
        $this->plain->expects($this->once())->method('onlyOnThing')->willReturn(null);
        // @mago-expect analysis:phpunit/mock-call-on-plain-type
        $this->plainHelper()->method('onlyOnThing')->willReturn(null);
        // @mago-expect analysis:non-existent-method
        $this->plain->method('notOnThing');
    }

    /**
     * An object that isn't a mock is reported the same way, since its type
     * looks the same.
     */
    public function testRealObject(): void
    {
        $thing = new Thing();
        // @mago-expect analysis:phpunit/mock-call-on-plain-type
        $thing->method('onlyOnThing');
    }

    /**
     * A mock that keeps its intersection type is fine.
     */
    public function testIntersection(): void
    {
        $mock = $this->createMock(Thing::class);
        $mock->expects($this->once())->method('onlyOnThing')->willReturn(null);
    }

    /**
     * Returns a mock under the mocked class's type.
     */
    private function plainHelper(): Thing
    {
        return $this->createMock(Thing::class);
    }

    /**
     * A union mock is accepted where the mocked type is expected.
     *
     * So is the union mock of a subclass.
     */
    public function testPassedAsArgument(): void
    {
        $this->takesThing($this->thing);
        $this->takesThing($this->special);
    }

    /**
     * A union that may hold null is still reported for the null.
     */
    public function testNullablePassedAsArgument(): void
    {
        // @mago-expect analysis:possibly-null-argument
        $this->takesThing($this->maybe);
    }

    /**
     * A union with an unrelated class is still reported.
     */
    public function testUnrelatedPassedAsArgument(): void
    {
        // @mago-expect analysis:possibly-invalid-argument
        $this->takesThing($this->either);
    }

    /**
     * A union with a scalar next to the mock half is still reported too.
     *
     * @param \Corpus\Thing|\PHPUnit\Framework\MockObject\MockObject|int $value
     *   A mock, or a number.
     */
    public function testScalarPassedAsArgument(Thing|MockObject|int $value): void
    {
        // @mago-expect analysis:possibly-invalid-argument
        $this->takesThing($value);
    }

    /**
     * Stands in for code that takes the mocked type.
     */
    private function takesThing(Thing $thing): void {}

    /**
     * The stub half of the union doesn't have `expects()`.
     */
    public function testExpectationOnStub(): void
    {
        // @mago-expect analysis:non-existent-method
        $this->stub->expects($this->once());
    }
}
