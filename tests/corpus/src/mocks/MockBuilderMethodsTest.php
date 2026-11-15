<?php

/**
 * @file
 * A mock builder can select a protected method.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\TestCase;

final class MockBuilderMethodsTest extends TestCase
{
    public function testProtected(): void
    {
        $mock = $this->getMockBuilder(Parts::class)->onlyMethods(['inner'])->getMock();
        $mock->method('inner');
        // A final method is still reported.
        // @mago-expect analysis:phpunit/mock-undefined-method
        $mock->method('sealed');
    }
}
