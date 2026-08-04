<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Repositories;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Since the 2.0 activation, every unqualified 1.x method name below means the
 * PLATFORM scope ('tenant', ''). Migration 006 replaced the global
 * `UNIQUE(plan_key)` with `UNIQUE(audience, owner_tenant_uuid, plan_key)`, so an
 * unscoped `WHERE plan_key = ?` is no longer single-row: it could read -- or
 * silently UPDATE -- a same-keyed workspace-owned plan. The only remaining
 * unscoped access is the `*Unscoped()` pair reserved for the pre-006 upgrade
 * bridge, where the scope columns do not exist yet.
 */
final class SubscriptionPlanRepository
{
    private const PLATFORM_AUDIENCE = 'tenant';
    private const PLATFORM_OWNER = '';

    /** @return array<string,mixed>|null */
    public function findByKey(ApplicationContext $context, string $planKey): ?array
    {
        return $this->findByKeyInScope($context, self::PLATFORM_AUDIENCE, self::PLATFORM_OWNER, $planKey);
    }

    /** @return array<string,mixed>|null */
    public function findResolvableByKey(ApplicationContext $context, string $planKey): ?array
    {
        return $this->findResolvableByKeyInScope(
            $context,
            self::PLATFORM_AUDIENCE,
            self::PLATFORM_OWNER,
            $planKey
        );
    }

    /** @return list<array<string,mixed>> */
    public function list(ApplicationContext $context): array
    {
        return $this->listInScope($context, self::PLATFORM_AUDIENCE, self::PLATFORM_OWNER);
    }

    /**
     * A plan uuid already identifies a specific row, so this is inherently
     * unambiguous and takes no scope.
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
     * The single scoped update path -- see the class docblock for why an unscoped
     * `WHERE plan_key = ?` update is unsafe post-006.
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
        $this->updateByKeyInScope(
            $context,
            self::PLATFORM_AUDIENCE,
            self::PLATFORM_OWNER,
            $planKey,
            $changes
        );
    }

    public function maxUpdatedAt(ApplicationContext $context): ?string
    {
        return $this->maxUpdatedAtInScope($context, self::PLATFORM_AUDIENCE, self::PLATFORM_OWNER);
    }

    public function exists(ApplicationContext $context, string $planKey): bool
    {
        return $this->findByKey($context, $planKey) !== null;
    }

    /**
     * PRE-006 UPGRADE BRIDGE ONLY (`subscriptions:prepare-v2`, spec §3).
     *
     * The bridge runs against a 1.x database that does not yet HAVE the
     * `audience`/`owner_tenant_uuid` columns, so it cannot use any of the scoped
     * reads above -- they would fail with "no such column". On that schema
     * `plan_key` is still globally unique, so an unscoped lookup is exactly right;
     * post-006 nothing may call these.
     *
     * @return array<string,mixed>|null
     */
    public function findByKeyUnscoped(ApplicationContext $context, string $planKey): ?array
    {
        $row = db($context)->table('subscription_plans')
            ->where('plan_key', '=', $planKey)
            ->limit(1)
            ->first();

        return $row !== null ? $this->decodeRow($row) : null;
    }

    /** PRE-006 UPGRADE BRIDGE ONLY -- see findByKeyUnscoped(). */
    public function maxUpdatedAtUnscoped(ApplicationContext $context): ?string
    {
        $row = db($context)->table('subscription_plans')
            ->selectRaw('MAX(updated_at) AS max_updated_at')
            ->first();

        $value = $row['max_updated_at'] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
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

        if (array_key_exists('provider_identifiers', $row) && is_array($row['provider_identifiers'])) {
            $row['provider_identifiers'] = json_encode($row['provider_identifiers'], JSON_THROW_ON_ERROR);
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

        // provider_identifiers (design spec §4.2, Task 13) is nullable: a NULL
        // column value decodes to [] -- "no identifiers configured" -- rather
        // than staying NULL, so every consumer (PlanPurchasability included)
        // can treat the field as a plain map without a null check.
        if (array_key_exists('provider_identifiers', $row)) {
            if (is_string($row['provider_identifiers'])) {
                $decoded = json_decode($row['provider_identifiers'], true, flags: JSON_THROW_ON_ERROR);
                $row['provider_identifiers'] = is_array($decoded) ? $decoded : [];
            } elseif ($row['provider_identifiers'] === null) {
                $row['provider_identifiers'] = [];
            }
        }

        return $row;
    }
}
