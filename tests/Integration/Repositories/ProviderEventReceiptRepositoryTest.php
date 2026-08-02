<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Repositories;

use Glueful\Extensions\Subscriptions\Repositories\ProviderEventReceiptRepository;
use Glueful\Extensions\Subscriptions\Tests\Support\V2SubscriptionsTestCase;

/**
 * Task 6: the provider-event receipt repository (spec §2/§8) backing the
 * claim/audit table created by migration 006. insertPending() claims the
 * (provider_gateway, provider_logical_event_key) slot before resolution
 * completes; markAccepted()/markRejected() settle it afterward.
 */
final class ProviderEventReceiptRepositoryTest extends V2SubscriptionsTestCase
{
    private ProviderEventReceiptRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ProviderEventReceiptRepository();
    }

    /** @param array<string,mixed> $overrides */
    private function pendingRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'rcpt00000001',
            'provider_gateway' => 'stripe',
            'provider_logical_event_key' => 'subscription.created:sub_1:v1',
            'event_type' => 'subscription.created',
            'candidate_tenant_uuid' => 'tenantA',
            'candidate_subject_type' => 'tenant',
            'candidate_subject_uuid' => 'tenantA',
            'candidate_plan_uuid' => 'planv2pro001',
        ], $overrides);
    }

    private function fetch(string $uuid): ?array
    {
        return $this->connection()->table('subscription_provider_event_receipts')
            ->where('uuid', '=', $uuid)
            ->first();
    }

    public function testInsertPendingWritesTheCandidateIdentityWithPendingOutcome(): void
    {
        $this->repo->insertPending($this->appContext(), $this->pendingRow());

        $row = $this->fetch('rcpt00000001');
        self::assertIsArray($row);
        self::assertSame('pending', $row['outcome']);
        self::assertSame('stripe', $row['provider_gateway']);
        self::assertSame('subscription.created:sub_1:v1', $row['provider_logical_event_key']);
        self::assertSame('tenantA', $row['candidate_tenant_uuid']);
        self::assertSame('tenant', $row['candidate_subject_type']);
        self::assertSame('tenantA', $row['candidate_subject_uuid']);
        self::assertSame('planv2pro001', $row['candidate_plan_uuid']);
        self::assertNull($row['tenant_uuid']);
        self::assertNull($row['rejection_code']);
    }

    public function testInsertPendingThrowsOnAClaimUniqueViolation(): void
    {
        $this->repo->insertPending($this->appContext(), $this->pendingRow(['uuid' => 'rcpt00000001']));

        $this->expectException(\Throwable::class);
        try {
            $this->repo->insertPending($this->appContext(), $this->pendingRow(['uuid' => 'rcpt00000002']));
        } catch (\Throwable $e) {
            self::assertTrue($this->repo->isUniqueViolation($e));
            throw $e;
        }
    }

    public function testDifferentGatewaysMayShareTheSameLogicalKey(): void
    {
        $this->repo->insertPending($this->appContext(), $this->pendingRow([
            'uuid' => 'rcpt00000001',
            'provider_gateway' => 'stripe',
        ]));
        $this->repo->insertPending($this->appContext(), $this->pendingRow([
            'uuid' => 'rcpt00000002',
            'provider_gateway' => 'paystack',
        ]));

        self::assertCount(2, $this->connection()->table('subscription_provider_event_receipts')->get());
    }

    public function testMarkAcceptedSettlesTheResolvedIdentityAndOutcome(): void
    {
        $this->repo->insertPending($this->appContext(), $this->pendingRow());

        $this->repo->markAccepted($this->appContext(), 'rcpt00000001', [
            'tenant_uuid' => 'tenantA',
            'subject_type' => 'tenant',
            'subject_uuid' => 'tenantA',
            'plan_uuid' => 'planv2pro001',
        ]);

        $row = $this->fetch('rcpt00000001');
        self::assertSame('accepted', $row['outcome']);
        self::assertSame('tenantA', $row['tenant_uuid']);
        self::assertSame('tenant', $row['subject_type']);
        self::assertSame('tenantA', $row['subject_uuid']);
        self::assertSame('planv2pro001', $row['plan_uuid']);
        self::assertNull($row['rejection_code']);
    }

    public function testMarkRejectedSettlesTheRejectionCodeAndLeavesResolvedIdentityNull(): void
    {
        $this->repo->insertPending($this->appContext(), $this->pendingRow());

        $this->repo->markRejected($this->appContext(), 'rcpt00000001', 'mismatched_subject');

        $row = $this->fetch('rcpt00000001');
        self::assertSame('rejected', $row['outcome']);
        self::assertSame('mismatched_subject', $row['rejection_code']);
        self::assertNull($row['tenant_uuid']);
    }

    public function testExistsByLogicalKeyIsGatewayScoped(): void
    {
        $this->repo->insertPending($this->appContext(), $this->pendingRow());

        self::assertTrue($this->repo->existsByLogicalKey(
            $this->appContext(),
            'stripe',
            'subscription.created:sub_1:v1'
        ));
        self::assertFalse($this->repo->existsByLogicalKey(
            $this->appContext(),
            'paystack',
            'subscription.created:sub_1:v1'
        ));
        self::assertFalse($this->repo->existsByLogicalKey($this->appContext(), 'stripe', 'no-such-key'));
    }

    public function testIsUniqueViolationDelegatesToTheSharedHelper(): void
    {
        self::assertTrue($this->repo->isUniqueViolation(
            new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed')
        ));
        self::assertFalse($this->repo->isUniqueViolation(new \RuntimeException('connection refused')));
    }
}
