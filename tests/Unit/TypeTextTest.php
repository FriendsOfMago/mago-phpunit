<?php

declare(strict_types=1);

namespace MagoPHPUnit\Tests;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ClassLikeStringKind;
use Mago\Sdk\Analyzer\Type\ClassLikeStringType;
use Mago\Sdk\Analyzer\Type\ClassLikeStringVariant;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ObjectProperty;
use Mago\Sdk\Analyzer\Type\ObjectShapeType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\StringCasing;
use Mago\Sdk\Analyzer\Type\StringLiteralKind;
use Mago\Sdk\Analyzer\Type\StringType;
use MagoPHPUnit\Internal\Type\GenericParts;
use MagoPHPUnit\Internal\Type\TypeText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TypeText::class)]
#[CoversClass(GenericParts::class)]
final class TypeTextTest extends TestCase
{
    /**
     * A string type prints its refinements in docblock syntax.
     */
    public function testPrintsStringRefinements(): void
    {
        $cases = [
            'string' => [],
            'non-empty-string' => ['nonEmpty' => true],
            'non-falsy-string' => ['truthy' => true, 'nonEmpty' => true],
            'non-empty-lowercase-string' => ['nonEmpty' => true, 'casing' => StringCasing::Lowercase],
            'uppercase-string' => ['casing' => StringCasing::Uppercase],
            'literal-string' => ['literalKind' => StringLiteralKind::Unspecified],
            'numeric-string' => ['numeric' => true],
            'callable-string' => ['callable' => true],
        ];
        foreach ($cases as $expected => $flags) {
            self::assertSame($expected, TypeText::of(self::string($flags)));
        }

        self::assertSame("'abc'", TypeText::of(Type::literalString('abc')));
    }

    /**
     * A class string prints its class or its constraint.
     */
    public function testPrintsClassStrings(): void
    {
        $foo = self::object('Foo');
        self::assertSame('class-string', TypeText::of(self::classString(ClassLikeStringVariant::Any, null, null)));
        self::assertSame('Foo::class', TypeText::of(self::classString(ClassLikeStringVariant::Literal, 'Foo', null)));
        self::assertSame(
            'class-string<Foo>',
            TypeText::of(self::classString(ClassLikeStringVariant::OfType, null, $foo)),
        );
        self::assertSame(
            'interface-string<Foo>',
            TypeText::of(Type::fromAtomic(
                new ScalarType(
                    ScalarTypeKind::ClassLikeString,
                    new ClassLikeStringType(
                        ClassLikeStringVariant::OfType,
                        ClassLikeStringKind::Interface,
                        constraint: $foo,
                    ),
                ),
            )),
        );
    }

    /**
     * An object shape prints its properties.
     */
    public function testPrintsObjectShapes(): void
    {
        $shape = new ObjectShapeType([
            new ObjectProperty('name', optional: false, type: Type::string()),
            new ObjectProperty('age', optional: true, type: Type::int()),
        ], sealed: false);
        self::assertSame('object{name: string, age?: int, ...}', TypeText::of(Type::fromAtomic($shape)));
    }

    /**
     * Generic objects are found inside arrays, intersections and object shapes.
     */
    public function testFindsGenericParts(): void
    {
        $box = Type::namedObject('Box', Type::int());
        self::assertTrue(GenericParts::in($box));
        self::assertTrue(GenericParts::in(Type::list($box)));
        self::assertTrue(GenericParts::in(Type::array(Type::string(), $box)));
        self::assertTrue(GenericParts::in(Type::fromAtomic(new ObjectShapeType([
            new ObjectProperty('box', optional: false, type: $box),
        ], sealed: true))));
        self::assertTrue(GenericParts::in(Type::fromAtomic(
            new NamedObjectType(
                'Marker',
                parameters: null,
                variances: null,
                static: false,
                isThis: false,
                intersections: $box->atomicTypes,
                remappedParameters: false,
            ),
        )));

        self::assertFalse(GenericParts::in(Type::namedObject('Box')));
        self::assertFalse(GenericParts::in(Type::list(Type::string())));
        self::assertFalse(GenericParts::in(Type::union(Type::string(), Type::null())));
    }

    /**
     * A string type with the given refinements.
     *
     * @param array{literalKind?: StringLiteralKind, nonEmpty?: bool, truthy?: bool, numeric?: bool, callable?: bool, casing?: StringCasing} $flags
     */
    private static function string(array $flags): Type
    {
        return Type::fromAtomic(
            new ScalarType(
                ScalarTypeKind::String,
                new StringType(
                    $flags['literalKind'] ?? StringLiteralKind::General,
                    literalValue: null,
                    numeric: $flags['numeric'] ?? false,
                    truthy: $flags['truthy'] ?? false,
                    nonEmpty: $flags['nonEmpty'] ?? false,
                    callable: $flags['callable'] ?? false,
                    casing: $flags['casing'] ?? StringCasing::Unspecified,
                ),
            ),
        );
    }

    /**
     * A class string of the given variant.
     */
    private static function classString(
        ClassLikeStringVariant $variant,
        ?string $literal,
        ?NamedObjectType $constraint,
    ): Type {
        return Type::fromAtomic(
            new ScalarType(
                ScalarTypeKind::ClassLikeString,
                new ClassLikeStringType($variant, ClassLikeStringKind::Class_, $literal, constraint: $constraint),
            ),
        );
    }

    /**
     * A plain object type of the class.
     */
    private static function object(string $class): NamedObjectType
    {
        return new NamedObjectType(
            $class,
            parameters: null,
            variances: null,
            static: false,
            isThis: false,
            intersections: null,
            remappedParameters: false,
        );
    }
}
