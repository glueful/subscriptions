<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration;

use Glueful\Entitlements\Contracts\EntitlementCheckerInterface;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\DefaultEntitlementChecker;
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\EffectivePlanResolver;
use Glueful\Extensions\Subscriptions\Resolution\EntitlementResolver;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

final class DefaultEntitlementCheckerTest extends SubscriptionsTestCase
{
    private DefaultEntitlementChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();

        $catalog = new PlanCatalog([
            'default_plan' => 'free',
            'plans' => [
                'free' => [
                    'entitlements' => ['reports.export' => false, 'projects.limit' => 3],
                ],
                'pro' => [
                    'entitlements' => [
                        'reports.export' => true,
                        'projects.limit' => 50,
                        'api.monthly' => 100000,
                        'support.priority' => true,
                        'storage.gb' => null, // explicit unlimited -- distinct from absent
                    ],
                ],
                'zero' => [
                    'entitlements' => ['projects.limit' => 0],
                ],
                'negative' => [
                    'entitlements' => ['projects.limit' => -5],
                ],
            ],
            'grace_days' => 3,
        ]);

        $resolver = new EntitlementResolver(
            $catalog,
            new SubscriptionRepository(),
            new OverrideRepository(),
            new EffectivePlanResolver(),
            null,
            false,
            300
        );

        $this->checker = new DefaultEntitlementChecker($resolver, $this->appContext());

        $this->seedSubscription(['tenant_uuid' => 'freeT', 'plan_key' => 'free', 'status' => 'active']);
        $this->seedSubscription(['tenant_uuid' => 'proT', 'plan_key' => 'pro', 'status' => 'active']);
        $this->seedSubscription(['tenant_uuid' => 'zeroT', 'plan_key' => 'zero', 'status' => 'active']);
        $this->seedSubscription(['tenant_uuid' => 'negativeT', 'plan_key' => 'negative', 'status' => 'active']);
        $this->seedSubscription(['tenant_uuid' => 'lapsedT', 'plan_key' => 'pro', 'status' => 'canceled']);
    }

    public function testImplementsTheCoreContract(): void
    {
        self::assertInstanceOf(EntitlementCheckerInterface::class, $this->checker);
    }

    public function testAbsentKeyDeniesTheTypoCase(): void
    {
        // 'reports.exprot' is a typo -- absent must NOT read as unlimited (S3,
        // array_key_exists not isset).
        self::assertFalse($this->checker->allows('proT', 'reports.exprot'));
        self::assertSame(0, $this->checker->limit('proT', 'reports.exprot'));
    }

    public function testFalseValueDenies(): void
    {
        self::assertFalse($this->checker->allows('freeT', 'reports.export'));
        self::assertSame(0, $this->checker->limit('freeT', 'reports.export'));
    }

    public function testTrueValueAllowsUnlimited(): void
    {
        self::assertTrue($this->checker->allows('proT', 'reports.export'));
        self::assertNull($this->checker->limit('proT', 'reports.export'));
    }

    public function testExplicitNullValueAllowsUnlimited(): void
    {
        // Explicit null is configured unlimited -- distinct from an absent key.
        self::assertTrue($this->checker->allows('proT', 'storage.gb'));
        self::assertNull($this->checker->limit('proT', 'storage.gb'));
    }

    public function testPositiveIntAllowsWithLimit(): void
    {
        self::assertTrue($this->checker->allows('proT', 'projects.limit'));
        self::assertSame(50, $this->checker->limit('proT', 'projects.limit'));
    }

    public function testZeroIntDenies(): void
    {
        self::assertFalse($this->checker->allows('zeroT', 'projects.limit'));
        self::assertSame(0, $this->checker->limit('zeroT', 'projects.limit'));
    }

    public function testNegativeIntDeniesWithZeroLimit(): void
    {
        // S3 consistency: allows(-5) is false, so limit() must read 0 (deny),
        // never a raw negative number.
        self::assertFalse($this->checker->allows('negativeT', 'projects.limit'));
        self::assertSame(0, $this->checker->limit('negativeT', 'projects.limit'));
    }

    public function testLapsedProTenantIsDowngradedToFree(): void
    {
        // canceled pro -> default (free) plan, where reports.export is false.
        self::assertFalse($this->checker->allows('lapsedT', 'reports.export'));
        self::assertSame(3, $this->checker->limit('lapsedT', 'projects.limit'));
    }

    /**
     * Override values are JSON-decoded and unvalidated, so a malformed value can
     * reach the checker as an unrecognized type. These must fail closed: the JSON
     * string "false" must not (bool)-coerce to a grant, and an array/object must
     * not read as unlimited.
     *
     * @return array<string,array{mixed}>
     */
    public static function unrecognizedTypeProvider(): array
    {
        return [
            'string false' => ['false'],
            'string true' => ['true'],
            'string word' => ['nope'],
            'array' => [[1, 2, 3]],
            'object' => [['nested' => 'value']],
        ];
    }

    /** @dataProvider unrecognizedTypeProvider */
    public function testUnrecognizedTypeDeniesAndZeroesLimit(mixed $value): void
    {
        $this->seedOverride('proT', 'projects.limit', $value);

        self::assertFalse($this->checker->allows('proT', 'projects.limit'));
        self::assertSame(0, $this->checker->limit('proT', 'projects.limit'));
    }

    private function seedOverride(string $tenantUuid, string $entitlement, mixed $value): void
    {
        $this->connection()->table('subscription_overrides')->insert([
            'uuid' => \Glueful\Helpers\Utils::generateNanoID(12),
            'tenant_uuid' => $tenantUuid,
            'entitlement' => $entitlement,
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'expires_at' => null,
        ]);
    }
}
