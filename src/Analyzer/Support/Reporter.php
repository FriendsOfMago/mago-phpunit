<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Support;

use Mago\Sdk\Analyzer\LifecycleContext;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use MagoPHPUnit\Internal\Text\Utf8Issue;

/**
 * Reports check and hook findings on the current lifecycle context.
 *
 * @internal
 */
final class Reporter
{
    public function __construct(
        private readonly LifecycleContext $context,
    ) {}

    /**
     * Reports the issue as an error.
     */
    public function error(string $code, Issue $issue): void
    {
        self::report($this->context, Level::Error, $code, $issue);
    }

    /**
     * Reports the issue as a warning.
     */
    public function warning(string $code, Issue $issue): void
    {
        self::report($this->context, Level::Warning, $code, $issue);
    }

    /**
     * Reports the issue with its text made valid UTF-8.
     *
     * Every analyzer issue of the extension goes through here. Mago stops the
     * whole analysis on an issue with text that isn't valid UTF-8.
     */
    public static function report(LifecycleContext $context, Level $level, string $code, Issue $issue): void
    {
        $context->report($level, $code, Utf8Issue::valid($issue));
    }

    /**
     * Builds an issue with an optional help text.
     *
     * @param Span|SourceLocation $where A node span in the current file, or a
     *   metadata location, which has its own file.
     */
    public static function issue(string $message, Span|SourceLocation $where, ?string $help = null): Issue
    {
        $issue = $where instanceof Span ? Issue::new($message, $where) : Issue::at($message, $where);

        return $help === null ? $issue : $issue->withHelp($help);
    }
}
