<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\Covers;

use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Metadata\MethodMetadataProjection;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Analyzer\TestClass\PHPUnitAttributes;
use MagoPHPUnit\Analyzer\TestClass\TestClass;
use MagoPHPUnit\Internal\Text\Docblocks;

use function array_filter;
use function array_key_exists;
use function array_values;
use function count;
use function explode;
use function ltrim;
use function preg_replace;
use function str_contains;
use function str_starts_with;
use function stripos;
use function strtolower;

/**
 * Reports covers and uses tags and attributes for missing code, built-in code, interfaces or other rejected targets.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class CoversCheck
{
    public const CODE = 'invalid-covers';

    public const REDUNDANT_CODE = 'redundant-covers';

    public const LEFT_OUT_HELP = 'PHPUnit warns about the target and leaves the test out of the coverage report.';

    public const BUILTIN_HELP = 'Only user-defined code is a valid target. ' . self::LEFT_OUT_HELP;

    public const PREFIXED_HELP =
        'PHPUnit 12 and later look the name up as written. Drop the backslash. ' . self::LEFT_OUT_HELP;

    private function __construct() {}

    /**
     * Checks the class covers and uses attributes and the class and test method coverage tags.
     *
     * PHPUnit checks uses targets like covers targets, and warns about each
     * target that can't be used. PHPUnit doesn't collect coverage for a test
     * with `CoversNothing`, so its targets aren't checked. On PHPUnit 10 and
     * 11, a method `@covers` tag overrides the class `@coversNothing`.
     */
    public static function check(TestClass $test): void
    {
        $tests = array_values(array_filter($test->methods(), $test->isTest(...)));

        // Only method tags are checked on an abstract class. PHPUnit reads class tags only from
        // the concrete test class, and method tags on the inherited method. Attributes aren't
        // inherited in PHP, so they aren't read on an abstract class either.
        $abstract = $test->class->flags->contains(MetadataFlags::ABSTRACT);
        $docblock = $abstract ? null : $test->annotationDocblock();
        $classNothing = $docblock !== null && CollectedTests::coversNothing($test, $docblock);
        $nothingAttribute = PHPUnitAttributes::of($test->class, name: 'CoversNothing') !== [];
        $methodCovers = ($classNothing || $nothingAttribute) && CollectedTests::methodCovers($test, $tests);
        if (!$abstract && CollectedTests::readsAttributes($tests, $nothingAttribute, $methodCovers)) {
            CoverageAttributes::check($test);
        }

        $classTags = $docblock !== null && (!$classNothing || $methodCovers) ? $docblock : null;
        foreach ([CoverageForm::Annotation, CoverageForm::UsesAnnotation] as $form) {
            self::checkTags($test, $form, $classTags, [$tests, $classNothing]);
        }
    }

    /**
     * Checks the class and test method `@covers` or `@uses` tags, and the default class tag.
     *
     * @param ?Span $docblock The class docblock, when its tags are read by PHPUnit.
     * @param array{list<MethodMetadataProjection>, bool} $tests The test methods, and whether the class has
     *   `@coversNothing`.
     */
    private static function checkTags(TestClass $test, CoverageForm $form, ?Span $docblock, array $tests): void
    {
        // The tags count only on PHPUnit 10 and 11. PHPUnit warns about a name that doesn't
        // resolve. The test is left out of the coverage report. This matches phpstan-phpunit's
        // `ClassCoversExistsRule` and `ClassMethodCoversExistsRule`, for `@covers` tags.
        [$methods, $classNothing] = $tests;
        $kind = $form->value;
        $written = "@{$kind}";
        if (!str_contains($test->contents, $written)) {
            return;
        }

        $default = null;
        $classTargets = [];
        if ($docblock !== null) {
            $tags = self::tags($test, $docblock, $kind);
            $default = self::defaultClass($test, $tags, $kind);
            foreach ($tags as $tag) {
                if ($tag['name'] !== $kind) {
                    continue;
                }

                $classTargets[self::target($tag['value'])] = true;
                self::checkValue($test, $tag['value'], $form, $default, $tag['span']);
            }
        }

        // Only class `@covers` tags make a method tag redundant. The method docblocks are
        // skipped when the class text doesn't mention the tag.
        $classTargets = $form === CoverageForm::Annotation ? $classTargets : [];
        foreach (stripos($test->text, needle: $written) !== false ? $methods : [] as $method) {
            $methodDocblock = $test->methodAnnotationDocblock($method);
            if ($methodDocblock === null || !CollectedTests::collects($test, $methodDocblock, $classNothing)) {
                continue;
            }

            self::checkMethodTags($test, $methodDocblock, $form, $default, $classTargets);
        }
    }

    /**
     * Checks the `@covers` or `@uses` tags of a test method's docblock.
     *
     * A method `@covers` tag with a target also named on the class is
     * redundant. This matches phpstan-phpunit's `ClassMethodCoversExistsRule`.
     *
     * @param array<string, true> $classTargets The class `@covers` targets.
     */
    private static function checkMethodTags(
        TestClass $test,
        Span $docblock,
        CoverageForm $form,
        ?string $default,
        array $classTargets,
    ): void {
        $kind = $form->value;
        foreach (self::tags($test, $docblock, $kind) as $tag) {
            if ($tag['name'] === "{$kind}DefaultClass") {
                self::report(
                    $test,
                    "@{$kind}DefaultClass is only read on the class.",
                    $tag['span'],
                    'PHPUnit ignores it on a method. Move it to the class docblock.',
                );
                continue;
            }

            if (array_key_exists(self::target($tag['value']), $classTargets)) {
                $test->reporter->warning(self::REDUNDANT_CODE, Reporter::issue(
                    "The class already has @covers {$tag['value']}, so this tag is redundant.",
                    where: $tag['span'],
                    help: "Remove this tag. PHPUnit uses the class's @covers tags for every test in the class.",
                ));
            }

            self::checkValue($test, $tag['value'], $form, $default, $tag['span']);
        }
    }

    /**
     * The `@covers` and `@coversDefaultClass` tags of a docblock, or the `@uses` and `@usesDefaultClass` tags.
     *
     * Each value is cleaned the same way as in PHPUnit. Trailing parentheses
     * and whitespace are dropped, and the value ends at the first space. A tag
     * without a space after its name is skipped, since it's reported by
     * AnnotationSpaceCheck.
     *
     * @return list<array{name: string, value: string, gap: string, span: Span}>
     */
    private static function tags(TestClass $test, Span $docblock, string $kind): array
    {
        $tags = [];
        foreach (Docblocks::tags($test->contents, $docblock, [$kind, "{$kind}DefaultClass"]) as $tag) {
            if ($tag['gap'] === '') {
                continue;
            }

            $value = (string) preg_replace('/[\s()]+$/', replacement: '', subject: $tag['value']);
            $tag['value'] = explode(' ', $value, limit: 2)[0];
            $tags[] = $tag;
        }

        return $tags;
    }

    /**
     * The `@covers` value as PHPUnit resolves it: without a leading backslash, and lowercased.
     *
     * PHP finds classes, methods and functions by name without case and
     * accepts a leading backslash, so `\Foo`, `Foo` and `foo` cover the same
     * code.
     */
    private static function target(string $value): string
    {
        return strtolower(ltrim($value, characters: '\\'));
    }

    /**
     * The class named by `@coversDefaultClass` or `@usesDefaultClass`.
     *
     * Reports a second tag, and a class that doesn't exist.
     *
     * @param list<array{name: string, value: string, gap: string, span: Span}> $tags
     */
    private static function defaultClass(TestClass $test, array $tags, string $kind): ?string
    {
        $defaults = [];
        foreach ($tags as $tag) {
            if ($tag['name'] !== "{$kind}DefaultClass") {
                continue;
            }

            $defaults[] = $tag;
        }

        if (count($defaults) > 1) {
            self::report(
                $test,
                "@{$kind}DefaultClass is defined more than once.",
                $defaults[1]['span'],
                'PHPUnit stops the run with an error for a class with two of these tags.',
            );
        }

        $first = $defaults[0] ?? null;
        if ($first === null) {
            return null;
        }

        $class = ltrim($first['value'], characters: '\\');
        if ($class === '' || !$test->codebase->classLikeExists($class)) {
            self::report(
                $test,
                "@{$kind}DefaultClass names {$first['value']}, which isn't a class.",
                $first['span'],
                "PHPUnit puts this class in front of each `@{$kind} ::name` value.",
            );

            return null;
        }

        return $class;
    }

    /**
     * Checks one `@covers` or `@uses` value, such as a class, `Class::method`, a function or `::method`.
     *
     * A `::method` value is a method of the default class.
     */
    private static function checkValue(
        TestClass $test,
        string $value,
        CoverageForm $form,
        ?string $default,
        Span $where,
    ): void {
        $kind = $form->value;
        $method = null;
        $class = ltrim($value, characters: '\\');
        if (str_contains($value, '::')) {
            [$class, $method] = explode('::', $class, limit: 2);
        }

        if ($method === '' || $class === '' && $method === null) {
            self::report($test, "@{$kind} {$value} doesn't name anything.", $where, self::LEFT_OUT_HELP);

            return;
        }

        // PHPUnit puts the default class in front of `::name`.
        $class = $class === '' ? $default ?? '' : $class;
        if ($class === '') {
            if (
                $method === null
                || CoverageTargets::problem($test->codebase, CoverageForm::FunctionAttribute, $method) === null
            ) {
                return;
            }

            // PHPUnit doesn't run an abstract class. PHPUnit reads the default class from
            // each concrete subclass's docblock. PHPUnit 10 and 11 also read class tags
            // from trait docblocks. Those docblocks can be in other files.
            if ($test->class->flags->contains(MetadataFlags::ABSTRACT) || $test->class->usedTraits !== []) {
                return;
            }

            self::report(
                $test,
                "@{$kind} {$value} doesn't name a function, and the class doesn't have @{$kind}DefaultClass.",
                $where,
            );

            return;
        }

        // Skip `<public>`-style selectors. PHPUnit 10 and later don't support
        // them.
        if ($method !== null && str_starts_with($method, '<') && $test->codebase->classLikeExists($class)) {
            return;
        }

        $problem = CoverageTargets::problem($test->codebase, $form, $class, $method);
        if ($problem === null) {
            return;
        }

        $missing =
            "@{$kind} {$value} doesn't name a " . ($method === null ? 'class or function' : 'method') . ' that exists.';
        [$message, $help] = match ($problem) {
            CoverageProblem::MissingMember => ["@{$kind} {$value} names a method that doesn't exist.", null],
            CoverageProblem::Interface => [
                "@{$kind} {$value} names an interface, which doesn't have any code.",
                self::LEFT_OUT_HELP,
            ],
            CoverageProblem::Builtin => ["@{$kind} {$value} names code built into PHP.", self::BUILTIN_HELP],
            CoverageProblem::MissingClass, CoverageProblem::Trait, CoverageProblem::Inherited => [
                $missing,
                str_contains($value, '\\') ? null : 'PHPUnit reads the value as a fully qualified name.',
            ],
        };

        self::report($test, $message, $where, $help);
    }

    /**
     * Reports an `invalid-covers` warning, with the default help when none is given.
     */
    public static function report(
        TestClass $test,
        string $message,
        Span|SourceLocation $where,
        ?string $help = null,
    ): void {
        $test->reporter->warning(self::CODE, Reporter::issue(
            $message,
            $where,
            $help
            ?? "PHPUnit warns about a target that can't be found, and leaves the test out of the coverage report.",
        ));
    }
}
