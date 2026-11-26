<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\Covers;

use Mago\Sdk\Analyzer\Metadata\MethodMetadataProjection;
use Mago\Sdk\Span;
use MagoPHPUnit\Analyzer\TestClass\PHPUnitAttributes;
use MagoPHPUnit\Analyzer\TestClass\TestClass;
use MagoPHPUnit\Internal\Text\Docblocks;

/**
 * Tells which tests get coverage collected by PHPUnit, from their `CoversNothing` metadata.
 *
 * @internal
 */
final class CollectedTests
{
    private function __construct() {}

    /**
     * Whether PHPUnit reads the class attributes for one of the tests.
     *
     * A class or test with `#[CoversNothing]` doesn't collect coverage. A
     * method `@covers` tag overrides the class attribute on PHPUnit 10 and 11.
     *
     * @param list<MethodMetadataProjection> $tests
     *
     * @mago-expect lint:no-boolean-flag-parameter
     */
    public static function readsAttributes(array $tests, bool $nothingAttribute, bool $methodCovers): bool
    {
        if ($nothingAttribute) {
            return $methodCovers;
        }

        foreach ($tests as $method) {
            if (PHPUnitAttributes::of($method, name: 'CoversNothing') === []) {
                return true;
            }
        }

        return $tests === [];
    }

    /**
     * Whether a test method has a `@covers` tag and doesn't have `@coversNothing`.
     *
     * @param list<MethodMetadataProjection> $tests
     */
    public static function methodCovers(TestClass $test, array $tests): bool
    {
        foreach ($tests as $method) {
            $docblock = $test->methodAnnotationDocblock($method);
            if (
                $docblock !== null
                && !self::coversNothing($test, $docblock)
                && Docblocks::tags($test->contents, $docblock, ['covers']) !== []
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether PHPUnit collects coverage for the test with the docblock.
     *
     * A test with `@coversNothing` doesn't. A test of a class with
     * `@coversNothing` only does with its own `@covers` tag.
     */
    public static function collects(TestClass $test, Span $docblock, bool $classNothing): bool
    {
        if (self::coversNothing($test, $docblock)) {
            return false;
        }

        return !$classNothing || Docblocks::tags($test->contents, $docblock, ['covers']) !== [];
    }

    /**
     * Whether the docblock has a `@coversNothing` tag.
     */
    public static function coversNothing(TestClass $test, Span $docblock): bool
    {
        return Docblocks::tags($test->contents, $docblock, ['coversNothing']) !== [];
    }
}
