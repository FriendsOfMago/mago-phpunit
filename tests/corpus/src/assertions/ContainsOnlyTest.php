<?php

/**
 * @file
 * `assertContainsOnly()` narrows the values of the haystack. PHPUnit 11.5 deprecates it and
 * PHPUnit 13 removes it. The deprecation is ignored here by the corpus's config. PHPUnit 10
 * doesn't have the deprecation.
 *
 * On PHPUnit 10, one case passes a class name. From PHPUnit 11.5 on, the docblock of
 * `assertContainsOnly()` allows only native type names, and a class goes to
 * `assertContainsOnlyInstancesOf()` instead.
 */

// Corpus: PHPUnit 12 and earlier.

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\TestCase;

final class ContainsOnlyTest extends TestCase
{
    /**
     * @param iterable<mixed> $names
     * @param array<mixed> $numbers
     * @param iterable<mixed> $nested
     * @param list<mixed> $fractions
     */
    public function testNarrows(iterable $names, array $numbers, iterable $nested, array $fractions): void
    {
        $this->assertContainsOnly('string', $names);
        foreach ($names as $name) {
            $this->assertGreaterThan(0, strlen($name));
        }

        $this->assertContainsOnly('integer', $numbers);
        // PHPUnit 12 treats `double` as float. PHPUnit 10 and 11 throw for it.
        $this->assertContainsOnly('double', $fractions);
        foreach ($fractions as $fraction) {
            $this->assertGreaterThan(0.0, $fraction + 1.5);
        }

        $this->assertGreaterThan(0, array_sum($numbers) + 1);

        // A plain `iterable` doesn't have a value type.
        $this->assertContainsOnly('iterable', $nested);
        // @mago-expect analysis:mixed-assignment
        foreach ($nested as $value) {
            $this->assertNotNull($value);
        }
    }
}

// Corpus: PHPUnit 10 and earlier.
final class ContainsOnlyClassTest extends TestCase
{
    /**
     * @param array<mixed> $things
     */
    public function testClass(array $things): void
    {
        $this->assertContainsOnly(Thing::class, $things);
        foreach ($things as $thing) {
            $thing->onlyOnThing();
        }
    }
}
