<?php

/**
 * @file
 * PHPUnit attributes imported under other names, matched by the names they
 * resolve to.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\DataProvider as Provider;
use PHPUnit\Framework\Attributes\RequiresPhp as NeedsPhp;
use PHPUnit\Framework\TestCase;

final class AliasedAttributesTest extends TestCase
{
    // @mago-expect analysis:phpunit/invalid-data-provider
    #[Provider('missing')]
    public function testMissing(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    #[Provider('words')]
    public function testWords(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    // @mago-expect analysis:phpunit/requires-without-operator
    #[NeedsPhp('8.2')]
    public function testNeeds(): void
    {
        $this->addToAssertionCount(1);
    }

    /**
     * @return list<array{string}>
     */
    public static function words(): array
    {
        // @mago-expect analysis:phpunit/data-provider-mismatch
        return [['one']];
    }
}

/**
 * A class with nothing to check except an aliased attribute.
 */
final class OnlyAliasedAttributeTest extends TestCase
{
    // @mago-expect analysis:phpunit/invalid-data-provider
    #[Provider('missing')]
    public function testMissing(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}
