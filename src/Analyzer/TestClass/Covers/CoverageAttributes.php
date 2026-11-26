<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\Covers;

use MagoPHPUnit\Analyzer\TestClass\PHPUnitAttributes;
use MagoPHPUnit\Analyzer\TestClass\TestClass;
use MagoPHPUnit\Internal\PHPUnitFeatures;

use function ltrim;
use function str_replace;
use function str_starts_with;

/**
 * Reports the class covers and uses attributes with targets rejected by PHPUnit.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class CoverageAttributes
{
    /**
     * The covers and uses attributes, with the names of their target and member arguments.
     */
    private const ATTRIBUTES = [
        [CoverageForm::ClassAttribute,    'CoversClass',    'className',    null],
        [CoverageForm::TraitAttribute,    'CoversTrait',    'traitName',    null],
        [CoverageForm::MethodAttribute,   'CoversMethod',   'className',    'methodName'],
        [CoverageForm::FunctionAttribute, 'CoversFunction', 'functionName', null],
        [CoverageForm::ClassAttribute,    'UsesClass',      'className',    null],
        [CoverageForm::TraitAttribute,    'UsesTrait',      'traitName',    null],
        [CoverageForm::MethodAttribute,   'UsesMethod',     'className',    'methodName'],
        [CoverageForm::FunctionAttribute, 'UsesFunction',   'functionName', null],
    ];

    private function __construct() {}

    /**
     * Checks the class `Covers*` and `Uses*` attributes for classes, traits, methods and functions.
     *
     * PHPUnit 10 doesn't have the trait and method attributes, and ignores them.
     */
    public static function check(TestClass $test): void
    {
        $targets = [];
        foreach (self::ATTRIBUTES as [$form, $written, $nameArgument, $memberArgument]) {
            $attributes = PHPUnitAttributes::of($test->class, name: $written);
            if ($attributes === [] || !$test->codebase->classLikeExists(PHPUnitAttributes::NAMESPACE . $written)) {
                continue;
            }

            foreach ($attributes as $attribute) {
                $name = PHPUnitAttributes::string($attribute, index: 0, name: $nameArgument);
                $member = $memberArgument === null
                    ? null
                    : PHPUnitAttributes::string($attribute, index: 1, name: $memberArgument);
                if ($name !== null && ($memberArgument === null || $member !== null)) {
                    $targets[] = [$form, $written, $name, $member, $attribute->location];
                }
            }
        }

        // Every named class is fetched in one query, so the lookups below
        // are served from the SDK's cache.
        $names = [];
        foreach ($targets as [$form, , $name]) {
            if ($form === CoverageForm::FunctionAttribute) {
                continue;
            }

            $names[] = ltrim($name, characters: '\\');
        }

        $test->codebase->getMultipleClassLikes($names);
        $asWritten = PHPUnitFeatures::mapsCoverageTargetsFromSource($test->codebase);
        foreach ($targets as [$form, $written, $name, $member, $location]) {
            $problem = CoverageTargets::problem($test->codebase, $form, $name, $member);
            if ($problem === null) {
                continue;
            }

            [$message, $help] = $asWritten && str_starts_with($name, '\\')
                ? [
                    "{$written} names {$name} with a leading backslash, so PHPUnit doesn't find it.",
                    CoversCheck::PREFIXED_HELP,
                ]
                : self::attributeMessage($form, $written, $name, $member, $problem);
            CoversCheck::report($test, $message, $location, $help);
        }
    }

    /**
     * The message and help for a covers or uses attribute with a target rejected by PHPUnit.
     *
     * @return array{string, ?string}
     */
    private static function attributeMessage(
        CoverageForm $form,
        string $written,
        string $name,
        ?string $member,
        CoverageProblem $problem,
    ): array {
        $target = $member === null ? $name : "{$name}::{$member}";
        $target .= $form === CoverageForm::MethodAttribute || $form === CoverageForm::FunctionAttribute ? '()' : '';

        return match ($problem) {
            CoverageProblem::Builtin => [
                "{$written} names {$target}, which is built into PHP.",
                CoversCheck::BUILTIN_HELP,
            ],
            CoverageProblem::Interface => $member === null
                ? ["{$written} names the interface {$name}, which doesn't have any code.", CoversCheck::LEFT_OUT_HELP]
                : [
                    "{$written} names the interface method {$target}, which doesn't have any code.",
                    CoversCheck::LEFT_OUT_HELP,
                ],
            CoverageProblem::Trait => [
                "{$written} names the trait {$name}. PHPUnit 12 and later only accept a class here.",
                'Use #['
                    . str_replace(search: 'Class', replace: 'Trait', subject: $written)
                    . '] instead. '
                    . CoversCheck::LEFT_OUT_HELP,
            ],
            CoverageProblem::Inherited => [
                "{$written} names {$target}, which isn't declared in {$name}. PHPUnit 12 and later only accept the "
                    . 'class or trait where the method is declared.',
                'Name the class or trait where the method is declared. ' . CoversCheck::LEFT_OUT_HELP,
            ],
            CoverageProblem::MissingClass => $member === null
                ? ["{$written} names {$name}, which doesn't exist.", null]
                : ["{$written} names {$target}, but {$name} doesn't exist.", null],
            CoverageProblem::MissingMember => ["{$written} names {$target}, which doesn't exist.", null],
        };
    }
}
