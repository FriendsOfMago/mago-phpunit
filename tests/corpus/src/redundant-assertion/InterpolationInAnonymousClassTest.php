<?php

declare(strict_types=1);

// A `"${name}"` interpolation in an anonymous class, whose property has the
// name of a property of the test class.
namespace Corpus;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

final class InterpolationInAnonymousClassTest extends TestCase
{
    /**
     * A value that is never null.
     */
    protected string $value = 'outer';

    public function testAnonymousProperty(): void
    {
        $holder = new class {
            /**
             * A value that can be cleared.
             */
            public ?string $value = 'inner';

            /**
             * Greets the name.
             */
            public function greet(string $name): string
            {
                return "Hi ${name}";
            }

            /**
             * Sets the value and runs the callback, which can clear it, then
             * checks it.
             */
            public function check(callable $clear): void
            {
                $this->value = 'set';
                $clear();
                // Declared nullable, and the callback may have cleared it.
                Assert::assertNotNull($this->value);
            }
        };
        $holder->check(static function () use ($holder): void {
            $holder->value = null;
        });
        $this->assertSame('outer', $this->value);
    }
}
