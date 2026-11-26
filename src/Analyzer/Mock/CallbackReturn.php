<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\FunctionLikeKind;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;
use MagoPHPUnit\Internal\Type\Types;

use function count;
use function explode;
use function ltrim;
use function str_contains;
use function str_starts_with;
use function strtolower;

/**
 * Finds the type returned by a callback given to a stub.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class CallbackReturn
{
    private function __construct() {}

    /**
     * The type returned by the callback and whether it's inferred, or null
     * when it isn't known.
     *
     * A closure written out, in the call or in the expression assigned to a
     * variable that's followed, has the return type inferred by
     * Mago from its body, also when it declares one. It isn't known when the
     * closure takes a variable by reference, since the variable can change
     * after the closure is created. A first-class
     * callable, a function name such as `'strlen'`, a
     * `'Foo::bar'` string, an array such as `[$this, 'bar']` and an
     * invokable object have the native return type of the function or the
     * method. A callable type from a docblock, such as `callable(): void`,
     * takes callables that return anything, so it isn't known. A return type
     * with `static`, `self` or `parent` isn't known either.
     *
     * @param Node $node The callback, in the syntax of `$source`.
     *
     * @return array{Type, bool}|null
     */
    public static function of(NodeAnalysisContext $context, SourceFile $source, Node $node, ?Type $callback): ?array
    {
        $atomics = $callback->atomicTypes ?? [];
        $atomic = count($atomics) === 1 ? $atomics[0] : null;
        if ($atomic instanceof CallableType) {
            return self::callable($context, $source, $node, $atomic);
        }

        $returnType = match (true) {
            $atomic instanceof ScalarType && $atomic->kind === ScalarTypeKind::String => self::named(
                $context,
                $callback?->getLiteralString() ?? '',
            ),
            $atomic instanceof ListType => self::pair($context, $atomic),
            $atomic instanceof NamedObjectType => self::method($context, $atomic->name, '__invoke'),
            default => null,
        };

        return $returnType === null || self::isRelative($returnType) ? null : [$returnType, false];
    }

    /**
     * Whether the callback is a closure, which is read from its syntax.
     */
    public static function isClosure(?Type $callback): bool
    {
        $atomics = $callback->atomicTypes ?? [];
        $atomic = count($atomics) === 1 ? $atomics[0] : null;

        return $atomic instanceof CallableType && $atomic->signature?->source?->kind === FunctionLikeKind::Closure;
    }

    /**
     * The return type of a closure or a first-class callable, and whether it's inferred.
     *
     * @return array{Type, bool}|null
     */
    private static function callable(
        NodeAnalysisContext $context,
        SourceFile $source,
        Node $node,
        CallableType $callable,
    ): ?array {
        $signature = $callable->signature;
        $origin = $signature?->source;
        if ($signature === null || $origin === null) {
            return null;
        }

        if ($origin->kind !== FunctionLikeKind::Closure) {
            $returnType = self::nativeReturn($context->codebase->getFunctionLike($origin));

            return $returnType === null || self::isRelative($returnType) ? null : [$returnType, false];
        }

        $returnType = $signature->returnType;
        if ($returnType === null || self::isRelative($returnType) || !self::isPlainClosure($source, $node)) {
            return null;
        }

        return [$returnType, true];
    }

    /**
     * Whether the node is a closure or an arrow function written out, which
     * doesn't take a variable by reference. Such a variable can change after
     * the closure is created. A closure around the call isn't looked at.
     */
    private static function isPlainClosure(SourceFile $source, Node $node): bool
    {
        while ($node->kind === NodeKind::Expression) {
            $inner = $source->getChildren($node)[0] ?? null;
            if ($inner === null) {
                return false;
            }

            $node = $inner;
        }

        if ($node->kind !== NodeKind::Closure && $node->kind !== NodeKind::ArrowFunction) {
            return false;
        }

        foreach ($source->getDescendants($node, NodeKind::ClosureUseClauseVariable) as $variable) {
            if (str_starts_with(ltrim($source->getText($variable)), '&')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The return type of a function or a method named by a string, such as
     * `'strlen'` or `'Foo::bar'`.
     */
    private static function named(NodeAnalysisContext $context, string $name): ?Type
    {
        if ($name === '') {
            return null;
        }

        if (!str_contains($name, '::')) {
            return self::nativeReturn($context->codebase->getFunction($name));
        }

        [$class, $method] = explode('::', $name, limit: 2);

        return $class === '' || $method === '' ? null : self::method($context, $class, $method);
    }

    /**
     * The return type of the method named by an array such as `[$this, 'bar']` or `[Foo::class, 'bar']`.
     */
    private static function pair(NodeAnalysisContext $context, ListType $list): ?Type
    {
        $elements = $list->knownElements ?? [];
        if ($list->knownCount !== 2 || count($elements) !== 2) {
            return null;
        }

        $target = $elements[0]->type;
        $method = $elements[1]->type->getLiteralString();
        $objects = Types::names($target);
        $class = count($objects) === 1 ? $objects[0] : Types::literalName($target);

        return $method === null || $method === '' || $class === '' ? null : self::method($context, $class, $method);
    }

    /**
     * The native return type of a method, or null.
     */
    private static function method(NodeAnalysisContext $context, string $class, string $method): ?Type
    {
        return self::nativeReturn($context->codebase->getMethod($class, $method));
    }

    /**
     * The native return type of a function or a method, or null.
     */
    private static function nativeReturn(?FunctionLikeMetadata $function): ?Type
    {
        return $function?->declaredReturnType?->type;
    }

    /**
     * Whether the type names `static`, `self` or `parent`, which depend on the class of the callback.
     */
    private static function isRelative(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            $lower = $atomic instanceof NamedObjectType ? strtolower($atomic->name) : '';
            if ($atomic instanceof NamedObjectType && $atomic->static || $lower === 'self' || $lower === 'parent') {
                return true;
            }
        }

        return false;
    }
}
