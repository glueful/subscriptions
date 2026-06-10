<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Repositories;

use Glueful\Bootstrap\ApplicationContext;

final class SubscriptionRepository
{
    public function findByTenant(ApplicationContext $context, string $tenantUuid): ?array
    {
        return db($context)->table('subscriptions')
            ->where('tenant_uuid', '=', $tenantUuid)
            ->limit(1)
            ->first();
    }

    public function findByPayviaSubscription(
        ApplicationContext $context,
        string $gateway,
        string $payviaSubscriptionId,
    ): ?array {
        return db($context)->table('subscriptions')
            ->where('payvia_gateway', '=', $gateway)
            ->where('payvia_subscription_id', '=', $payviaSubscriptionId)
            ->limit(1)
            ->first();
    }

    /** @param array<string,mixed> $data */
    public function insert(ApplicationContext $context, array $data): void
    {
        db($context)->table('subscriptions')->insert($this->normalizeJson($data));
    }

    /** @param array<string,mixed> $changes */
    public function updateByTenant(ApplicationContext $context, string $tenantUuid, array $changes): void
    {
        $changes['updated_at'] = $this->now($context);

        db($context)->table('subscriptions')
            ->where('tenant_uuid', '=', $tenantUuid)
            ->update($this->normalizeJson($changes));
    }

    /** @return list<array<string,mixed>> */
    public function allWithPayvia(ApplicationContext $context): array
    {
        return db($context)->table('subscriptions')
            ->whereRaw('payvia_subscription_id IS NOT NULL')
            ->orderBy(['created_at' => 'ASC'])
            ->get();
    }

    /** @param array<string,mixed> $row */
    public function latestUpdatedAt(array $row): ?string
    {
        $value = $row['updated_at'] ?? $row['created_at'] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** @param array<string,mixed> $row */
    private function normalizeJson(array $row): array
    {
        foreach (['metadata'] as $column) {
            if (isset($row[$column]) && is_array($row[$column])) {
                $row[$column] = json_encode($row[$column], JSON_THROW_ON_ERROR);
            }
        }

        return $row;
    }

    private function now(ApplicationContext $context): string
    {
        return db($context)->getDriver()->formatDateTime();
    }
}
