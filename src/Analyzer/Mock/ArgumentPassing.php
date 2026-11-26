<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MemberIdentifier;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;
use MagoPHPUnit\Internal\Type\Types;

use function array_filter;
use function array_values;
use function count;
use function in_array;
use function ltrim;
use function str_contains;
use function str_starts_with;
use function strtolower;

/**
 * Finds whether an argument is passed by value, so the call can't change
 * the variable given to it.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class ArgumentPassing
{
    private const STUBS = 'phpunit\\framework\\mockobject\\stub\\';

    private const OTHER_CLASSES = [NodeKind::AnonymousClass, NodeKind::Trait, NodeKind::Enum, NodeKind::Interface];

    private function __construct() {}

    /**
     * Whether the argument is passed by value, or false when that isn't known.
     *
     * The arguments of `new` for PHPUnit's stub classes are. For other
     * calls, the parameter is looked up: for a function by its name, for a
     * method by the type of the object or the named class, and for `new` by
     * the class's constructor. PHPUnit's double interfaces in the type of
     * the object are left out, since the method is on the doubled class. An
     * unpacked argument is passed by value when every parameter from its
     * position on takes values by value.
     *
     * @param Node $argument A `PositionalArgument` or a `NamedArgument` node.
     */
    public static function byValue(NodeAnalysisContext $context, SourceFile $file, Node $argument): bool
    {
        $list = $file->getParent($file->getParent($argument) ?? $argument);
        $call = $list?->kind === NodeKind::ArgumentList ? $file->getParent($list) : null;
        if ($list === null || $call === null) {
            return false;
        }

        $parts = $file->getChildren($call);
        $name = match ($call->kind) {
            NodeKind::MethodCall, NodeKind::NullSafeMethodCall, NodeKind::StaticMethodCall => strtolower($file->getText(
                $parts[1] ?? $call,
            )),
            default => '',
        };
        if (self::isStubClass($file, $call)) {
            return true;
        }

        $function = self::callee($context, $file, $call, $name);
        if ($function === false) {
            return true;
        }

        return $function !== null && self::isByValue($file, $list, $argument, $function);
    }

    /**
     * The function called by name, or null. An unqualified name in a
     * namespace falls back to the global function, as in PHP.
     */
    private static function function(
        NodeAnalysisContext $context,
        SourceFile $file,
        Node $callee,
    ): ?FunctionLikeMetadata {
        $name = $file->getResolvedName($callee)?->name;
        $written = $file->getText($callee);
        $function = $name === null ? null : $context->codebase->getFunction($name);
        if ($function !== null || $written === '' || str_contains($written, '\\') || str_starts_with($written, '$')) {
            return $function;
        }

        return $context->codebase->getFunction($written);
    }

    /**
     * Whether `new` creates one of PHPUnit's stubs.
     */
    private static function isStubClass(SourceFile $file, Node $call): bool
    {
        if ($call->kind !== NodeKind::Instantiation) {
            return false;
        }

        $class = $file->getChildren($call)[1] ?? null;
        $resolved = $class === null ? null : $file->getResolvedName($class)?->name;

        return $resolved !== null && str_starts_with(strtolower(ltrim($resolved, characters: '\\')), self::STUBS);
    }

    /**
     * The function or method called, false for a class without a
     * constructor, or null when it isn't known.
     */
    private static function callee(
        NodeAnalysisContext $context,
        SourceFile $file,
        Node $call,
        string $method,
    ): FunctionLikeMetadata|false|null {
        $codebase = $context->codebase;
        $parts = $file->getChildren($call);
        $first = $parts[0] ?? null;
        if ($first === null) {
            return null;
        }

        if ($call->kind === NodeKind::FunctionCall) {
            return self::function($context, $file, $first);
        }

        if ($call->kind === NodeKind::Instantiation) {
            // A subclass of the class named by `static` can take the argument by reference.
            $named = $parts[1] ?? $first;
            $class = strtolower($file->getText($named)) === 'static' ? null : self::className($file, $named);
            if ($class === null || $codebase->getClassLike($class) === null) {
                return null;
            }

            return $codebase->getDeclaringMethod($class, '__construct') ?? false;
        }

        $classes = $call->kind === NodeKind::StaticMethodCall
            ? [self::className($file, $first)]
            : array_values(array_filter(
                Types::names($context->analysis->getExpressionType($first)),
                static fn(string $name): bool => !Doubles::isDoubleName($name),
            ));
        if ($classes === [] || in_array(null, $classes, strict: true) || $method === '') {
            return null;
        }

        $identifiers = [];
        foreach ($classes as $class) {
            $identifiers[] = new MemberIdentifier((string) $class, $method);
        }

        $methods = $codebase->getMultipleDeclaringMethods($identifiers);

        return count($methods) === 1 ? $methods[0] : self::same($methods);
    }

    /**
     * The method when every class has it with the same by-reference
     * parameters, or null.
     *
     * @param list<?FunctionLikeMetadata> $methods
     */
    private static function same(array $methods): ?FunctionLikeMetadata
    {
        $first = $methods[0] ?? null;
        foreach ($methods as $method) {
            if ($method === null || self::references($method) !== self::references($first)) {
                return null;
            }
        }

        return $first;
    }

    /**
     * The positions of the by-reference parameters, as a string.
     */
    private static function references(?FunctionLikeMetadata $function): string
    {
        $positions = '';
        foreach ($function->parameters ?? [] as $position => $parameter) {
            $positions .= $parameter->flags->contains(MetadataFlags::BY_REFERENCE) ? "{$position}," : '';
        }

        return $positions;
    }

    /**
     * The class named by the node, with `self`, `static` and `parent`
     * resolved from the class around it, or null.
     */
    private static function className(SourceFile $file, Node $node): ?string
    {
        $text = strtolower(ltrim($file->getText($node), characters: '\\'));
        if ($text !== 'self' && $text !== 'static' && $text !== 'parent') {
            return $file->getResolvedName($node)?->name;
        }

        // `self` and `static` name the class around the call. `parent` isn't followed.
        return $text === 'parent' ? null : self::enclosingClass($file, $node);
    }

    /**
     * The full name of the class around the node, or null.
     */
    private static function enclosingClass(SourceFile $file, Node $node): ?string
    {
        $class = null;
        $namespace = '';
        foreach ($file->getAncestors($node) as $ancestor) {
            // `self` in an anonymous class, a trait or an enum isn't followed.
            if ($class === null && in_array($ancestor->kind, self::OTHER_CLASSES, strict: true)) {
                return null;
            }

            if ($class === null && $ancestor->kind === NodeKind::Class_) {
                foreach ($file->getChildren($ancestor) as $child) {
                    $class ??= $child->kind === NodeKind::LocalIdentifier ? $file->getText($child) : null;
                }
            }

            if ($ancestor->kind === NodeKind::Namespace_) {
                foreach ($file->getChildren($ancestor) as $child) {
                    $isName = $child->kind === NodeKind::Identifier;
                    $namespace = $namespace === '' && $isName ? $file->getText($child) . '\\' : $namespace;
                }
            }
        }

        return $class === null ? null : $namespace . $class;
    }

    /**
     * Whether the function's parameter for the argument takes it by value.
     */
    private static function isByValue(
        SourceFile $file,
        Node $list,
        Node $argument,
        FunctionLikeMetadata $function,
    ): bool {
        $parameter = null;
        if ($argument->kind === NodeKind::NamedArgument) {
            $name = '$' . $file->getText($file->getChildren($argument)[0] ?? $argument);
            foreach ($function->parameters as $candidate) {
                $parameter = $candidate->name === $name ? $candidate : $parameter;
            }

            return $parameter !== null && !$parameter->flags->contains(MetadataFlags::BY_REFERENCE);
        }

        $position = 0;
        $item = $file->getParent($argument);
        foreach ($file->getChildren($list) as $candidate) {
            if ($candidate->id === $item?->id) {
                break;
            }

            $position++;
        }

        // An unpacked argument fills the parameter at its position and those after it.
        $unpacked = str_starts_with($file->getText($argument), '...');
        foreach ($function->parameters as $index => $candidate) {
            $variadic = $candidate->flags->contains(MetadataFlags::VARIADIC);
            $byReference = $candidate->flags->contains(MetadataFlags::BY_REFERENCE);
            if ($unpacked && $index >= $position && $byReference) {
                return false;
            }

            $parameter = $index === $position || $variadic && $index < $position ? $candidate : $parameter;
        }

        return $parameter !== null && !$parameter->flags->contains(MetadataFlags::BY_REFERENCE);
    }
}
