<?php

/**
 * @file
 * Comparison assertions that narrow their value: `assertNotSame()`, the bound
 * assertions and `assertContains()`, plus `assertContainsOnlyInstancesOf()`
 * and `assertIsList()`, untagged on PHPUnit 10.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertNotSame;

final class ComparisonAssertionsTest extends TestCase
{
    /**
     * Takes a positive integer.
     *
     * @param positive-int $number
     */
    private function takesPositive(int $number): void {}

    /**
     * Takes a negative integer.
     *
     * @param negative-int $number
     */
    private function takesNegative(int $number): void {}

    /**
     * Takes one of two letters.
     *
     * @param 'a'|'b' $letter
     */
    private function takesLetter(string $letter): void {}

    /**
     * Takes the letter b.
     *
     * @param 'b' $letter
     */
    private function takesB(string $letter): void {}

    /**
     * Takes the letter c.
     *
     * @param 'c' $letter
     */
    private function takesC(string $letter): void {}

    /**
     * Takes a list.
     *
     * @param list<mixed> $items
     */
    private function takesList(array $items): void {}

    /**
     * `assertNotSame(null, ...)` removes null, as a method and as a function.
     */
    public function testNotSameNull(?Thing $thing, ?Thing $other): void
    {
        $this->assertNotSame(null, $thing);
        $thing->onlyOnThing();
        assertNotSame(null, $other);
        $other->onlyOnThing();
    }

    /**
     * A string literal is removed from a union of literals.
     *
     * @param 'a'|'b' $letter
     */
    public function testNotSameLiteral(string $letter): void
    {
        $this->assertNotSame('a', $letter);
        $this->takesB($letter);
    }

    /**
     * A value that isn't a single literal doesn't narrow.
     */
    public function testNotSameVariable(?Thing $thing, ?Thing $other): void
    {
        $this->assertNotSame($other, $thing);
        // @mago-expect analysis:possible-method-access-on-null
        $thing->onlyOnThing();
    }

    /**
     * An integer literal bound narrows an integer.
     */
    public function testBounds(int $above, int $atLeast, int $below, int $atMost): void
    {
        $this->assertGreaterThan(0, $above);
        $this->takesPositive($above);
        $this->assertGreaterThanOrEqual(1, $atLeast);
        $this->takesPositive($atLeast);
        $this->assertLessThan(0, $below);
        $this->takesNegative($below);
        $this->assertLessThanOrEqual(-1, $atMost);
        $this->takesNegative($atMost);
    }

    /**
     * A bound that isn't a literal doesn't narrow.
     */
    public function testBoundVariable(int $number, int $minimum): void
    {
        $this->assertGreaterThan($minimum, $number);
        // @mago-expect analysis:possibly-invalid-argument
        $this->takesPositive($number);
    }

    /**
     * A float keeps its type after a bound assertion.
     */
    public function testBoundFloat(float $ratio): float
    {
        $this->assertGreaterThan(0, $ratio);

        return $ratio;
    }

    /**
     * The needle of `assertContains()` gets the type of the haystack's values.
     */
    public function testContains(string $letter): void
    {
        $this->assertContains($letter, ['a', 'b']);
        $this->takesLetter($letter);
    }

    /**
     * `assertNotContains()` removes the haystack's values from the needle.
     *
     * @param 'a'|'b' $letter
     */
    public function testNotContains(string $letter): void
    {
        $this->assertNotContains($letter, ['a']);
        $this->takesB($letter);
    }

    /**
     * A list that may be empty doesn't remove anything from the needle.
     *
     * @param 'a'|'b' $letter
     * @param list<'a'> $haystack
     */
    public function testNotContainsMaybeEmpty(string $letter, array $haystack): void
    {
        $this->assertNotContains($letter, $haystack);
        // @mago-expect analysis:possibly-invalid-argument
        $this->takesB($letter);
    }

    /**
     * An optional entry may be missing, so only the entries that are always there are removed.
     *
     * @param 'a'|'b'|'c' $letter
     * @param array{0: 'a', 1?: 'b'} $haystack
     */
    public function testNotContainsOptional(string $letter, array $haystack): void
    {
        $this->assertNotContains($letter, $haystack);
        // @mago-expect analysis:possibly-invalid-argument
        $this->takesC($letter);
    }

    /**
     * For a union of haystacks, a value is only removed when each of them has it.
     *
     * @param 'a'|'b' $letter
     * @param array{'a'}|array{'b'} $haystack
     */
    public function testNotContainsUnion(string $letter, array $haystack): void
    {
        $this->assertNotContains($letter, $haystack);
        // @mago-expect analysis:possibly-invalid-argument
        $this->takesB($letter);
    }

    /**
     * The loose `assertContainsEquals()` doesn't narrow, since `'1'` equals `1`.
     */
    public function testContainsEquals(string $letter): void
    {
        $this->assertContainsEquals($letter, ['a', 'b']);
        // @mago-expect analysis:possibly-invalid-argument
        $this->takesLetter($letter);
    }

    /**
     * A `Traversable` haystack doesn't narrow the needle.
     *
     * @param \ArrayIterator<int, 'a'|'b'> $letters
     */
    public function testContainsTraversable(string $letter, \ArrayIterator $letters): void
    {
        $this->assertContains($letter, $letters);
        // @mago-expect analysis:possibly-invalid-argument
        $this->takesLetter($letter);
    }

    /**
     * Each value after `assertContainsOnlyInstancesOf()` is an instance of the class.
     *
     * @param iterable<mixed> $items
     */
    public function testContainsOnlyInstancesOf(iterable $items): void
    {
        $this->assertContainsOnlyInstancesOf(Thing::class, $items);
        foreach ($items as $item) {
            $item->onlyOnThing();
        }
    }

    /**
     * An array is a list after `assertIsList()`.
     *
     * @param array<mixed> $items
     */
    public function testIsList(array $items): void
    {
        $this->assertIsList($items);
        $this->takesList($items);
    }
}
