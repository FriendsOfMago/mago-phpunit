<?php

/**
 * @file
 * A provider declared in another file.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\DataProvider;

final class InheritedProviderTest extends ProviderBase
{
    #[DataProvider('inherited')]
    public function testInherited(int $number): void
    {
        $this->assertSame(1, $number);
    }
}
