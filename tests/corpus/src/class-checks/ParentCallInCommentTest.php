<?php

/**
 * @file
 * A file with a setUp() that starts with the parent call only in a comment,
 * and a tearDown() that does start with it. The text doesn't prove the
 * setUp() call, so the method is looked up and reported.
 */

declare(strict_types=1);

namespace Corpus;

final class ParentCallInCommentTest extends PreparedTestCase
{
    private bool $prepared = false;

    // protected function setUp(): void { parent::setUp(); }

    // @mago-expect analysis:phpunit/missing-parent-call
    protected function setUp(): void
    {
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
