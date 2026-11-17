<?php

/**
 * @file
 * Values given to willReturn() on a prophecy.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Sets up the values returned by prophecies of the mailbox.
 */
final class ProphecyReturnTest extends TestCase
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
     * Values of the return type, and prophecies of them, are taken.
     */
    public function testAccepted(): void
    {
        $thing = $this->prophesize(Thing::class);
        $mailbox = $this->prophesize(Mailbox::class);
        $mailbox->send($thing)->willReturn(true, false);
        $mailbox->find(1)->willReturn($thing);
        $mailbox->find(2)->willReturn($thing->reveal());
        $mailbox->find(3)->willReturn($this->bare);
        $mailbox->find(4)->willReturn();
        $mailbox->findAll()->willReturn([$thing, $thing->reveal()]);
        $mailbox->withSubject('subject')->willReturn($mailbox);
        $mailbox->copyTo('a@example.com')->willReturn($mailbox);
        $mailbox->copyTo('b@example.com')->willReturn($mailbox->reveal());
        $mailbox->make(Other::class)->willReturn($thing);
    }

    /**
     * A value that the method can't return is reported.
     */
    public function testWrongType(): void
    {
        $mailbox = $this->prophesize(Mailbox::class);
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $mailbox->send(new Thing())->willReturn('sent');
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $mailbox->find(1)->willReturn($this->prophesize(Other::class));
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $mailbox->findAll()->willReturn([new Other()]);
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $mailbox->send(new Thing())->willReturn();
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $mailbox->copyTo('a@example.com')->willReturn([]);
    }

    /**
     * The calls between the method and `willReturn()` are followed back.
     */
    public function testChain(): void
    {
        $mailbox = $this->prophesize(Mailbox::class);
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $mailbox->sendAll('subject')->shouldBeCalledOnce()->willReturn('none');
    }

    /**
     * A value that only fits the return type in part, or is wider than it,
     * is a warning.
     */
    public function testPartly(?int $count, object $thing): void
    {
        $mailbox = $this->prophesize(Mailbox::class);
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $mailbox->sendAll('subject')->willReturn($count);
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $mailbox->find(1)->willReturn($thing);
    }

    /**
     * A scalar that PHP converts to the native return type, and a value that
     * only breaks a docblock type, are warnings. A value that PHP can't
     * convert is an error.
     */
    public function testConverted(): void
    {
        $ledger = $this->prophesize(Ledger::class);
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $ledger->total()->willReturn('5');
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $ledger->closed()->willReturn(1);
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $ledger->label()->willReturn(5);
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $ledger->balance()->willReturn(true);
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $ledger->legacy()->willReturn('none');
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $ledger->total()->willReturn('five');
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $ledger->open()->willReturn(false);
    }

    /**
     * `willReturnArgument()` returns an argument of the call, or null when
     * the method doesn't have the parameter.
     */
    public function testReturnArgument(): void
    {
        $relay = $this->prophesize(Relay::class);
        $relay->pass('hello')->willReturnArgument();
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $relay->swap(new Thing())->willReturnArgument();
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $relay->pass('hello')->willReturnArgument(1);
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $relay->pass('hello')->willReturnArgument(-1);
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $this->prophesize(Stack::class)->pick(1, '2')->willReturnArgument(1);
    }

    /**
     * `willYield()` returns a generator of the items, without revealing the
     * prophecies in them.
     */
    public function testYield(): void
    {
        $relay = $this->prophesize(Relay::class);
        $relay->things()->willYield([new Thing()]);
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $relay->things()->willYield([$this->prophesize(Thing::class)]);
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $relay->name()->willYield(['name']);
    }

    /**
     * Prophecy throws for a value set up on a void method.
     */
    public function testVoid(): void
    {
        $mailbox = $this->prophesize(Mailbox::class);
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $mailbox->sendList([])->willReturn(null);
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $mailbox->sendList([])->willReturnArgument();
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $mailbox->sendList([])->willYield([]);
    }

    /**
     * A double of a `never` method fails after any value set up for it.
     */
    public function testNever(): void
    {
        $outbox = $this->prophesize(Outbox::class);
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $outbox->fail()->willReturn();
        // @mago-expect analysis:phpunit/prophecy-invalid-return
        $outbox->fail()->shouldBeCalled()->willYield([]);
    }
}
