<?php

/**
 * @file
 * Data providers that don't return any data sets. PHPUnit fails a test with such a provider. On
 * PHPUnit 13 and later, a test can ask to be skipped instead.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmptyDataProviderTest extends TestCase
{
    /**
     * @return array<array{int}>
     */
    public static function none(): array
    {
        // @mago-expect analysis:phpunit/invalid-data-provider
        return [];
    }

    /**
     * @return array<array{int}>
     */
    public static function noneOnEitherBranch(): array
    {
        if (getenv('CORPUS_FLAG') !== false) {
            // @mago-expect analysis:phpunit/invalid-data-provider
            return [];
        }

        return [];
    }

    /**
     * @return array<array{int}>
     */
    public static function some(): array
    {
        return [[1]];
    }

    /**
     * @return array<array{int}>
     */
    public static function emptyOnOneBranch(): array
    {
        if (getenv('CORPUS_FLAG') !== false) {
            return [];
        }

        return [[1]];
    }

    /**
     * Empty, but only used next to a provider with data.
     *
     * @return array<array{int}>
     */
    public static function noneButMerged(): array
    {
        return [];
    }

    /**
     * A generator isn't checked, since whether it yields depends on its control flow.
     *
     * @return iterable<array{int}>
     */
    public static function generator(): iterable
    {
        yield from [];
    }

    #[DataProvider('none')]
    public function testNone(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * A second test with the same provider doesn't report it again.
     */
    #[DataProvider('none')]
    public function testNoneAgain(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    #[DataProvider('noneOnEitherBranch')]
    public function testNoneOnEitherBranch(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    #[DataProvider('some')]
    public function testSome(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    #[DataProvider('emptyOnOneBranch')]
    public function testEmptyOnOneBranch(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * PHPUnit merges the data sets of a test's providers, so one with data is enough.
     */
    #[DataProvider('noneButMerged')]
    #[DataProvider('some')]
    public function testMerged(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    #[DataProvider('generator')]
    public function testGenerator(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}

// Corpus: PHPUnit 13 and later.
final class SkipWhenEmptyProviderTest extends TestCase
{
    /**
     * @return array<array{int}>
     */
    public static function none(): array
    {
        return [];
    }

    /**
     * @return array<array{int}>
     */
    public static function noneByName(): array
    {
        return [];
    }

    #[DataProvider('none', true, true)]
    public function testSkipped(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    // PHPUnit's attributes are marked as not taking named arguments.
    // @mago-expect analysis:named-argument-not-allowed
    #[DataProvider('noneByName', skipWhenEmpty: true)]
    public function testSkippedByName(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}
