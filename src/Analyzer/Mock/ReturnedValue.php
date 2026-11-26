<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\Type;

/**
 * A value returned by a double for a configured method.
 *
 * @internal
 */
final class ReturnedValue
{
    public const ANY = 'any';

    public const AS_WARNING = 'as-warning';

    public const FAILS_ONLY = 'fails-only';

    /**
     * @param Type $type The type of the value.
     * @param bool $fromStub Whether a stub returns the value, rather than the
     *   value being given as it is.
     * @param bool $exact Whether the class of an object value is known, such
     *   as for a double created right there.
     * @param self::ANY|self::AS_WARNING|self::FAILS_ONLY $reporting How the
     *   value is reported: like any other, as a warning, since the double
     *   only returns it for a call that passes more arguments than the
     *   method declares, or only when it never fits, as for a type inferred
     *   by Mago or an argument whose parameter is wider than the return type.
     * @param ?string $reason A sentence that says why the double returns the
     *   value, when the message needs one.
     */
    public function __construct(
        public readonly Type $type,
        public readonly bool $fromStub,
        public readonly bool $exact = false,
        public readonly string $reporting = self::ANY,
        public readonly ?string $reason = null,
    ) {}
}
