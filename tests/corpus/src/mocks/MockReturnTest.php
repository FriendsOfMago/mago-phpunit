<?php

/**
 * @file
 * Values given to `willReturn()` and `willReturnOnConsecutiveCalls()` that
 * don't fit the native return type of the configured method.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub\Exception as ThrowingStub;
use PHPUnit\Framework\MockObject\Stub\ReturnArgument;
use PHPUnit\Framework\MockObject\Stub\ReturnSelf;
use PHPUnit\Framework\MockObject\Stub\ReturnStub;
use PHPUnit\Framework\TestCase;

interface Accounts
{
    public function total(): int;

    public function rate(): float;

    public function name(): string;

    public function maybeName(): ?string;

    public function enabled(): bool;

    public function confirmed(): true;

    public function close(): void;

    public function fail(): never;

    public function entries(): array;

    public function rows(): iterable;

    public function owner(): Owner;

    public function account(): Account;

    public function person(): Person;

    public function anything(): object;

    public function both(): Owner&Account;

    public function suit(): CardSuit;

    public function any(): mixed;

    public function untyped();

    public function self(): self;

    public function static(): static;

    public function callback(): callable;

    public function pick(int $index, string $label = 'none'): string;

    public function either(): int|string|null;

    public function nothing(): null;

    public function nullableBoth(): (Owner&Account)|null;

    public function node(): Node;
}

interface Entity {}

class Node implements Entity {}

final class CustomStub implements \PHPUnit\Framework\MockObject\Stub\Stub
{
    /**
     * Returns a string for any call.
     */
    public function invoke(\PHPUnit\Framework\MockObject\Invocation $invocation): mixed
    {
        return 'custom';
    }
}

class BaseLedger
{
    public function fluent(): self
    {
        return $this;
    }
}

class ChildLedger extends BaseLedger
{
    public function asParent(): parent
    {
        return $this;
    }
}

interface Owner {}

interface Account {}

class Person implements Owner {}

class Company {}

final class Manager extends Person {}

enum CardSuit
{
    case Hearts;
}

enum Color
{
    case Red;
}

final class MockReturnTest extends TestCase
{
    /** @var Accounts|MockObject */
    private $legacy;

    /** @var Person|MockObject */
    private $legacyPerson;

    private MockObject $bare;

    private MockObject&Entity $entity;

    /**
     * Builds the legacy union double.
     */
    protected function setUp(): void
    {
        $this->legacy = $this->createMock(Accounts::class);
        $this->legacyPerson = $this->createMock(Person::class);
        $this->bare = $this->createMock(Person::class);
        $this->entity = $this->createMock(Node::class);
    }

    public function testStringForInt(): void
    {
        // @mago-expect analysis:phpunit/mock-invalid-return
        $this->createStub(Accounts::class)->method('total')->willReturn('5');
    }

    public function testIntForFloat(): void
    {
        // @mago-expect analysis:phpunit/mock-invalid-return
        $this->createStub(Accounts::class)->method('rate')->willReturn(1);
    }

    public function testNullForString(): void
    {
        // @mago-expect analysis:phpunit/mock-invalid-return
        $this->createStub(Accounts::class)->method('name')->willReturn(null);
    }

    public function testNullForVoid(): void
    {
        // @mago-expect analysis:phpunit/mock-invalid-return
        $this->createStub(Accounts::class)->method('close')->willReturn(null);
    }

    public function testValueForNever(): void
    {
        // @mago-expect analysis:phpunit/mock-invalid-return
        $this->createStub(Accounts::class)->method('fail')->willReturn(1);
    }

    public function testFalseForTrue(): void
    {
        // @mago-expect analysis:phpunit/mock-invalid-return
        $this->createStub(Accounts::class)->method('confirmed')->willReturn(false);
    }

    public function testDoubleOfAnotherInterface(): void
    {
        $stub = $this->createStub(Accounts::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('owner')->willReturn($this->createStub(Account::class));
    }

    public function testUnrelatedClass(): void
    {
        $mock = $this->createMock(Accounts::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $mock->method('person')->willReturn(new Company());
    }

    public function testEnumCaseOfAnotherEnum(): void
    {
        // @mago-expect analysis:phpunit/mock-invalid-return
        $this->createStub(Accounts::class)->method('suit')->willReturn(Color::Red);
    }

    public function testScalarForArray(): void
    {
        // @mago-expect analysis:phpunit/mock-invalid-return
        $this->createStub(Accounts::class)->method('entries')->willReturn('a');
    }

    /**
     * Each value of a consecutive list is checked.
     */
    public function testSecondValue(): void
    {
        // @mago-expect analysis:phpunit/mock-invalid-return
        $this->createStub(Accounts::class)->method('total')->willReturn(1, 'two');
    }

    /**
     * The chain is followed back through `expects()` and `with()`.
     */
    public function testExpectationChain(): void
    {
        $mock = $this->createMock(Accounts::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $mock->expects($this->never())->method('name')->with()->willReturn(5);
    }

    /**
     * The method can be named with the `$constraint` parameter. PHPUnit
     * doesn't allow named arguments for it, but the call still works.
     */
    public function testNamedMethod(): void
    {
        $mock = $this->createMock(Accounts::class);
        // @mago-expect analysis:named-argument-not-allowed
        // @mago-expect analysis:phpunit/mock-invalid-return
        $mock->expects($this->never())->method(constraint: 'total')->willReturn('5');
    }

    public function testLegacyUnion(): void
    {
        // @mago-expect analysis:phpunit/mock-invalid-return
        $this->legacy->method('total')->willReturn('5');
    }

    /**
     * A value that only fits in part is a warning.
     */
    public function testNullableForInt(?int $total = 1): void
    {
        // @mago-expect analysis:phpunit/mock-invalid-return
        $this->createStub(Accounts::class)->method('total')->willReturn($total);
    }

    public function testFits(): void
    {
        $stub = $this->createStub(Accounts::class);
        $stub->method('total')->willReturn(1);
        $stub->method('rate')->willReturn(1.5);
        $stub->method('name')->willReturn('a');
        $stub->method('maybeName')->willReturn(null);
        $stub->method('enabled')->willReturn(false);
        $stub->method('confirmed')->willReturn(true);
        $stub->method('entries')->willReturn([1]);
        $stub->method('rows')->willReturn([1], new \ArrayIterator([]));
        $stub->method('owner')->willReturn(new Manager(), $this->createStub(Owner::class));
        $stub->method('anything')->willReturn(new Company());
        $stub->method('both')->willReturn(new Company());
        $stub->method('suit')->willReturn(CardSuit::Hearts);
        $stub->method('any')->willReturn(null);
        $stub->method('untyped')->willReturn('a');
        $stub->method('self')->willReturn($stub);
        $stub->method('static')->willReturn($stub);
        $this->addToAssertionCount(1);
    }

    /**
     * A value whose runtime class isn't known isn't reported.
     */
    public function testUnknown(Owner $owner, mixed $value, callable $callback): void
    {
        $stub = $this->createStub(Accounts::class);
        $stub->method('account')->willReturn($owner);
        $stub->method('self')->willReturn(new Company());
        $stub->method('total')->willReturn($value);
        $stub->method('callback')->willReturn($callback);
        $this->addToAssertionCount(1);
    }

    /**
     * The double returns consecutive values with strict types. PHP throws a
     * `TypeError` for a value that doesn't fit, but converts an `int` to a
     * `float`.
     */
    public function testConsecutiveStringForInt(): void
    {
        $stub = $this->createStub(Accounts::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('total')->willReturnOnConsecutiveCalls(1, 'two');
        $stub->total();
        $stub->total();
    }

    public function testConsecutiveNever(): void
    {
        $stub = $this->createStub(Accounts::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('fail')->willReturnOnConsecutiveCalls(1);
        $stub->fail();
    }

    /**
     * A stub in the list is called, and its result is returned.
     */
    public function testConsecutiveReturnStub(): void
    {
        $stub = $this->createStub(Accounts::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnOnConsecutiveCalls(new ReturnStub(5));
        $stub->name();
    }

    public function testConsecutiveReturnSelf(): void
    {
        $stub = $this->createStub(Accounts::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnOnConsecutiveCalls(new ReturnSelf());
        $stub->name();
    }

    public function testConsecutiveNullableForInt(?int $total = 1): void
    {
        $stub = $this->createStub(Accounts::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('total')->willReturnOnConsecutiveCalls($total);
        $this->assertSame(1, $stub->total());
    }

    public function testConsecutiveFits(): void
    {
        $stub = $this->createStub(Accounts::class);
        $stub->method('rate')->willReturnOnConsecutiveCalls(1, 2.5);
        $stub->method('close')->willReturnOnConsecutiveCalls('dropped');
        $stub->method('fail')->willReturnOnConsecutiveCalls(new ThrowingStub(new \RuntimeException()));
        $stub->method('name')->willReturnOnConsecutiveCalls('a', new ReturnStub('b'));
        $stub->method('self')->willReturnOnConsecutiveCalls(new ReturnSelf());
        $stub->method('owner')->willReturnOnConsecutiveCalls(new Manager());
        $this->assertSame(1.0, $stub->rate());
        $this->assertSame(2.5, $stub->rate());
        $stub->close();
        $this->assertSame('a', $stub->name());
        $this->assertSame('b', $stub->name());
        $this->assertSame($stub, $stub->self());
    }

    /**
     * `ReturnArgument` returns an argument of the call, so it has the
     * parameter's type.
     */
    public function testConsecutiveReturnArgument(): void
    {
        $stub = $this->createStub(Accounts::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('pick')->willReturnOnConsecutiveCalls(new ReturnArgument(0));
        $stub->pick(1);
    }

    /**
     * A negative index always gets null.
     */
    public function testConsecutiveNegativeArgument(): void
    {
        $stub = $this->createStub(Accounts::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('pick')->willReturnOnConsecutiveCalls(new ReturnArgument(-1));
        $stub->pick(1);
    }

    /**
     * A stub that returns a value fails on a `never` method.
     */
    public function testConsecutiveNeverReturnStub(): void
    {
        $stub = $this->createStub(Accounts::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('fail')->willReturnOnConsecutiveCalls(new ReturnStub(null));
        $stub->fail();
    }

    public function testConsecutiveArgumentFits(): void
    {
        $stub = $this->createStub(Accounts::class);
        $stub->method('pick')->willReturnOnConsecutiveCalls(new ReturnArgument(1));
        $this->assertSame('none', $stub->pick(1));
        $child = $this->createStub(ChildLedger::class);
        $child->method('asParent')->willReturnOnConsecutiveCalls(new ReturnSelf());
        $child->method('fluent')->willReturnOnConsecutiveCalls(new ReturnSelf());
        $this->assertSame($child, $child->asParent());
        $this->assertSame($child, $child->fluent());
    }

    /**
     * An index past the parameters gets null, unless the call passes more
     * arguments, so it's a warning.
     */
    public function testConsecutiveArgumentPastParameters(): void
    {
        $stub = $this->createStub(Accounts::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('total')->willReturnOnConsecutiveCalls(new ReturnArgument(0));
    }

    /**
     * A stub with a result that isn't known here and an object for an
     * intersection type aren't reported.
     */
    public function testConsecutiveUnknown(): void
    {
        $stub = $this->createStub(Accounts::class);
        $stub->method('both')->willReturnOnConsecutiveCalls(new Company());
        $stub->method('name')->willReturnOnConsecutiveCalls($this->createStub(
            \PHPUnit\Framework\MockObject\Stub\Stub::class,
        ));
        $this->addToAssertionCount(1);
    }

    /**
     * A double typed `MockObject` alone, a legacy union, and a double typed
     * wider than its class aren't reported. Their classes aren't known.
     */
    public function testDoublesOfUnknownClass(): void
    {
        $stub = $this->createStub(Accounts::class);
        $stub->method('person')->willReturn($this->bare);
        $stub->method('person')->willReturn($this->legacyPerson);
        $stub->method('node')->willReturn($this->entity);
        $this->legacy->method('self')->willReturn($this->legacy);
        $this->legacy->method('self')->willReturnOnConsecutiveCalls(new ReturnSelf());
        $this->assertSame($this->entity, $stub->node());
        $this->addToAssertionCount(1);
    }

    /**
     * PHPUnit takes any `willReturn()` value for a union of two or more
     * types and `null`, and for `null` alone. The double fails when it
     * returns a value that doesn't fit. A DNF type isn't checked.
     */
    public function testNullUnions(): void
    {
        $stub = $this->createStub(Accounts::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('either')->willReturn(1.5);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('nothing')->willReturn('x');
        $stub->method('nullableBoth')->willReturn('x');
        $stub->method('either')->willReturn('a');
        $this->addToAssertionCount(1);
    }

    /**
     * A `bool` for a `true` return type fits in part.
     */
    public function testBoolForTrue(bool $confirmed = true): void
    {
        // @mago-expect analysis:phpunit/mock-invalid-return
        $this->createStub(Accounts::class)->method('confirmed')->willReturn($confirmed);
    }

    /**
     * The result of a custom stub isn't known.
     */
    public function testCustomStub(): void
    {
        $stub = $this->createStub(Accounts::class);
        $stub->method('name')->willReturnOnConsecutiveCalls(new CustomStub());
        $this->assertSame('custom', $stub->name());
    }

    /**
     * A stub given to `willReturn()` for a null union is called, since
     * PHPUnit doesn't check the value at setup.
     */
    public function testStubForNullUnion(): void
    {
        $stub = $this->createStub(Accounts::class);
        $stub->method('either')->willReturn(new ReturnStub(1));
        $this->assertSame(1, $stub->either());
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('either')->willReturn(new ReturnStub(1.5));
    }

    /**
     * A double of a class named by a value that isn't a literal can be of
     * any subclass.
     */
    public function testCreatedFromClassString(): void
    {
        $stub = $this->createStub(Accounts::class);
        $stub->method('owner')->willReturn($this->createStub($this->ownerClass()));
        $this->addToAssertionCount(1);
    }

    /**
     * A double of any class doesn't fit a return type without objects.
     */
    public function testBareDoubleForString(): void
    {
        // @mago-expect analysis:phpunit/mock-invalid-return
        $this->createStub(Accounts::class)->method('name')->willReturn($this->bare);
    }

    /**
     * Returns the class of an owner.
     *
     * @return class-string<Owner>
     */
    private function ownerClass(): string
    {
        return Person::class;
    }
}
