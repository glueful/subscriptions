<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Lifecycle;

use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Lifecycle\TenantIntegration;
use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;
use Glueful\Extensions\Subscriptions\Projection\SubscriptionEventProjector;
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Repositories\ProviderEventReceiptRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\DefaultSubjectResolver;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Extensions\Subscriptions\Tests\Support\PermissiveSubjectResolver;
use Glueful\Extensions\Subscriptions\Tests\Support\RecordingTenantContextRunner;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

/**
 * Task 12 -- context-runner discipline (spec §9): `TenantIntegration::runAsTenantOr()`
 * / `runAsSystemOr()` degrade to a direct call of `$fn` when no `TenantContextRunner`
 * is bound (the contracts-absent path, and the ordinary harness), and delegate to the
 * bound runner's `runAsTenant()`/`runAsSystem()` otherwise. `SubscriptionService`'s
 * subject-aware `…For()` methods must run through `runAsTenant($subject->tenantUuid, ...)`;
 * `SubscriptionEventProjector::project()` must run through `runAsSystem(...)` because it
 * has to locate the target row BEFORE any tenant context can be known.
 */
final class TenantIntegrationTest extends SubscriptionsTestCase
{
    private const PLATFORM_FREE = 'planv2free01';

    // ---------------------------------------------------------------
    // TenantIntegration itself
    // ---------------------------------------------------------------

    public function testRunAsTenantOrCallsFnDirectlyWhenNoRunnerIsBound(): void
    {
        $called = false;

        $result = TenantIntegration::runAsTenantOr($this->appContext(), 'tenantA', function () use (&$called): string {
            $called = true;
            return 'direct';
        });

        self::assertTrue($called);
        self::assertSame('direct', $result);
    }

    public function testRunAsSystemOrCallsFnDirectlyWhenNoRunnerIsBound(): void
    {
        $called = false;

        $result = TenantIntegration::runAsSystemOr($this->appContext(), function () use (&$called): string {
            $called = true;
            return 'direct';
        });

        self::assertTrue($called);
        self::assertSame('direct', $result);
    }

    public function testRunAsTenantOrDelegatesToTheBoundRunner(): void
    {
        $runner = new RecordingTenantContextRunner();
        $this->bind(\Glueful\Extensions\Contracts\Tenancy\TenantContextRunner::class, $runner);

        $result = TenantIntegration::runAsTenantOr(
            $this->appContext(),
            'tenantA',
            static fn (): string => 'via-runner'
        );

        self::assertSame('via-runner', $result);
        self::assertSame([['mode' => 'tenant', 'tenantUuid' => 'tenantA']], $runner->calls());
    }

    public function testRunAsSystemOrDelegatesToTheBoundRunner(): void
    {
        $runner = new RecordingTenantContextRunner();
        $this->bind(\Glueful\Extensions\Contracts\Tenancy\TenantContextRunner::class, $runner);

        $result = TenantIntegration::runAsSystemOr($this->appContext(), static fn (): string => 'via-runner');

        self::assertSame('via-runner', $result);
        self::assertSame([['mode' => 'system', 'tenantUuid' => null]], $runner->calls());
    }

    // ---------------------------------------------------------------
    // SubscriptionService: subject-scoped work runs through runAsTenant()
    // ---------------------------------------------------------------

    private function serviceWithRunner(RecordingTenantContextRunner $runner): SubscriptionService
    {
        $this->bind(\Glueful\Extensions\Contracts\Tenancy\TenantContextRunner::class, $runner);

        return new SubscriptionService(
            new SubscriptionRepository(),
            new SubscriptionEventRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext(),
            new PermissiveSubjectResolver(),
        );
    }

    public function testCurrentForRunsThroughRunAsTenantWithTheSubjectsTenantUuid(): void
    {
        $runner = new RecordingTenantContextRunner();
        $service = $this->serviceWithRunner($runner);

        $service->currentFor(Subject::tenant('tenantA'));

        self::assertSame('tenant', $runner->lastMode());
        self::assertSame('tenantA', $runner->lastTenantUuid());
    }

    public function testStartForRunsThroughRunAsTenantWithTheSubjectsTenantUuid(): void
    {
        $runner = new RecordingTenantContextRunner();
        $service = $this->serviceWithRunner($runner);

        $service->startFor(Subject::tenant('tenantB'), self::PLATFORM_FREE);

        self::assertNotSame([], $runner->calls());
        foreach ($runner->calls() as $call) {
            self::assertSame('tenant', $call['mode']);
            self::assertSame('tenantB', $call['tenantUuid']);
        }
    }

    public function testChangePlanForRunsThroughRunAsTenantWithTheSubjectsTenantUuid(): void
    {
        $runner = new RecordingTenantContextRunner();
        $service = $this->serviceWithRunner($runner);
        $subject = Subject::tenant('tenantC');
        $service->startFor($subject, self::PLATFORM_FREE);

        $before = $runner->callCount();
        $service->changePlanFor($subject, self::PLATFORM_FREE);

        self::assertGreaterThan($before, $runner->callCount());
        foreach ($runner->calls() as $call) {
            self::assertSame('tenant', $call['mode']);
            self::assertSame('tenantC', $call['tenantUuid']);
        }
    }

    public function testCancelForRunsThroughRunAsTenantWithTheSubjectsTenantUuid(): void
    {
        $runner = new RecordingTenantContextRunner();
        $service = $this->serviceWithRunner($runner);
        $subject = Subject::tenant('tenantD');
        $service->startFor($subject, self::PLATFORM_FREE);

        $before = $runner->callCount();
        $service->cancelFor($subject);

        self::assertGreaterThan($before, $runner->callCount());
        foreach ($runner->calls() as $call) {
            self::assertSame('tenant', $call['mode']);
            self::assertSame('tenantD', $call['tenantUuid']);
        }
    }

    public function testReconcileForRunsThroughRunAsTenantWithTheSubjectsTenantUuid(): void
    {
        $runner = new RecordingTenantContextRunner();
        $service = $this->serviceWithRunner($runner);
        $subject = Subject::tenant('tenantE');
        $service->startFor($subject, self::PLATFORM_FREE);

        $before = $runner->callCount();
        $service->reconcileFor($subject);

        self::assertGreaterThan($before, $runner->callCount());
        foreach ($runner->calls() as $call) {
            self::assertSame('tenant', $call['mode']);
            self::assertSame('tenantE', $call['tenantUuid']);
        }
    }

    public function testLegacyTenantFacadeRunsThroughRunAsTenantToo(): void
    {
        $runner = new RecordingTenantContextRunner();
        $service = $this->serviceWithRunner($runner);

        $service->start('tenantF', 'free');

        self::assertNotSame([], $runner->calls());
        foreach ($runner->calls() as $call) {
            self::assertSame('tenant', $call['mode']);
            self::assertSame('tenantF', $call['tenantUuid']);
        }
    }

    // ---------------------------------------------------------------
    // SubscriptionEventProjector: project() runs through runAsSystem()
    // ---------------------------------------------------------------

    public function testProjectorProjectRunsThroughRunAsSystem(): void
    {
        $runner = new RecordingTenantContextRunner();
        $this->bind(\Glueful\Extensions\Contracts\Tenancy\TenantContextRunner::class, $runner);

        $this->seedSubscription([
            'tenant_uuid' => 'tenantG',
            'plan_key' => 'free',
            'provider_gateway' => 'stripe',
            'provider_subscription_id' => 'sub_g1',
        ]);

        $projector = new SubscriptionEventProjector(
            new SubscriptionRepository(),
            new SubscriptionEventRepository(),
            new ProviderEventReceiptRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext(),
            new DefaultSubjectResolver(),
        );

        $projector->project(new ProviderSubscriptionEvent(
            gateway: 'stripe',
            type: 'subscription.updated',
            logicalEventKey: 'evt-1',
            normalized: [
                'gateway_subscription_id' => 'sub_g1',
                'status' => 'past_due',
            ],
        ));

        self::assertNotSame([], $runner->calls());
        foreach ($runner->calls() as $call) {
            self::assertSame('system', $call['mode']);
        }
    }
}
