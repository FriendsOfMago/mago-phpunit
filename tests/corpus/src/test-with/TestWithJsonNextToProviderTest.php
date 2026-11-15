<?php

/**
 * @file
 * `#[TestWithJson]` JSON that isn't valid, on a test with a data provider.
 *
 * PHPUnit decodes the JSON when it reads the metadata, so JSON that isn't
 * valid stops the whole run. The cases are in a file of their own.
 */

// Corpus: PHPUnit 11 and earlier.

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWithJson;
use PHPUnit\Framework\TestCase;

final class TestWithJsonNextToProviderTest extends TestCase
{
    public static function rows(): iterable
    {
        yield [1];
    }

    // @mago-expect analysis:phpunit/invalid-data-provider
    #[DataProvider('rows')]
    #[TestWithJson('[1,')]
    public function testInvalidJson(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * PHPUnit ignores JSON that is valid but isn't an array.
     */
    #[DataProvider('rows')]
    #[TestWithJson('1')]
    public function testNotAnArray(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}
