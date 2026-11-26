<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\DataSets;

use Mago\Sdk\Analyzer\Metadata\AttributeMetadata;
use Mago\Sdk\Analyzer\Metadata\MethodMetadataProjection;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\SourceLocation;
use MagoPHPUnit\Analyzer\TestClass\PHPUnitAttributes;
use MagoPHPUnit\Analyzer\TestClass\TestClass;

use function array_key_exists;
use function in_array;
use function is_int;
use function max;

/**
 * Finds the data sets given by a test's `#[TestWith]`, `#[TestWithJson]` and `@testWith`.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class TestWithSets
{
    private const TEST_WITH = 'testwith';

    private const TEST_WITH_JSON = 'testwithjson';

    /**
     * The lowercased short names of the attributes that give a test a data provider.
     *
     * PHPUnit ignores `#[TestWith]` and `@testWith` on a test with one of them.
     */
    private const PROVIDERS = [
        'dataprovider',
        'dataproviderexternal',
        'dataproviderclosure',
    ];

    /**
     * The lowercased short names of the attributes that give a test data.
     */
    private const DATA = ['dataprovider', 'dataproviderexternal', 'dataproviderclosure', 'testwith', 'testwithjson'];

    private function __construct() {}

    /**
     * Whether the attribute gives a test data, such as `#[DataProvider]` or `#[TestWith]`.
     */
    public static function isDataAttribute(string $name): bool
    {
        return in_array(PHPUnitAttributes::shortName($name), self::DATA, strict: true);
    }

    /**
     * Whether the test gets data from a data provider, or from a `TestWith` attribute or tag.
     */
    public static function givesData(TestClass $test, MethodMetadataProjection $method): bool
    {
        foreach ($method->attributes ?? [] as $attribute) {
            if (self::isDataAttribute($attribute->name)) {
                return true;
            }
        }

        return $test->methodTags($method, ['dataProvider', 'testWith']) !== [];
    }

    /**
     * The data sets of the test in PHPUnit's order, and the location of its first `#[TestWith*]` next to a
     * data provider attribute.
     *
     * @return array{list<TestWithSet>, ?SourceLocation}
     */
    public static function find(TestClass $test, MethodMetadataProjection $method): array
    {
        // Each set has a name or a name span to resolve. Each set has a type or an expression span to
        // resolve. For JSON that can't be used by PHPUnit, a problem is given instead of a type. The
        // problem consists of an issue message and help text. Labels are assigned in labels() once the
        // names are known.
        $sets = [];
        $invalid = [];
        $provided = false;
        $first = null;
        foreach ($method->attributes ?? [] as $attribute) {
            $name = PHPUnitAttributes::shortName($attribute->name);
            $provided = $provided || in_array($name, self::PROVIDERS, strict: true);
            if (!in_array($name, [self::TEST_WITH, self::TEST_WITH_JSON], strict: true)) {
                continue;
            }

            $first ??= $attribute->location;
            [$set, $isInvalid] = self::fromAttribute($attribute, $name);
            $sets[] = $set;
            if ($isInvalid) {
                $invalid[] = $set;
            }
        }

        // PHPUnit decodes the JSON of each #[TestWithJson] when it reads the
        // metadata, before it picks the data. JSON that isn't valid stops the
        // run even on a test with a data provider.
        if ($provided) {
            return [$invalid, $first];
        }

        // A `@testWith` tag counts only without a data provider or `#[TestWith*]` attribute. Only
        // `#[TestWithJson]` sets with invalid JSON are returned for a test with a data provider.
        return [$sets !== [] ? $sets : TestWithTag::sets($test, $method), null];
    }

    /**
     * The label of each set, given its name, numbered by PHP's rules for array keys.
     *
     * @param list<?string> $names
     * @return list<string>
     */
    public static function labels(array $names, PHPVersion $php): array
    {
        $labels = [];
        foreach (self::keys($names, $php) as $key) {
            $labels[] = is_int($key) ? '#' . (string) $key : "'{$key}'";
        }

        return $labels;
    }

    /**
     * The index of the first set with a repeated key, or null.
     *
     * @param list<?string> $names
     */
    public static function duplicate(array $names, PHPVersion $php): ?int
    {
        // Only named sets can have a repeated key. A fresh key is assigned to each unnamed set.
        $seen = [];
        foreach (self::keys($names, $php) as $index => $key) {
            if (array_key_exists($key, $seen)) {
                return $index;
            }

            $seen[$key] = true;
        }

        return null;
    }

    /**
     * The array key of each set, given its name, by PHP's rules for array keys.
     *
     * @param list<?string> $names
     * @return list<int|string>
     */
    private static function keys(array $names, PHPVersion $php): array
    {
        // PHP turns a name such as '3' into the integer key 3. The next unnamed set is numbered
        // after it. PHPUnit appends unnamed sets with `$result[] =`. On PHP below 8.3, the next key
        // is at least 0. On PHP 8.3 and later, the next key after a first key of -5 is -4.
        $keys = [];
        // Null until the first integer key on PHP 8.3 and later.
        $next = $php->isAtLeast(PHPVersion::fromParts(major: 8, minor: 3)) ? null : 0;
        foreach ($names as $name) {
            $key = $name ?? (string) ($next ?? 0);
            $integer = $key === (string) (int) $key;
            $next = $integer ? max($next ?? ((int) $key + 1), (int) $key + 1) : $next;
            $keys[] = $integer ? (int) $key : $key;
        }

        return $keys;
    }

    /**
     * The set of one `#[TestWith]` or `#[TestWithJson]` attribute, and whether its JSON is invalid.
     *
     * @param string $name The lowercased short name of the attribute.
     * @return array{TestWithSet, bool}
     */
    private static function fromAttribute(AttributeMetadata $attribute, string $name): array
    {
        // The type of a `#[TestWith]` set is resolved by the analysis. Expressions such as
        // `Foo::Bar->value` or `self::NAME` aren't resolved in attribute metadata. Only literal
        // JSON strings are checked for `#[TestWithJson]`. A set with invalid JSON is marked
        // invalid.
        $json = $name === self::TEST_WITH_JSON;
        $data = $attribute->getArgument(0, $json ? 'json' : 'data');
        $nameArgument = $attribute->getArgument(1, 'name');
        $setName = PHPUnitAttributes::string($attribute, index: 1, name: 'name');
        $text = $json ? $data?->valueType?->getLiteralString() : null;
        $type = $text === null ? null : JsonDataSets::type($text);
        $error = $text === null ? null : JsonDataSets::error($text);
        $problem = match (true) {
            $error !== null => [
                "The JSON of this #[TestWithJson] attribute isn't valid: {$error}.",
                'PHPUnit reads the attribute with the metadata of the class, and stops the whole run. Fix the JSON.',
            ],
            $text !== null && $type === null => [
                "The JSON of this #[TestWithJson] attribute isn't an array.",
                'PHPUnit errors out on the test. Write the data set as a JSON array.',
            ],
            default => null,
        };

        $set = new TestWithSet(
            where: $json && $text === null ? null : $data?->valueLocation?->span,
            type: $type,
            problem: $problem,
            name: $setName,
            nameSpan: $setName === null ? $nameArgument?->valueLocation?->span : null,
        );

        return [$set, $error !== null];
    }
}
