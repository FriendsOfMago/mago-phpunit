<?php

declare(strict_types=1);

namespace MagoPHPUnit\Linter;

use Mago\Sdk\Span;
use Mago\Sdk\Syntax\Node;

/**
 * One call to a PHPUnit assertion or helper on `$this`, `static`, `self` or `parent`.
 *
 * @internal
 *
 * @mago-expect lint:excessive-parameter-list
 */
final class AssertionCall
{
    /**
     * @param string $name The method name as written.
     * @param 'this'|'static'|'self'|'parent' $receiver
     * @param Span $receiverSpan The receiver and the `->` or `::` after it.
     * @param list<Node> $arguments The value expression of each argument, or none for `(...)`.
     * @param bool $plain Whether every argument is positional and not unpacked.
     */
    public function __construct(
        public readonly Node $node,
        public readonly string $name,
        public readonly Node $selector,
        public readonly string $receiver,
        public readonly Span $receiverSpan,
        public readonly ?Node $argumentList,
        public readonly array $arguments,
        public readonly bool $plain,
    ) {}
}
