<?php

/**
 * @file
 * Arguments of the calls that set up a prophecy.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Argument as Arg;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Prophecy\Prophecy\ProphecyInterface;

/**
 * Sets up prophecies of the mailbox with right and wrong arguments.
 */
final class ProphecyArgumentsTest extends TestCase
{
    use ProphecyTrait;

    /**
     * A prophecy documented without the prophesized class.
     *
     * @var \Prophecy\Prophecy\ObjectProphecy
     */
    protected $bare;

    /**
     * Builds the bare prophecy.
     */
    protected function setUp(): void
    {
        $this->bare = $this->prophesize(Thing::class);
    }

    /**
     * Values, tokens and prophecies of the parameter types are taken.
     *
     * Prophecy compares a number and a numeric string by their text, so each
     * one matches an argument of the other type.
     */
    public function testAccepted(): void
    {
        $thing = $this->prophesize(Thing::class);
        $mailbox = $this->prophesize(Mailbox::class);
        $mailbox->send($thing->reveal(), 'subject')->willReturn(true);
        $mailbox->send($thing, Argument::type('string'))->willReturn(true);
        $mailbox->send(Argument::any())->willReturn(true);
        $mailbox->send($this->bare)->willReturn(true);
        $mailbox->sendAll('subject', $thing, $thing->reveal(), Argument::any())->willReturn(2);
        $mailbox->sendList([$thing, $thing->reveal()])->shouldBeCalled();
        $mailbox->find(1)->willReturn(null);
        $mailbox->find('2')->willReturn(null);
        $mailbox->send($thing, 3)->willReturn(true);
        $mailbox->send($thing, new \RuntimeException('subject'))->willReturn(true);
        $mailbox->make(Thing::class)->willReturn($thing);
    }

    /**
     * `Argument::cetera()` takes the rest of the arguments, any number of them.
     */
    public function testCetera(): void
    {
        $mailbox = $this->prophesize(Mailbox::class);
        $mailbox->send(Argument::cetera())->willReturn(true);
        $mailbox->sendAll(Argument::cetera())->willReturn(0);
        $mailbox->send(Argument::any(), Argument::cetera(), 'ignored')->willReturn(true);
        $mailbox->send(Arg::cetera())->willReturn(true);
        $mailbox->sendAll('subject', new Thing(), Argument::cetera())->willReturn(1);
    }

    /**
     * A value that the method doesn't take is reported.
     *
     * An object of another class is a possible mismatch for Mago, since a
     * subclass of it could also be an argument token.
     */
    public function testWrongType(): void
    {
        $mailbox = $this->prophesize(Mailbox::class);
        // @mago-expect analysis:invalid-argument
        $mailbox->send('thing')->willReturn(true);
        // @mago-expect analysis:possibly-invalid-argument
        $mailbox->send($this->prophesize(Other::class))->willReturn(true);
        // @mago-expect analysis:possibly-invalid-argument
        $mailbox->sendAll('subject', new Other())->willReturn(1);
        // @mago-expect analysis:invalid-argument
        $mailbox->sendList([new Other()])->shouldBeCalled();
    }

    /**
     * A call with a required argument missing never matches, and one with
     * an extra argument only matches an extra argument.
     */
    public function testArity(): void
    {
        $mailbox = $this->prophesize(Mailbox::class);
        // @mago-expect analysis:too-few-arguments
        $mailbox->send()->shouldNotBeCalled();
        // @mago-expect analysis:too-many-arguments
        $mailbox->find(1, 2)->willReturn(null);
    }

    /**
     * `Argument::type()` takes a type with an `is_*()` function or a class.
     */
    public function testTypeTokens(): void
    {
        $mailbox = $this->prophesize(Mailbox::class);
        $mailbox->send(Argument::type(Thing::class), Argument::type('string'))->willReturn(true);
        $mailbox->send(Argument::type('\Corpus\SpecialThing'), Argument::type('numeric'))->willReturn(true);
        // @mago-expect analysis:phpunit/prophecy-unknown-type
        $mailbox->send(Argument::type('Corpus\Missing'))->willReturn(true);
        // @mago-expect analysis:phpunit/prophecy-unknown-type
        $mailbox->send(Argument::any(), Argument::type('mixed'))->willReturn(true);
    }

    /**
     * A prophecy documented without its class isn't checked, also as part of
     * a union or inside an array.
     *
     * @param \Corpus\Thing|\Prophecy\Prophecy\ObjectProphecy $union
     *   A prophecy documented as a union with its class.
     */
    // @mago-expect analysis:phpunit/prophecy-union
    public function testBare($union, ProphecyInterface $any): void
    {
        $mailbox = $this->prophesize(Mailbox::class);
        $mailbox->send($union)->willReturn(true);
        $mailbox->send($any)->willReturn(true);
        $mailbox->sendList([$this->bare])->shouldBeCalled();
        $mailbox->findAll()->willReturn([$this->bare]);
    }

    /**
     * After `willExtend()` on its own line, the extended class's method is
     * the one checked. The double extends the class.
     */
    public function testExtended(): void
    {
        $measure = $this->prophesize(Measure::class);
        $measure->willExtend(Ruler::class);
        $measure->size('cm')->willReturn(1);
    }

    /**
     * A call on a prophecy of one class or another isn't checked when the
     * classes have different methods of that name.
     *
     * @param ObjectProphecy<Counter>|ObjectProphecy<Namer> $either
     *   The prophecy.
     */
    public function testEither(ObjectProphecy $either): void
    {
        $either->step(1)->willReturn(1);
        $either->step('one')->willReturn('one');
    }

    /**
     * A token that can match the parameter's arguments is taken.
     */
    public function testTokensAccepted(): void
    {
        $mailbox = $this->prophesize(Mailbox::class);
        $mailbox->send(Argument::type(SpecialThing::class), Argument::containingString('hi'))->willReturn(true);
        $mailbox->send(Argument::that(static fn(Thing $thing): bool => true))->willReturn(true);
        $mailbox->send(Argument::any(), Argument::is('subject'))->willReturn(true);
        $mailbox->send(Argument::any(), Argument::exact(5))->willReturn(true);
        $mailbox->find(Argument::type('numeric'))->willReturn(null);
        $mailbox->sendList(Argument::size(2))->shouldBeCalled();
        $mailbox->sendList(Argument::withEveryEntry(Argument::type(Thing::class)))->shouldBeCalled();
        $mailbox->find(Argument::that(static fn(string $id): bool => true))->willReturn(null);
    }

    /**
     * Prophecy compares numbers with `==`, an object with `__toString()` by
     * its text, and two dates by their time.
     */
    public function testLooseValues(): void
    {
        $scale = $this->prophesize(Scale::class);
        $scale->weigh(5)->willReturn(1);
        $scale->weigh(Argument::exact(5))->willReturn(1);
        $scale->tag('label')->willReturn(1);
        $scale->tag(Argument::exact(5))->willReturn(1);
        $scale->stamp(new \DateTime())->willReturn(1);
        $scale->stamp(Argument::exact(new \DateTime()))->willReturn(1);
        $scale->weigh($this->token('string'))->willReturn(1);
    }

    /**
     * A token that can't match an array or a float parameter is reported.
     */
    public function testTokensForArraysAndFloats(): void
    {
        $scale = $this->prophesize(Scale::class);
        $scale->sort(Argument::type('array'))->willReturn(1);
        // @mago-expect analysis:phpunit/prophecy-token-mismatch
        $scale->sort(Argument::containingString('x'))->willReturn(1);
        // @mago-expect analysis:phpunit/prophecy-token-mismatch
        $scale->weigh(Argument::is(1))->willReturn(1);
        // @mago-expect analysis:phpunit/prophecy-token-mismatch
        $scale->weigh(Argument::type('int'))->willReturn(1);
    }

    /**
     * Builds a token. Its argument isn't the token's.
     */
    private function token(string $name): Argument\Token\TypeToken
    {
        return new Argument\Token\TypeToken($name === '' ? 'int' : 'float');
    }

    /**
     * A token that can't match the parameter's arguments is reported.
     */
    public function testTokensRejected(): void
    {
        $mailbox = $this->prophesize(Mailbox::class);
        // @mago-expect analysis:phpunit/prophecy-token-mismatch
        $mailbox->send(Argument::type(Other::class))->willReturn(true);
        // @mago-expect analysis:phpunit/prophecy-token-mismatch
        $mailbox->find(Argument::type('string'))->willReturn(null);
        // @mago-expect analysis:phpunit/prophecy-token-mismatch
        $mailbox->find(Argument::containingString('1'))->willReturn(null);
        // @mago-expect analysis:phpunit/prophecy-token-mismatch
        $mailbox->send(Argument::any(), Argument::is(5))->willReturn(true);
        // @mago-expect analysis:phpunit/prophecy-token-mismatch
        $mailbox->send(Argument::that(static fn(Other $other): bool => true))->willReturn(true);
        // @mago-expect analysis:phpunit/prophecy-token-mismatch
        $mailbox->find(Argument::that(static fn(Thing $id): bool => true))->willReturn(null);
        // @mago-expect analysis:phpunit/prophecy-token-mismatch
        $mailbox->sendAll('subject', new Thing(), Argument::type(Other::class))->willReturn(1);
    }

    /**
     * A prophecy documented as one is checked the same way.
     *
     * @param ObjectProphecy<Mailbox> $mailbox
     *   The prophecy.
     */
    private function checkDocumented(ObjectProphecy $mailbox): void
    {
        // @mago-expect analysis:invalid-argument
        $mailbox->find(true)->willReturn(null);
    }

    /**
     * Calls the helper.
     */
    public function testDocumented(): void
    {
        $this->checkDocumented($this->prophesize(Mailbox::class));
    }
}
