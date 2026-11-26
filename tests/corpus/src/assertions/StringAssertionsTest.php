<?php

/**
 * @file
 * The string assertions, which aren't annotated by PHPUnit. A string that
 * contains, starts with or ends with a non-empty string isn't empty, and
 * neither is valid JSON.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertStringContainsString;

final class StringAssertionsTest extends TestCase
{
    /**
     * The checked string is a non-empty string afterwards, also when the
     * value given to the assertion may be false.
     */
    public function testNarrowed(string $contains, string $starts, string $ends, string $json, string|false $read): void
    {
        $this->assertStringContainsString('needle', $contains);
        self::takesNonEmpty($contains);
        self::assertStringStartsWith('prefix', $starts);
        self::takesNonEmpty($starts);
        static::assertStringEndsWith('suffix', $ends);
        self::takesNonEmpty($ends);
        $this->assertJson($json);
        self::takesNonEmpty($json);
        // @mago-expect analysis:possibly-false-argument
        assertStringContainsString('needle', $read);
        self::takesNonEmpty($read);
    }

    /**
     * An empty needle, or one that may be empty, matches the empty string.
     */
    public function testNotNarrowed(string $haystack, string $needle): void
    {
        $this->assertStringContainsString('', $haystack);
        $this->assertStringContainsString($needle, $haystack);
        // @mago-expect analysis:possibly-invalid-argument
        self::takesNonEmpty($haystack);
    }

    /**
     * Other assertions and needles that are never empty.
     *
     * @param non-empty-string $needle
     */
    public function testOtherForms(string $folded, string $byClass, string $named, mixed $json, string $needle): void
    {
        $this->assertStringContainsStringIgnoringCase('needle', $folded);
        self::takesNonEmpty($folded);
        Assert::assertStringContainsString(Thing::class, $byClass);
        self::takesNonEmpty($byClass);
        $this->assertStringEndsWith($needle, $named);
        self::takesNonEmpty($named);
        // @mago-expect analysis:mixed-argument
        $this->assertJson($json);
        self::takesNonEmpty($json);
    }

    /**
     * Without strict types, PHP converts an int to a string for the
     * assertion, so a value that may be an int isn't narrowed.
     */
    public function testOtherScalars(int|string $id): void
    {
        // @mago-expect analysis:possibly-invalid-argument
        $this->assertStringContainsString('2', $id);
        // @mago-expect analysis:possibly-invalid-argument
        self::takesNonEmpty($id);
    }

    /**
     * An unpacked argument isn't narrowed.
     */
    public function testUnpacked(mixed $parts): void
    {
        // @mago-expect analysis:mixed-argument
        $this->assertStringContainsString('needle', ...$parts);
        // @mago-expect analysis:mixed-argument
        self::takesNonEmpty($parts);
    }

    /**
     * A value that can't be a string isn't narrowed, since its type may only
     * be a guess.
     */
    public function testNotAString(): void
    {
        $value = null;
        // @mago-expect analysis:null-argument
        $this->assertStringContainsString('needle', $value);
    }

    /**
     * Takes a non-empty string.
     *
     * @param non-empty-string $value
     */
    private static function takesNonEmpty(string $value): void
    {
        unset($value);
    }
}
