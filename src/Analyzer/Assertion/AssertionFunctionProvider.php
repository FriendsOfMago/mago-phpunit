<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Assertion;

use Mago\Sdk\Analyzer\AssertionProviderContext;
use Mago\Sdk\Analyzer\FunctionAssertionProvider;
use Mago\Sdk\Analyzer\InvocationAssertions;

/**
 * Narrows the values passed to PHPUnit's global assertion functions, such as `assertArrayHasKey()`.
 *
 * @internal
 */
final class AssertionFunctionProvider implements FunctionAssertionProvider
{
    public function __construct(
        private readonly AssertionProvider $methods,
    ) {}

    /**
     * Targets the global functions named like the assertions in AssertionProvider.
     */
    public function getTargets(): array
    {
        return AssertionProvider::functionTargets();
    }

    /**
     * Returns the same narrowing as AssertionProvider does for the method.
     */
    public function getAssertions(AssertionProviderContext $context): ?InvocationAssertions
    {
        return $this->methods->getAssertions($context);
    }
}
