<?php

/**
 * @file
 * Dependencies on a test that gets data, and on a result whose class has a
 * parent that isn't known.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

interface Sized {}

// @mago-expect analysis:non-existent-class-like
final class LooseBag extends \Corpus\Missing\Bag {}

final class DependencyDataTest extends TestCase
{
    /**
     * @return iterable<array{int}>
     */
    public static function numbers(): iterable
    {
        yield [1];
    }

    #[DataProvider('numbers')]
    public function testProvided(int $number): string
    {
        $this->assertSame(1, $number);

        return 'one';
    }

    /**
     * PHPUnit stores the results of a test with data under the names of its
     * data sets, so a dependency on it gets null.
     */
    #[Depends('testProvided')]
    public function testAfterProvided(?int $result = null): void
    {
        $this->assertNull($result);
    }

    #[TestWith([1])]
    public function testWith(int $number): int
    {
        return $number;
    }

    // @mago-expect analysis:phpunit/dependency-mismatch
    #[Depends('testWith')]
    public function testAfterWith(int $result): void
    {
        $this->assertSame(1, $result);
    }

    public function testLoose(): LooseBag
    {
        return new LooseBag();
    }

    /**
     * The parent of `LooseBag` isn't known, and may implement `Sized`.
     */
    #[Depends('testLoose')]
    public function testAfterLoose(Sized $bag): void
    {
        unset($bag);
        $this->addToAssertionCount(1);
    }
}
