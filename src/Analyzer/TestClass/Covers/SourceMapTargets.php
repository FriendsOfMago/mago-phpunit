<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\Covers;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;

use function str_starts_with;
use function strtolower;

/**
 * Decides whether PHPUnit accepts the target of a namespace, subclass or implementation attribute.
 *
 * These attributes exist on PHPUnit 12 and later. Their targets are
 * looked up as written in the source map built by php-code-coverage's
 * `MapBuilder`.
 *
 * @internal
 */
final class SourceMapTargets
{
    /**
     * The start of the name given by Mago to an anonymous class.
     */
    private const ANONYMOUS_PREFIX = '{anonymous-class:';

    private function __construct() {}

    /**
     * Why PHPUnit rejects the namespace named by a namespace attribute, or null.
     *
     * The source map has the namespace of each class, trait and function in
     * the source, and each namespace above it. The names don't start with a
     * backslash and are compared without case.
     */
    public static function namespaceProblem(Codebase $codebase, string $namespace): ?CoverageProblem
    {
        if (str_starts_with($namespace, '\\')) {
            return CoverageProblem::MissingNamespace;
        }

        // Namespaces are only recorded for class-likes in Mago. A namespace
        // with only functions is found in the list of functions, which is
        // only fetched when needed.
        if ($codebase->namespaceExists($namespace)) {
            return null;
        }

        $prefix = strtolower($namespace) . '\\';
        foreach ($codebase->getFunctionNames() as $function) {
            if (str_starts_with(strtolower($function), $prefix)) {
                return null;
            }
        }

        return CoverageProblem::MissingNamespace;
    }

    /**
     * Why PHPUnit rejects the class-like named by a subclass or implementation attribute, or null.
     *
     * A class is only in the map when a class in the source extends it. An
     * interface is only in the map when a class in the source names it in
     * its `implements` list. An enum, a child interface and an anonymous
     * class don't count.
     */
    public static function kindProblem(Codebase $codebase, CoverageForm $form, string $name): ?CoverageProblem
    {
        $metadata = str_starts_with($name, '\\') ? null : $codebase->getClassLike($name);
        if ($metadata === null) {
            return CoverageProblem::MissingClass;
        }

        if ($metadata->flags->contains(MetadataFlags::BUILTIN)) {
            return CoverageProblem::Builtin;
        }

        $expected = $form === CoverageForm::ExtendsAttribute ? ClassLikeKind::Class_ : ClassLikeKind::Interface;
        if ($metadata->kind !== $expected) {
            return CoverageProblem::WrongKind;
        }

        // The direct descendants returned by Mago are classes, enums,
        // interfaces and anonymous classes. A class that extends a subclass
        // also extends the class, so the direct ones are enough. An anonymous
        // class has a name that starts with `{anonymous-class:`.
        $descendants = $codebase->getMultipleClassLikes($codebase->getDirectClassDescendants($metadata->name));
        foreach ($descendants as $descendant) {
            if (
                $descendant?->kind === ClassLikeKind::Class_
                && !str_starts_with($descendant->name, self::ANONYMOUS_PREFIX)
            ) {
                return null;
            }
        }

        return CoverageProblem::Unused;
    }
}
