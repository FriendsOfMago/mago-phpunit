<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Syntax\Node;
use MagoPHPUnit\Internal\Type\Types;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function in_array;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * Finds the values returned by a double for a value or a stub.
 *
 * PHPUnit calls a stub, such as `new ReturnStub(5)`, and the double returns
 * its result. `ReturnStub` returns its value, `ReturnSelf` the double,
 * `ReturnArgument` an argument of the call, `ReturnCallback` the result of
 * the callback, `ReturnValueMap` the value of a matching row, and
 * `ConsecutiveCalls` its values in turn, calling the stubs among them. An
 * `Exception` stub throws. The results of other stubs aren't known here.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class StubbedValue
{
    private const STUBS = 'phpunit\\framework\\mockobject\\stub\\';

    private const STUB_INTERFACE = 'phpunit\\framework\\mockobject\\stub\\stub';

    /**
     * How deep stubs in a `ConsecutiveCalls` list are followed.
     */
    private const MAXIMUM_DEPTH = 4;

    private function __construct() {}

    /**
     * The values returned by the double for a value given to it, or null
     * when they aren't known.
     *
     * A value that isn't a stub is returned as it is. A stub is called.
     *
     * @return list<ReturnedValue>|null
     */
    public static function of(
        NodeAnalysisContext $context,
        Node $node,
        ?Type $value,
        ConfiguredMethod $method,
        int $depth = 0,
    ): ?array {
        if ($value === null) {
            return null;
        }

        $stub = self::stub($value);
        if ($stub === null) {
            return (
                self::isCustomStub($context, $value)
                    ? null
                    : [new ReturnedValue($value, fromStub: $depth > 0, exact: Doubles::isCreatedBy($context, $node))]
            );
        }

        if (count($value->atomicTypes) !== 1 || $depth >= self::MAXIMUM_DEPTH) {
            return null;
        }

        if ($stub === 'returnself') {
            return [new ReturnedValue($method->double, fromStub: true)];
        }

        if ($stub === 'exception') {
            return [];
        }

        $arguments = StubSyntax::arguments($context, $context->source, $node);
        if ($arguments === null) {
            return null;
        }

        $types = $arguments === [] ? [] : $context->analysis->getMultipleExpressionTypes($arguments);

        return match ($stub) {
            'returnstub' => self::given($context, $arguments, $types),
            'returnargument' => ArgumentStubs::argument($method, ($types[0] ?? null)?->getLiteralInt()),
            'returncallback' => $arguments === []
                ? null
                : ArgumentStubs::callback($context, $arguments[0], $types[0] ?? null, $method),
            'returnvaluemap' => ValueMapRows::values(
                $types[0] ?? null,
                $method,
                filled: false,
                strict: count($arguments) > 1 && self::isStrict($types[1] ?? null),
            ),
            'consecutivecalls' => self::consecutive($context, $node, $arguments, $method, $depth),
            default => null,
        };
    }

    /**
     * Whether the value is one of PHPUnit's stubs, such as `ReturnStub`.
     */
    public static function isStub(Type $value): bool
    {
        return self::stub($value) !== null;
    }

    /**
     * Whether the value is an object of a class that implements PHPUnit's
     * `Stub` interface. Its result isn't known here.
     */
    public static function isCustomStub(NodeAnalysisContext $context, Type $value): bool
    {
        $names = array_values(array_filter(
            Types::names($value),
            static fn(string $name): bool => !Doubles::isDoubleName($name),
        ));

        foreach ($names === [] ? [] : $context->codebase->getMultipleClassLikes($names) as $metadata) {
            if (in_array(self::STUB_INTERFACE, $metadata->parentInterfaces ?? [], strict: true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The short lowercase name of the PHPUnit stub class in the type, such as `returnstub`, or null.
     */
    private static function stub(Type $type): ?string
    {
        foreach (Types::names($type) as $name) {
            $lower = strtolower($name);
            if (str_starts_with($lower, self::STUBS)) {
                return substr($lower, strlen(self::STUBS));
            }
        }

        return null;
    }

    /**
     * The value given to `ReturnStub`, which is returned as it is, also when it's a stub.
     *
     * @param list<Node> $arguments
     * @param list<?Type> $types
     *
     * @return list<ReturnedValue>|null
     */
    private static function given(NodeAnalysisContext $context, array $arguments, array $types): ?array
    {
        $type = $types[0] ?? null;

        return (
            $type === null
                ? null
                : [new ReturnedValue($type, fromStub: true, exact: Doubles::isCreatedBy($context, $arguments[0]))]
        );
    }

    /**
     * The values returned by a `ConsecutiveCalls` stub. A value that isn't
     * known is left out.
     *
     * `new ConsecutiveCalls([...])` takes a list, and
     * `$this->onConsecutiveCalls(...)` takes the values as arguments.
     *
     * @param list<Node> $arguments
     *
     * @return list<ReturnedValue>
     */
    private static function consecutive(
        NodeAnalysisContext $context,
        Node $node,
        array $arguments,
        ConfiguredMethod $method,
        int $depth,
    ): array {
        $first = $arguments[0] ?? null;
        $isNew = StubSyntax::isInstantiation($context->source, $node);
        $elements = match (true) {
            !$isNew => $arguments,
            $first === null => [],
            default => StubSyntax::elements($context->source, $first),
        };
        if ($elements === null) {
            return $first === null ? [] : self::listed($context, $context->analysis->getExpressionType($first));
        }

        $elementTypes = $elements === [] ? [] : $context->analysis->getMultipleExpressionTypes($elements);
        $values = [];
        foreach ($elements as $position => $element) {
            $values = [
                ...$values,
                ...(self::of($context, $element, $elementTypes[$position] ?? null, $method, $depth + 1) ?? []),
            ];
        }

        return $values;
    }

    /**
     * The values of a list that isn't written out, from its type. A value
     * that's a stub isn't known and is left out.
     *
     * @return list<ReturnedValue>
     */
    private static function listed(NodeAnalysisContext $context, ?Type $list): array
    {
        $atomics = $list->atomicTypes ?? [];
        $atomic = count($atomics) === 1 ? $atomics[0] : null;
        if (!$atomic instanceof ListType) {
            return [];
        }

        $types = array_map(static fn($element): Type => $element->type, $atomic->knownElements ?? []);
        $values = [];
        foreach ($types as $type) {
            if (self::stub($type) !== null || self::isCustomStub($context, $type)) {
                continue;
            }

            $values[] = new ReturnedValue($type, fromStub: true);
        }

        return $values;
    }

    /**
     * Whether the `$strict` argument of a value map may be true.
     */
    private static function isStrict(?Type $strict): bool
    {
        return $strict?->getLiteralBool() !== false;
    }
}
