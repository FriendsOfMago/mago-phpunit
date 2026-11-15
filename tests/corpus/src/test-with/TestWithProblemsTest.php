<?php

/**
 * @file
 * `#[TestWithJson]` data sets that can't be used by PHPUnit.
 *
 * PHPUnit stops the whole run on JSON that isn't valid, so these cases are in
 * a file of their own.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\TestWithJson;
use PHPUnit\Framework\TestCase;

final class TestWithProblemsTest extends TestCase
{
    // @mago-expect analysis:phpunit/invalid-data-provider(1)
    #[TestWithJson('[1,')]
    public function testInvalidJson(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * The other sets of the test aren't checked then.
     */
    // @mago-expect analysis:phpunit/invalid-data-provider(1)
    #[TestWithJson('1')]
    #[TestWithJson('["one"]')]
    public function testNotAnArray(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}
