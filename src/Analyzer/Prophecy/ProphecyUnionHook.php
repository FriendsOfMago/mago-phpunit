<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\MethodFields;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\NodeKind;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Internal\Declaration\ClassFacts;
use MagoPHPUnit\Internal\Declaration\DeclaredClass;

use function array_key_exists;
use function implode;
use function in_array;
use function ltrim;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function strtolower;

use const PREG_OFFSET_CAPTURE;

/**
 * Reports a property, parameter or return type documented as a union of a
 * prophecy and the class it prophesizes.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class ProphecyUnionHook implements NodeAnalysisHook
{
    public const CODE = 'prophecy-union';

    private const PROPHECIES = ['prophecy\prophecy\prophecyinterface', 'prophecy\prophecy\objectprophecy'];

    private const PROPHECY_NAMESPACE = 'Prophecy\\';

    /**
     * A `@var`, `@param` or `@return` type with a `|` and a Prophecy class in it.
     */
    private const UNION_TAG = '/@(?:var|param|return)\s+(?=[^\s*]*\|)[^\s*]*Prophecy/';

    private const METHOD_FIELDS =
        MethodFields::NAMES | MethodFields::LOCATIONS | MethodFields::PARAMETERS | MethodFields::RETURN_TYPES;

    /**
     * Targets classes and traits.
     */
    public function getTargets(): array
    {
        return [NodeKind::Class_, NodeKind::Trait];
    }

    /**
     * Asks for the file's text.
     */
    public function getRequirements(): array
    {
        // Mago sends a file's text once for all hooks, and it's already asked
        // for by other hooks.
        return [FileAnalysisRequirement::SourceText];
    }

    /**
     * Checks the properties, parameters and return types declared by the class.
     *
     * `@var Foo|ProphecyInterface` is an old way to document a prophecy, but a
     * prophecy is never a `Foo`. Such a union isn't typed by
     * ProphecyCallProvider, so every call on it is reported on one half or the
     * other, far from the docblock that causes it. The docblock is reported
     * here once, with the type to use instead.
     */
    public function analyze(NodeAnalysisContext $context): void
    {
        $file = $context->source;
        if (!self::documentsUnion($file->contents, $context->node->span)) {
            return;
        }

        $class = DeclaredClass::resolve($file, $context->node, $context->codebase);
        if ($class === null) {
            return;
        }

        // A promoted constructor parameter is a property too, at the same
        // place, and is reported once.
        $reporter = new Reporter($context);
        $properties = [];
        foreach ((new ClassFacts($class, $context->codebase))->properties() as $property) {
            self::check($reporter, $property->name, $property->type?->type, $property->nameLocation);
            $properties[$property->nameLocation?->span->start ?? -1] = true;
        }

        $methods = $context->codebase->findMethods(
            class: $class->name,
            fields: self::METHOD_FIELDS,
            declaredOnly: true,
        );
        foreach ($methods as $method) {
            foreach ($method->parameters ?? [] as $parameter) {
                if (array_key_exists($parameter->nameLocation->span->start, $properties)) {
                    continue;
                }

                self::check($reporter, $parameter->name, $parameter->type?->type, $parameter->nameLocation);
            }

            $name = $method->originalName ?? $method->method->member;
            self::check($reporter, "{$name}()", $method->returnType?->type, $method->nameLocation);
        }
    }

    /**
     * Whether a docblock inside the span documents a union with a prophecy.
     * The pattern is matched on the whole file from the span's start, so the
     * class text isn't copied.
     */
    private static function documentsUnion(string $contents, Span $span): bool
    {
        if (!str_contains($contents, 'Prophecy')) {
            return false;
        }

        $matches = [];
        if (preg_match(self::UNION_TAG, $contents, $matches, PREG_OFFSET_CAPTURE, $span->start) !== 1) {
            return false;
        }

        return $matches[0][1] < $span->end;
    }

    /**
     * Reports the member when its type is a union of a prophecy and other classes.
     */
    private static function check(Reporter $reporter, string $what, ?Type $type, ?SourceLocation $where): void
    {
        $classes = self::prophesized($type);
        if ($classes === [] || $where === null) {
            return;
        }

        $class = implode('|', $classes);
        $reporter->warning(self::CODE, Reporter::issue(
            "A prophecy is never a `{$class}`, but `{$what}` is documented as either.",
            $where,
            "Document it as `\\Prophecy\\Prophecy\\ObjectProphecy<\\{$classes[0]}>`, and call `reveal()` where the object itself is needed.",
        ));
    }

    /**
     * The other classes in a union that also holds a prophecy, or none.
     *
     * @return list<string>
     */
    private static function prophesized(?Type $type): array
    {
        $prophecy = false;
        $classes = [];
        foreach ($type->atomicTypes ?? [] as $atomic) {
            if (!$atomic instanceof NamedObjectType) {
                continue;
            }

            $name = ltrim($atomic->name, characters: '\\');
            if (in_array(strtolower($name), self::PROPHECIES, strict: true)) {
                $prophecy = true;
                continue;
            }

            if (!str_starts_with($name, self::PROPHECY_NAMESPACE)) {
                $classes[] = $name;
            }
        }

        return $prophecy ? $classes : [];
    }
}
