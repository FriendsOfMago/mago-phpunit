<?php

/**
 * @file
 * Properties set by setUp(), and the exceptions of failed assertions.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InitializationAndThrowsTest extends TestCase
{
    private Thing $thing;

    protected function setUp(): void
    {
        $this->thing = new Thing();
    }

    /**
     * An assertion throws when it fails. The exception is caught by PHPUnit.
     */
    public function testAssertions(): void
    {
        $this->assertSame('a', strtolower('A'));
        $this->thing->onlyOnThing();
        $this->fail('Stops here.');
    }

    /**
     * Any other exception is still reported.
     */
    public function testOtherException(): void
    {
        // @mago-expect analysis:unhandled-thrown-type
        throw new RuntimeException('Not a test failure.');
    }
}

final class NotInitializedTest extends TestCase
{
    // @mago-expect analysis:uninitialized-property
    private Thing $thing;

    protected function setUp(): void {}

    public function testThing(): void
    {
        $this->thing->onlyOnThing();
        $this->addToAssertionCount(1);
    }
}
