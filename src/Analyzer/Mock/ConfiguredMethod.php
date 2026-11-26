<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MemberIdentifier;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Syntax\CallExpression;
use MagoPHPUnit\Internal\Type\Bottom;
use MagoPHPUnit\Internal\Type\Types;

use function array_map;
use function array_values;
use function count;
use function strtolower;

/**
 * Finds the double method configured by a call on PHPUnit's invocation
 * builder, such as `willReturn()`.
 *
 * `$double->expects($this->once())->method('save')->with(1)->willReturn(true)`
 * is followed back through `with()` to `method('save')`, and from there to
 * the double.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class ConfiguredMethod
{
    /**
     * PHPUnit's invocation builders. PHPUnit 10 and 11 return the classes,
     * and PHPUnit 12 and later the interfaces.
     */
    private const BUILDERS = [
        'PHPUnit\Framework\MockObject\Builder\InvocationStubber',
        'PHPUnit\Framework\MockObject\Builder\InvocationMocker',
        'PHPUnit\Framework\MockObject\InvocationStubber',
        'PHPUnit\Framework\MockObject\InvocationMocker',
    ];

    /**
     * How many calls, such as `with()`, are followed back to `method()`.
     */
    private const MAXIMUM_CHAIN = 8;

    /**
     * @param FunctionLikeMetadata $metadata The method.
     * @param string $name The method as `Class::method()`.
     * @param Type $double The type of the double.
     * @param Type $returnType The native return type of the method, with
     *   `parent` resolved.
     */
    private function __construct(
        public readonly FunctionLikeMetadata $metadata,
        public readonly string $name,
        public readonly Type $double,
        public readonly Type $returnType,
    ) {}

    /**
     * Targets the builder method of the given name.
     *
     * @return non-empty-list<MethodTarget>
     */
    public static function targets(string $method): array
    {
        return array_map(static fn(string $class): MethodTarget => MethodTarget::exact(
            $class,
            $method,
        ), self::BUILDERS);
    }

    /**
     * The method configured by the call, when the call has positional values
     * and the method has a native return type, or null.
     */
    public static function of(NodeAnalysisContext $context, CallExpression $call): ?self
    {
        if ($call->arguments === []) {
            return null;
        }

        foreach ($call->arguments as $argument) {
            if ($argument->name !== null || $argument->unpacked) {
                return null;
            }
        }

        $expression = $call->receiver;
        for ($step = 0; $step < self::MAXIMUM_CHAIN && $expression !== null; $step++) {
            $inner = CallExpression::fromExpression($context->source, $expression);
            if ($inner === null || !$inner->isMethod() || $inner->receiver === null) {
                return null;
            }

            if (strtolower($inner->getName($context->source) ?? '') === 'method') {
                return self::method($context, $inner);
            }

            $expression = $inner->receiver;
        }

        return null;
    }

    /**
     * `void` or `never` when the native return type is one of them, or null.
     *
     * @return 'void'|'never'|null
     */
    public function bottom(): ?string
    {
        return Bottom::of($this->returnType);
    }

    /**
     * The method named by `method('name')` on a double, when its return
     * values are checked, or null.
     */
    private static function method(NodeAnalysisContext $context, CallExpression $setUp): ?self
    {
        // The parameter is named `$constraint` where `method()` declares one.
        $argument = $setUp->arguments[0] ?? null;
        $double = $setUp->receiver;
        if (
            $argument === null
            || $double === null
            || $argument->unpacked
            || count($setUp->arguments) !== 1
            || $argument->name !== null && strtolower($argument->name) !== 'constraint'
        ) {
            return null;
        }

        $expects = CallExpression::fromExpression($context->source, $double);
        if (
            $expects !== null
            && $expects->isMethod()
            && $expects->receiver !== null
            && strtolower($expects->getName($context->source) ?? '') === 'expects'
        ) {
            $double = $expects->receiver;
        }

        [$nameType, $doubleType] = $context->analysis->getMultipleExpressionTypes([$argument->value, $double]);
        $names = Types::literalStrings($nameType);
        $classes = Doubles::classes($doubleType);
        if ($names === null || count($names) !== 1 || $doubleType === null || $classes === []) {
            return null;
        }

        $method = self::declaring($context, $classes, $names[0]);
        $class = $method?->identifier->class;
        $returnType = $method?->declaredReturnType?->type;
        $returnType = $returnType === null || $class === null ? null : self::withParent($context, $returnType, $class);

        return $method === null || $returnType === null
            ? null
            : new self($method, "{$class}::{$method->originalName}()", $doubleType, $returnType);
    }

    /**
     * The method of the double's classes with the name, when PHPUnit can
     * configure it, or null.
     *
     * PHPUnit doesn't create a double of interfaces that declare the same
     * method, so only a method declared once is checked.
     *
     * @param non-empty-list<non-empty-string> $classes
     */
    private static function declaring(NodeAnalysisContext $context, array $classes, string $name): ?FunctionLikeMetadata
    {
        $identifiers = [];
        foreach ($classes as $class) {
            $identifiers[] = new MemberIdentifier($class, $name);
        }

        $declaring = [];
        foreach ($context->codebase->getMultipleDeclaringMethods($identifiers) as $metadata) {
            $class = $metadata?->identifier->class;
            if ($metadata === null || $class === null || $metadata->flags->contains(MetadataFlags::MAGIC_METHOD)) {
                continue;
            }

            $declaring[strtolower($class)] = $metadata;
        }

        $method = count($declaring) === 1 ? array_values($declaring)[0] : null;

        // `method()` itself fails for these methods. MockMethodHook reports them.
        if (
            $method === null
            || $method->final
            || $method->static
            || $method->constructor
            || $method->visibility === Visibility::Private
        ) {
            return null;
        }

        return $method;
    }

    /**
     * The return type with a native `parent` replaced by the parent class of
     * the declaring class, or null when that class isn't known.
     */
    private static function withParent(NodeAnalysisContext $context, Type $returnType, string $class): ?Type
    {
        $atomics = [];
        $parent = null;
        foreach ($returnType->atomicTypes as $atomic) {
            if (!$atomic instanceof NamedObjectType || strtolower($atomic->name) !== 'parent') {
                $atomics[] = $atomic;
                continue;
            }

            // The parent's name is read from its own metadata, since the child's has it lowercased.
            $parentName = $context->codebase->getClassLike($class)?->directParentClass;
            $parent ??= $parentName === null ? null : $context->codebase->getClassLike($parentName)?->originalName;
            if ($parent === null) {
                return null;
            }

            $atomics = [...$atomics, ...Type::namedObject($parent)->atomicTypes];
        }

        return $parent === null ? $returnType : Type::fromAtomics(...$atomics);
    }
}
