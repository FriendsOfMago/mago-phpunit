<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\IterableType;
use Mago\Sdk\Analyzer\Type\ResourceType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;

use function ltrim;
use function strtolower;

/**
 * Gives the type of the arguments that an argument token can match.
 *
 * @internal
 */
final class TokenNeed
{
    /**
     * The token classes in `Prophecy\Argument\Token` without their `Token`
     * suffix, by the type of the arguments each one can match, other than
     * `TypeToken`.
     */
    private const MATCHERS = [
        'StringContains' => 'string',
        'ArrayCount' => 'countable',
        'ArrayEntry' => 'entries',
        'ArrayEveryEntry' => 'traversable',
        'ApproximateValue' => 'numeric',
    ];

    private function __construct() {}

    /**
     * The type of the arguments the token can match, or null when it can
     * match any argument or the type isn't known.
     *
     * @param string $matcher The token class without its namespace and its
     *   `Token` suffix, such as `Type`.
     * @param ?string $name The name given to `Argument::type()`.
     */
    public static function of(Codebase $codebase, string $matcher, ?string $name): ?Type
    {
        if ($matcher === 'Type') {
            return $name === null ? null : self::named($codebase, $name);
        }

        $need = self::MATCHERS[$matcher] ?? null;

        return $need === null ? null : self::kind($need);
    }

    /**
     * The type of the values accepted by `Argument::type()` for the name: a
     * type with an `is_*()` function, a class or an interface.
     *
     * An enum, a trait and a type such as `callable` or `a` aren't checked.
     */
    private static function named(Codebase $codebase, string $name): ?Type
    {
        $type = match (strtolower($name)) {
            'int', 'integer', 'long' => Type::int(),
            'float', 'double' => Type::float(),
            'string' => Type::string(),
            'bool' => Type::bool(),
            'null' => Type::null(),
            'object' => Type::object(),
            'array' => self::kind('array'),
            'iterable' => self::kind('traversable'),
            'countable' => self::kind('countable'),
            'numeric' => self::kind('numeric'),
            'scalar' => Type::fromAtomic(new ScalarType(ScalarTypeKind::Scalar)),
            'resource' => Type::fromAtomic(new ResourceType(null)),
            default => null,
        };
        if ($type !== null) {
            return $type;
        }

        $class = ltrim($name, characters: '\\');
        $metadata = $class === '' ? null : $codebase->getClassLike($class);

        return $metadata !== null
        && ($metadata->kind === ClassLikeKind::Class_ || $metadata->kind === ClassLikeKind::Interface)
            ? Type::namedObject($metadata->name)
            : null;
    }

    /**
     * The type of one kind of argument.
     *
     * @param 'string'|'array'|'countable'|'entries'|'traversable'|'numeric' $kind
     */
    private static function kind(string $kind): Type
    {
        $array = Type::array(Type::fromAtomic(new ScalarType(ScalarTypeKind::ArrayKey)), Type::mixed());

        return match ($kind) {
            'string' => Type::string(),
            'array' => $array,
            'countable' => Type::union($array, Type::namedObject('Countable')),
            'entries' => Type::union($array, Type::namedObject('Traversable'), Type::namedObject('ArrayAccess')),
            'traversable' => Type::fromAtomic(new IterableType(Type::mixed(), Type::mixed(), null)),
            'numeric' => Type::fromAtomic(new ScalarType(ScalarTypeKind::Numeric)),
        };
    }
}
