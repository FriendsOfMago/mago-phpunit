<?php

/**
 * @file
 * Results of dependencies that don't fit the parameters of the test. PHPUnit
 * passes them after the test's data, and its test runner uses strict types.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\Attributes\DependsUsingDeepClone;
use PHPUnit\Framework\TestCase;

final class DependencyResultsTest extends TestCase
{
    public const PRODUCER = 'testInt';

    public function testInt(): int
    {
        $this->addToAssertionCount(1);

        return 1;
    }

    public function testMaybeString(): ?string
    {
        $this->addToAssertionCount(1);

        return 'a';
    }

    public function testObject(): Thing
    {
        $this->addToAssertionCount(1);

        return new Thing();
    }

    public function testUntyped()
    {
        $this->addToAssertionCount(1);

        return 'a';
    }

    // @mago-expect analysis:phpunit/dependency-mismatch
    #[Depends('testInt')]
    public function testIntForString(string $value): void
    {
        $this->assertNotSame('', $value);
    }

    // @mago-expect analysis:phpunit/dependency-mismatch
    #[Depends(self::PRODUCER)]
    public function testConstantTarget(string $value): void
    {
        $this->assertNotSame('', $value);
    }

    // @mago-expect analysis:phpunit/dependency-mismatch
    #[DependsUsingDeepClone('testObject')]
    public function testCloneForInt(int $value): void
    {
        $this->assertGreaterThan(0, $value);
    }

    // @mago-expect analysis:phpunit/dependency-mismatch
    #[DependsExternal(DependencyResultsTest::class, 'testInt')]
    public function testExternal(string $value): void
    {
        $this->assertNotSame('', $value);
    }

    /**
     * The second result goes to the second parameter.
     */
    #[Depends('testObject')]
    // @mago-expect analysis:phpunit/dependency-mismatch
    #[Depends('testInt')]
    public function testSecond(Thing $thing, string $value): void
    {
        $this->assertNotSame('', $value . $thing::class);
    }

    // @mago-expect analysis:phpunit/dependency-mismatch
    #[Depends('testInt')]
    public function testVariadic(string ...$values): void
    {
        $this->assertCount(1, $values);
    }

    /**
     * The result comes after the data set's values.
     */
    // @mago-expect analysis:phpunit/dependency-mismatch
    #[Depends('testInt')]
    #[DataProvider('numbers')]
    public function testAfterData(int $number, string $value): void
    {
        $this->assertNotSame('', $value . $number);
    }

    /**
     * PHP returns an `int` as a `float`, a result that may fit isn't
     * reported, and a test without a native return type isn't checked.
     */
    #[Depends('testInt')]
    #[Depends('testMaybeString')]
    #[Depends('testUntyped')]
    public function testFits(float $number, string $value, string $untyped): void
    {
        $this->assertNotSame('', $value . $number . $untyped);
    }

    /**
     * The result goes after the values of each data set.
     */
    // @mago-expect analysis:phpunit/dependency-mismatch(2)
    #[Depends('testInt')]
    #[DataProvider('uneven')]
    public function testUneven(int $number, string $value = '', string $more = ''): void
    {
        $this->assertNotSame('', $value . $number . $more);
    }

    /**
     * A result after data that filled the variadic parameter goes there too.
     */
    // @mago-expect analysis:phpunit/dependency-mismatch
    #[Depends('testObject')]
    #[DataProvider('three')]
    public function testVariadicAfterData(int ...$numbers): void
    {
        $this->assertCount(4, $numbers);
    }

    /**
     * A nullable parameter doesn't take an `int` either.
     */
    // @mago-expect analysis:phpunit/dependency-mismatch
    #[Depends('testInt')]
    public function testNullable(?string $value = 'x'): void
    {
        $this->assertNotSame('', $value);
    }

    /**
     * PHPUnit runs a public static test and passes its result.
     */
    // @mago-expect analysis:phpunit/dependency-mismatch
    #[Depends('testStatic')]
    public function testFromStatic(string $value): void
    {
        $this->assertNotSame('', $value);
    }

    public static function testStatic(): int
    {
        return 1;
    }

    /**
     * The second name in another case makes PHPUnit skip the test.
     */
    #[Depends('testInt')]
    #[Depends('testint')]
    public function testSameNameInAnotherCase(string $value = 'x'): void
    {
        $this->assertNotSame('', $value);
    }

    /**
     * A test that expects an exception passes null.
     */
    #[Depends('testThrows')]
    public function testAfterException(?string $value): void
    {
        $this->assertNull($value);
    }

    /**
     * Expects an exception, so PHPUnit passes null to the tests that depend on it.
     *
     * @throws \RuntimeException
     */
    public function testThrows(): int
    {
        $this->expectException(\RuntimeException::class);

        throw new \RuntimeException();
    }

    /**
     * PHPUnit skips or fails a test with a dependency it can't use, before
     * the test runs.
     */
    #[Depends('testint')]
    public function testWrongCase(string $value): void
    {
        $this->assertNotSame('', $value);
    }

    #[DependsExternal('\\Corpus\\DependencyResultsTest', 'testInt')]
    public function testLeadingBackslash(string $value): void
    {
        $this->assertNotSame('', $value);
    }

    #[Depends('helper')]
    public function testHelperTarget(string $value): void
    {
        $this->assertNotSame('', $value);
    }

    public function helper(): int
    {
        return 1;
    }

    /**
     * @return iterable<array{int, int, int}>
     */
    public static function three(): iterable
    {
        yield [1, 2, 3];
    }

    /**
     * @return iterable<array{int}>
     */
    public static function numbers(): iterable
    {
        yield [1];
    }

    /**
     * @return iterable<list<int|string>>
     */
    public static function uneven(): iterable
    {
        yield [1];
        yield [1, 'a'];
    }
}

// Corpus: PHPUnit 10 only.
final class VoidDependencyResultTest extends TestCase
{
    public function testVoid(): void
    {
        $this->addToAssertionCount(1);
    }

    /**
     * PHPUnit 10 passes null for a test that returns `void`.
     */
    // @mago-expect analysis:phpunit/dependency-mismatch
    #[Depends('testVoid')]
    public function testNull(string $value): void
    {
        $this->assertNotSame('', $value);
    }
}

// Corpus: PHPUnit 12 and later.
/**
 * A test with its own `invokeTestMethod()` may get other arguments.
 */
final class OwnArgumentsTest extends TestCase
{
    public function testInt(): int
    {
        $this->addToAssertionCount(1);

        return 1;
    }

    #[Depends('testInt')]
    public function testUse(string $injected, int $number): void
    {
        $this->assertNotSame('', $injected . $number);
    }

    /**
     * Passes a string before the other arguments.
     *
     * @param array<mixed> $testArguments
     */
    protected function invokeTestMethod(string $methodName, array $testArguments): mixed
    {
        // @mago-expect analysis:string-member-selector
        return $this->{$methodName}('injected', ...$testArguments);
    }
}

// Corpus: PHPUnit 10 only.
final class StringKeyDependencyTest extends TestCase
{
    public function testInt(): int
    {
        $this->addToAssertionCount(1);

        return 1;
    }

    /**
     * PHPUnit 10 passes string-keyed values in order, so the result comes
     * after them.
     */
    // @mago-expect analysis:phpunit/dependency-mismatch
    #[Depends('testInt')]
    #[DataProvider('named')]
    public function testNamed(int $first, string $second = 'x'): void
    {
        $this->assertNotSame('', $second . $first);
    }

    /**
     * @return iterable<array{first: int}>
     */
    public static function named(): iterable
    {
        yield ['first' => 1];
    }
}

/**
 * A dependency on a test declared only in the subclasses isn't found, so the
 * results aren't known.
 */
abstract class AbstractDependencyTestCase extends TestCase
{
    #[Depends('testCreate')]
    #[DataProvider('named')]
    public function testUse(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * @return iterable<array{number: int}>
     */
    public static function named(): iterable
    {
        yield ['number' => 1];
    }
}

// Corpus: PHPUnit 11 and earlier.
final class TaggedDependencyTest extends TestCase
{
    /**
     * A test by its tag.
     *
     * @test
     */
    public function creates(): int
    {
        $this->addToAssertionCount(1);

        return 1;
    }

    // @mago-expect analysis:phpunit/dependency-mismatch
    /** @depends creates */
    public function testUse(string $value): void
    {
        $this->assertNotSame('', $value);
    }
}
