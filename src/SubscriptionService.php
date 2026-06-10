<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Helpers\Utils;

/**
 * Tenant subscription lifecycle -- works fully with NO payvia installed
 * (free/trial/comp subscriptions never touch a payment object).
 *
 * Holds the ApplicationContext from construction, so methods take no $ctx.
 */
final class SubscriptionService
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionEventRepository $events,
        private readonly PlanCatalog $catalog,
        private readonly ApplicationContext $context,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function current(string $tenantUuid): ?array
    {
        return $this->subscriptions->findByTenant($this->context, $tenantUuid);
    }

    /**
     * @param array<string,mixed> $opts status, trial_ends_at, current_period_end, payvia_* keys, metadata
     * @return array<string,mixed>
     */
    public function start(string $tenantUuid, string $planKey, array $opts = []): array
    {
        $status = (string) ($opts['status'] ?? 'active');

        $row = [
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenantUuid,
            'plan_key' => $planKey,
            'status' => $status,
            'trial_ends_at' => $opts['trial_ends_at'] ?? null,
            'current_period_end' => $opts['current_period_end'] ?? null,
            'payvia_gateway' => $opts['payvia_gateway'] ?? null,
            'payvia_customer_id' => $opts['payvia_customer_id'] ?? null,
            'payvia_subscription_id' => $opts['payvia_subscription_id'] ?? null,
            'payvia_priced_plan_uuid' => $opts['payvia_priced_plan_uuid']
                ?? $this->catalog->pricedPlanUuid($planKey),
            'metadata' => $opts['metadata'] ?? null,
        ];

        $this->subscriptions->insert($this->context, $row);

        $this->events->append($this->context, [
            'tenant_uuid' => $tenantUuid,
            'type' => 'created',
            'from_status' => null,
            'to_status' => $status,
            'source' => 'manual',
            'data' => ['plan_key' => $planKey],
        ]);

        return $this->requireCurrent($tenantUuid);
    }

    /** @return array<string,mixed> */
    public function changePlan(string $tenantUuid, string $planKey): array
    {
        $current = $this->requireCurrent($tenantUuid);
        $fromPlan = (string) ($current['plan_key'] ?? '');

        $this->subscriptions->updateByTenant($this->context, $tenantUuid, [
            'plan_key' => $planKey,
            'payvia_priced_plan_uuid' => $this->catalog->pricedPlanUuid($planKey),
        ]);

        $this->events->append($this->context, [
            'tenant_uuid' => $tenantUuid,
            'type' => 'plan_changed',
            'from_status' => $current['status'] ?? null,
            'to_status' => $current['status'] ?? null,
            'source' => 'manual',
            'data' => ['from_plan' => $fromPlan, 'to_plan' => $planKey],
        ]);

        return $this->requireCurrent($tenantUuid);
    }

    /** @return array<string,mixed> */
    public function cancel(string $tenantUuid, bool $atPeriodEnd = true): array
    {
        $current = $this->requireCurrent($tenantUuid);
        $fromStatus = (string) ($current['status'] ?? '');

        if ($atPeriodEnd) {
            // Keep the status until the period ends -- just flag the intent.
            $metadata = $this->decodeMetadata($current['metadata'] ?? null);
            $metadata['cancel_at_period_end'] = true;

            $this->subscriptions->updateByTenant($this->context, $tenantUuid, [
                'metadata' => $metadata,
            ]);
            $toStatus = $fromStatus;
        } else {
            $this->subscriptions->updateByTenant($this->context, $tenantUuid, [
                'status' => 'canceled',
                'canceled_at' => $this->now(),
            ]);
            $toStatus = 'canceled';
        }

        $this->events->append($this->context, [
            'tenant_uuid' => $tenantUuid,
            'type' => 'canceled',
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'source' => 'manual',
            'data' => ['at_period_end' => $atPeriodEnd],
        ]);

        return $this->requireCurrent($tenantUuid);
    }

    /**
     * Pull authoritative provider state and re-derive local status (S8).
     * STUB until Task 6.4 -- returns the current row unchanged.
     *
     * @return array<string,mixed>|null
     */
    public function reconcile(string $tenantUuid): ?array
    {
        return $this->current($tenantUuid);
    }

    /** @return array<string,mixed> */
    private function requireCurrent(string $tenantUuid): array
    {
        $row = $this->current($tenantUuid);
        if ($row === null) {
            throw new \RuntimeException("No subscription for tenant '{$tenantUuid}'.");
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }
        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function now(): string
    {
        return db($this->context)->getDriver()->formatDateTime();
    }
}
