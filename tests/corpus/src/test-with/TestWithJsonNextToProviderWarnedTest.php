<?php

/**
 * @file
 * `#[TestWithJson]` JSON that isn't valid, on a test with a data provider.
 *
 * PHPUnit decodes the JSON when it reads the metadata, so JSON that isn't
 * valid stops the whole run. PHPUnit 12.5 and later also warn about the
 * `#[TestWithJson]` next to the data provider.
 */

// Corpus: PHPUnit 12 and later.

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWithJson;
use PHPUnit\Framework\TestCase;

final class TestWithJsonNextToProviderWarnedTest extends TestCase
{
    public static function rows(): iterable
    {
        yield [1];
    }

    // @mago-expect analysis:phpunit/invalid-data-provider(2)
    #[DataProvider('rows')]
    #[TestWithJson('[1,')]
    public function testInvalidJson(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}
