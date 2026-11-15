<?php

use PHPUnit\Framework\TestCase;

final class StyleEdgesTest extends TestCase
{
    public function testEdges(bool $flag): void
    {
        // @mago-expect lint:phpunit/assertion-style-static
        $this /* -> */ ->assertTrue($flag);
        // @mago-expect lint:phpunit/assertion-style-static
        self /* a::b */ ::assertTrue($flag);
        $text = "{$this->assertTrue($flag)}";
        static::assertTrue($flag);
    }
}
