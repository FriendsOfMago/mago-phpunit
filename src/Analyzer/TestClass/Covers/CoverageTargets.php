<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\Covers;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use MagoPHPUnit\Internal\PHPUnitFeatures;

use function in_array;
use function ltrim;
use function str_starts_with;
use function strtolower;

/**
 * Decides whether PHPUnit accepts a coverage target on the installed major.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class CoverageTargets
{
    /**
     * The lowercase names of the methods added by PHP to a backed enum.
     */
    private const BACKED_ENUM_METHODS = ['from', 'tryfrom'];

    private function __construct() {}

    /**
     * Returns the reason for rejecting a coverage target, or null.
     *
     * @param string $class The class name, or the function name for a function target.
     * @return ?CoverageProblem The reason, or null for an accepted target.
     */
    public static function problem(
        Codebase $codebase,
        CoverageForm $form,
        string $class,
        ?string $member = null,
    ): ?CoverageProblem {
        // PHPUnit 10 and 11 map targets through reflection. PHPUnit uses the `Mapper` of
        // sebastian/code-unit there. Only user-defined code is accepted. PHPUnit 12 and later look up
        // targets in a map of the source files. The map is built by php-code-coverage's `MapBuilder`.
        // Code built into PHP isn't in that map either.
        $fromSource = PHPUnitFeatures::mapsCoverageTargetsFromSource($codebase);
        // PHPUnit 12 and later look an attribute's target up in the map as
        // written, and the names in the map don't start with a backslash.
        // PHPUnit 10 and 11 find the target through reflection. PHP's
        // reflection ignores a leading backslash.
        if (str_starts_with($class, '\\')) {
            if ($fromSource && !$form->isAnnotation()) {
                return $form === CoverageForm::FunctionAttribute
                    ? CoverageProblem::MissingMember
                    : CoverageProblem::MissingClass;
            }

            $class = ltrim($class, characters: '\\');
        }

        if ($form === CoverageForm::FunctionAttribute) {
            return self::functionProblem($codebase, $class);
        }

        $metadata = $class === '' ? null : $codebase->getClassLike($class);
        if ($member !== null && ($form->isAnnotation() || !$fromSource)) {
            return self::mappedMethodProblem($codebase, $metadata, $member);
        }

        if ($metadata === null) {
            $function = $form->isAnnotation()
                ? self::functionProblem($codebase, $class)
                : CoverageProblem::MissingMember;

            return $function === CoverageProblem::MissingMember ? CoverageProblem::MissingClass : $function;
        }

        if ($metadata->flags->contains(MetadataFlags::BUILTIN)) {
            return CoverageProblem::Builtin;
        }

        $kind = $metadata->kind;
        if ($member !== null) {
            // PHPUnit 12 and later key each method by the class or trait it's
            // declared in. A method inherited from a parent class or taken
            // from a trait isn't found under the class.
            if ($kind === ClassLikeKind::Interface) {
                return CoverageProblem::Interface;
            }

            if (self::isEnumMethod($metadata, $member)) {
                return CoverageProblem::Builtin;
            }

            // Mago lists the methods of `@method` tags among the declared
            // methods. PHPUnit only knows the methods found in the source.
            $method = strtolower($member);
            if (in_array($method, $metadata->methods, strict: true) && !self::isPseudoMethod($metadata, $method)) {
                return null;
            }

            $declaring = $codebase->getDeclaringMethod($class, $member);

            return $declaring === null || $declaring->flags->contains(MetadataFlags::MAGIC_METHOD)
                ? CoverageProblem::MissingMember
                : CoverageProblem::Inherited;
        }

        // A class that isn't a trait is already reported by Mago for
        // `CoversTrait`, since the parameter type is `trait-string`.
        if ($form === CoverageForm::TraitAttribute) {
            return null;
        }

        // PHPUnit 10 passes a `CoversClass` name straight to the `Mapper`.
        // A user-defined interface is accepted there. PHPUnit 10 and 11 reject
        // a `@covers` tag for an interface, but not a `@uses` tag.
        if ($kind === ClassLikeKind::Interface) {
            $accepted =
                $form === CoverageForm::UsesAnnotation
                || $form === CoverageForm::ClassAttribute && !PHPUnitFeatures::rejectsCoversClassInterface($codebase);

            return $accepted ? null : CoverageProblem::Interface;
        }

        // PHPUnit 12 and later only look `CoversClass` up among classes.
        return $fromSource && $form === CoverageForm::ClassAttribute && $kind === ClassLikeKind::Trait
            ? CoverageProblem::Trait
            : null;
    }

    /**
     * Why `Class::name` is rejected by the Mapper on PHPUnit 10 and 11, or null.
     */
    private static function mappedMethodProblem(
        Codebase $codebase,
        ?ClassLikeMetadata $metadata,
        string $method,
    ): ?CoverageProblem {
        // A user-defined method of the class, interface or trait is valid. A user-defined function
        // `name` is valid too. PHPUnit 10 tries the function first. PHPUnit 11 tries the method
        // first. The covered code depends on the order. Whether the value is valid doesn't depend
        // on it.
        if (self::functionProblem($codebase, $method) === null) {
            return null;
        }

        if ($metadata === null) {
            return CoverageProblem::MissingClass;
        }

        $declaring = $codebase->getDeclaringMethod($metadata->name, $method);
        if ($declaring === null || $declaring->flags->contains(MetadataFlags::MAGIC_METHOD)) {
            return CoverageProblem::MissingMember;
        }

        $builtin =
            $metadata->flags->contains(MetadataFlags::BUILTIN)
            || $declaring->flags->contains(MetadataFlags::BUILTIN)
            || self::isEnumMethod($metadata, $method);

        return $builtin ? CoverageProblem::Builtin : null;
    }

    /**
     * Whether the method is one of the methods added by PHP to an enum.
     */
    private static function isEnumMethod(ClassLikeMetadata $metadata, string $method): bool
    {
        // Mago lists `cases()`, `from()` and `tryFrom()` among the enum's methods. The built-in
        // flag isn't set on them. A backed enum has a backing type.
        if ($metadata->kind !== ClassLikeKind::Enum) {
            return false;
        }

        $method = strtolower($method);
        // PHP adds `cases()` to every enum.
        if ($method === 'cases') {
            return true;
        }

        // A pure enum can declare its own `from()` and `tryFrom()`.
        return $metadata->enumType !== null && in_array($method, self::BACKED_ENUM_METHODS, strict: true);
    }

    /**
     * Whether the lowercase method name comes from an `@method` tag of the class.
     */
    private static function isPseudoMethod(ClassLikeMetadata $metadata, string $method): bool
    {
        return (
            in_array($method, $metadata->pseudoMethods, strict: true)
            || in_array($method, $metadata->staticPseudoMethods, strict: true)
        );
    }

    /**
     * Why PHPUnit rejects the function, or null when it's user-defined.
     */
    private static function functionProblem(Codebase $codebase, string $name): ?CoverageProblem
    {
        $function = $codebase->getFunction($name);
        if ($function === null) {
            return CoverageProblem::MissingMember;
        }

        return $function->flags->contains(MetadataFlags::BUILTIN) ? CoverageProblem::Builtin : null;
    }
}
