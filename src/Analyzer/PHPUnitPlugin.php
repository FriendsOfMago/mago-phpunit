<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use MagoPHPUnit\Analyzer\Assertion\AssertCountHook;
use MagoPHPUnit\Analyzer\Assertion\AssertionFunctionProvider;
use MagoPHPUnit\Analyzer\Assertion\AssertionProvider;
use MagoPHPUnit\Analyzer\Assertion\RedundantAssertionFunctionHook;
use MagoPHPUnit\Analyzer\Assertion\RedundantAssertionHook;
use MagoPHPUnit\Analyzer\Mock\IntersectionMockProvider;
use MagoPHPUnit\Analyzer\Mock\MockMethodHook;
use MagoPHPUnit\Analyzer\Mock\MockUnionArgumentFilter;
use MagoPHPUnit\Analyzer\Mock\MockUnionProvider;
use MagoPHPUnit\Analyzer\Mock\PlainMockCallHook;
use MagoPHPUnit\Analyzer\Mock\TraitDoubleProvider;
use MagoPHPUnit\Analyzer\Prophecy\ProphecyCallProvider;
use MagoPHPUnit\Analyzer\Prophecy\ProphecyThisOutProvider;
use MagoPHPUnit\Analyzer\Prophecy\ProphecyUnionHook;
use MagoPHPUnit\Analyzer\TestClass\TestClassHook;

use function array_keys;

/**
 * Registers the extension's providers, filters and hooks with the analyzer.
 *
 * @internal
 */
final class PHPUnitPlugin implements Plugin
{
    /**
     * Returns the plugin's identifier, name and description.
     */
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(
            identifier: 'phpunit',
            name: 'PHPUnit',
            description: 'Narrows the assertions left untagged by PHPUnit, types intersection mocks, mock unions and Prophecy calls, and reports invalid data providers, mocked methods that do not exist, missing parent setUp() calls, invalid covers and assertions that always pass.',
        );
    }

    /**
     * Registers the providers, filters and hooks.
     *
     * The providers add types and narrowing that are missing from the PHPUnit
     * and Prophecy declarations. The hooks report mistakes in tests that
     * PHPUnit only finds when the tests run.
     */
    public function register(PluginRegistry $registry): void
    {
        $registry->enableProviderMemoization();
        $assertions = new AssertionProvider();
        $registry->registerMethodAssertionProvider($assertions);
        $registry->registerFunctionAssertionProvider(new AssertionFunctionProvider($assertions));
        $registry->registerMethodReturnTypeProvider(new IntersectionMockProvider());
        $registry->registerMethodReturnTypeProvider(new TraitDoubleProvider());
        $registry->registerMethodReturnTypeProvider(new MockUnionProvider());
        $registry->registerIssueFilterHook(new MockUnionArgumentFilter());
        $registry->registerIssueFilterHook(new UncheckedExceptionFilter());
        $registry->registerMethodCallAnalysisHook(new MockMethodHook());
        $registry->registerMethodCallAnalysisHook(new AssertCountHook());
        $registry->registerMethodCallAnalysisHook(new PlainMockCallHook('expects'));
        $registry->registerMethodCallAnalysisHook(new PlainMockCallHook('method'));
        foreach (array_keys(RedundantAssertionHook::METHODS) as $method) {
            $registry->registerMethodCallAnalysisHook(new RedundantAssertionHook($method));
        }

        $registry->registerNodeAnalysisHook(new RedundantAssertionFunctionHook());

        $registry->registerClassLikeAnalysisHook(new TestClassHook());

        $registry->registerMethodReturnTypeProvider(new ProphecyCallProvider());
        $registry->registerNodeAnalysisHook(new ProphecyUnionHook());
        $thisOut = new ProphecyThisOutProvider();
        $registry->registerMethodReturnTypeProvider($thisOut);
        $registry->registerMethodAssertionProvider($thisOut);
    }
}
