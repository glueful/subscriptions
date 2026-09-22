<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Plans;

use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Glueful\Extensions\Subscriptions\Plans\PlanPayloadValidator;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

/**
 * A plan carried no price, so a pricing page or plan picker could name a plan but not say what it
 * costs. A plan may now carry a display price: an amount in minor units, a currency and a billing
 * interval. It is for display only; the payment gateway decides what is charged.
 */
final class PlanDisplayPriceTest extends SubscriptionsTestCase
{
    private PlanManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearPlatformPlans();
        $this->service = new PlanManagementService(
            $this->appContext(),
            new SubscriptionPlanRepository(),
            new PlanPayloadValidator(),
        );
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function payload(array $extra = []): array
    {
        return ['plan_key' => 'pro', 'display_name' => 'Pro', 'entitlements' => [], 'status' => 'active'] + $extra;
    }

    public function testAPlanCarriesAPriceCurrencyAndInterval(): void
    {
        $row = $this->service->create($this->payload([
            'price_amount' => 1900, 'price_currency' => 'usd', 'billing_interval' => 'month',
        ]));

        self::assertSame(1900, (int) $row['price_amount']);
        self::assertSame('USD', $row['price_currency']);
        self::assertSame('month', $row['billing_interval']);
    }

    public function testAPlanWithoutAPriceStaysValid(): void
    {
        $row = $this->service->create($this->payload());

        self::assertNull($row['price_amount']);
        self::assertNull($row['price_currency']);
    }

    public function testAPriceIsChangedOrClearedByPatch(): void
    {
        $this->service->create($this->payload([
            'price_amount' => 1900, 'price_currency' => 'USD', 'billing_interval' => 'month',
        ]));

        $yearly = $this->service->update('pro', ['price_amount' => 19000, 'billing_interval' => 'year']);
        self::assertSame(19000, (int) $yearly['price_amount']);
        self::assertSame('year', $yearly['billing_interval']);
        self::assertSame('USD', $yearly['price_currency']);

        $cleared = $this->service->update('pro', [
            'price_amount' => null, 'price_currency' => null, 'billing_interval' => null,
        ]);
        self::assertNull($cleared['price_amount']);
    }

    /** @return iterable<string, array{array<string,mixed>, string}> */
    public static function invalidPrices(): iterable
    {
        yield 'negative amount' => [['price_amount' => -1, 'price_currency' => 'USD', 'billing_interval' => 'month'], 'price_amount'];
        yield 'amount without currency' => [['price_amount' => 100, 'billing_interval' => 'month'], 'price_currency'];
        yield 'currency without amount' => [['price_currency' => 'USD', 'billing_interval' => 'month'], 'price_amount'];
        yield 'bad currency' => [['price_amount' => 100, 'price_currency' => 'DOLLARS', 'billing_interval' => 'month'], 'price_currency'];
        yield 'bad interval' => [['price_amount' => 100, 'price_currency' => 'USD', 'billing_interval' => 'fortnight'], 'billing_interval'];
        yield 'price without interval' => [['price_amount' => 100, 'price_currency' => 'USD'], 'billing_interval'];
    }

    /**
     * @param array<string,mixed> $price
     * @dataProvider invalidPrices
     */
    public function testAnIncompleteOrMalformedPriceIsRefused(array $price, string $field): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/{$field}/");

        $this->service->create($this->payload($price));
    }
}
