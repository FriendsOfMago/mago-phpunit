<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\DataSets;

use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Metadata\MethodMetadataProjection;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Analyzer\TestClass\PHPUnitAttributes;
use MagoPHPUnit\Analyzer\TestClass\TestClass;
use MagoPHPUnit\Internal\Declaration\ClassFacts;
use MagoPHPUnit\Internal\PHPUnitFeatures;

use function explode;
use function ltrim;
use function preg_match;
use function rtrim;
use function str_contains;
use function stripos;

/**
 * Reports data providers that are missing, invalid, or tests themselves.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class DataProviderCheck
{
    public const CODE = 'invalid-data-provider';

    private function __construct() {}

    /**
     * Checks the providers of each test method.
     *
     * A test's provider is set with `#[DataProvider('name')]` or
     * `#[DataProviderExternal(Foo::class, 'name')]`. On PHPUnit 10 and 11, it
     * can also be set with a `@dataProvider name` or `@dataProvider Foo::name`
     * tag.
     * Matches phpstan-phpunit's `DataProviderDeclarationRule`, plus the
     * checks for arguments and for a provider that is a test.
     */
    public static function check(TestClass $test): void
    {
        // `DataProvider` also matches `DataProviderExternal` and the tag.
        if (stripos($test->text, needle: 'DataProvider') === false) {
            return;
        }

        ClosureProviderCheck::check($test);
        $annotations = str_contains($test->contents, '@dataProvider');
        foreach ($test->methods() as $method) {
            // PHPUnit ignores the providers of methods that aren't tests.
            if (!$test->isTest($method)) {
                continue;
            }

            foreach (PHPUnitAttributes::of($method, name: 'DataProvider') as $attribute) {
                $name = PHPUnitAttributes::string($attribute, index: 0, name: 'methodName');
                if ($name !== null) {
                    self::checkProvider($test, class: null, name: $name, written: $name, where: $attribute->location);
                }
            }

            foreach (PHPUnitAttributes::of($method, name: 'DataProviderExternal') as $attribute) {
                $class = PHPUnitAttributes::string($attribute, index: 0, name: 'className');
                $name = PHPUnitAttributes::string($attribute, index: 1, name: 'methodName');
                if ($class !== null && $name !== null) {
                    // PHPUnit finds the class through reflection. PHP's reflection ignores a leading backslash.
                    $found = ltrim($class, characters: '\\');
                    self::checkProvider($test, $found, $name, "{$class}::{$name}", $attribute->location);
                }
            }

            if ($annotations) {
                self::checkAnnotations($test, $method);
            }
        }
    }

    /**
     * The provider name in a `@dataProvider` tag value, as PHPUnit reads it.
     */
    public static function tagValue(string $value): string
    {
        // PHPUnit drops trailing parentheses and whitespace.
        return rtrim($value, characters: " ()\n\r\t\v\0");
    }

    /**
     * Checks the providers given in the `@dataProvider` tags of a test method.
     */
    private static function checkAnnotations(TestClass $test, MethodMetadataProjection $method): void
    {
        foreach ($test->methodTags($method, ['dataProvider']) as $tag) {
            $value = self::tagValue($tag['value']);
            // PHPUnit doesn't read a tag with text joined to its name.
            if ($tag['gap'] === '' && $tag['value'] !== '') {
                continue;
            }

            // PHPUnit reads a bare tag as a provider with an empty name.
            if ($value === '') {
                $test->reporter->error(self::CODE, Reporter::issue(
                    "This @dataProvider tag doesn't name a data provider.",
                    $tag['span'],
                    help: "PHPUnit looks for a data provider with an empty name, and stops the test when it can't call it.",
                ));
                continue;
            }

            [$class, $name] = str_contains($value, '::')
                ? explode('::', ltrim($value, characters: '\\'), limit: 2)
                : [null, $value];
            if ($class !== '' && $name !== '') {
                self::checkProvider($test, $class, $name, $value, $tag['span']);
            }
        }
    }

    /**
     * Reports a provider that's missing, isn't public or static, takes arguments, or is a test.
     *
     * @param ?string $class The named class, or null for a provider named without a class.
     * @param string $written The provider's name as written in the test, for the message.
     */
    private static function checkProvider(
        TestClass $test,
        ?string $class,
        string $name,
        string $written,
        Span|SourceLocation $where,
    ): void {
        // PHPUnit doesn't run an abstract class. A provider named without a class is
        // looked up in the concrete subclass. It can be declared there. Its visibility
        // can also be widened to public there.
        $late = $class === null && $test->class->flags->contains(MetadataFlags::ABSTRACT);
        // PHPUnit looks up a provider named without a class in the class used to run the test.
        $class ??= $test->class->name;
        $provider = self::provider($test, $class, $name);
        // PHPUnit 11 and later stop the test for an invalid provider. PHPUnit
        // 10 only stops it for a missing provider and deprecates the other
        // problems.
        $stops = PHPUnitFeatures::rejectsInvalidProviders($test->codebase)
            ? 'PHPUnit stops the test when its data provider is invalid.'
            : 'PHPUnit 10 reports a deprecation for it, and PHPUnit 11 stops the test.';
        [$problem, $help] = match (true) {
            $provider === null && $late => [null, null],
            $provider === null => [
                $test->codebase->classLikeExists($class)
                    ? "The data provider {$written}() doesn't exist."
                    : "The class of the data provider {$written}() doesn't exist.",
                "PHPUnit stops the test when it can't call the data provider.",
            ],
            !$late && $provider->visibility !== null && $provider->visibility !== Visibility::Public => [
                "The data provider {$written}() must be public.",
                $stops,
            ],
            $provider->static === false => ["The data provider {$written}() must be static.", $stops],
            ($provider->parameters ?? []) !== [] => ["The data provider {$written}() must not take arguments.", $stops],
            default => [null, null],
        };
        if ($problem !== null) {
            $test->reporter->error(self::CODE, Reporter::issue($problem, $where, $help));

            return;
        }

        // PHPUnit only warns about a provider that is also a test.
        if ($provider !== null && $test->isTest($provider)) {
            $test->reporter->warning(self::CODE, Reporter::issue(
                "The data provider {$written}() is a test method too.",
                $where,
                help: 'PHPUnit warns about it and runs the provider as a test of its own.',
            ));
        }
    }

    /**
     * The provider method in the class, or null when it isn't found.
     */
    private static function provider(TestClass $test, string $class, string $name): ?MethodMetadataProjection
    {
        // A provider declared by the test class is already in its method list,
        // so it doesn't need a query.
        $provider = $class === $test->class->name ? $test->method($name) : null;
        // The name passed to findMethods() is read as a pattern. An exception
        // is thrown for a `*` before the end, and a `*` at the end gives a
        // search by prefix. A name that isn't a valid method name can't be a
        // provider.
        if ($provider !== null || preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $name) !== 1) {
            return $provider;
        }

        return $test->codebase->findMethods(class: $class, name: $name, fields: ClassFacts::METHOD_FIELDS)[0] ?? null;
    }
}
