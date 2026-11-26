<?php

/**
 * @file
 * Values returned through PHPUnit's stubs and the `willReturn*()` methods
 * that create them, which don't fit the native return type of the
 * configured method. The double uses strict types, so PHP throws a
 * `TypeError` when it returns such a value.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\MockObject\Stub\ConsecutiveCalls;
use PHPUnit\Framework\MockObject\Stub\ReturnCallback;
use PHPUnit\Framework\MockObject\Stub\ReturnStub;
use PHPUnit\Framework\MockObject\Stub\ReturnValueMap;
use PHPUnit\Framework\TestCase;

interface Forms
{
    public function name(int $id): string;

    public function title(string $text): string;

    public function anything(): object;

    public function pick(int|string ...$values): string;

    public function numbers(string $label, int ...$values): string;

    public function label(int $id, string $suffix = 'x'): string;

    public function stop(): never;

    public function close(): void;

    public function customer(): Customer;

    public function wide(?string $text): string;
}

class Customer {}

final class Numbers
{
    /**
     * Returns a number.
     */
    public static function one(): int
    {
        return 1;
    }

    /**
     * Returns a name.
     */
    public static function text(): string
    {
        return 'a';
    }
}

final class MockStubReturnTest extends TestCase
{
    /**
     * Always fails the test.
     */
    private function stopped(): void
    {
        $this->fail('Stopped.');
    }

    /**
     * An unpacked array that's written out gives its values.
     */
    public function testUnpackedValues(): void
    {
        $stub = $this->createStub(Forms::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturn(...[123]);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnOnConsecutiveCalls(...[new ReturnStub('ok'), new ReturnStub(77)]);
    }

    /**
     * A callback has the return type of its signature, declared or inferred,
     * or of the function or method it names.
     */
    public function testCallbacks(): void
    {
        $stub = $this->createStub(Forms::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnOnConsecutiveCalls(new ReturnCallback(fn(): array => []));
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnCallback(fn() => 99);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnCallback(function (): void {});
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('title')->willReturnCallback('strlen');
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnCallback([Numbers::class, 'one']);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnCallback(Numbers::one(...));
    }

    public function testCallbacksFit(callable $callback): void
    {
        $stub = $this->createStub(Forms::class);
        $stub->method('name')->willReturnCallback(fn(int $id): string => "n{$id}");
        $stub->method('name')->willReturnCallback([Numbers::class, 'text']);
        $stub->method('name')->willReturnCallback($callback);
        $stub->method('stop')->willReturnCallback(static function (): never {
            // @mago-expect analysis:unhandled-thrown-type
            throw new \RuntimeException();
        });
        $stub->method('close')->willReturnCallback(fn(): int => 1);
        $this->assertSame('n1', $stub->name(1));
    }

    /**
     * A closure that takes a variable by reference returns its value when
     * the double is called, which may differ from the type Mago infers. An
     * inferred type, an argument with a wider type and a docblock callable
     * are only reported when no part of them fits. Invalid callables aren't
     * read.
     *
     * @param callable(): void $sideEffect
     */
    public function testCallbacksNotKnown(callable $sideEffect): void
    {
        $stub = $this->createStub(Forms::class);
        $value = null;
        $stub->method('name')->willReturnCallback(static function () use (&$value) {
            return $value;
        });
        $stub->method('name')->willReturnCallback(static function () use (&$value): ?string {
            return $value;
        });
        $value = 'set later';
        $queue = ['a', 'b'];
        $stub->method('name')->willReturnCallback(static function () use (&$queue) {
            return array_shift($queue);
        });
        $stub->method('title')->willReturnCallback(static fn(string $text) => preg_replace('/a/', 'b', $text));
        $stub->method('wide')->willReturnArgument(0);
        $stub->method('name')->willReturnCallback($sideEffect);
        $stub->method('stop')->willReturnCallback(function (): void {
            $this->fail('Stopped.');
        });
        $stub->method('stop')->willReturnCallback(fn() => $this->stopped());
        $stub->method('stop')->willReturnCallback(static function () {
            // @mago-expect analysis:unhandled-thrown-type
            throw new \RuntimeException();
        });
        // @mago-expect analysis:possibly-invalid-argument
        $stub->method('name')->willReturnCallback('::');
        // @mago-expect analysis:less-specific-nested-argument-type
        $stub->method('name')->willReturnCallback([$this, '']);
        $this->assertSame('set later', $stub->name(1));
    }

    /**
     * A value map returns the value of a matching row, or null when no row
     * has one value for each parameter and the returned value.
     */
    public function testValueMaps(): void
    {
        $stub = $this->createStub(Forms::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnOnConsecutiveCalls(new ReturnValueMap([[100, 123]]));
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnMap([[100, 'ok'], [200, 456]]);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnMap([]);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnOnConsecutiveCalls(new ReturnValueMap([['ok']]));
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('label')->willReturnOnConsecutiveCalls(new ReturnValueMap([[1, 'one']]));
    }

    /**
     * `willReturnMap()` fills in a short row with the defaults of the
     * parameters. A map whose rows aren't known isn't reported.
     *
     * @param list<list<mixed>> $rows
     */
    public function testValueMapsFit(array $rows): void
    {
        $stub = $this->createStub(Forms::class);
        $stub->method('label')->willReturnMap([[1, 'one']]);
        $stub->method('name')->willReturnMap($rows);
        $stub->method('name')->willReturnMap([[1, 'one'], [2, 'two']]);
        $stub->method('pick')->willReturnMap([['a', 'b', 'c']]);
        $this->assertSame('one', $stub->label(1));
    }

    /**
     * A `ConsecutiveCalls` stub returns its values in turn, and calls the stubs among them.
     */
    public function testNestedConsecutiveCalls(): void
    {
        $stub = $this->createStub(Forms::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnOnConsecutiveCalls(new ConsecutiveCalls([123]));
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnOnConsecutiveCalls(new ConsecutiveCalls([new ReturnStub(123)]));
        $stub->method('name')->willReturnOnConsecutiveCalls(new ConsecutiveCalls(['a', new ReturnStub('b')]));
    }

    /**
     * An argument has the type of its parameter. A variadic parameter takes
     * the index and those after it. A parameter type that fits only in part
     * isn't reported. An index past the parameters gets null, unless the
     * call passes more arguments, so it's a warning.
     */
    public function testArguments(): void
    {
        $stub = $this->createStub(Forms::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('numbers')->willReturnArgument(1);
        $stub->method('pick')->willReturnArgument(0);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnArgument(1);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnArgument(0);
        $stub->method('label')->willReturnArgument(1);
        $this->assertSame('x', $stub->label(1));
    }

    /**
     * `willReturnSelf()` returns the double, and a stub given to
     * `willReturn()` passes PHPUnit's check for a method that returns any
     * object, so the double returns its result.
     */
    public function testSelfAndStubs(): void
    {
        $stub = $this->createStub(Forms::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnSelf();
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('anything')->willReturn(new ReturnStub(5));
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->will(new ReturnStub(5));
        $stub->method('anything')->willReturn(new ReturnStub(new Customer()));
        $stub->method('anything')->willReturnSelf();
    }

    /**
     * A `never` method fails when the double gets a value, other than from
     * a stub that throws.
     */
    public function testNever(): void
    {
        $stub = $this->createStub(Forms::class);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('stop')->willReturnCallback(fn() => 1);
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('stop')->willReturnSelf();
        $stub->method('stop')->will($this->throwException(new \RuntimeException()));
    }
}
