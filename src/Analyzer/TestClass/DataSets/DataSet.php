<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\DataSets;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Span;

/**
 * One data set of a test, with its label, its type and the span to report on.
 *
 * @internal
 */
final class DataSet
{
    /**
     * @param string $label The set's key as shown in messages, such as `#0` or `'name'`.
     */
    public function __construct(
        public readonly string $label,
        public readonly Type $type,
        public readonly Span $where,
    ) {}
}
