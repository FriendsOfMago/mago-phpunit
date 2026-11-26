<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Assertion;

use Mago\Sdk\Analyzer\Assertion\ArrayKeyAssertion;
use Mago\Sdk\Analyzer\Assertion\ArrayKeyAssertionKind;
use Mago\Sdk\Analyzer\Assertion\Assertion;
use Mago\Sdk\Analyzer\Assertion\IntegerAssertion;
use Mago\Sdk\Analyzer\Assertion\IntegerAssertionKind;
use Mago\Sdk\Analyzer\Assertion\SimpleAssertion;
use Mago\Sdk\Analyzer\Assertion\SimpleAssertionKind;
use Mago\Sdk\Analyzer\Assertion\TypeAssertion;
use Mago\Sdk\Analyzer\Assertion\TypeAssertionKind;
use Mago\Sdk\Analyzer\AssertionProviderContext;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\InvocationAssertions;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodAssertionProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AnyObjectType;
use Mago\Sdk\Analyzer\Type\ArrayKey;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\IterableType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ObjectWithPropertyType;
use Mago\Sdk\Analyzer\Type\ResourceType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;

use function count;
use function in_array;
use function ltrim;
use function strrpos;
use function strtolower;
use function substr;

/**
 * Narrows the values passed to the PHPUnit assertions without a `@phpstan-assert` tag.
 *
 * @internal
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class AssertionProvider implements MethodAssertionProvider
{
    private const ASSERT = 'PHPUnit\Framework\Assert';

    private const FUNCTIONS = 'PHPUnit\Framework\\';

    /**
     * The assertions, by lowercase name. The host reports names in lowercase.
     */
    private const METHODS = [
        'assertnotempty',
        'assertempty',
        'assertarrayhaskey',
        'assertarraynothaskey',
        'assertobjecthasproperty',
        'assertcount',
        'assertnotcount',
        'assertcontainsonly',
        'assertnotsame',
        'assertgreaterthan',
        'assertgreaterthanorequal',
        'assertlessthan',
        'assertlessthanorequal',
        'assertcontains',
        'assertnotcontains',
        'assertcontainsonlyinstancesof',
        'assertislist',
        'assertstringcontainsstring',
        'assertstringcontainsstringignoringcase',
        'assertstringstartswith',
        'assertstringendswith',
        'assertjson',
    ];

    /**
     * Targets the static methods of `Assert`, called through `$this->`,
     * `self::` or `Assert::`.
     */
    public function getTargets(): array
    {
        $targets = [];
        foreach (self::METHODS as $method) {
            $targets[] = MethodTarget::exact(self::ASSERT, $method);
        }

        return $targets;
    }

    /**
     * Returns the global assertion functions with the same names, for
     * AssertionFunctionProvider.
     *
     * @return non-empty-list<FunctionTarget>
     */
    public static function functionTargets(): array
    {
        $targets = [];
        foreach (self::METHODS as $method) {
            $targets[] = FunctionTarget::exact(self::FUNCTIONS . $method);
        }

        return $targets;
    }

    /**
     * Returns the narrowing for one assertion call.
     *
     * PHPUnit tags `assertNotNull()`, `assertInstanceOf()` and the
     * `assertIs*()` family with `@phpstan-assert`, and Mago uses those tags.
     * The assertions handled here aren't tagged, because their effect depends
     * on the value of an argument. Without this provider, a value checked by
     * one of them keeps its type, and every later use of it is reported.
     * phpstan-phpunit narrows all of them.
     */
    public function getAssertions(AssertionProviderContext $context): ?InvocationAssertions
    {
        $invocation = $context->invocation;
        $name = strtolower($invocation->name);
        // Function names are fully qualified.
        $separator = strrpos($name, needle: '\\');
        if ($separator !== false) {
            $name = substr($name, offset: $separator + 1);
        }

        [$parameter, $assertion] = match ($name) {
            'assertnotempty' => ['$actual', new SimpleAssertion(SimpleAssertionKind::Truthy)],
            'assertempty' => ['$actual', self::empty($context->codebase, $invocation)],
            'assertarrayhaskey' => ['$array', self::key($invocation, ArrayKeyAssertionKind::HasKey)],
            'assertarraynothaskey' => ['$array', self::key($invocation, ArrayKeyAssertionKind::DoesNotHaveKey)],
            'assertobjecthasproperty' => ['$object', self::property($invocation)],
            'assertcount' => ['$haystack', self::count($invocation, IntegerAssertionKind::HasExactCount)],
            'assertnotcount' => ['$haystack', self::count($invocation, IntegerAssertionKind::DoesNotHaveExactCount)],
            'assertcontainsonly' => ['$haystack', self::containsOnly($invocation)],
            'assertnotsame' => ['$actual', ComparisonAssertions::notSame($invocation)],
            'assertgreaterthan' => [
                '$actual',
                ComparisonAssertions::bound($invocation, kind: IntegerAssertionKind::IsGreaterThan, name: 'minimum'),
            ],
            'assertgreaterthanorequal' => [
                '$actual',
                ComparisonAssertions::bound(
                    $invocation,
                    kind: IntegerAssertionKind::IsGreaterThanOrEqual,
                    name: 'minimum',
                ),
            ],
            'assertlessthan' => [
                '$actual',
                ComparisonAssertions::bound($invocation, kind: IntegerAssertionKind::IsLessThan, name: 'maximum'),
            ],
            'assertlessthanorequal' => [
                '$actual',
                ComparisonAssertions::bound(
                    $invocation,
                    kind: IntegerAssertionKind::IsLessThanOrEqual,
                    name: 'maximum',
                ),
            ],
            'assertcontains' => ['$needle', ComparisonAssertions::contains($invocation)],
            'assertnotcontains' => [
                '$needle',
                ComparisonAssertions::notContains($invocation),
            ],
            'assertcontainsonlyinstancesof' => [
                '$haystack',
                UntaggedAssertions::onlyInstancesOf($context->codebase, $invocation),
            ],
            'assertislist' => ['$array', UntaggedAssertions::isList($context->codebase)],
            'assertstringcontainsstring', 'assertstringcontainsstringignoringcase' => [
                '$haystack',
                StringAssertions::nonEmptyHaystack($invocation, ['needle', 'haystack']),
            ],
            'assertstringstartswith' => [
                '$string',
                StringAssertions::nonEmptyHaystack($invocation, ['prefix', 'string']),
            ],
            'assertstringendswith' => [
                '$string',
                StringAssertions::nonEmptyHaystack($invocation, ['suffix', 'string']),
            ],
            'assertjson' => [
                '$actual',
                StringAssertions::nonEmpty($invocation->getArgument(0, 'actual')),
            ],
            default => ['', null],
        };

        if ($assertion === null || $assertion === []) {
            return null;
        }

        return new InvocationAssertions([$parameter => $assertion instanceof Assertion ? [$assertion] : $assertion]);
    }

    /**
     * Returns the falsy assertion for `assertEmpty()`, unless the value may be counted.
     */
    private static function empty(Codebase $codebase, Invocation $invocation): ?Assertion
    {
        $actual = $invocation->getArgument(0, 'actual')?->type;

        // PHPUnit counts a Countable. An empty Countable passes `assertEmpty()`. An `EmptyIterator`
        // passes too. PHPUnit passes other objects to PHP's `empty()`. For a `GMP` or
        // `BcMath\Number` of zero, the result is true.
        return self::mayCount($codebase, $actual) ? null : new SimpleAssertion(SimpleAssertionKind::Falsy);
    }

    /**
     * Returns the key assertion for a key written as an integer or string literal.
     */
    private static function key(Invocation $invocation, ArrayKeyAssertionKind $kind): ?Assertion
    {
        $type = $invocation->getArgument(0, 'key')?->type;
        if ($type === null || count($type->atomicTypes) !== 1) {
            return null;
        }

        $integer = $type->getLiteralInt();
        if ($integer !== null) {
            return new ArrayKeyAssertion($kind, new ArrayKey(ArrayKeyKind::Integer, $integer));
        }

        $string = $type->getLiteralString();

        return $string === null ? null : new ArrayKeyAssertion($kind, new ArrayKey(ArrayKeyKind::String, $string));
    }

    /**
     * Returns the property assertion for a value typed `object` or not typed.
     *
     * Mago treats a class type combined with a property type as impossible,
     * so a value with a class type isn't narrowed.
     */
    private static function property(Invocation $invocation): ?Assertion
    {
        $name = $invocation->getArgument(0, 'propertyName')?->type?->getLiteralString();
        $object = $invocation->getArgument(1, 'object')?->type;
        if ($name === null || $name === '' || $object === null) {
            return null;
        }

        foreach ($object->atomicTypes as $atomic) {
            if (!$atomic instanceof AnyObjectType && !$atomic instanceof MixedType) {
                return null;
            }
        }

        return new TypeAssertion(TypeAssertionKind::IsType, Type::fromAtomic(new ObjectWithPropertyType($name, null)));
    }

    /**
     * Returns the count assertion for a non-negative literal count.
     */
    private static function count(Invocation $invocation, IntegerAssertionKind $kind): ?Assertion
    {
        $expected = $invocation->getArgument(0, 'expectedCount')?->type?->getLiteralInt();

        return $expected === null || $expected < 0 ? null : new IntegerAssertion($kind, $expected);
    }

    /**
     * Returns `iterable<T>` for `assertContainsOnly()` with a literal type name.
     *
     * PHPUnit 10 to 12 have this method. A name from PHPUnit's list of native
     * types means that type, unless `$isNativeType` is false. Any other name
     * means a class or interface. PHPUnit 12 adds `double`, `real`, `callable`
     * and `resource (closed)` to the list. Earlier versions throw for those
     * names, so treating them as native types is safe there. `iterable`,
     * `callable` and `resource (closed)` aren't narrowed.
     */
    private static function containsOnly(Invocation $invocation): ?Assertion
    {
        $name = $invocation->getArgument(0, 'type')?->type?->getLiteralString();
        if ($name === null || $name === '') {
            return null;
        }

        $native = $invocation->getArgument(2, 'isNativeType')?->type?->getLiteralBool();
        $element = match ($native === false ? '' : $name) {
            'numeric' => Type::fromAtomic(new ScalarType(ScalarTypeKind::Numeric)),
            'scalar' => Type::fromAtomic(new ScalarType(ScalarTypeKind::Scalar)),
            'int', 'integer' => Type::int(),
            'float', 'double', 'real' => Type::float(),
            'string' => Type::string(),
            'bool', 'boolean' => Type::bool(),
            'null' => Type::null(),
            'array' => Type::array(Type::fromAtomic(new ScalarType(ScalarTypeKind::ArrayKey)), Type::mixed()),
            'object' => Type::object(),
            'resource' => Type::fromAtomic(new ResourceType(null)),
            'iterable', 'callable', 'resource (closed)' => null,
            default => $native === true ? null : Type::namedObject(ltrim($name, characters: '\\')),
        };
        if ($element === null) {
            return null;
        }

        return new TypeAssertion(
            TypeAssertionKind::IsType,
            Type::fromAtomic(new IterableType(Type::mixed(), $element, null)),
        );
    }

    /**
     * Whether the value may be counted by PHPUnit or may be an object empty to PHP.
     */
    private static function mayCount(Codebase $codebase, ?Type $type): bool
    {
        // Scalars, null and arrays aren't objects counted by PHPUnit. Only final classes without
        // Countable or an `EmptyIterator` parent are sure not to be counted. A subclass, an
        // implementation, a template or an enum may be counted. A `GMP` or `BcMath\Number` of zero
        // is empty to PHP.
        $classes = [];
        foreach ($type === null ? [] : $type->atomicTypes as $atomic) {
            if ($atomic instanceof NamedObjectType) {
                $classes[] = $atomic->name;
                continue;
            }

            if (
                !$atomic instanceof ScalarType
                && !$atomic instanceof SimpleAtomicType
                && !$atomic instanceof ListType
                && !$atomic instanceof KeyedArrayType
                && !$atomic instanceof ResourceType
            ) {
                return true;
            }
        }

        foreach ($classes === [] ? [] : $codebase->getMultipleClassLikes($classes) as $class) {
            if (
                $class === null
                || $class->kind !== ClassLikeKind::Class_
                || !$class->flags->contains(MetadataFlags::FINAL)
                || in_array('countable', [$class->name, ...$class->parentInterfaces], strict: true)
                || in_array('emptyiterator', $class->parentClasses, strict: true)
                || in_array($class->name, ['gmp', 'bcmath\\number'], strict: true)
            ) {
                return true;
            }
        }

        return false;
    }
}
