<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Repositories;

use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

final class SubscriptionEventRepositoryTest extends SubscriptionsTestCase
{
    private SubscriptionEventRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SubscriptionEventRepository();
    }

    /** @param array<string,mixed> $overrides */
    private function event(array $overrides = []): array
    {
        return array_merge([
            'tenant_uuid' => 'tenantA',
            'type' => 'subscription.past_due',
            'source' => 'payvia_event',
            'payvia_gateway' => 'paystack',
            'payvia_logical_event_key' => 'k1',
        ], $overrides);
    }

    private function eventCount(): int
    {
        return count($this->connection()->table('subscription_events')->get());
    }

    public function testInsertOrThrowPropagatesUniqueViolation(): void
    {
        $this->repo->insertOrThrow($this->appContext(), $this->event());

        try {
            $this->repo->insertOrThrow($this->appContext(), $this->event());
            self::fail('Expected the (payvia_gateway, payvia_logical_event_key) unique violation to propagate.');
        } catch (\Throwable $e) {
            // The claim semantics depend on the violation PROPAGATING (the listener
            // wraps insertOrThrow in a transaction so a duplicate rolls back the
            // projection) AND on the repo recognizing it as a unique violation.
            self::assertTrue($this->repo->isUniqueViolation($e));
        }

        self::assertSame(1, $this->eventCount());
    }

    public function testAppendReturnsFalseOnDuplicateAndLeavesOneRow(): void
    {
        self::assertTrue($this->repo->append($this->appContext(), $this->event()));
        self::assertFalse($this->repo->append($this->appContext(), $this->event()));
        self::assertSame(1, $this->eventCount());
    }

    public function testSameLogicalKeyUnderDifferentGatewaySucceeds(): void
    {
        self::assertTrue($this->repo->append($this->appContext(), $this->event(['payvia_gateway' => 'paystack'])));
        self::assertTrue($this->repo->append($this->appContext(), $this->event(['payvia_gateway' => 'stripe'])));
        self::assertSame(2, $this->eventCount());
    }

    public function testNullLogicalKeyAppendsBothSucceed(): void
    {
        $manual = $this->event([
            'type' => 'plan_changed',
            'source' => 'manual',
            'payvia_gateway' => null,
            'payvia_logical_event_key' => null,
        ]);

        self::assertTrue($this->repo->append($this->appContext(), $manual));
        self::assertTrue($this->repo->append($this->appContext(), $manual));
        self::assertSame(2, $this->eventCount());
    }

    public function testExistsByLogicalKeyIsGatewayScoped(): void
    {
        $this->repo->append($this->appContext(), $this->event());

        self::assertTrue($this->repo->existsByLogicalKey($this->appContext(), 'paystack', 'k1'));
        self::assertFalse($this->repo->existsByLogicalKey($this->appContext(), 'stripe', 'k1'));
        self::assertFalse($this->repo->existsByLogicalKey($this->appContext(), 'paystack', 'k2'));
    }

    public function testIsUniqueViolationIsPublicAndDiscriminates(): void
    {
        // Public API (the listener branches on it around the claim transaction).
        $method = new \ReflectionMethod(SubscriptionEventRepository::class, 'isUniqueViolation');
        self::assertTrue($method->isPublic());

        self::assertTrue($this->repo->isUniqueViolation(
            new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed')
        ));
        self::assertTrue($this->repo->isUniqueViolation(
            new \RuntimeException('wrapped', 0, new \PDOException('duplicate key value violates unique constraint'))
        ));
        self::assertFalse($this->repo->isUniqueViolation(new \RuntimeException('connection refused')));
    }

    public function testAppendJsonEncodesDataAndFillsUuid(): void
    {
        $this->repo->append($this->appContext(), $this->event(['data' => ['from_plan' => 'free', 'to_plan' => 'pro']]));

        $row = $this->connection()->table('subscription_events')->first();
        self::assertIsArray($row);
        self::assertNotEmpty($row['uuid']);
        self::assertSame(
            ['from_plan' => 'free', 'to_plan' => 'pro'],
            json_decode((string) $row['data'], true)
        );
    }
}
