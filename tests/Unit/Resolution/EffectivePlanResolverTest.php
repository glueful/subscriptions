<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Unit\Resolution;

use Glueful\Extensions\Subscriptions\Resolution\EffectivePlanResolver;
use PHPUnit\Framework\TestCase;

final class EffectivePlanResolverTest extends TestCase
{
    private EffectivePlanResolver $resolver;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new EffectivePlanResolver();
        $this->now = new \DateTimeImmutable('2026-06-10 12:00:00');
    }

    /** @param array<string,mixed> $overrides */
    private function sub(array $overrides = []): array
    {
        return array_merge([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'active',
            'grace_ends_at' => null,
        ], $overrides);
    }

    public function testNoSubscriptionResolvesDefaultPlan(): void
    {
        self::assertSame('free', $this->resolver->resolve(null, 'free', $this->now));
    }

    public function testActiveResolvesPlanKey(): void
    {
        self::assertSame('pro', $this->resolver->resolve($this->sub(), 'free', $this->now));
    }

    public function testTrialingResolvesPlanKey(): void
    {
        // S11 plan-as-trialed: trial entitlements are the trialed plan's entitlements.
        self::assertSame('pro', $this->resolver->resolve($this->sub(['status' => 'trialing']), 'free', $this->now));
    }

    public function testPastDueWithFutureGraceKeepsPlan(): void
    {
        $sub = $this->sub(['status' => 'past_due', 'grace_ends_at' => '2026-06-11 12:00:00']);

        self::assertSame('pro', $this->resolver->resolve($sub, 'free', $this->now));
    }

    public function testPastDueWithPassedGraceDowngrades(): void
    {
        $sub = $this->sub(['status' => 'past_due', 'grace_ends_at' => '2026-06-09 12:00:00']);

        self::assertSame('free', $this->resolver->resolve($sub, 'free', $this->now));
    }

    public function testPastDueWithNullGraceDowngrades(): void
    {
        $sub = $this->sub(['status' => 'past_due', 'grace_ends_at' => null]);

        self::assertSame('free', $this->resolver->resolve($sub, 'free', $this->now));
    }

    public function testIncompleteResolvesDefaultPlan(): void
    {
        // Created but never activated -- no paid access.
        self::assertSame('free', $this->resolver->resolve($this->sub(['status' => 'incomplete']), 'free', $this->now));
    }

    public function testCanceledResolvesDefaultPlan(): void
    {
        self::assertSame('free', $this->resolver->resolve($this->sub(['status' => 'canceled']), 'free', $this->now));
    }

    public function testUnknownStatusResolvesDefaultPlan(): void
    {
        self::assertSame('free', $this->resolver->resolve($this->sub(['status' => 'bogus']), 'free', $this->now));
    }

    // ===========================================
    // Task 11 (design spec §3.7/§4.3) -- non_renewing entitles only until its
    // boundary. Every case below asserts the EFFECTIVE plan immediately BEFORE
    // and AFTER `current_period_end`, not merely that a column was stored.
    // ===========================================

    public function testNonRenewingImmediatelyBeforeBoundaryKeepsPlan(): void
    {
        $sub = $this->sub(['status' => 'non_renewing', 'current_period_end' => '2026-06-10 12:00:01']);

        self::assertSame('pro', $this->resolver->resolve($sub, 'free', $this->now));
    }

    public function testNonRenewingImmediatelyAfterBoundaryDowngrades(): void
    {
        $sub = $this->sub(['status' => 'non_renewing', 'current_period_end' => '2026-06-10 11:59:59']);

        self::assertSame('free', $this->resolver->resolve($sub, 'free', $this->now));
    }

    public function testNonRenewingExactlyAtBoundaryDowngrades(): void
    {
        // The boundary itself is exclusive -- "> now", not ">=" -- so the exact
        // instant of current_period_end no longer entitles.
        $sub = $this->sub(['status' => 'non_renewing', 'current_period_end' => '2026-06-10 12:00:00']);

        self::assertSame('free', $this->resolver->resolve($sub, 'free', $this->now));
    }

    public function testNonRenewingWithNullPeriodEndDowngrades(): void
    {
        $sub = $this->sub(['status' => 'non_renewing', 'current_period_end' => null]);

        self::assertSame('free', $this->resolver->resolve($sub, 'free', $this->now));
    }

    public function testNonRenewingWithInvalidPeriodEndDowngrades(): void
    {
        $sub = $this->sub(['status' => 'non_renewing', 'current_period_end' => 'not-a-date']);

        self::assertSame('free', $this->resolver->resolve($sub, 'free', $this->now));
    }
}
