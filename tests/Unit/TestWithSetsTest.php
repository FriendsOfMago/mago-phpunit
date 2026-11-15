<?php

declare(strict_types=1);

namespace MagoPHPUnit\Tests;

use Mago\Sdk\PHPVersion;
use MagoPHPUnit\Analyzer\TestClass\DataSets\TestWithSets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TestWithSets::class)]
final class TestWithSetsTest extends TestCase
{
    /**
     * Set names with the PHP minor version, the labels and the index of the first duplicate key.
     *
     * @return iterable<string, array{list<?string>, int, list<string>, ?int}>
     */
    public static function names(): iterable
    {
        yield 'unnamed sets' => [[null, null], 3, ['#0', '#1'], null];
        yield 'after an integer name' => [['1', null, '2'], 3, ['#1', '#2', '#2'], 2];
        yield 'after a negative name on PHP 8.2' => [['-5', null, '0'], 2, ['#-5', '#0', '#0'], 2];
        yield 'after a negative name on PHP 8.3' => [['-5', null, '-4'], 3, ['#-5', '#-4', '#-4'], 2];
        yield 'zero after a negative name on PHP 8.3' => [['-5', null, '0'], 3, ['#-5', '#-4', '#0'], null];
        yield 'negative name after an unnamed set on PHP 8.3' => [[null, '-5', null], 3, ['#0', '#-5', '#1'], null];
    }

    /**
     * Checks the labels and the duplicate key of the sets, numbered for the PHP version.
     *
     * @param list<?string> $names
     * @param list<string> $labels
     */
    #[DataProvider('names')]
    public function testKeys(array $names, int $minor, array $labels, ?int $duplicate): void
    {
        $php = PHPVersion::fromParts(major: 8, minor: $minor);
        $this->assertSame($labels, TestWithSets::labels($names, $php));
        $this->assertSame($duplicate, TestWithSets::duplicate($names, $php));
    }
}
