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
use MagoPHPUnit\Analyzer\Mock\MockBuilderMethodsHook;
use MagoPHPUnit\Analyzer\Mock\MockMethodHook;
use MagoPHPUnit\Analyzer\Mock\MockReturnHook;
use MagoPHPUnit\Analyzer\Mock\MockUnionArgumentFilter;
use MagoPHPUnit\Analyzer\Mock\MockUnionProvider;
use MagoPHPUnit\Analyzer\Mock\PlainMockCallHook;
use MagoPHPUnit\Analyzer\Mock\TraitDoubleProvider;
use MagoPHPUnit\Analyzer\Prophecy\BareProphecyArgumentFilter;
use MagoPHPUnit\Analyzer\Prophecy\ProphecyCallProvider;
use MagoPHPUnit\Analyzer\Prophecy\ProphecyDoubleHook;
use MagoPHPUnit\Analyzer\Prophecy\ProphecyReturnArgumentHook;
use MagoPHPUnit\Analyzer\Prophecy\ProphecyReturnHook;
use MagoPHPUnit\Analyzer\Prophecy\ProphecySetUpHook;
use MagoPHPUnit\Analyzer\Prophecy\ProphecyThisOutProvider;
use MagoPHPUnit\Analyzer\Prophecy\ProphecyTokenHook;
use MagoPHPUnit\Analyzer\Prophecy\ProphecyTypeTokenHook;
use MagoPHPUnit\Analyzer\Prophecy\ProphecyUnionHook;
use MagoPHPUnit\Analyzer\Prophecy\ProphecyYieldHook;
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
            description: 'Narrows the assertions left untagged by PHPUnit, types intersection mocks, mock unions and Prophecy calls, checks the arguments and return values of Prophecy calls and the classes and methods Prophecy cannot double, and reports invalid data providers, mocked methods that do not exist, mock return values that do not fit the return type of the method, missing parent setUp() calls, invalid covers and assertions that always pass.',
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
        $registry->registerMethodCallAnalysisHook(new MockReturnHook());
        $registry->registerMethodCallAnalysisHook(new MockBuilderMethodsHook());
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
        $registry->registerMethodCallAnalysisHook(new ProphecyReturnHook());
        $registry->registerMethodCallAnalysisHook(new ProphecyReturnArgumentHook());
        $registry->registerMethodCallAnalysisHook(new ProphecyYieldHook());
        $registry->registerMethodCallAnalysisHook(new ProphecyDoubleHook());
        $registry->registerMethodCallAnalysisHook(new ProphecySetUpHook());
        $registry->registerMethodCallAnalysisHook(new ProphecyTypeTokenHook());
        $registry->registerMethodCallAnalysisHook(new ProphecyTokenHook());
        $registry->registerIssueFilterHook(new BareProphecyArgumentFilter());
        $thisOut = new ProphecyThisOutProvider();
        $registry->registerMethodReturnTypeProvider($thisOut);
        $registry->registerMethodAssertionProvider($thisOut);
    }
}
