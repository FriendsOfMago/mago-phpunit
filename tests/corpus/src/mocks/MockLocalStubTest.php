<?php

/**
 * @file
 * Stubs, lists and doubles held in local variables. A variable that's
 * assigned once, in a statement of the method's body, and isn't changed
 * anywhere else, is followed to the expression assigned to it.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\MockObject\Stub\ConsecutiveCalls;
use PHPUnit\Framework\MockObject\Stub\ReturnArgument;
use PHPUnit\Framework\MockObject\Stub\ReturnCallback;
use PHPUnit\Framework\MockObject\Stub\ReturnStub;
use PHPUnit\Framework\MockObject\Stub\ReturnValueMap;
use PHPUnit\Framework\TestCase;

interface Journal
{
    public function name(int $id): string;

    public function stop(): never;

    public function entry(): Entry;

    public function owner(): Holder;

    public function ids(): int;
}

class Entry {}

class Audit
{
    public function __construct(
        public readonly Auditor $auditor,
    ) {}

    /**
     * Checks the values.
     *
     * @param list<int> $values
     */
    public function check(array $values): bool
    {
        return $values !== [];
    }
}

final class Lists
{
    /**
     * Replaces the list.
     *
     * @param array<mixed> $list
     */
    public static function replace(array &$list): void
    {
        $list = ['a'];
    }

    /**
     * Replaces the stub.
     */
    public static function replaceStub(ReturnStub &$stub): void
    {
        $stub = new ReturnStub('a');
    }
}

/**
 * An exception that can be unpacked.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class ListFailure extends \RuntimeException implements \IteratorAggregate
{
    /**
     * Yields one name.
     *
     * @return \Generator<int, string>
     */
    public function getIterator(): \Generator
    {
        yield 'a';
    }
}

interface Holder {}

interface Auditor {}

final class MockLocalStubTest extends TestCase
{
    public function testStubsInVariables(): void
    {
        $stub = $this->createStub(Journal::class);
        $number = new ReturnStub(321);
        $alias = $number;
        $callback = new ReturnCallback(static fn(): int => 42);
        $list = [new ReturnStub(88)];
        $argument = new ReturnArgument(0);
        $map = new ReturnValueMap([[1, 2]]);
        $items = [new ReturnStub(5)];
        $nested = new ConsecutiveCalls($items);
        $empty = new ReturnStub(null);
        $closure = static fn(): int => 7;
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnOnConsecutiveCalls($number);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnOnConsecutiveCalls($alias);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnOnConsecutiveCalls($callback);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnOnConsecutiveCalls(...$list);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->will($argument);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->will($map);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->will($nested);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('stop')->willReturnOnConsecutiveCalls($empty);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnCallback($closure);
    }

    /**
     * A double created right there has a known class, also through a
     * variable, so a double of another class can't be the returned one.
     */
    public function testDoublesInVariables(): void
    {
        $stub = $this->createStub(Journal::class);
        $auditor = $this->createStub(Auditor::class);
        $audit = new Audit($auditor);
        self::assertNotSame($auditor, $audit);
        $partial = $this->createPartialMock(Audit::class, []);
        $values = [5];
        self::assertCount(1, $values);
        $partial->check($values);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnOnConsecutiveCalls(...$values);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('entry')->willReturnSelf();
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('owner')->willReturn($auditor);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('owner')->willReturnOnConsecutiveCalls($auditor);
        $this->assertNotSame($auditor, $stub);
    }

    /**
     * A variable that may change isn't followed: assigned twice, in a
     * branch, after the call, in a loop, by reference, as a parameter, or
     * in a body that can set any variable.
     */
    public function testChangedVariables(bool $flag, ReturnStub $given, string $code): void
    {
        $stub = $this->createStub(Journal::class);
        $twice = new ReturnStub(1);
        $twice = new ReturnStub('a');
        $stub->method('name')->willReturnOnConsecutiveCalls($twice);
        if ($flag) {
            $branch = new ReturnStub(1);
            $stub->method('name')->willReturnOnConsecutiveCalls($branch);
        }

        $stub->method('name')->willReturnOnConsecutiveCalls($given);
        $appended = [new ReturnStub('a')];
        $appended[] = new ReturnStub('b');
        $stub->method('name')->willReturnOnConsecutiveCalls(...$appended);
        $captured = new ReturnStub(1);
        $change = static function () use (&$captured): void {
            $captured = new ReturnStub('a');
        };
        $change();
        $stub->method('name')->willReturnOnConsecutiveCalls($captured);
        $looped = [new ReturnStub(1)];
        foreach ($looped as &$item) {
            $item = new ReturnStub('a');
        }

        unset($item);
        $stub->method('name')->willReturnOnConsecutiveCalls(...$looped);
        $shifted = [1, 'a'];
        array_shift($shifted);
        $stub->method('name')->willReturnOnConsecutiveCalls(...$shifted);
        $replaced = [1];
        Lists::replace($replaced);
        $stub->method('name')->will(new ConsecutiveCalls($replaced));
        $replacedStub = new ReturnStub(1);
        Lists::replaceStub($replacedStub);
        $stub->method('name')->willReturnOnConsecutiveCalls($replacedStub);
        $this->evaluated($code);
    }

    /**
     * An assignment that may not run, a `catch` variable, a `goto` and a
     * `ConsecutiveCalls` stub shared by two methods aren't followed either.
     *
     * @param list<mixed> $given
     */
    public function testOtherChanges(bool $flag, array $given): void
    {
        $stub = $this->createStub(Journal::class);
        // @mago-expect analysis:invalid-operand
        $flag && ($given = [1]);
        $stub->method('name')->willReturnOnConsecutiveCalls(...$given);
        $caught = [1];
        try {
            throw new ListFailure();
        } catch (ListFailure $caught) {
        }

        $stub->method('name')->willReturnOnConsecutiveCalls(...$caught);
        $shared = new ConsecutiveCalls([1, 'a']);
        $stub->method('ids')->will($shared);
        $stub->method('name')->will($shared);
        $sharedList = [new ConsecutiveCalls([1, 'a'])];
        $stub->method('ids')->willReturnOnConsecutiveCalls(...$sharedList);
        $stub->method('name')->willReturnOnConsecutiveCalls(...$sharedList);
        $this->assertSame(1, $stub->ids());
        $this->assertSame('a', $stub->name(1));
    }

    /**
     * A body with `eval` can set any variable.
     */
    private function evaluated(string $code): void
    {
        $stub = $this->createStub(Journal::class);
        $evaluated = new ReturnStub(1);
        eval($code);
        $stub->method('name')->willReturnOnConsecutiveCalls($evaluated);
    }

    /**
     * A double whose variable may hold another double isn't known.
     */
    public function testChangedDoubles(bool $flag): void
    {
        $stub = $this->createStub(Journal::class);
        $auditor = $this->createStub(Auditor::class);
        if ($flag) {
            $auditor = $this->createStub(Holder::class);
        }

        $stub->method('owner')->willReturn($auditor);
        $this->assertNotSame($auditor, $stub);
    }
}
