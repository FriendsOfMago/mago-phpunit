<?php

/**
 * @file
 * The emptiness assertions, which aren't annotated by PHPUnit. One case has `assertEmpty()` on a
 * value that may be counted by PHPUnit, and on `'0'`.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\TestCase;

final class EmptinessTest extends TestCase
{
    /**
     * PHPUnit annotates `assertNotNull()` but not the emptiness assertions.
     */
    public function testEmptiness(?Thing $present, ?Sealed $absent): void
    {
        $this->assertNotEmpty($present);
        $present->onlyOnThing();

        $this->assertEmpty($absent);
        // @mago-expect analysis:method-access-on-null
        $absent->onlyOnSealed();
    }
}

// Corpus: PHPUnit 11 and later.
// PHPUnit 10 tags assertEmpty() with `@psalm-assert empty`. With that tag, an
// object is narrowed to never.
final class EmptyCountableTest extends TestCase
{
    /**
     * PHPUnit counts a Countable, so an empty one passes `assertEmpty()`.
     */
    public function testEmptyCountable(\ArrayObject $items): int
    {
        $this->assertEmpty($items);

        return $items->count();
    }

    /**
     * A plain object may be Countable too.
     */
    public function testEmptyObject(object $items): object
    {
        $this->assertEmpty($items);

        return $items;
    }

    /**
     * A class that isn't final may be Countable at runtime, so the value isn't
     * narrowed.
     */
    public function testEmptyOpenClass(?Thing $maybe): void
    {
        $this->assertEmpty($maybe);
        // @mago-expect analysis:possible-method-access-on-null
        $maybe->onlyOnThing();
    }

    /**
     * `'0'` is empty to PHPUnit, as to PHP.
     */
    public function testEmptyZero(?string $value): string
    {
        $this->assertEmpty($value);

        return $value === '0' ? 'zero' : 'other';
    }

    /**
     * A template bounded by `object` may be Countable.
     *
     * @template T of object
     * @param T $value
     */
    public function testEmptyTemplate(object $value): string
    {
        $this->assertEmpty($value);

        return $value::class;
    }

    /**
     * `empty()` is true for a `GMP` or `BcMath\Number` of zero.
     */
    public function testEmptyNumbers(): string
    {
        $zero = gmp_init(0);
        $this->assertEmpty($zero);
        $decimal = new \BcMath\Number('0');
        $this->assertEmpty($decimal);

        return gmp_strval($zero) . $decimal->value;
    }

    /**
     * PHPUnit counts any `EmptyIterator` as empty.
     */
    public function testEmptyIterator(NoItems $items): NoItems
    {
        $this->assertEmpty($items);

        return $items;
    }
}
