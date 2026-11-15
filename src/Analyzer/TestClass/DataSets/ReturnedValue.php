<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\DataSets;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Span;

/**
 * One value returned or yielded by a data provider.
 *
 * @internal
 */
final class ReturnedValue
{
    /**
     * @param int $keyword The token of the keyword: `T_RETURN`, `T_YIELD` or `T_YIELD_FROM`.
     * @param Span $span The span of the value expression.
     * @param ?string $key The key written before a yielded value, or null.
     * @param bool $alwaysGiven Whether the value is given on every run of the provider.
     * @param ?Type $type The type of the value, or null until it is known.
     */
    public function __construct(
        public readonly int $keyword,
        public readonly Span $span,
        public readonly ?string $key,
        public readonly bool $alwaysGiven,
        public readonly ?Type $type = null,
    ) {}

    /**
     * The same value with the given type.
     */
    public function withType(?Type $type): self
    {
        return new self($this->keyword, $this->span, $this->key, $this->alwaysGiven, $type);
    }
}
