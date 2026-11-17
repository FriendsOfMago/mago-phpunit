<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Metadata\ParameterMetadata;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use MagoPHPUnit\Internal\Type\Types;

use function array_slice;
use function count;
use function preg_match;

/**
 * Builds the parameters that a call setting up a prophesized method takes.
 *
 * @internal
 */
final class ProphecySignature
{
    /**
     * The class of `Argument::cetera()`, which matches the rest of the arguments.
     */
    private const REST_MATCHER = 'Prophecy\Argument\Token\AnyValuesToken';

    private function __construct() {}

    /**
     * The signature of the call that sets up the method.
     *
     * @param string $class The class that declares the method.
     * @param list<\Mago\Sdk\Analyzer\Argument> $arguments The arguments of the call.
     */
    public static function of(
        Codebase $codebase,
        FunctionLikeMetadata $method,
        string $class,
        array $arguments,
    ): EffectiveCallableSignature {
        // `Argument::cetera()` matches the arguments from its position to the
        // end, any number of them. The parameters from there on are left out,
        // and a variadic parameter takes any value in their place. A variadic
        // parameter before the token already takes the rest.
        $cetera = self::ceteraPosition($arguments);
        $declared = $cetera === null
            ? $method->parameters
            : array_slice($method->parameters, offset: 0, length: $cetera);
        // PHP treats a parameter with a default before a required one as
        // required.
        $lastRequired = -1;
        foreach ($declared as $index => $parameter) {
            if (
                $parameter->flags->contains(MetadataFlags::VARIADIC)
                || $parameter->flags->contains(MetadataFlags::HAS_DEFAULT)
            ) {
                continue;
            }

            $lastRequired = $index;
        }

        $parameters = [];
        foreach ($declared as $index => $parameter) {
            $variadic = $parameter->flags->contains(MetadataFlags::VARIADIC);
            $parameters[] = new CallableParameter(
                name: $parameter->name,
                type: self::accepted($codebase, $parameter, $class),
                variadic: $variadic,
                hasDefault: !$variadic && $index > $lastRequired,
            );
        }

        $last = $declared === [] ? null : $declared[count($declared) - 1];
        if ($cetera !== null && $last?->flags->contains(MetadataFlags::VARIADIC) !== true) {
            $parameters[] = new CallableParameter(variadic: true);
        }

        return new EffectiveCallableSignature($parameters, displayName: "{$class}::{$method->originalName}");
    }

    /**
     * The type taken by the parameter in a prophecy call.
     */
    private static function accepted(Codebase $codebase, ParameterMetadata $parameter, string $class): ?Type
    {
        $type = RevealedType::matching($parameter->type?->type, $class, $codebase);

        return $type === null ? null : Type::union($type, Type::namedObject(ProphecyCallProvider::ARGUMENT_MATCHER));
    }

    /**
     * The position of the first `Argument::cetera()` argument, or null when the call doesn't have one.
     *
     * Mago doesn't pass the argument types to a signature provider yet, so
     * the token is found by its text when the type is missing. Any class
     * name counts, so an alias of `Argument` does too. A token kept in a
     * variable isn't found then.
     *
     * @param list<\Mago\Sdk\Analyzer\Argument> $arguments
     */
    private static function ceteraPosition(array $arguments): ?int
    {
        foreach ($arguments as $position => $argument) {
            if ($argument->name !== null || $argument->unpacked) {
                continue;
            }

            $rest = $argument->type === null
                ? preg_match('/^\\\\?[\\w\\\\]+::cetera\(\s*\)$/i', $argument->expression) === 1
                : Types::names($argument->type) === [self::REST_MATCHER];
            if ($rest) {
                return $position;
            }
        }

        return null;
    }
}
