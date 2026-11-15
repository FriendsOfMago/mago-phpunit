<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataProviderNameCaseTest extends TestCase
{
    public static function numberCases(): iterable
    {
        yield [1];
    }

    // @mago-expect lint:phpunit/data-provider-name-case
    #[DataProvider('NumberCases')]
    public function testAttribute(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    #[DataProvider('numberCases')]
    public function testSameCase(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    // @mago-expect lint:phpunit/data-provider-name-case
    /**
     * @dataProvider numbercases
     */
    public function testTag(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * A provider in another class isn't checked.
     *
     * @dataProvider OtherTest::NumberCases
     */
    public function testOtherClass(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * A name without a declared method is left to the analyzer's provider check.
     */
    #[DataProvider('missing')]
    public function testMissing(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}

final class DataProviderOwnMethodsTest extends TestCase
{
    public static function rows(): iterable
    {
        yield [1];
    }

    public static function RowsCases(): iterable
    {
        yield [1];
    }

    /**
     * A method of an anonymous class isn't a declaration of this class.
     */
    #[DataProvider('rows')]
    public function testAnonymousClass(int $number): void
    {
        $object = new class {
            public function ROWS(): int
            {
                return 1;
            }
        };

        $this->assertSame($number, $object->ROWS());
    }

    /**
     * A name built from parts isn't read.
     */
    #[DataProvider('Rows' . 'Cases')]
    public function testConcatenation(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    // @mago-expect lint:phpunit/data-provider-name-case
    #[DataProvider(methodName: 'ROWS')]
    public function testNamedArgument(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}
