<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Container;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Container\Bootstrap\ContainerFactory;
use Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener;
use Glueful\Extensions\Subscriptions\Bridge\StrictLaneRegistration;
use Glueful\Extensions\Subscriptions\Bridge\StrictPayviaSubscriptionEventBridge;
use Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider;
use Glueful\Extensions\Subscriptions\Tests\Support\ForcedStrictLaneModeProvider;
use PHPUnit\Framework\TestCase;

/**
 * Task 7 -- end-to-end proof that the strict adapter is actually reachable
 * through payvia's `StrictPaymentEventListener::CONTAINER_TAG` via the REAL
 * framework container pipeline (`ContainerFactory::create()`), not just via
 * the pure `tagsForMode()` unit assertions.
 *
 * IMPORTANT FINDING (documented on `SubscriptionsServiceProvider::tags()`):
 * `ContainerFactory::loadExtensionDefinitions()` only ever calls a provider's
 * `static tags()` for a TYPED `defs()`-based provider
 * (`applyProviderTags()` is invoked exclusively from the `defs()` branch).
 * `SubscriptionsServiceProvider` is `services()`-based (DSL), so `tags()` is
 * NOT presently reachable from real container construction -- verified
 * empirically by probing the real framework classes directly. The 'tags' key
 * on the strict adapter's own DSL entry (read by `applyDslTags()`) is what
 * actually wires the tag today; `tags()`/`tagsForMode()` are kept per the
 * accepted design so the wiring stays correct for free if this provider ever
 * migrates to `defs()`. This test would fail without that 'tags' key even
 * though every `tagsForMode()`/`serviceDefinitionsForMode()` unit assertion
 * passes -- it is the thing that actually closes the loop.
 */
final class StrictLaneTagWiringTest extends TestCase
{
    protected function tearDown(): void
    {
        // Restore the fixture's default so it can't leak its forced mode into
        // any other test that happens to load this class in the same process.
        ForcedStrictLaneModeProvider::$mode = StrictLaneRegistration::STRICT;
        parent::tearDown();
    }

    private function contextWithProvider(string $providerFqcn): ApplicationContext
    {
        $base = sys_get_temp_dir() . '/glueful-strict-tag-wiring-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents(
            $base . '/config/serviceproviders.php',
            "<?php\nreturn " . var_export(['enabled' => [$providerFqcn]], true) . ";\n"
        );
        file_put_contents(
            $base . '/config/database.php',
            "<?php\nreturn ['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:']];\n"
        );

        $ctx = new ApplicationContext($base, 'testing');
        $ctx->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));

        return $ctx;
    }

    /**
     * The real, no-arg services()/tags() entry points, in this repo's real
     * (always-strict) environment: the strict adapter is genuinely resolvable
     * through the container tag, and it's a real, working instance.
     */
    public function testRealStrictEnvironmentPublishesTheStrictAdapterUnderTheContainerTag(): void
    {
        $ctx = $this->contextWithProvider(SubscriptionsServiceProvider::class);

        $container = ContainerFactory::create($ctx, false);

        $tagged = $container->get(StrictPaymentEventListener::CONTAINER_TAG);
        self::assertIsIterable($tagged);
        $tagged = is_array($tagged) ? $tagged : iterator_to_array($tagged, false);

        self::assertCount(1, $tagged);
        self::assertInstanceOf(StrictPayviaSubscriptionEventBridge::class, $tagged[0]);
    }

    /**
     * Bus mode publishes no tag at all -- forced via the fixture provider
     * (see its docblock) since real-environment `services()` can't be driven
     * into bus mode without runtime class fakery. An absent tag has zero
     * contributors, so ContainerFactory never binds it and `has()` is false.
     */
    public function testBusModePublishesNoContainerTag(): void
    {
        ForcedStrictLaneModeProvider::$mode = StrictLaneRegistration::BUS;
        $ctx = $this->contextWithProvider(ForcedStrictLaneModeProvider::class);

        $container = ContainerFactory::create($ctx, false);

        self::assertFalse($container->has(StrictPaymentEventListener::CONTAINER_TAG));
    }

    /**
     * None mode publishes no tag at all -- same reasoning as bus mode above.
     */
    public function testNoneModePublishesNoContainerTag(): void
    {
        ForcedStrictLaneModeProvider::$mode = StrictLaneRegistration::NONE;
        $ctx = $this->contextWithProvider(ForcedStrictLaneModeProvider::class);

        $container = ContainerFactory::create($ctx, false);

        self::assertFalse($container->has(StrictPaymentEventListener::CONTAINER_TAG));
    }
}
