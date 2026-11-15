<?php

/**
 * @file
 * A test whose setUp() and tearDown() start with the parent call. The call
 * is found in the file's text, without a method lookup.
 */

declare(strict_types=1);

namespace Corpus;

final class ParentCallFirstTest extends PreparedTestCase
{
    private bool $prepared = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepared = true;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->prepared = false;
    }

    public function testPrepared(): void
    {
        self::assertTrue($this->prepared);
    }
}
