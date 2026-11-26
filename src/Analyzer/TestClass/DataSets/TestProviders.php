<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\DataSets;

use Mago\Sdk\Analyzer\Metadata\AttributeMetadata;
use Mago\Sdk\Analyzer\Metadata\MethodMetadataProjection;
use Mago\Sdk\Span;
use MagoPHPUnit\Analyzer\TestClass\PHPUnitAttributes;
use MagoPHPUnit\Analyzer\TestClass\TestClass;
use MagoPHPUnit\Internal\PHPUnitFeatures;

use function explode;
use function ltrim;
use function preg_match;
use function str_contains;
use function strrpos;
use function strtolower;
use function substr;

/**
 * Finds the data providers of a test in its class, including closure providers.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class TestProviders
{
    private function __construct() {}

    /**
     * The providers of the test method in the test class, with their flags.
     *
     * Each provider has its method name, or the span of its closure, its
     * count flag, and whether the class name is omitted. A closure that only
     * calls a method of the class, such as `self::cases(...)`, is given by
     * the method's name.
     *
     * @return list<array{string, ?Span, bool|Span, bool}>
     */
    public static function of(TestClass $test, MethodMetadataProjection $method): array
    {
        // Only same-class providers are included. The `DataProvider` attributes are checked. The
        // `@dataProvider` tags are checked when annotations are parsed by PHPUnit.
        $found = [];
        foreach (PHPUnitAttributes::of($method, name: 'DataProvider') as $attribute) {
            $name = PHPUnitAttributes::string($attribute, index: 0, name: 'methodName');
            if ($name !== null) {
                $found[] = [$name, null, self::countsValues($attribute), true];
            }
        }

        $closures = PHPUnitFeatures::hasClosureProviders($test->codebase);
        foreach ($closures ? PHPUnitAttributes::of($method, name: 'DataProviderClosure') : [] as $attribute) {
            $closure = $attribute->getArgument(0, 'closure')?->valueLocation?->span;
            if ($closure === null) {
                continue;
            }

            $callable = self::classCallable($test, $closure);
            $found[] = $callable === null
                ? ['', $closure, self::countsValues($attribute), false]
                : [$callable, null, self::countsValues($attribute), false];
        }

        foreach ($test->methodTags($method, ['dataProvider']) as $tag) {
            $value = DataProviderCheck::tagValue($tag['value']);
            [$class, $name] = str_contains($value, '::') ? explode('::', $value, limit: 2) : ['', $value];
            if (
                $tag['gap'] !== ''
                && $name !== ''
                && ($class === '' || strtolower(ltrim($class, characters: '\\')) === strtolower($test->class->name))
            ) {
                $found[] = [$name, null, true, $class === ''];
            }
        }

        return $found;
    }

    /**
     * Whether PHPUnit counts the values of each data set of the attribute's provider.
     *
     * PHPUnit counts them unless `validateArgumentCount` is false. A flag
     * that isn't a literal is given as its span, for the analysis.
     */
    private static function countsValues(AttributeMetadata $attribute): bool|Span
    {
        $validate = $attribute->getArgument(1, 'validateArgumentCount');
        $literal = $validate?->valueType?->getLiteralBool();

        return $validate === null ? true : $literal ?? $validate->valueLocation->span ?? false;
    }

    /**
     * The method named by a first-class callable of the class, such as `self::cases(...)`, or null.
     *
     * The class is written as `self`, its fully qualified name with a leading
     * backslash, or its short name. PHP doesn't allow `static` there.
     */
    public static function classCallable(TestClass $test, Span $closure): ?string
    {
        $text = substr($test->contents, $closure->start, $closure->length());
        $matches = [];
        if (preg_match('/^\s*([\w\\\\]+)\s*::\s*(\w+)\s*\(\s*\.\.\.\s*\)\s*$/', $text, $matches) !== 1) {
            return null;
        }

        $written = strtolower($matches[1]);
        $class = strtolower($test->class->name);
        $short = substr($class, (int) strrpos('\\' . $class, needle: '\\'));
        $own =
            $written === 'self' || $written === '\\' . $class || !str_contains($written, '\\') && $written === $short;

        return $own ? $matches[2] : null;
    }
}
