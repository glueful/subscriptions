<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Plans;

use Glueful\Extensions\Subscriptions\Plans\PlanPurchasability;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

/**
 * Task 13 (design spec §4.2): per-gateway checkout-purchasability projection.
 *
 * `PlanPurchasability::forGateway()` is the ONE declared authority for
 * checkout purchasability -- the closed `provider_identifiers` map, never the
 * legacy scalar `provider_price_id`. This suite exercises the full audience /
 * status / identifier-presence matrix plus the mutation test the design spec
 * calls out explicitly: a plan carrying only the scalar must NOT be
 * purchasable.
 */
final class PlanPurchasabilityTest extends SubscriptionsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // This suite builds its own fixture plans; the harness's seeded
        // 'free'/'pro' rows (no provider_identifiers) would otherwise pollute
        // every assertion below.
        $this->clearPlatformPlans();
    }

    public function testReturnsOnlyActiveTenantPlansWithAnIdentifierForTheRequestedGateway(): void
    {
        $this->seedPlan('pro', [
            'status' => 'active',
            'provider_identifiers' => ['stripe' => 'price_pro_stripe', 'paystack' => 'PLN_pro'],
        ]);
        $this->seedPlan('team', [
            'status' => 'active',
            'provider_identifiers' => ['paystack' => 'PLN_team'], // no stripe identifier
        ]);

        $result = PlanPurchasability::forGateway($this->appContext(), 'stripe');

        self::assertSame(['pro'], array_column($result, 'plan_key'));
        self::assertSame([
            'plan_uuid' => $this->uuidFor('pro'),
            'plan_key' => 'pro',
            'name' => 'Pro',
            'provider_identifier' => 'price_pro_stripe',
        ], $result[0]);
    }

    public function testExcludesDraftPlansEvenWithAnIdentifierConfigured(): void
    {
        $this->seedPlan('pro', [
            'status' => 'draft',
            'provider_identifiers' => ['stripe' => 'price_pro_stripe'],
        ]);

        self::assertSame([], PlanPurchasability::forGateway($this->appContext(), 'stripe'));
    }

    public function testExcludesArchivedPlansEvenWithAnIdentifierConfigured(): void
    {
        $this->seedPlan('pro', [
            'status' => 'archived',
            'provider_identifiers' => ['stripe' => 'price_pro_stripe'],
        ]);

        self::assertSame([], PlanPurchasability::forGateway($this->appContext(), 'stripe'));
    }

    public function testExcludesWorkspaceMembershipPlansEvenWhenActiveWithAnIdentifier(): void
    {
        $this->seedPlan('member', [
            'status' => 'active',
            'audience' => 'user',
            'owner_tenant_uuid' => 'tenantA',
            'provider_identifiers' => ['stripe' => 'price_member_stripe'],
        ]);

        self::assertSame([], PlanPurchasability::forGateway($this->appContext(), 'stripe'));
    }

    public function testExcludesPlansWithNoProviderIdentifiersAtAll(): void
    {
        $this->seedPlan('free', ['status' => 'active', 'provider_identifiers' => null]);

        self::assertSame([], PlanPurchasability::forGateway($this->appContext(), 'stripe'));
    }

    public function testExcludesPlansWhoseIdentifierForTheGatewayIsAnEmptyString(): void
    {
        $this->seedPlan('pro', [
            'status' => 'active',
            'provider_identifiers' => ['stripe' => ''],
        ]);

        self::assertSame([], PlanPurchasability::forGateway($this->appContext(), 'stripe'));
    }

    /**
     * The mutation test the design spec calls out explicitly (§4.2): a plan
     * carrying ONLY the legacy scalar `provider_price_id` -- no
     * `provider_identifiers` entry at all -- must NOT be purchasable. The
     * scalar is compatibility-only (webhook correlation for pre-existing
     * provider-managed rows) and is never read by this projection.
     */
    public function testScalarProviderPriceIdAloneNeverMakesAPlanPurchasable(): void
    {
        $this->seedPlan('legacy', [
            'status' => 'active',
            'provider_price_id' => 'price_legacy_scalar_only',
            'provider_identifiers' => null,
        ]);

        self::assertSame([], PlanPurchasability::forGateway($this->appContext(), 'stripe'));
    }

    public function testUnknownGatewayReturnsAnEmptyList(): void
    {
        $this->seedPlan('pro', [
            'status' => 'active',
            'provider_identifiers' => ['stripe' => 'price_pro_stripe'],
        ]);

        self::assertSame([], PlanPurchasability::forGateway($this->appContext(), 'braintree'));
    }

    public function testEmptyGatewayStringReturnsAnEmptyListWithoutQuerying(): void
    {
        $this->seedPlan('pro', [
            'status' => 'active',
            'provider_identifiers' => ['stripe' => 'price_pro_stripe'],
        ]);

        self::assertSame([], PlanPurchasability::forGateway($this->appContext(), ''));
    }

    public function testMultiplePurchasablePlansAreOrderedBySortOrderThenPlanKey(): void
    {
        $this->seedPlan('team', [
            'status' => 'active',
            'sort_order' => 20,
            'provider_identifiers' => ['stripe' => 'price_team'],
        ]);
        $this->seedPlan('pro', [
            'status' => 'active',
            'sort_order' => 10,
            'provider_identifiers' => ['stripe' => 'price_pro'],
        ]);

        $result = PlanPurchasability::forGateway($this->appContext(), 'stripe');

        self::assertSame(['pro', 'team'], array_column($result, 'plan_key'));
    }

    /** @param array<string,mixed> $overrides */
    private function seedPlan(string $planKey, array $overrides = []): void
    {
        $identifiers = array_key_exists('provider_identifiers', $overrides)
            ? $overrides['provider_identifiers']
            : null;
        unset($overrides['provider_identifiers']);

        $this->connection()->table('subscription_plans')->insert(array_merge([
            'uuid' => $this->uuidFor($planKey),
            'plan_key' => $planKey,
            'display_name' => ucfirst($planKey),
            'description' => null,
            'entitlements' => json_encode([], JSON_THROW_ON_ERROR),
            'provider_price_id' => null,
            'status' => 'active',
            'sort_order' => 0,
            'audience' => 'tenant',
            'owner_tenant_uuid' => '',
            'provider_identifiers' => $identifiers !== null
                ? json_encode($identifiers, JSON_THROW_ON_ERROR)
                : null,
        ], $overrides));
    }

    /** A deterministic, unique-per-key 12-char uuid (subscription_plans.uuid is VARCHAR(12)). */
    private function uuidFor(string $planKey): string
    {
        return substr(str_pad($planKey, 12, '0'), 0, 12);
    }
}
