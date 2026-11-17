<?php

/**
 * @file
 * Calls that set up a prophecy through ObjectProphecy::__call().
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Sets up prophecies of the corpus thing.
 */
final class ProphecyCallsTest extends TestCase
{
    use ProphecyTrait;

    /**
     * A prophecy documented as a union with the prophesized class.
     *
     * @var \Corpus\Thing|\Prophecy\Prophecy\ProphecyInterface
     */
    // @mago-expect analysis:phpunit/prophecy-union
    protected $union;

    /**
     * A prophecy documented as one.
     *
     * @var \Prophecy\Prophecy\ObjectProphecy<\Corpus\Thing>
     */
    protected $documented;

    /**
     * Builds the documented prophecy.
     */
    protected function setUp(): void
    {
        $this->union = $this->prophesize(Thing::class);
        $this->documented = $this->prophesize(Thing::class);
    }

    /**
     * Takes and returns prophecies documented as unions.
     *
     * @param \Corpus\Thing|\Prophecy\Prophecy\ObjectProphecy $thing
     *   The prophecy.
     *
     * @return \Corpus\Thing|\Prophecy\Prophecy\ProphecyInterface
     *   The same prophecy.
     */
    // @mago-expect analysis:phpunit/prophecy-union(2)
    protected function passUnion($thing)
    {
        return $thing;
    }

    /**
     * A method the prophesized class declares returns a method prophecy.
     */
    public function testDeclared(): void
    {
        $thing = $this->prophesize(Thing::class);
        $thing->onlyOnThing()->shouldBeCalled();
        $thing->reveal()->onlyOnThing();
    }

    /**
     * A prophecy documented as one takes the calls that set it up.
     */
    public function testDocumented(): void
    {
        $this->documented->onlyOnThing()->shouldBeCalled();
        $this->passUnion($this->documented);
    }

    /**
     * A method missing from the class is still reported, since Prophecy throws
     * for it.
     */
    public function testUnknownMethod(): void
    {
        $thing = $this->prophesize(Thing::class);
        // @mago-expect analysis:non-documented-method
        $thing->notOnThing();
    }

    /**
     * A prophecy that implements another interface through `willImplement()`
     * has its methods too.
     */
    public function testWillImplement(): void
    {
        $chained = $this->prophesize(Thing::class)->willImplement(ThingLabelInterface::class);
        $chained->label()->willReturn('chained');

        $statement = $this->prophesize(Thing::class);
        $statement->willImplement(ThingLabelInterface::class);
        $statement->label()->willReturn('statement');
    }

    /**
     * The class and the other interfaces are kept when an interface is added
     * on one branch only.
     */
    public function testConditionalWillImplement(): void
    {
        $thing = $this->prophesize(Thing::class);
        if (getenv('CORPUS_COUNTABLE') !== false) {
            $thing->willImplement(\Countable::class);
        }
        $thing->willImplement(ThingLabelInterface::class);
        $thing->label()->willReturn('label');
        $thing->onlyOnThing()->shouldBeCalled();
    }

    /**
     * A double of an interface that is only traversable is an iterator.
     */
    public function testTraversable(): void
    {
        $list = $this->prophesize(ThingListInterface::class);
        $list->valid()->willReturn(false);
        $list->next();
        $list->rewind();
        $list->current()->willReturn(null);
        $list->key()->willReturn(0);
    }

    /**
     * A double of an iterator aggregate doesn't get iterator methods.
     */
    public function testIteratorAggregate(): void
    {
        $aggregate = $this->prophesize(ThingAggregateInterface::class);
        // @mago-expect analysis:non-documented-method
        $aggregate->current();
    }

    /**
     * The union is left alone.
     *
     * A prophecy is never a thing, so the docblock needs fixing.
     */
    public function testUnion(): void
    {
        // @mago-expect analysis:non-existent-method
        $this->union->onlyOnThing();
    }

    /**
     * `willExtend()` replaces the parent class of the double.
     */
    public function testWillExtend(): void
    {
        $extended = $this->prophesize(Thing::class)->willExtend(Other::class);
        $extended->onlyOnOther()->shouldBeCalled();
        // @mago-expect analysis:non-documented-method
        $extended->onlyOnThing();

        $labelled = $this->prophesize(ThingLabelInterface::class)->willExtend(Other::class);
        $labelled->label()->willReturn('kept');
        $labelled->onlyOnOther()->shouldBeCalled();
    }

    /**
     * On a variable, the old class stays next to the new one after
     * `willExtend()`, since narrowing can't remove a type.
     */
    public function testWillExtendStatement(): void
    {
        $prophecy = $this->prophesize(Thing::class);
        $prophecy->willExtend(Other::class);
        $prophecy->onlyOnOther()->shouldBeCalled();
    }
}
