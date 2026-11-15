<?php

declare(strict_types=1);

namespace MagoPHPUnit;

use Composer\InstalledVersions;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Extension;
use Mago\Sdk\Linter\Rule;
use MagoPHPUnit\Analyzer\PHPUnitPlugin;
use MagoPHPUnit\Linter\AssertionStyleRule;
use MagoPHPUnit\Linter\DataProviderNameCaseRule;
use MagoPHPUnit\Linter\NoAssertEmptyRule;
use MagoPHPUnit\Linter\PreferTestAttributeRule;
use MagoPHPUnit\Linter\StrictAssertionsRule;
use MagoPHPUnit\Linter\UseSpecificAssertionsRule;

/**
 * Builds the Mago extension for PHPUnit and Prophecy.
 *
 * @api
 */
final class PHPUnitExtension
{
    private const PACKAGE = 'friendsofmago/mago-phpunit';

    private function __construct() {}

    /**
     * Returns the extension that is run by resources/worker.php.
     */
    public static function create(): Extension
    {
        return new Extension(
            identifier: self::PACKAGE,
            name: 'PHPUnit',
            version: self::version(),
            linterRules: self::linterRules(),
            analyzerPlugins: [self::plugin()],
        );
    }

    /**
     * Returns the analyzer plugin, for another extension to register next to its own plugins.
     *
     * That way a project doesn't run two workers.
     */
    public static function plugin(): Plugin
    {
        return new PHPUnitPlugin();
    }

    /**
     * Returns the linter rules, for another extension to register next to its own rules.
     *
     * @return list<Rule>
     */
    public static function linterRules(): array
    {
        return [
            new StrictAssertionsRule(),
            new UseSpecificAssertionsRule(),
            new AssertionStyleRule('static'),
            new AssertionStyleRule('self'),
            new AssertionStyleRule('this'),
            new NoAssertEmptyRule(),
            new DataProviderNameCaseRule(),
            new PreferTestAttributeRule(),
        ];
    }

    /**
     * Returns the version installed by Composer.
     *
     * That's the tag, or `dev-<branch>` for a checkout. It's listed by
     * `mago extension list`.
     */
    private static function version(): string
    {
        // A package loaded without Composer's autoloader doesn't have a record.
        if (!InstalledVersions::isInstalled(self::PACKAGE)) {
            return 'unknown';
        }

        return InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'unknown';
    }
}
