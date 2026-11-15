<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\Covers;

use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Analyzer\TestClass\PHPUnitAttributes;
use MagoPHPUnit\Analyzer\TestClass\TestClass;
use MagoPHPUnit\Internal\PHPUnitFeatures;
use MagoPHPUnit\Internal\Text\Docblocks;

use function count;
use function explode;
use function ltrim;
use function preg_replace;
use function str_contains;
use function str_starts_with;
use function stripos;
use function strtolower;

/**
 * Reports covers tags and attributes for missing code, built-in code, interfaces or other rejected targets.
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

    private const TAGS = ['covers', 'coversDefaultClass'];

    /**
     * The covers attributes, with the names of their target and member arguments.
     */
    private const ATTRIBUTES = [
        [CoverageForm::ClassAttribute,    'className',    null],
        [CoverageForm::TraitAttribute,    'traitName',    null],
        [CoverageForm::MethodAttribute,   'className',    'methodName'],
        [CoverageForm::FunctionAttribute, 'functionName', null],
    ];

    private const LEFT_OUT_HELP = 'PHPUnit warns about the target and leaves the test out of the coverage report.';

    private const BUILTIN_HELP = 'PHPUnit only covers user-defined code. ' . self::LEFT_OUT_HELP;

    private const PREFIXED_HELP =
        'PHPUnit 12 and later look the name up as written. Drop the backslash. ' . self::LEFT_OUT_HELP;

    private function __construct() {}

    /**
     * Checks the class covers attributes and the class and method coverage tags.
     */
    public static function check(TestClass $test): void
    {
        // Only method tags are checked on an abstract class. PHPUnit reads class tags only from the
        // concrete test class. Attributes aren't inherited in PHP. PHPUnit reads method tags on the
        // inherited method.
        $abstract = $test->class->flags->contains(MetadataFlags::ABSTRACT);
        if (!$abstract) {
            self::checkAttributes($test);
        }

        // The `@covers` and `@coversDefaultClass` tags count only on PHPUnit 10 and 11.
        // PHPUnit warns about a name that doesn't resolve. The test is left out of the
        // coverage report. This matches phpstan-phpunit's `ClassCoversExistsRule` and
        // `ClassMethodCoversExistsRule`, plus the attributes.
        if (!str_contains($test->contents, '@covers')) {
            return;
        }

        $default = null;
        $classCovers = [];
        $docblock = $abstract ? null : $test->annotationDocblock();
        if ($docblock !== null) {
            $tags = self::tags($test, $docblock);
            $default = self::defaultClass($test, $tags);
            foreach ($tags as $tag) {
                if ($tag['name'] !== 'covers') {
                    continue;
                }

                $classCovers[self::target($tag['value'])] = true;
                self::checkValue($test, $tag['value'], $default, $tag['span']);
            }
        }

        // Skip the method docblocks when the class text doesn't mention
        // `@covers`.
        foreach (stripos($test->text, needle: '@covers') !== false ? $test->methods() : [] as $method) {
            $methodDocblock = $test->methodAnnotationDocblock($method);
            foreach ($methodDocblock === null ? [] : self::tags($test, $methodDocblock) as $tag) {
                if ($tag['name'] === 'coversDefaultClass') {
                    self::report($test, message: '@coversDefaultClass is only read on the class.', where: $tag['span']);
                    continue;
                }

                // A method `@covers` tag with a target also named on the class is redundant.
                // This matches phpstan-phpunit's `ClassMethodCoversExistsRule`.
                if ($classCovers[self::target($tag['value'])] ?? false) {
                    $test->reporter->warning(self::REDUNDANT_CODE, Reporter::issue(
                        "The class already has @covers {$tag['value']}, so this tag is redundant.",
                        where: $tag['span'],
                        help: "Remove this tag. PHPUnit uses the class's @covers tags for every test in the class.",
                    ));
                }

                self::checkValue($test, $tag['value'], $default, $tag['span']);
            }
        }
    }

    /**
     * The `@covers` and `@coversDefaultClass` tags of a docblock.
     *
     * Each value is cleaned the same way as in PHPUnit. Trailing parentheses
     * and whitespace are dropped, and the value ends at the first space. A tag
     * without a space after its name is skipped, since it's reported by
     * AnnotationSpaceCheck.
     *
     * @return list<array{name: string, value: string, gap: string, span: Span}>
     */
    private static function tags(TestClass $test, Span $docblock): array
    {
        $tags = [];
        foreach (Docblocks::tags($test->contents, $docblock, self::TAGS) as $tag) {
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
     * The class named by `@coversDefaultClass`.
     *
     * Reports a second tag, and a class that doesn't exist.
     *
     * @param list<array{name: string, value: string, gap: string, span: Span}> $tags
     */
    private static function defaultClass(TestClass $test, array $tags): ?string
    {
        $defaults = [];
        foreach ($tags as $tag) {
            if ($tag['name'] !== 'coversDefaultClass') {
                continue;
            }

            $defaults[] = $tag;
        }

        if (count($defaults) > 1) {
            self::report($test, message: '@coversDefaultClass is defined more than once.', where: $defaults[1]['span']);
        }

        $first = $defaults[0] ?? null;
        if ($first === null) {
            return null;
        }

        $class = ltrim($first['value'], characters: '\\');
        if ($class === '' || !$test->codebase->classLikeExists($class)) {
            self::report($test, "@coversDefaultClass names {$first['value']}, which isn't a class.", $first['span']);

            return null;
        }

        return $class;
    }

    /**
     * Checks one `@covers` value: a class, `Class::method`, a function, or
     * `::method` of the default class.
     */
    private static function checkValue(TestClass $test, string $value, ?string $default, Span $where): void
    {
        $method = null;
        $class = ltrim($value, characters: '\\');
        if (str_contains($value, '::')) {
            [$class, $method] = explode('::', $class, limit: 2);
        }

        if ($method === '' || $class === '' && $method === null) {
            self::report($test, "@covers {$value} doesn't name anything.", $where);

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
                "@covers {$value} doesn't name a function, and the class doesn't have @coversDefaultClass.",
                $where,
            );

            return;
        }

        // Skip `<public>`-style selectors. PHPUnit 10 and later don't support
        // them.
        if ($method !== null && str_starts_with($method, '<') && $test->codebase->classLikeExists($class)) {
            return;
        }

        $problem = CoverageTargets::problem($test->codebase, CoverageForm::Annotation, $class, $method);
        if ($problem === null) {
            return;
        }

        $missing =
            "@covers {$value} doesn't name a " . ($method === null ? 'class or function' : 'method') . ' that exists.';
        [$message, $help] = match ($problem) {
            CoverageProblem::MissingMember => ["@covers {$value} names a method that doesn't exist.", null],
            CoverageProblem::Interface => [
                "@covers {$value} names an interface, which doesn't have code to cover.",
                null,
            ],
            CoverageProblem::Builtin => ["@covers {$value} names code built into PHP.", self::BUILTIN_HELP],
            CoverageProblem::MissingClass, CoverageProblem::Trait, CoverageProblem::Inherited => [
                $missing,
                str_contains($value, '\\') ? null : 'PHPUnit reads the value as a fully qualified name.',
            ],
        };

        self::report($test, $message, $where, $help);
    }

    /**
     * Checks the class `CoversClass`, `CoversTrait`, `CoversMethod` and `CoversFunction` attributes.
     */
    private static function checkAttributes(TestClass $test): void
    {
        $targets = [];
        foreach (self::ATTRIBUTES as [$form, $nameArgument, $memberArgument]) {
            foreach (PHPUnitAttributes::of($test->class, name: $form->value) as $attribute) {
                $name = PHPUnitAttributes::string($attribute, index: 0, name: $nameArgument);
                $member = $memberArgument === null
                    ? null
                    : PHPUnitAttributes::string($attribute, index: 1, name: $memberArgument);
                if ($name !== null && ($memberArgument === null || $member !== null)) {
                    $targets[] = [$form, $name, $member, $attribute->location];
                }
            }
        }

        // Every named class is fetched in one query, so the lookups below
        // are served from the SDK's cache.
        $names = [];
        foreach ($targets as [$form, $name]) {
            if ($form === CoverageForm::FunctionAttribute) {
                continue;
            }

            $names[] = ltrim($name, characters: '\\');
        }

        $test->codebase->getMultipleClassLikes($names);
        $asWritten = PHPUnitFeatures::mapsCoverageTargetsFromSource($test->codebase);
        foreach ($targets as [$form, $name, $member, $location]) {
            $problem = CoverageTargets::problem($test->codebase, $form, $name, $member);
            if ($problem !== null) {
                [$message, $help] = $asWritten && str_starts_with($name, '\\')
                    ? [
                        "{$form->value} names {$name} with a leading backslash, so PHPUnit doesn't find it.",
                        self::PREFIXED_HELP,
                    ]
                    : self::attributeMessage($form, $name, $member, $problem);
                self::report($test, $message, $location, $help);
            }
        }
    }

    /**
     * The message and help for a covers attribute with a target rejected by PHPUnit.
     *
     * @return array{string, ?string}
     */
    private static function attributeMessage(
        CoverageForm $form,
        string $name,
        ?string $member,
        CoverageProblem $problem,
    ): array {
        $target = $member === null ? $name : "{$name}::{$member}";
        $target .= $form === CoverageForm::MethodAttribute || $form === CoverageForm::FunctionAttribute ? '()' : '';
        $written = $form->value;
        return match ($problem) {
            CoverageProblem::Builtin => ["{$written} names {$target}, which is built into PHP.", self::BUILTIN_HELP],
            CoverageProblem::Interface => $member === null
                ? ["{$written} names the interface {$name}, which doesn't have code to cover.", null]
                : ["{$written} names the interface method {$target}, which doesn't have code to cover.", null],
            CoverageProblem::Trait => [
                "{$written} names the trait {$name}. PHPUnit 12 and later only accept a class here.",
                'Use #[CoversTrait] instead. ' . self::LEFT_OUT_HELP,
            ],
            CoverageProblem::Inherited => [
                "{$written} names {$target}, which isn't declared in {$name}. PHPUnit 12 and later only accept the "
                    . 'class or trait where the method is declared.',
                'Name the class or trait where the method is declared. ' . self::LEFT_OUT_HELP,
            ],
            CoverageProblem::MissingClass => $member === null
                ? ["{$written} names {$name}, which doesn't exist.", null]
                : ["{$written} names {$target}, but {$name} doesn't exist.", null],
            CoverageProblem::MissingMember => ["{$written} names {$target}, which doesn't exist.", null],
        };
    }

    /**
     * Reports an `invalid-covers` warning, with the default help when none is
     * given.
     */
    private static function report(
        TestClass $test,
        string $message,
        Span|SourceLocation $where,
        ?string $help = null,
    ): void {
        $test->reporter->warning(self::CODE, Reporter::issue(
            $message,
            $where,
            $help ?? "PHPUnit warns about a name it can't find, and the coverage report leaves the test out.",
        ));
    }
}
