<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;

use function explode;
use function ltrim;
use function preg_match;
use function str_starts_with;
use function strtolower;

/**
 * Drops unhandled-exception reports for exceptions from PHPUnit, its `sebastian/*` libraries and Prophecy.
 *
 * @internal
 */
final class UncheckedExceptionFilter implements IssueFilterHook
{
    private const NAMESPACES = ['phpunit\\', 'prophecy\\', 'sebastianbergmann\\'];

    /**
     * Filters Mago's `unhandled-thrown-type` issues.
     *
     * With `check-throws` on, Mago reports every assertion in a test, since
     * PHPUnit documents that an assertion throws when it fails. A test lets
     * those exceptions reach PHPUnit on purpose. phpstan-phpunit treats the
     * PHPUnit and sebastian exceptions as unchecked for the same reason.
     */
    public function getCodes(): array
    {
        return ['unhandled-thrown-type'];
    }

    /**
     * Removes the issue when every exception class in its message is unchecked.
     */
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $matches = [];
        if (preg_match('/exception `([^`]+)`/', $context->issue->message, $matches) !== 1) {
            return IssueFilterDecision::Keep;
        }

        foreach (explode('|', $matches[1]) as $class) {
            if (!self::unchecked($class)) {
                return IssueFilterDecision::Keep;
            }
        }

        return IssueFilterDecision::Remove;
    }

    /**
     * Whether the exception class is in one of the unchecked namespaces.
     */
    private static function unchecked(string $class): bool
    {
        $class = strtolower(ltrim($class, characters: '\\'));
        foreach (self::NAMESPACES as $namespace) {
            if (str_starts_with($class, $namespace)) {
                return true;
            }
        }

        return false;
    }
}
