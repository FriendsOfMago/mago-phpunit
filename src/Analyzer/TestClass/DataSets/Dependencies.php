<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\DataSets;

use Mago\Sdk\Analyzer\Metadata\AttributeMetadata;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MemberIdentifier;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Metadata\MethodMetadataProjection;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use MagoPHPUnit\Analyzer\TestClass\PHPUnitAttributes;
use MagoPHPUnit\Analyzer\TestClass\TestClass;
use MagoPHPUnit\Internal\PHPUnitFeatures;
use MagoPHPUnit\Internal\Type\Bottom;
use MagoPHPUnit\Internal\Type\Types;
use WeakMap;

use function array_key_exists;
use function end;
use function explode;
use function preg_split;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function stripos;
use function strtolower;
use function substr;
use function trim;

/**
 * Finds the results of other tests passed by PHPUnit to a test after its data.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class Dependencies
{
    /**
     * The dependency attributes, keyed by lowercased short name.
     *
     * @var array<string, bool> Whether the target class is given as an attribute argument.
     */
    private const DEPENDS = [
        'depends' => false,
        'dependsusingdeepclone' => false,
        'dependsusingshallowclone' => false,
        'dependsexternal' => true,
        'dependsexternalusingdeepclone' => true,
        'dependsexternalusingshallowclone' => true,
    ];

    /**
     * The results of each test, keyed by lowercased method name, since a test
     * is read once per data provider.
     *
     * @var WeakMap<TestClass, array<string, list<array{string, ?Type, Span|SourceLocation, 'null'|'exception'|null}>|null>>|null
     */
    private static ?WeakMap $found = null;

    private function __construct() {}

    /**
     * The passed results in order, or null when they aren't known.
     *
     * Each result has the name of the test it comes from, its type, the place
     * of the dependency, and why it may be null. The type is the native
     * return type of that test, or null when it doesn't have one. PHPUnit 10
     * passes null for a test that returns `void`, and every major passes null
     * for a test that gets data, since its results are stored under the
     * names of its data sets. A test that expects an exception may pass null.
     *
     * The results aren't known when a dependency can't be used by PHPUnit.
     * PHPUnit skips or fails the test before running it.
     *
     * @return list<array{string, ?Type, Span|SourceLocation, 'null'|'exception'|null}>|null
     */
    public static function passed(TestClass $test, MethodMetadataProjection $method): ?array
    {
        $found = self::$found ?? new WeakMap();
        self::$found = $found;

        /** @var array<string, list<array{string, ?Type, Span|SourceLocation, 'null'|'exception'|null}>|null> $tests */
        $tests = $found[$test] ?? [];
        $key = strtolower($method->name ?? '');
        if ($key === '') {
            return null;
        }

        if (!array_key_exists($key, $tests)) {
            $tests[$key] = self::find($test, $method);
            $found[$test] = $tests;
        }

        return $tests[$key];
    }

    /**
     * The passed results in order, or null when they aren't known.
     *
     * @return list<array{string, ?Type, Span|SourceLocation, 'null'|'exception'|null}>|null
     */
    private static function find(TestClass $test, MethodMetadataProjection $method): ?array
    {
        $targets = self::targets($test, $method);
        if ($targets === null || $targets === []) {
            return $targets;
        }

        $identifiers = [];
        foreach ($targets as [$class, $name]) {
            $identifiers[] = new MemberIdentifier($class, $name);
        }

        // On PHPUnit 11 and later, a dependency on a test with a native `void`
        // or `never` return type doesn't pass a value. PHPUnit 11 stops
        // passing results at the first such target, and PHPUnit 12 skips each
        // one. Only PHPUnit 10 and 11 parse annotations, so PHPUnit 11 is
        // detected with readsAnnotations().
        $codebase = $test->codebase;
        $skips = PHPUnitFeatures::skipsVoidDependencies($codebase);
        $stops = $skips && PHPUnitFeatures::readsAnnotations($codebase);
        $passed = [];
        foreach ($codebase->getMultipleDeclaringMethods($identifiers) as $index => $target) {
            [$class, $name, $where] = $targets[$index];
            if ($target === null || !self::isUsable($test, $target, $class, $name)) {
                return null;
            }

            $label = "{$class}::{$target->originalName}()";
            $data = self::getsData($test, $target, $class, $name);
            if ($data !== false) {
                $passed[] = [$label, $data ? Type::null() : null, $where, 'null'];
                continue;
            }

            $type = $target->declaredReturnType?->type;
            if (Bottom::of($type) === null) {
                $passed[] = [
                    $label,
                    $type,
                    $where,
                    self::mayExpectException($test, $class, $name) ? 'exception' : null,
                ];
                continue;
            }

            if ($stops) {
                break;
            }

            if (!$skips) {
                $passed[] = [$label, Type::null(), $where, 'null'];
            }
        }

        return $passed;
    }

    /**
     * Whether PHPUnit runs the target and passes its result.
     *
     * PHPUnit looks the name up as written, so a name in another case doesn't
     * match. The target must be a public test method. A method of this class
     * is a test by the rules of `TestClass::isTest()`. A method elsewhere is a
     * test by its `test` prefix or its `#[Test]` attribute, or by a docblock
     * on PHPUnit 10 and 11, which may have a `@test` tag.
     */
    private static function isUsable(TestClass $test, FunctionLikeMetadata $target, string $class, string $name): bool
    {
        if ($target->originalName !== $name || $target->visibility !== Visibility::Public) {
            return false;
        }

        $own = strtolower($class) === strtolower($test->class->name) ? $test->method($name) : null;
        if ($own !== null) {
            return $test->isTest($own);
        }

        $isTest =
            str_starts_with($name, 'test')
            || $target->hasDocblock && PHPUnitFeatures::readsAnnotations($test->codebase);
        foreach ($target->attributes as $attribute) {
            $isTest = $isTest || strtolower($attribute->name) === 'phpunit\framework\attributes\test';
        }

        return $isTest;
    }

    /**
     * Whether the target gets data from a data provider or a `TestWith`
     * attribute or tag, or null when that isn't known.
     *
     * A method of this class is read in full. A method elsewhere is read
     * from its attributes, and isn't known when it has a docblock on PHPUnit
     * 10 and 11, which may have a tag.
     */
    private static function getsData(TestClass $test, FunctionLikeMetadata $target, string $class, string $name): ?bool
    {
        $own = strtolower($class) === strtolower($test->class->name) ? $test->method($name) : null;
        if ($own !== null) {
            return TestWithSets::givesData($test, $own);
        }

        foreach ($target->attributes as $attribute) {
            if (TestWithSets::isDataAttribute($attribute->name)) {
                return true;
            }
        }

        return $target->hasDocblock && PHPUnitFeatures::readsAnnotations($test->codebase) ? null : false;
    }

    /**
     * Whether the target may expect an exception, so PHPUnit passes null.
     *
     * Only the text of a method of this class is read. A method elsewhere
     * may expect one.
     */
    private static function mayExpectException(TestClass $test, string $class, string $name): bool
    {
        $own = strtolower($class) === strtolower($test->class->name) ? $test->method($name) : null;
        $span = $own?->location?->span;

        return (
            $span === null
            || stripos(substr($test->contents, $span->start, $span->length()), needle: 'expectException') !== false
        );
    }

    /**
     * The class, method and place of each dependency in order, or null when one isn't known.
     *
     * PHPUnit keeps one result per test, so naming a test twice passes one
     * value. The method name keeps its case here, since PHPUnit doesn't find
     * a name in another case. PHPUnit doesn't find a class with a leading backslash, a method
     * written with parentheses, or a test of another abstract class.
     *
     * @return list<array{non-empty-string, non-empty-string, Span|SourceLocation}>|null
     *
     * @mago-expect lint:halstead
     */
    private static function targets(TestClass $test, MethodMetadataProjection $method): ?array
    {
        // A dependency without a class name is on a test of this class.
        $class = $test->class->originalName;
        $targets = [];

        // `@depends` tags only count when the method's annotations are parsed
        // by PHPUnit. They aren't parsed next to a PHPUnit attribute.
        foreach ($test->methodTags($method, ['depends']) as $tag) {
            // `@depends-on` is another tag. The target comes last, after an
            // optional `clone` or `shallowClone`. A dependency on
            // `Foo::class` doesn't pass a value.
            $words = $tag['gap'] === '' ? false : preg_split('/\s+/', trim($tag['value']));
            $target = $words === false ? '' : (string) end($words);
            [$targetClass, $name] = str_contains($target, '::') ? explode('::', $target, limit: 2) : [$class, $target];
            if (str_ends_with($name, '()')) {
                return null;
            }

            if ($name !== '' && strtolower($name) !== 'class') {
                $targets[strtolower($targetClass) . '::' . $name] ??= [$targetClass, $name, $tag['span']];
            }
        }

        // The attributes are read in source order, since the count on PHPUnit
        // 11 depends on it.
        foreach ($method->attributes ?? [] as $attribute) {
            $external = self::DEPENDS[PHPUnitAttributes::shortName($attribute->name) ?? ''] ?? null;
            if ($external === null) {
                continue;
            }

            $targetClass = $external
                ? PHPUnitAttributes::string($attribute, index: 0, name: 'className') ?? ''
                : $class;
            $name = self::methodName($test, $attribute, $external);
            $targets[strtolower($targetClass) . '::' . $name] ??= [$targetClass, $name, $attribute->location];
        }

        $found = [];
        $external = [];
        foreach ($targets as [$targetClass, $name, $where]) {
            if ($targetClass === '' || $name === '' || str_starts_with($targetClass, '\\')) {
                return null;
            }

            $found[] = [$targetClass, $name, $where];
            if ($targetClass !== $class) {
                $external[] = $targetClass;
            }
        }

        // A dependency on this class is run on the concrete test class, which may extend it.
        foreach ($external === [] ? [] : $test->codebase->getMultipleClassLikes($external) as $metadata) {
            if ($metadata === null || $metadata->flags->contains(MetadataFlags::ABSTRACT)) {
                return null;
            }
        }

        return $found;
    }

    /**
     * The method named by a dependency attribute, or an empty string.
     *
     * A name given by a constant, such as `self::TARGET`, is read from the
     * type of the argument.
     *
     * @mago-expect lint:no-boolean-flag-parameter
     */
    private static function methodName(TestClass $test, AttributeMetadata $attribute, bool $external): string
    {
        $name = PHPUnitAttributes::string($attribute, index: $external ? 1 : 0, name: 'methodName');
        if ($name !== null) {
            return $name;
        }

        $where = $attribute->getArgument($external ? 1 : 0, 'methodName')?->valueLocation;

        return $where === null ? '' : Types::literalName($test->context->analysis->getExpressionType($where->span));
    }
}
