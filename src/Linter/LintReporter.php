<?php

declare(strict_types=1);

namespace MagoPHPUnit\Linter;

use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Reporting\Issue;
use MagoPHPUnit\Internal\Text\Utf8Issue;

/**
 * Reports linter issues with their text made valid UTF-8.
 *
 * @internal
 */
final class LintReporter
{
    private function __construct() {}

    /**
     * Reports the issue with its text made valid UTF-8.
     */
    public static function report(LintContext $context, Issue $issue): void
    {
        // Mago stops the whole lint run on an issue with text that isn't
        // valid UTF-8. A message can hold a name from a Latin-1 file.
        $context->report(Utf8Issue::valid($issue));
    }
}
