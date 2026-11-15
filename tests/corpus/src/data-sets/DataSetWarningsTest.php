<?php

/**
 * @file
 * Data sets with a warning from PHPUnit 12.5 and later. The cases are data sets with more values
 * than the test takes, and the `validateArgumentCount` flag of a data provider given as a constant.
 */

// Corpus: PHPUnit 12 and later.

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class DataSetWarningsTest extends TestCase
{
    public static function rows(): iterable
    {
        yield [1];
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield [2, 3];
    }

    #[DataProvider('rows')]
    public function testMoreValues(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * The second argument is `validateArgumentCount`. PHPUnit doesn't allow named arguments.
     */
    #[DataProvider('rows', false)]
    public function testNotCounted(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    #[DataProvider('rows')]
    public function testVariadic(int ...$numbers): void
    {
        $this->assertNotEmpty($numbers);
    }

    // @mago-expect analysis:phpunit/data-provider-mismatch
    #[TestWith([1, 2])]
    public function testTestWithMoreValues(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    public static function sparse(): iterable
    {
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield [10 => 1, 20 => 2];
    }

    /**
     * PHPUnit counts the values, whatever their keys.
     */
    #[DataProvider('sparse')]
    public function testSparseKeys(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * PHPUnit only uses the data provider.
     */
    // @mago-expect analysis:phpunit/invalid-data-provider
    #[TestWith(['one'])]
    #[DataProvider('single')]
    public function testMixed(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    public static function single(): iterable
    {
        yield [1];
    }
}

final class ProviderFlagConstantTest extends TestCase
{
    private const bool NO = false;

    private const bool YES = true;

    /**
     * @return iterable<array{int, int, int}>
     */
    public static function three(): iterable
    {
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield [1, 2, 3];
    }

    /**
     * The second argument is `validateArgumentCount`. PHPUnit doesn't allow named arguments.
     */
    #[DataProvider('three', self::NO)]
    public function testConstantOff(int $first, int $second): void
    {
        $this->assertNotSame($first, $second);
    }

    #[DataProvider('three', self::YES)]
    public function testConstantOn(int $first, int $second): void
    {
        $this->assertNotSame($first, $second);
    }
}
