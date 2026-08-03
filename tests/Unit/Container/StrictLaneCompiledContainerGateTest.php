<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Unit\Container;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Compile\ContainerCompiler;
use Glueful\Container\Container;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Container\Loader\DefaultServicesLoader;
use Glueful\Database\Connection;
use Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener;
use Glueful\Extensions\Subscriptions\Bridge\StrictLaneRegistration;
use Glueful\Extensions\Subscriptions\Bridge\StrictPayviaSubscriptionEventBridge;
use Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Task 7 -- the MANDATORY compiled-container gate (spec §4 brief, step 2):
 * feeds the ACTUAL `bus`/`none` definition maps `serviceDefinitionsForMode()`
 * produces through the framework's REAL `ContainerCompiler` and `Container`
 * (vendor/glueful/framework/src/Container/*) and proves, via an
 * `spl_autoload_register` spy, that neither `StrictPayviaSubscriptionEventBridge`
 * nor payvia's `StrictPaymentEventListener` is ever reflected/autoloaded while
 * compiling or resolving those maps. A grep or serialized-array inspection does
 * NOT satisfy this gate (see the brief); this drives the real compiler/container.
 *
 * WHY THIS RUNS IN A SEPARATE PROCESS: the autoload spy can only observe a
 * class the FIRST time anything in the process needs it -- once loaded,
 * `new \ReflectionClass($name)` (what both `ContainerCompiler` and the
 * runtime `Container`'s autowire resolver use) never re-triggers the
 * autoloader. Other suites in this repo (e.g. StrictBridgeSupportsTest)
 * legitimately construct `StrictPayviaSubscriptionEventBridge` directly,
 * which would load both FQCNs into the SAME PHPUnit process and make the spy
 * here a false pass. `#[RunClassInSeparateProcess]` guarantees a clean
 * process where the only thing that could load either class is the code
 * under test.
 *
 * WHY THIS DOESN'T CALL `ContainerFactory::create()`: that entry point merges
 * in every framework-core provider (ORM, Auth, Queue, ...) and, verified by
 * direct probe against the real framework classes, ALWAYS fails to compile in
 * this repo for reasons entirely orthogonal to Task 7 -- `ContainerCompiler`
 * cannot serialize the `ApplicationContext` `ValueDefinition` that
 * `ContainerFactory` itself binds, and that failure aborts the compiler's
 * definitions loop immediately, before it ever reaches this extension's own
 * definitions. Feeding `serviceDefinitionsForMode()`'s own map straight into
 * the real `ContainerCompiler`/`Container` -- the same two classes
 * `ContainerFactory` itself delegates to for exactly this work -- is what
 * keeps the gate driven by real, reachable framework code.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class StrictLaneCompiledContainerGateTest extends TestCase
{
    private const FORBIDDEN_FQCNS = [
        StrictPayviaSubscriptionEventBridge::class,
        StrictPaymentEventListener::class,
    ];

    /** @return iterable<string, array{string}> */
    public static function nonStrictModes(): iterable
    {
        yield 'bus' => [StrictLaneRegistration::BUS];
        yield 'none' => [StrictLaneRegistration::NONE];
    }

    #[DataProvider('nonStrictModes')]
    public function testCompilerAndContainerNeverReflectTheStrictAdapterOrItsContractForNonStrictModes(
        string $mode
    ): void {
        $dsl = SubscriptionsServiceProvider::serviceDefinitionsForMode($mode);
        $definitions = (new DefaultServicesLoader())->load($dsl, SubscriptionsServiceProvider::class, prod: true);

        // Sanity: the map genuinely doesn't reference the strict adapter's id --
        // otherwise the rest of this test would trivially pass for the wrong
        // reason (nothing to reflect in the first place).
        self::assertArrayNotHasKey(StrictPayviaSubscriptionEventBridge::class, $definitions);

        $seen = [];
        $spy = static function (string $class) use (&$seen): void {
            $seen[] = $class;
        };
        spl_autoload_register($spy, true, true); // prepend: observe every request, real or not

        try {
            // Step A -- the REAL compiler. It walks EVERY definition in the map,
            // reflecting each AutowireDefinition's class via
            // ContainerCompiler::emitCtorArgs()'s `new \ReflectionClass(...)`,
            // before it finally throws -- because this provider's OWN
            // factory-based services (PlanCatalog, EntitlementResolver, ...)
            // are pre-existing, legitimate FactoryDefinition entries
            // ContainerCompiler cannot compile (a framework limitation
            // entirely unrelated to the strict lane). That expected failure
            // is asserted below; what this gate actually cares about is that
            // the walk never touched either forbidden FQCN on its way there.
            try {
                (new ContainerCompiler())->compile(
                    $definitions,
                    'ProbeContainer',
                    'Glueful\\Container\\Compiled\\StrictLaneGateProbe'
                );
                self::fail(
                    "expected ContainerCompiler to reject mode '{$mode}'\'s pre-existing "
                    . 'FactoryDefinition-backed services (PlanCatalog et al.) -- if it no '
                    . "longer does, update this test's expectations, but keep the autoload spy assertion"
                );
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('FactoryDefinition', $e->getMessage());
                foreach (self::FORBIDDEN_FQCNS as $forbidden) {
                    self::assertStringNotContainsString($forbidden, $e->getMessage());
                }
            }

            // Step B -- the REAL runtime container. Resolve every id we can;
            // some (e.g. TierResolverInterface's framework-core `TierResolver`
            // dependency) are legitimately unresolvable in this isolated,
            // subscriptions-only map and are expected to throw -- this loop
            // only cares about exercising real autowire reflection, not full
            // functional resolution.
            $container = new Container($definitions + [
                ApplicationContext::class => new ValueDefinition(
                    ApplicationContext::class,
                    new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing')
                ),
                Connection::class => new ValueDefinition(
                    Connection::class,
                    new Connection([
                        'engine' => 'sqlite',
                        'sqlite' => ['primary' => ':memory:'],
                        'pooling' => ['enabled' => false],
                    ])
                ),
            ]);
            foreach (array_keys($definitions) as $id) {
                try {
                    $container->get($id);
                } catch (\Throwable) {
                    // Expected for ids needing infra this isolated map doesn't provide
                    // (e.g. the framework-core TierResolver, CacheStore).
                }
            }
        } finally {
            spl_autoload_unregister($spy);
        }

        foreach (self::FORBIDDEN_FQCNS as $forbidden) {
            self::assertNotContains(
                $forbidden,
                $seen,
                "mode '{$mode}' must never autoload/reflect {$forbidden}"
            );
        }
    }
}
