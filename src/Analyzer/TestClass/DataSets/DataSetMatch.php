<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\DataSets;

use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Metadata\MethodMetadataProjection;
use Mago\Sdk\Analyzer\Metadata\ParameterMetadata;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AnyObjectType;
use Mago\Sdk\Analyzer\Type\EnumType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ResourceType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\TypeComparator;
use Mago\Sdk\Analyzer\TypeComparison;
use Mago\Sdk\Span;
use MagoPHPUnit\Internal\Type\Types;

use function array_slice;
use function count;
use function is_int;
use function substr;

/**
 * Matches data sets with a test's parameters under PHPUnit's argument rules.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class DataSetMatch
{
    private function __construct() {}

    /**
     * The problems of the data sets that don't fit the test's parameters.
     *
     * Each set has its label, its type and the span to report on. Each
     * problem has the set's label, the rest of the message and the span.
     *
     * @param list<DataSet> $sets
     * @param ?int $dependencies How many results of other tests come after the data, or null when it isn't known.
     * @return list<array{string, string, Span}>
     */
    public static function problems(
        TypeComparator $types,
        MethodMetadataProjection $method,
        array $sets,
        bool $named,
        ?int $dependencies,
    ): array {
        // PHPUnit passes each data set as the test's arguments in order. PHPUnit 11 and later pass
        // string-keyed values by parameter name. Dependency results are passed after the data. A value
        // rejected by a native parameter type causes an error. A missing value causes an error too. A
        // value given twice causes an error. A key that isn't a parameter name causes an error.
        $parameters = $method->parameters ?? [];
        $problems = [];
        $comparisons = [];
        $pending = [];
        foreach ($sets as $set) {
            $values = self::values($set->type, $named);
            if ($values === null) {
                continue;
            }

            // When the dependency count isn't known, skip the problems that
            // need it, and treat every parameter after the data as filled.
            if ($dependencies !== 0 && $values[1] !== []) {
                if ($dependencies !== null) {
                    $problems[] = [
                        $set->label,
                        "has string keys, and the results of the test's dependencies come after it without keys.",
                        $set->where,
                    ];
                }

                continue;
            }

            foreach (self::match($parameters, $values, $dependencies ?? count($parameters)) as $found) {
                if ($found[0] === 'problem') {
                    $problems[] = [$set->label, $found[1], $set->where];
                    continue;
                }

                // A value that may fit, such as a `mixed` one or a union with
                // a type that fits, isn't reported.
                [, $parameter, $value, $expected] = $found;
                $comparisons[] = TypeComparison::containedBy($value, $expected);
                $comparisons[] = TypeComparison::canBeIdentical($value, $expected);
                $pending[] = [$set->label, $parameter, $value, $expected, $set->where];
            }
        }

        // Each pending value has two results in a row: contained by, then can
        // be identical.
        $results = $comparisons === [] ? [] : $types->compareMultiple($comparisons);
        foreach ($pending as $index => [$label, $parameter, $value, $expected, $where]) {
            if (($results[2 * $index] ?? true) || ($results[(2 * $index) + 1] ?? true)) {
                continue;
            }

            $problems[] = [
                $label,
                "has a value of type {$value} for {$parameter->name}, but the parameter takes {$expected}.",
                $where,
            ];
        }

        return $problems;
    }

    /**
     * The label and span of the first data set whose type can't be an array, or null.
     *
     * @param list<DataSet> $sets
     * @return array{string, Span}|null
     */
    public static function notArray(array $sets): ?array
    {
        // PHPUnit stops the test at a data set that isn't an array. Only types made of scalars,
        // null, objects, enums and resources count here. A type that may be an array isn't
        // returned.
        foreach ($sets as $set) {
            $never = true;
            foreach ($set->type->atomicTypes as $atomic) {
                $never =
                    $never
                    && (
                        $atomic instanceof ScalarType
                        || $atomic instanceof NamedObjectType
                        || $atomic instanceof AnyObjectType
                        || $atomic instanceof EnumType
                        || $atomic instanceof ResourceType
                        || $atomic instanceof SimpleAtomicType
                        && $atomic->kind === SimpleAtomicTypeKind::Null
                    );
            }

            if ($never) {
                return [$set->label, $set->where];
            }
        }

        return null;
    }

    /**
     * The label, value count and span of each data set with more values than parameters.
     *
     * @param list<DataSet> $sets
     * @return list<array{string, int, Span}>
     */
    public static function extraValues(MethodMetadataProjection $method, array $sets): array
    {
        // Any number of values is accepted by a variadic test.
        $parameters = $method->parameters ?? [];
        foreach ($parameters as $parameter) {
            if ($parameter->flags->contains(MetadataFlags::VARIADIC)) {
                return [];
            }
        }

        $found = [];
        foreach ($sets as $set) {
            $count = self::size($set->type) ?? 0;
            if ($count > count($parameters)) {
                $found[] = [$set->label, $count, $set->where];
            }
        }

        return $found;
    }

    /**
     * Pairs data set values with parameters under PHP's call argument rules.
     *
     * @param list<ParameterMetadata> $parameters
     * @param array{list<Type>, array<string, Type>} $values
     * @return list<array{'compare', ParameterMetadata, Type, Type}|array{'problem', string}> Values with their required native types, or problems.
     */
    private static function match(array $parameters, array $values, int $dependencies): array
    {
        [$positional, $named] = $values;
        $found = [];
        $variadic = null;
        $fixed = 0;
        foreach ($parameters as $index => $parameter) {
            if ($parameter->flags->contains(MetadataFlags::VARIADIC)) {
                $variadic = $parameter;
                break;
            }

            $fixed++;
            $name = substr($parameter->name, offset: 1);
            $byName = $named[$name] ?? null;
            unset($named[$name]);
            $byPosition = $positional[$index] ?? null;
            if ($byName !== null && $byPosition !== null) {
                $found[] = ['problem', "has a value for {$parameter->name} both by position and by name."];
            }

            $value = $byPosition ?? $byName;
            if ($value === null) {
                // The results of other tests fill the parameters after the data.
                $filled = $index < (count($positional) + $dependencies);
                if (!$filled && !$parameter->flags->contains(MetadataFlags::HAS_DEFAULT)) {
                    $found[] = ['problem', "doesn't have a value for {$parameter->name}."];
                }

                continue;
            }

            $type = $parameter->declaredType?->type;
            if ($type !== null) {
                $found[] = ['compare', $parameter, $value, $type];
            }
        }

        if ($variadic === null) {
            foreach ($named as $key => $_) {
                $found[] = ['problem', "has the key '{$key}', which isn't a parameter of the test."];
            }

            return $found;
        }

        // A variadic parameter takes the leftover positional and named values.
        $type = $variadic->declaredType?->type;
        if ($type === null) {
            return $found;
        }

        foreach ([...array_slice($positional, offset: $fixed), ...$named] as $value) {
            $found[] = ['compare', $variadic, $value, $type];
        }

        return $found;
    }

    /**
     * The number of values in one data set, or null when it isn't known.
     */
    private static function size(Type $row): ?int
    {
        // PHPUnit counts the values with `count()`. The order of the keys doesn't matter here.
        if (count($row->atomicTypes) !== 1) {
            return null;
        }

        $atomic = $row->atomicTypes[0];
        // The count of a list with a spread, such as `[1, ...$rest]`, isn't
        // known. Neither is the count of an array with a generic key type.
        $items = match (true) {
            $atomic instanceof ListType => Types::never($atomic->elementType) ? $atomic->knownElements : null,
            $atomic instanceof KeyedArrayType => $atomic->keyType === null ? $atomic->knownItems : null,
            default => null,
        };
        foreach ($items ?? [] as $item) {
            if ($item->optional) {
                return null;
            }
        }

        return $items === null ? null : count($items);
    }

    /**
     * The values of one data set, by position and by name.
     *
     * Null when the set isn't an array written out in full, or when the
     * order of its keys matters but isn't known.
     *
     * @return array{list<Type>, array<string, Type>}|null
     *
     * @mago-expect lint:no-boolean-flag-parameter
     */
    private static function values(Type $row, bool $named): ?array
    {
        if (count($row->atomicTypes) !== 1) {
            return null;
        }

        $atomic = $row->atomicTypes[0];
        $positional = [];
        $byName = [];
        if ($atomic instanceof ListType && $atomic->knownElements !== null) {
            // The count of a list with a spread, such as `[1, ...$rest]`,
            // isn't known.
            if (!Types::never($atomic->elementType)) {
                return null;
            }

            foreach ($atomic->knownElements as $element) {
                if ($element->optional) {
                    return null;
                }

                $positional[] = $element->type;
            }

            return [$positional, $byName];
        }

        if (!$atomic instanceof KeyedArrayType || $atomic->knownItems === null || $atomic->keyType !== null) {
            return null;
        }

        foreach ($atomic->knownItems as $item) {
            if ($item->optional) {
                return null;
            }

            $key = $item->key->value;
            if ($key !== null && !is_int($key)) {
                // PHPUnit 10 passes string-keyed values in the order written.
                // Mago doesn't keep the order of string keys, so the set can't
                // be checked.
                if (!$named) {
                    return null;
                }

                $byName[$key] = $item->type;
                continue;
            }

            // PHPUnit passes integer-keyed values in the order written. Mago
            // lists the keys sorted by value, so the order is only known for
            // the keys 0, 1, 2 and so on.
            if ($key !== count($positional)) {
                return null;
            }

            $positional[] = $item->type;
        }

        return [$positional, $byName];
    }
}
