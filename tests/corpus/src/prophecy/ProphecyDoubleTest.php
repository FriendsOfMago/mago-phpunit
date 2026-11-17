<?php

/**
 * @file
 * Classes and methods that Prophecy can't double.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Doubles classes and sets up methods that Prophecy throws for.
 */
final class ProphecyDoubleTest extends TestCase
{
    use ProphecyTrait;

    /**
     * Classes, interfaces and methods that Prophecy can double. A class name
     * that isn't a literal isn't checked. A class marked final only in its
     * docblock can be doubled.
     *
     * @param class-string $class
     */
    public function testAccepted(string $class): void
    {
        $this->prophesize($class)->willImplement(Clock::class);
        $this->prophesize(Thing::class)->willImplement(Clock::class);
        $this->prophesize(Clock::class)->willExtend(Thing::class);
        $this->prophesize(Drawer::class)->open()->willReturn(1);
        $this->prophesize(Stack::class)->_peek()->willReturn(1);
        $this->prophesize(Stack::class)->willImplement(Loader::class)->_load()->willReturn(1);
        $this->prophesize(SealedByDocblock::class);
        $this->prophesize(SoftFinal::class);
        $outbox = $this->prophesize(Outbox::class);
        $outbox->count()->willReturn(1);
        $outbox->fail()->willThrow(\RuntimeException::class);
        $outbox->reveal();
    }

    /**
     * A final class, an enum and a trait can't be doubled.
     */
    public function testClasses(): void
    {
        // @mago-expect analysis:phpunit/prophecy-invalid-double
        $this->prophesize(Sealed::class);
        // @mago-expect analysis:phpunit/prophecy-invalid-double
        $this->prophesize(Priority::class);
        // @mago-expect analysis:phpunit/prophecy-invalid-double
        $this->prophesize('Corpus\Greets');
        // @mago-expect analysis:phpunit/prophecy-invalid-double
        $this->prophesize('\Corpus\Sealed');
        // @mago-expect analysis:phpunit/prophecy-invalid-double
        $this->prophesize(\OneLineFinal::class);
    }

    /**
     * `willExtend()` takes a class, and `willImplement()` an interface.
     */
    public function testExtendAndImplement(): void
    {
        // @mago-expect analysis:phpunit/prophecy-invalid-double
        $this->prophesize(Thing::class)->willImplement(Other::class);
        // @mago-expect analysis:phpunit/prophecy-invalid-double
        $this->prophesize(Thing::class)->willExtend(Clock::class);
        // @mago-expect analysis:phpunit/prophecy-invalid-double
        $this->prophesize(Clock::class)->willExtend(Sealed::class);
    }

    /**
     * A final method, and a method that returns an intersection, can't be set up.
     */
    public function testSetUp(): void
    {
        $outbox = $this->prophesize(Outbox::class);
        // @mago-expect analysis:phpunit/prophecy-invalid-setup
        $this->prophesize(Parts::class)->sealed()->shouldBeCalled();
        // @mago-expect analysis:phpunit/prophecy-invalid-setup
        $outbox->queue()->willReturn(null);
        // @mago-expect analysis:phpunit/prophecy-invalid-setup
        $outbox->pending()->shouldNotBeCalled();
    }

    /**
     * A protected method that isn't abstract, and a class's method whose
     * name starts with `_`, aren't replaced in the double.
     */
    public function testNotReplaced(): void
    {
        // @mago-expect analysis:phpunit/prophecy-invalid-setup
        $this->prophesize(Parts::class)->inner();
        // @mago-expect analysis:phpunit/prophecy-invalid-setup
        $this->prophesize(Drawer::class)->_fill()->shouldBeCalled();
        // @mago-expect analysis:phpunit/prophecy-invalid-setup
        $this->prophesize(Stack::class)->create()->willReturn(null);
    }
}
