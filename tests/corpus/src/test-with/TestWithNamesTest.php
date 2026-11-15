<?php

/**
 * @file
 * Names of `#[TestWith]` data sets, supported by PHPUnit 11 and later. The cases:
 *
 * - Named sets and string keys.
 * - Names that are null or used twice.
 * - Keys of unnamed sets after a negative integer name.
 */

// Corpus: PHPUnit 11 and later.

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\TestWithJson;
use PHPUnit\Framework\TestCase;

final class TestWithNamedTest extends TestCase
{
    private const WRONG = 'wrong';

    /**
     * The set named `wrong` is reported by its name.
     */
    // @mago-expect analysis:phpunit/data-provider-mismatch(1)
    #[TestWith([1], 'right')]
    #[TestWith(['one'], 'wrong')]
    public function testNamedSet(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * A name such as `'5'` is the integer key 5, so the next unnamed set is #6.
     */
    // @mago-expect analysis:phpunit/data-provider-mismatch(1)
    #[TestWith([1], '5')]
    #[TestWith(['one'])]
    public function testNumericName(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * A name given by a constant is resolved.
     */
    // @mago-expect analysis:phpunit/data-provider-mismatch(1)
    #[TestWith(['one'], self::WRONG)]
    public function testConstantName(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    // @mago-expect analysis:phpunit/data-provider-mismatch(1)
    #[TestWithJson('{"number": 1, "nmae": "one"}')]
    public function testJsonStringKeys(int $number, string $name = ''): void
    {
        $this->assertNotSame('x', $name);
        $this->assertGreaterThan(0, $number);
    }

    // @mago-expect analysis:phpunit/data-provider-mismatch(1)
    #[TestWith(['number' => 1, 'nmae' => 'one'])]
    public function testStringKeys(int $number, string $name = ''): void
    {
        $this->assertNotSame('x', $name);
        $this->assertGreaterThan(0, $number);
    }
}

final class TestWithNameKeysTest extends TestCase
{
    private const ?string NO_NAME = null;

    /**
     * PHPUnit treats a null name as no name, so the set is still checked.
     */
    // @mago-expect analysis:phpunit/data-provider-mismatch(2)
    #[TestWith(['wrong'], null)]
    #[TestWith(['wrong'], self::NO_NAME)]
    public function testNullName(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * PHPUnit stops the test at the second set, so the sets aren't checked.
     */
    // @mago-expect analysis:phpunit/invalid-data-provider
    #[TestWith(['one'], 'same')]
    #[TestWith(['two'], 'same')]
    public function testSameName(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * PHPUnit keeps a leading backslash in a name, so `'\one'` and `'one'`
     * are different keys.
     */
    #[TestWith([1], '\one')]
    #[TestWith([2], 'one')]
    public function testBackslashName(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * The name `'0'` is the key of the unnamed set before it.
     */
    // @mago-expect analysis:phpunit/invalid-data-provider
    #[TestWith([1])]
    #[TestWith([2], '0')]
    public function testNameOfNumberedSet(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * The unnamed set after the name `'1'` gets the key 2.
     */
    #[TestWith([1], '1')]
    #[TestWith([2])]
    #[TestWith([3], 'other')]
    public function testDistinctKeys(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}

final class TestWithNegativeNameTest extends TestCase
{
    /**
     * From PHP 8.3, the unnamed set after the name `'-5'` is given the key -4.
     */
    // @mago-expect analysis:phpunit/invalid-data-provider
    #[TestWith([1], '-5')]
    #[TestWith([2])]
    #[TestWith([3], '-4')]
    public function testNextKeyAfterNegative(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * From PHP 8.3, the unnamed set after the name `'-5'` isn't given the key 0.
     */
    #[TestWith([1], '-5')]
    #[TestWith([2])]
    #[TestWith([3], '0')]
    public function testZeroAfterNegative(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}
