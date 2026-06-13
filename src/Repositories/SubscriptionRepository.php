<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Repositories;

use Glueful\Bootstrap\ApplicationContext;

final class SubscriptionRepository
{
    /** @return array<string,mixed>|null */
    public function findByTenant(ApplicationContext $context, string $tenantUuid): ?array
    {
        return db($context)->table('subscriptions')
            ->where('tenant_uuid', '=', $tenantUuid)
            ->limit(1)
            ->first();
    }

    /** @return array<string,mixed>|null */
    public function findByProviderSubscription(
        ApplicationContext $context,
        string $gateway,
        string $providerSubscriptionId,
    ): ?array {
        return db($context)->table('subscriptions')
            ->where('provider_gateway', '=', $gateway)
            ->where('provider_subscription_id', '=', $providerSubscriptionId)
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
    public function allWithProvider(ApplicationContext $context): array
    {
        return db($context)->table('subscriptions')
            ->whereRaw('provider_subscription_id IS NOT NULL')
            ->orderBy(['created_at' => 'ASC'])
            ->get();
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
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
