<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\DataSets;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Span;

/**
 * One data set of a `#[TestWith]`, `#[TestWithJson]` or `@testWith`.
 *
 * @internal
 *
 * @mago-expect lint:excessive-parameter-list
 */
final class TestWithSet
{
    /**
     * @param ?Span $where The span of the set's data, or null when there isn't one to report on.
     * @param ?Type $type The type of the set, or null while it is left to the analysis.
     * @param ?array{string, string} $problem The message and help of an issue, for JSON that can't be used by PHPUnit.
     * @param ?string $name The literal name of the set, or null.
     * @param ?Span $nameSpan The span of a name left to the analysis, or null.
     * @param ?Type $nameType The type of the name at $nameSpan, once it is known.
     */
    public function __construct(
        public readonly ?Span $where,
        public readonly ?Type $type,
        public readonly ?array $problem,
        public readonly ?string $name = null,
        public readonly ?Span $nameSpan = null,
        public readonly ?Type $nameType = null,
    ) {}

    /**
     * The same set with the types found by the analysis.
     */
    public function withTypes(?Type $nameType, ?Type $type): self
    {
        return new self($this->where, $type, $this->problem, $this->name, $this->nameSpan, $nameType);
    }
}
