<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Repositories;

use Glueful\Bootstrap\ApplicationContext;

final class SubscriptionPlanRepository
{
    /** @return array<string,mixed>|null */
    public function findByKey(ApplicationContext $context, string $planKey): ?array
    {
        $row = db($context)->table('subscription_plans')
            ->where('plan_key', '=', $planKey)
            ->limit(1)
            ->first();

        return $row !== null ? $this->decodeRow($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function findResolvableByKey(ApplicationContext $context, string $planKey): ?array
    {
        $row = db($context)->table('subscription_plans')
            ->where('plan_key', '=', $planKey)
            ->whereIn('status', ['active', 'archived'])
            ->limit(1)
            ->first();

        return $row !== null ? $this->decodeRow($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function list(ApplicationContext $context): array
    {
        return array_map(
            fn (array $row): array => $this->decodeRow($row),
            db($context)->table('subscription_plans')
                ->orderBy(['sort_order' => 'ASC', 'plan_key' => 'ASC'])
                ->get()
        );
    }

    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('subscription_plans')->insert($this->encodeRow($row));
    }

    /** @param array<string,mixed> $changes */
    public function updateByKey(ApplicationContext $context, string $planKey, array $changes): void
    {
        db($context)->table('subscription_plans')
            ->where('plan_key', '=', $planKey)
            ->update($this->encodeRow($changes));
    }

    public function maxUpdatedAt(ApplicationContext $context): ?string
    {
        $row = db($context)->table('subscription_plans')
            ->selectRaw('MAX(updated_at) AS max_updated_at')
            ->first();

        $value = $row['max_updated_at'] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public function exists(ApplicationContext $context, string $planKey): bool
    {
        return $this->findByKey($context, $planKey) !== null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function encodeRow(array $row): array
    {
        if (isset($row['entitlements']) && is_array($row['entitlements'])) {
            $row['entitlements'] = json_encode($row['entitlements'], JSON_THROW_ON_ERROR);
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decodeRow(array $row): array
    {
        if (isset($row['entitlements']) && is_string($row['entitlements'])) {
            $decoded = json_decode($row['entitlements'], true, flags: JSON_THROW_ON_ERROR);
            $row['entitlements'] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }
}
