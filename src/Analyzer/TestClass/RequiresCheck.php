<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass;

use Mago\Sdk\Analyzer\Metadata\AttributeMetadata;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Internal\PHPUnitFeatures;

use function is_numeric;
use function preg_match;
use function preg_quote;
use function preg_split;
use function str_contains;
use function stripos;
use function substr;
use function substr_count;
use function trim;

/**
 * Reports a version in a `Requires*` attribute without a comparison operator, or without a minor or patch level.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class RequiresCheck
{
    public const CODE = 'requires-without-operator';

    public const INCOMPLETE_CODE = 'incomplete-requires-version';

    /**
     * The attributes with a version requirement, and its position.
     */
    private const ATTRIBUTES = ['RequiresPhp' => 0, 'RequiresPhpunit' => 0, 'RequiresPhpExtension' => 1];

    /**
     * The pattern used by PHPUnit to read a requirement with a comparison operator.
     */
    private const VERSION_COMPARISON = "/(?P<operator>!=|<|<=|<>|=|==|>|>=)?\s*(?P<version>[\d\.-]+(dev|(RC|alpha|beta)[\d\.])?)[ \t]*\r?$/m";

    /**
     * The pattern that each `|` part of a requirement must match for phar-io/version, PHPUnit's version constraint parser.
     *
     * PHPUnit only uses VERSION_COMPARISON when that parser rejects the
     * requirement: `8.1dev` or `^8.1dev` is rejected, and `8.1-dev` isn't.
     */
    private const CONSTRAINT = '/^[\^~*]?v?[\d.*]+(?:-.*)?$/i';

    /**
     * The SemVer pattern of phar-io/version's `Version`.
     */
    private const SEMVER = '/^v?(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?(?:-(?:dev|beta|b|rc|alpha|a|patch|p|pl)\.?\d*)?(?:\+[0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-@]+)*)?$/i';

    private function __construct() {}

    /**
     * Checks the `RequiresPhp`, `RequiresPhpunit` and `RequiresPhpExtension`
     * attributes of the class and of each method.
     *
     * An example is `#[RequiresPhp('8.2')]`. PHPUnit 12 deprecates a version
     * without an operator. PHPUnit 13 ignores the requirement. Matches
     * phpstan-phpunit's `AttributeRequiresPhpVersionRule` and
     * `ClassAttributeRequiresPhpVersionRule`. As in those rules, an incomplete
     * version such as `>= 8.1` is reported where PHPUnit warns about it.
     */
    public static function check(TestClass $test): void
    {
        if (stripos($test->text, needle: 'Requires') === false) {
            return;
        }

        foreach (self::ATTRIBUTES as $name => $index) {
            foreach (PHPUnitAttributes::of($test->class, $name) as $attribute) {
                self::checkAttribute($test, $name, $index, $attribute);
            }

            foreach ($test->methods() as $method) {
                foreach (PHPUnitAttributes::of($method, $name) as $attribute) {
                    self::checkAttribute($test, $name, $index, $attribute);
                }
            }
        }
    }

    /**
     * Reports the attribute when its version is a bare number, or an incomplete version after an operator.
     */
    private static function checkAttribute(
        TestClass $test,
        string $name,
        int $index,
        AttributeMetadata $attribute,
    ): void {
        $requirement = PHPUnitAttributes::string($attribute, index: $index, name: 'versionRequirement');
        if ($requirement === null) {
            return;
        }

        $version = trim($requirement);
        if (is_numeric($version)) {
            $test->reporter->warning(self::CODE, Reporter::issue(
                "The version requirement '{$version}' of {$name} doesn't have a comparison operator.",
                $attribute->location,
                "Write '>= {$version}'. PHPUnit 12 deprecates a version without an operator, and PHPUnit 13 ignores the requirement.",
            ));

            return;
        }

        // PHPUnit doesn't trim the requirement, and a space changes how it's parsed.
        self::checkIncomplete($test, $name, $requirement, $attribute);
    }

    /**
     * Reports a comparison requirement whose version doesn't have a major, minor and patch level.
     *
     * PHPUnit warns when the version found by VERSION_COMPARISON doesn't have
     * two dots. The feature check is cached by the SDK, so it runs once per
     * analysis.
     */
    private static function checkIncomplete(
        TestClass $test,
        string $name,
        string $requirement,
        AttributeMetadata $attribute,
    ): void {
        $matches = [];
        if (
            !self::rejectedAsConstraint($requirement)
            || preg_match(self::VERSION_COMPARISON, $requirement, $matches) !== 1
            || !PHPUnitFeatures::warnsAboutIncompleteVersions($test->codebase)
        ) {
            return;
        }

        $version = $matches['version'];
        $dots = substr_count($version, needle: '.');
        if ($dots === 2) {
            return;
        }

        $test->reporter->warning(self::INCOMPLETE_CODE, Reporter::issue(
            "The version requirement '{$requirement}' of {$name} doesn't have a major, minor and patch level.",
            $attribute->location,
            self::help($requirement, $matches['operator'], $dots),
        ));
    }

    /**
     * Whether phar-io/version rejects the requirement, which splits it on `|` and checks each part.
     */
    private static function rejectedAsConstraint(string $requirement): bool
    {
        // phar-io only trims the parts of a `|` group.
        $parts = str_contains($requirement, '|') ? preg_split('{\s*\|\|?\s*}', trim($requirement)) : [$requirement];
        foreach ($parts === false ? [] : $parts as $part) {
            $part = str_contains($requirement, '|') ? trim($part) : $part;
            if (preg_match(self::CONSTRAINT, $part) !== 1) {
                return true;
            }

            // The parts are parsed in order. For `^` and `~`, phar-io builds a
            // SemVer version and throws for one such as `8`, and PHPUnit then
            // errors instead of reading the requirement as a comparison.
            if (($part[0] === '^' || $part[0] === '~') && preg_match(self::SEMVER, substr($part, offset: 1)) !== 1) {
                return false;
            }
        }

        return false;
    }

    /**
     * The help text, with the requirement written out in full when it's an operator and a plain number.
     *
     * Only then is the number all of the requirement. In `8.1.0dev5`, the
     * version found is `5`.
     */
    private static function help(string $requirement, string $operator, int $dots): string
    {
        // The pattern only allows a major and a minor level, so `.0` is
        // added once or twice.
        $plain = preg_match('/^' . preg_quote($operator, delimiter: '/') . '\s*\d+(?:\.\d+)?\z/', $requirement) === 1;
        $start = $plain
            ? "Write '" . $requirement . substr('.0.0', offset: $dots * 2) . "'"
            : 'Write the version with a major, minor and patch level';

        return $start . '. PHPUnit warns about an incomplete version when the test runs.';
    }
}
