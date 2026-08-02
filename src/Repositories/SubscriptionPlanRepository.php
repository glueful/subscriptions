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

    /**
     * Scope-aware finder (Task 6): every 1.x finder above gains a scope-aware sibling,
     * added ALONGSIDE the byte-compatible 1.x names (which remain untouched through
     * Task 8; Task 9 switches them to the platform scope ('tenant', '')).
     *
     * @return array<string,mixed>|null
     */
    public function findByUuid(ApplicationContext $context, string $uuid): ?array
    {
        $row = db($context)->table('subscription_plans')
            ->where('uuid', '=', $uuid)
            ->limit(1)
            ->first();

        return $row !== null ? $this->decodeRow($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function findByKeyInScope(
        ApplicationContext $context,
        string $audience,
        string $owner,
        string $key
    ): ?array {
        $row = db($context)->table('subscription_plans')
            ->where('audience', '=', $audience)
            ->where('owner_tenant_uuid', '=', $owner)
            ->where('plan_key', '=', $key)
            ->limit(1)
            ->first();

        return $row !== null ? $this->decodeRow($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function findResolvableByKeyInScope(
        ApplicationContext $context,
        string $audience,
        string $owner,
        string $key
    ): ?array {
        $row = db($context)->table('subscription_plans')
            ->where('audience', '=', $audience)
            ->where('owner_tenant_uuid', '=', $owner)
            ->where('plan_key', '=', $key)
            ->whereIn('status', ['active', 'archived'])
            ->limit(1)
            ->first();

        return $row !== null ? $this->decodeRow($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function listInScope(ApplicationContext $context, string $audience, string $owner): array
    {
        return array_map(
            fn (array $row): array => $this->decodeRow($row),
            db($context)->table('subscription_plans')
                ->where('audience', '=', $audience)
                ->where('owner_tenant_uuid', '=', $owner)
                ->orderBy(['sort_order' => 'ASC', 'plan_key' => 'ASC'])
                ->get()
        );
    }

    public function maxUpdatedAtInScope(ApplicationContext $context, string $audience, string $owner): ?string
    {
        $row = db($context)->table('subscription_plans')
            ->where('audience', '=', $audience)
            ->where('owner_tenant_uuid', '=', $owner)
            ->selectRaw('MAX(updated_at) AS max_updated_at')
            ->first();

        $value = $row['max_updated_at'] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * Scope-aware sibling of updateByKey() (Task 8): since migration 006 drops the
     * global `plan_key` uniqueness in favor of `(audience, owner_tenant_uuid,
     * plan_key)`, an unscoped `WHERE plan_key = ?` update could silently hit a
     * same-keyed row in a different scope. PlanManagementService::*InScope() uses
     * this instead of updateByKey() for every scoped write.
     *
     * @param array<string,mixed> $changes
     */
    public function updateByKeyInScope(
        ApplicationContext $context,
        string $audience,
        string $owner,
        string $key,
        array $changes
    ): void {
        db($context)->table('subscription_plans')
            ->where('audience', '=', $audience)
            ->where('owner_tenant_uuid', '=', $owner)
            ->where('plan_key', '=', $key)
            ->update($this->encodeRow($changes));
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
