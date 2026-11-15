<?php

declare(strict_types=1);

namespace MagoPHPUnit\Internal;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\MethodFields;

/**
 * Detects PHPUnit features from the project's classes and methods.
 *
 * @internal
 *
 * @mago-expect lint:too-many-methods
 */
final class PHPUnitFeatures
{
    private function __construct() {}

    /**
     * Whether PHPUnit passes the string keys of a data set as named
     * arguments. PHPUnit 11 and later do. PHPUnit 10 passes the values in
     * order. `CoversMethod` exists from PHPUnit 11 on.
     *
     * Each check here is a codebase lookup, cached by the SDK per analysis.
     */
    public static function namedDataSetKeys(Codebase $codebase): bool
    {
        return $codebase->classLikeExists('PHPUnit\Framework\Attributes\CoversMethod');
    }

    /**
     * Whether PHPUnit parses annotations such as `@dataProvider`. PHPUnit 12
     * removed annotation support along with its annotation parser.
     */
    public static function readsAnnotations(Codebase $codebase): bool
    {
        return $codebase->classLikeExists('PHPUnit\Metadata\Parser\AnnotationParser');
    }

    /**
     * Whether PHPUnit looks coverage targets up in a map built from the source files.
     */
    public static function mapsCoverageTargetsFromSource(Codebase $codebase): bool
    {
        // PHPUnit 12 and later use a source map. Classes, traits, functions and methods are kept
        // apart in that map. Each method is keyed by its declaring class or trait. PHPUnit 10 and
        // 11 use reflection. A trait named by `CoversClass` is found there. An inherited method is
        // found there too. The annotation parser exists only in the versions using reflection.
        return !self::readsAnnotations($codebase);
    }

    /**
     * Whether PHPUnit rejects an interface named by `CoversClass`.
     */
    public static function rejectsCoversClassInterface(Codebase $codebase): bool
    {
        // PHPUnit 11 and later reject interfaces here. PHPUnit 10 uses sebastian/code-unit's
        // `Mapper`. A user-defined interface is accepted there. The `CoversMethod` attribute exists
        // on PHPUnit 11 and later.
        return self::namedDataSetKeys($codebase);
    }

    /**
     * Whether methods missing from the mocked class can be added with a mock builder.
     */
    public static function addsMockMethods(Codebase $codebase): bool
    {
        // The `addMethods()` method exists on PHPUnit 10 and 11. It doesn't exist on PHPUnit 12 and
        // later.
        return $codebase->methodExists('PHPUnit\Framework\MockObject\MockBuilder', method: 'addMethods');
    }

    /**
     * Whether dependency results are skipped for native `void` or `never` return types.
     */
    public static function skipsVoidDependencies(Codebase $codebase): bool
    {
        // PHPUnit 11 and later skip these results. PHPUnit 10 passes null.
        return $codebase->methodExists('PHPUnit\TestRunner\TestResult\PassedTests', method: 'hasReturnValue');
    }

    /**
     * Whether an invalid data provider stops the test. It does from PHPUnit
     * 11 on. PHPUnit 10 reports a deprecation for a provider that isn't
     * public or static, or that takes arguments.
     */
    public static function rejectsInvalidProviders(Codebase $codebase): bool
    {
        return self::namedDataSetKeys($codebase);
    }

    /**
     * Whether PHPUnit warns about extra data set values and mixed data source attributes.
     */
    public static function warnsAboutDataSetShape(Codebase $codebase): bool
    {
        // PHPUnit 12.5 and 13 warn about more values than parameters. PHPUnit also warns about
        // `#[TestWith*]` next to `#[DataProvider*]`. The first warning can be disabled with
        // `validateArgumentCount` on the provider attribute. PHPUnit 11.5 doesn't have that option.
        // PHPUnit 11.5 doesn't warn about either case.
        return $codebase->methodExists('PHPUnit\Metadata\DataProvider', method: 'validateArgumentCount');
    }

    /**
     * Whether PHPUnit stops a test for the data set key `''`.
     */
    public static function rejectsEmptyDataSetKeys(Codebase $codebase): bool
    {
        // PHPUnit 13 rejects this key. PHPUnit 13 doesn't have `assertContainsOnly()`. PHPUnit 12.5
        // has `assertContainsOnly()`. The `validateArgumentCount` option exists on both versions.
        return (
            self::warnsAboutDataSetShape($codebase)
            && !$codebase->methodExists('PHPUnit\Framework\Assert', method: 'assertContainsOnly')
        );
    }

    /**
     * Whether the assertion method of `Assert` has an assert tag, such as `@phpstan-assert`.
     *
     * Mago applies the tag itself, so a provider doesn't add the same
     * assertion again.
     */
    public static function tagsAssertion(Codebase $codebase, string $method): bool
    {
        $found = $codebase->findMethods(
            class: 'PHPUnit\Framework\Assert',
            name: $method,
            fields: MethodFields::ASSERTIONS,
            declaredOnly: true,
        );

        return ($found[0]->assertions ?? []) !== [];
    }

    /**
     * Whether PHPUnit warns about a version requirement with an operator but
     * without a minor or patch level, such as `>= 8.1`.
     *
     * PHPUnit 12.5 and 13 trigger a PHPUnit warning for each test with such a
     * `RequiresPhp`, `RequiresPhpunit` or `RequiresPhpExtension` requirement,
     * from the private `Requirements::warnAboutIncompleteVersion()`.
     * PHPUnit 11.5 doesn't have that method and doesn't warn.
     */
    public static function warnsAboutIncompleteVersions(Codebase $codebase): bool
    {
        return $codebase->methodExists('PHPUnit\Metadata\Api\Requirements', method: 'warnAboutIncompleteVersion');
    }
}
