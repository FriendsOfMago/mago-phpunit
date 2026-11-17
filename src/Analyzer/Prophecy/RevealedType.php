<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AnyObjectType;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\AtomicType;
use Mago\Sdk\Analyzer\Type\IterableType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListElement;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use MagoPHPUnit\Internal\Type\LooseScalar;
use MagoPHPUnit\Internal\Type\SettledType;

use function array_map;

/**
 * Builds the types of the values that Prophecy turns into a declared type.
 *
 * Prophecy passes arguments and return values through its `Revealer`. It
 * replaces each prophecy with its double, in arrays too. So a value of type
 * `ObjectProphecy<Foo>` stands for a `Foo`.
 *
 * @internal
 */
final class RevealedType
{
    private const PROPHECY_INTERFACE = 'Prophecy\Prophecy\ProphecyInterface';

    /**
     * A value matched against an argument.
     */
    private const MATCHED = 'matched';

    /**
     * A value returned by the double.
     */
    private const RETURNED = 'returned';

    /**
     * A value returned by the double, after PHP converts scalars to the
     * native return type.
     */
    private const CONVERTED = 'converted';

    /**
     * @param string $self The class that declares the method. A docblock
     *   `$this` type stands for it.
     * @param self::MATCHED|self::RETURNED|self::CONVERTED $use How the value is used.
     * @param ?Codebase $codebase The codebase, for the objects that Prophecy
     *   compares loosely with an argument.
     */
    private function __construct(
        private readonly string $self,
        private readonly string $use,
        private readonly ?Codebase $codebase = null,
    ) {}

    /**
     * The declared type of an argument widened with the values that Prophecy
     * matches against it, or null when the type is `mixed` or depends on a
     * template.
     *
     * Prophecy compares a number and a numeric string by their text, so `1`
     * matches the argument `'1'`. An object with `__toString()` matches its
     * text, and a `DateTimeInterface` matches another one at the same time.
     *
     * @param string $self The class that declares the method.
     */
    public static function matching(?Type $declared, string $self, Codebase $codebase): ?Type
    {
        return self::widened($declared, new self($self, self::MATCHED, $codebase));
    }

    /**
     * The declared return type widened with the prophecies of its objects,
     * or null when the type is `mixed` or depends on a template.
     *
     * @param string $self The class that declares the method.
     */
    public static function returning(?Type $declared, string $self): ?Type
    {
        return self::widened($declared, new self($self, self::RETURNED));
    }

    /**
     * The native return type widened with the prophecies of its objects and
     * the scalars that PHP converts to it, or null when the type is `mixed`
     * or depends on a template.
     *
     * The code of a double isn't in strict mode, so PHP converts a returned
     * `'5'` to an `int`, a `5` to a `string` and a `1` to a `bool`.
     *
     * @param string $self The class that declares the method.
     */
    public static function converted(?Type $native, string $self): ?Type
    {
        return self::widened($native, new self($self, self::CONVERTED));
    }

    /**
     * The declared type widened by the given builder.
     *
     * A template type isn't known in the call. Mago doesn't replace it in a
     * signature built here, so a check against it would be wrong.
     */
    private static function widened(?Type $declared, self $builder): ?Type
    {
        if ($declared === null || !SettledType::is($declared)) {
            return null;
        }

        $atomics = [];
        foreach ($declared->atomicTypes as $atomic) {
            if ($atomic instanceof MixedType) {
                return null;
            }

            $atomics = [...$atomics, ...$builder->widen($atomic)];
        }

        return Type::fromAtomics(...$atomics)->withFlags($declared->flags);
    }

    /**
     * The atomic type and the prophecies that stand for it.
     *
     * @return non-empty-list<AtomicType>
     */
    private function widen(AtomicType $atomic): array
    {
        return match (true) {
            $atomic instanceof NamedObjectType => $this->objectAndProphecy($atomic),
            $atomic instanceof AnyObjectType => [
                $atomic,
                new NamedObjectType(self::PROPHECY_INTERFACE, null, null, false, false, null, false),
            ],
            $atomic instanceof ListType => [new ListType(
                $this->widenType($atomic->elementType),
                $atomic->knownElements === null
                    ? null
                    : array_map(
                        fn(ListElement $element): ListElement => new ListElement(
                            $element->index,
                            $element->optional,
                            $this->widenType($element->type),
                        ),
                        $atomic->knownElements,
                    ),
                $atomic->knownCount,
                $atomic->nonEmpty,
            )],
            $atomic instanceof KeyedArrayType => [new KeyedArrayType(
                $atomic->knownItems === null
                    ? null
                    : array_map(
                        fn(ArrayItem $item): ArrayItem => new ArrayItem(
                            $item->key,
                            $item->optional,
                            $this->widenType($item->type),
                        ),
                        $atomic->knownItems,
                    ),
                $atomic->keyType,
                $atomic->valueType === null ? null : $this->widenType($atomic->valueType),
                $atomic->nonEmpty,
            )],
            // An array passed for an iterable is revealed too. Any other
            // iterable is passed as it is.
            $atomic instanceof IterableType => [
                $atomic,
                new KeyedArrayType(null, $atomic->keyType, $this->widenType($atomic->valueType), false),
            ],
            $atomic instanceof ScalarType && $this->use === self::MATCHED => LooseScalar::matched($atomic),
            $atomic instanceof ScalarType && $this->use === self::CONVERTED => LooseScalar::converted($atomic),
            default => [$atomic],
        };
    }

    /**
     * The object type and `ObjectProphecy` of it.
     *
     * A `static` or `$this` type is replaced with its class. The double
     * returned by `reveal()` has the prophesized class, not the late static
     * binding. A `$this` from a docblock has the name `$this` instead of the
     * class.
     *
     * @return non-empty-list<AtomicType>
     */
    private function objectAndProphecy(NamedObjectType $atomic): array
    {
        $object = $atomic->static || $atomic->isThis
            ? new NamedObjectType(
                $atomic->name === '$this' ? $this->self : $atomic->name,
                $atomic->parameters,
                $atomic->variances,
                false,
                false,
                $atomic->intersections,
                $atomic->remappedParameters,
            )
            : $atomic;

        return [
            $object,
            new NamedObjectType(
                ProphesizedMethod::OBJECT_PROPHECY,
                [Type::fromAtomic($object)],
                null,
                false,
                false,
                null,
                false,
            ),
            ...(
                $this->use === self::MATCHED && $this->codebase !== null
                    ? LooseScalar::matchedObject($this->codebase, $object->name)
                    : []
            ),
        ];
    }

    /**
     * The type with each member widened.
     */
    private function widenType(Type $type): Type
    {
        $atomics = [];
        foreach ($type->atomicTypes as $atomic) {
            $atomics = [...$atomics, ...$this->widen($atomic)];
        }

        return Type::fromAtomics(...$atomics)->withFlags($type->flags);
    }
}
