<?php

declare(strict_types=1);

namespace MagoPHPUnit\Tests;

use Mago\Sdk\Analyzer\Type;
use MagoPHPUnit\Internal\Type\Types;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Types::class)]
final class TypesTest extends TestCase
{
    /**
     * The values are returned only when every member is a literal string.
     */
    public function testReadsTheValuesOfALiteralStringUnion(): void
    {
        $union = Type::union(Type::literalString('node'), Type::literalString('user'));
        self::assertSame(['node', 'user'], Types::literalStrings($union));
        self::assertSame(['node'], Types::literalStrings(Type::literalString('node')));

        self::assertNull(Types::literalStrings(Type::union(Type::literalString('node'), Type::string())));
        self::assertNull(Types::literalStrings(Type::union(Type::literalString('node'), Type::null())));
        self::assertNull(Types::literalStrings(Type::string()));
        self::assertNull(Types::literalStrings(null));
    }

    /**
     * A union is split at each `|` outside the given brackets only.
     */
    public function testSplitsAUnionOutsideTheGivenBrackets(): void
    {
        self::assertSame(
            ['array<int|string, Foo>', 'array{a: int|null}', 'Closure(int|string): void', 'null'],
            Types::unionMembers(
                'array<int|string, Foo>|array{a: int|null}|Closure(int|string): void|null',
                brackets: '<>{}()',
            ),
        );
        self::assertSame(['a[b', 'c]'], Types::unionMembers('a[b|c]', brackets: '<>{}()'));
        self::assertSame(['a[b|c]'], Types::unionMembers('a[b|c]', brackets: '<>{}()[]'));
        self::assertSame(['int', ''], Types::unionMembers('int|', brackets: '<>{}()'));
    }
}
