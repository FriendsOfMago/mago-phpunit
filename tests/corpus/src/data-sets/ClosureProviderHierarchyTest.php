<?php

/**
 * @file
 * A data provider closure that returns an object whose class has a parent
 * that isn't known, and may be iterable.
 */

declare(strict_types=1);

// Corpus: PHPUnit 13 and later.
namespace Corpus;

use PHPUnit\Framework\Attributes\DataProviderClosure;
use PHPUnit\Framework\TestCase;

// @mago-expect analysis:non-existent-class-like
final class LooseSets extends \Corpus\Missing\Sets {}

final class ClosureProviderHierarchyTest extends TestCase
{
    #[DataProviderClosure(static function (): LooseSets {
        return new LooseSets();
    })]
    public function testLoose(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}
