<?php

declare(strict_types=1);

namespace MagoPHPUnit\Tests;

use Mago\Sdk\Analyzer\Type;
use MagoPHPUnit\Analyzer\Assertion\AlwaysPasses;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AlwaysPasses::class)]
final class AlwaysPassesTest extends TestCase
{
    /**
     * assertNotNull() always passes for a type without null, assertNull() for null alone.
     */
    public function testNullChecks(): void
    {
        $object = Type::namedObject('Vendor\Thing');
        $nullable = Type::union($object, Type::null());

        self::assertTrue(AlwaysPasses::check('assertNotNull', $object));
        self::assertTrue(AlwaysPasses::check('assertNotNull', Type::string()));
        self::assertFalse(AlwaysPasses::check('assertNotNull', $nullable));
        self::assertFalse(AlwaysPasses::check('assertNotNull', Type::mixed()));
        self::assertTrue(AlwaysPasses::check('assertNull', Type::null()));
        self::assertFalse(AlwaysPasses::check('assertNull', $nullable));
    }

    /**
     * The true and false assertions always pass only when the other value isn't in the type.
     */
    public function testBoolChecks(): void
    {
        self::assertTrue(AlwaysPasses::check('assertTrue', Type::true()));
        self::assertFalse(AlwaysPasses::check('assertTrue', Type::bool()));
        self::assertTrue(AlwaysPasses::check('assertFalse', Type::false()));
        self::assertTrue(AlwaysPasses::check('assertNotFalse', Type::union(Type::int(), Type::string())));
        self::assertTrue(AlwaysPasses::check('assertNotFalse', Type::union(Type::true(), Type::null())));
        self::assertFalse(AlwaysPasses::check('assertNotFalse', Type::bool()));
        self::assertFalse(AlwaysPasses::check('assertNotTrue', Type::bool()));
        self::assertTrue(AlwaysPasses::check('assertNotTrue', Type::false()));
    }

    /**
     * The assertIs*() assertions always pass only when every member of the type matches.
     */
    public function testTypeChecks(): void
    {
        self::assertTrue(AlwaysPasses::check('assertIsArray', Type::list(Type::string())));
        self::assertTrue(AlwaysPasses::check('assertIsString', Type::literalString('x')));
        self::assertTrue(AlwaysPasses::check('assertIsInt', Type::literalInt(3)));
        self::assertTrue(AlwaysPasses::check('assertIsNumeric', Type::float()));
        self::assertTrue(AlwaysPasses::check('assertIsScalar', Type::union(Type::int(), Type::string())));
        self::assertTrue(AlwaysPasses::check('assertIsObject', Type::object()));
        self::assertFalse(AlwaysPasses::check('assertIsString', Type::union(Type::string(), Type::null())));
        self::assertFalse(AlwaysPasses::check('assertIsNumeric', Type::string()));
        self::assertFalse(AlwaysPasses::check('assertIsArray', Type::mixed()));
    }

    /**
     * An assertion that isn't in the table never passes. assertInstanceOf()
     * is checked with the host's type comparison instead.
     */
    public function testUnknownAssertionNeverPasses(): void
    {
        self::assertFalse(AlwaysPasses::check('assertInstanceOf', Type::object()));
    }
}
