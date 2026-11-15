<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass;

use Mago\Sdk\Analyzer\Metadata\MethodFields;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Internal\Text\CodeMask;

use function in_array;
use function preg_match_all;
use function str_contains;
use function strrpos;
use function strtolower;
use function substr;

/**
 * Reports a `setUp()` or `tearDown()` that doesn't call the parent's.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class ParentCallCheck
{
    public const CODE = 'missing-parent-call';

    private const METHODS = ['setUp', 'tearDown'];

    private const TEST_CASE = 'phpunit\framework\testcase';

    /**
     * The method fields fetched by findMethods().
     */
    private const FIELDS = MethodFields::NAMES | MethodFields::LOCATIONS | MethodFields::METHOD_DETAILS;

    private function __construct() {}

    /**
     * Checks the class's own `setUp()` and `tearDown()` for missing parent calls.
     */
    public static function check(TestClass $test): void
    {
        // Only parent methods below `TestCase` count. The tests rely on the parent's setup or
        // cleanup. Failures from skipping it can be hard to trace. This matches phpstan-phpunit's
        // `ShouldCallParentMethodsRule`.
        $parent = $test->class->directParentClass;
        if ($parent === null || strtolower($parent) === self::TEST_CASE) {
            return;
        }

        // Class metadata has the names of the class's own methods.
        // Checking them first skips the query for a class without `setUp()`
        // or `tearDown()`.
        $class = $test->class;
        foreach (self::METHODS as $name) {
            if (
                !in_array(strtolower($name), $class->methods, strict: true)
                || self::startsWithParentCall($test->contents, $name)
            ) {
                continue;
            }

            // Only check a method declared by the class in this file, since
            // its body comes from this file's text.
            $method =
                $test->codebase->findMethods(
                    class: $class->name,
                    name: $name,
                    fields: self::FIELDS,
                    declaredOnly: true,
                )[0] ?? null;
            $location = $method?->location;
            if (
                $method === null
                || $location === null
                || $method->abstract === true
                || $location->file !== $class->location->file
            ) {
                continue;
            }

            // Look for `Name::setUp(` in the masked method body. Comments and
            // strings are blanked in the mask, so a call inside them doesn't
            // count. A comment inside a call is blanked too, so that call
            // still matches. Only the method body is masked, not the whole
            // file. `parent::setUp(...)` only creates a callable and doesn't
            // match. A call by the name of a parent class, such as
            // `BaseTest::setUp()`, counts.
            $span = $location->span;
            $pattern = '/([\w\\\\]+)\s*::\s*' . $name . '\s*\((?!\s*\.\.\.\s*\))/i';
            $body = substr($test->contents, $span->start, $span->length());
            $calls = [];
            if (str_contains($body, '::')) {
                preg_match_all($pattern, CodeMask::fragment($body), $calls);
            }

            if (self::callsParent($test, $calls[1] ?? [])) {
                continue;
            }

            // Most methods call the parent's, so the parent method is only
            // looked up when the call is missing. An abstract method of the
            // parent doesn't have code to call.
            $parentMethod = $test->codebase->findMethods(class: $parent, name: $name, fields: self::FIELDS)[0] ?? null;
            $declaring = $parentMethod?->identifier->class;
            if ($declaring === null || $parentMethod->abstract === true || strtolower($declaring) === self::TEST_CASE) {
                continue;
            }

            $original = $method->originalName;
            $test->reporter->warning(self::CODE, Reporter::issue(
                "{$original}() doesn't call parent::{$original}().",
                $method->nameLocation ?? $location,
                "{$declaring}::{$original}() prepares or cleans up what the tests of its subclasses rely on.",
            ));
        }
    }

    /**
     * Whether the file's text proves that check() doesn't report anything.
     */
    public static function neverMissing(string $contents): bool
    {
        foreach (self::METHODS as $name) {
            if (!self::startsWithParentCall($contents, $name)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether every method with the name in the file starts with a call to the parent's.
     *
     * The answer comes from the file's text alone, so a method of the usual
     * form doesn't cost a query. Every mention of the name in the file must
     * be part of `function setUp(): void { parent::setUp()`, with only
     * whitespace between the parts. A declaration is never inside a comment
     * or a string, so the call right after it is code in the method's body.
     * False only means the text doesn't prove it.
     */
    private static function startsWithParentCall(string $contents, string $name): bool
    {
        $mentions = preg_match_all('/\b' . $name . '\b/i', $contents);
        if ($mentions === 0) {
            return true;
        }

        $methods = preg_match_all(
            '/\bfunction\s+' . $name . '\s*\(\s*\)\s*(?::\s*void\s*)?\{\s*parent\s*::\s*' . $name . '\s*\(\s*\)/i',
            $contents,
        );

        return $mentions === (2 * (int) $methods);
    }

    /**
     * Whether one of the names in front of the `::` calls is `parent` or the
     * short name of a parent class of the test.
     *
     * @param array<string> $names
     */
    private static function callsParent(TestClass $test, array $names): bool
    {
        $short = [];
        foreach ($names as $name) {
            if (strtolower($name) === 'parent') {
                return true;
            }

            $short[] = self::shortName($name);
        }

        if ($short === []) {
            return false;
        }

        foreach ($test->codebase->getClassAncestors($test->class->name) as $ancestor) {
            if (
                strtolower($ancestor) !== self::TEST_CASE
                && in_array(self::shortName($ancestor), $short, strict: true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The lowercased class name without its namespace.
     */
    private static function shortName(string $name): string
    {
        $lower = strtolower($name);
        $position = strrpos($lower, needle: '\\');

        return $position === false ? $lower : substr($lower, $position + 1);
    }
}
