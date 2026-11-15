<?php

/**
 * @file
 * A partial mock can select a protected method.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\TestCase;

final class MockPartialMethodsTest extends TestCase
{
    public function testProtected(): void
    {
        $mock = $this->createPartialMock(Parts::class, ['inner']);
        $mock->method('inner');
        // A final method is still reported.
        // @mago-expect analysis:phpunit/mock-undefined-method
        $mock->method('sealed');
    }
}
