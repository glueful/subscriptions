<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;
use Glueful\Extensions\Subscriptions\SubjectType;

/**
 * Scoped, DB-authoritative plan catalog (spec §3).
 *
 * Every instance resolves plans strictly within ONE (audience, ownerTenantUuid)
 * scope. There is NO config overlay: `subscriptions.plans.*` is a SEED for the
 * platform catalog (imported via `subscriptions:plans:import-config`), never a
 * runtime fallback -- a config-only key with no matching DB row in this scope
 * "does not exist" everywhere. The config array survives only for the two
 * genuinely config-owned values, `default_plan` and `grace_days`.
 */
final class PlanCatalog
{
    private const PLATFORM_AUDIENCE = SubjectType::TENANT;
    private const PLATFORM_OWNER = '';

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly ?ApplicationContext $context = null,
        private readonly ?SubscriptionPlanRepository $plans = null,
        private readonly string $audience = self::PLATFORM_AUDIENCE,
        private readonly string $ownerTenantUuid = self::PLATFORM_OWNER,
    ) {
    }

    /**
     * The platform catalog -- `forScope($context, 'tenant', '')`. This is what the
     * 1.x-facing surfaces (SubscriptionService's tenant facade, EntitlementResolver,
     * SubscriptionEventProjector, the tenant console commands) resolve against.
     */
    public static function fromContext(ApplicationContext $context): self
    {
        return self::forScope($context, self::PLATFORM_AUDIENCE, self::PLATFORM_OWNER);
    }

    public static function forScope(ApplicationContext $context, string $audience, string $ownerTenantUuid): self
    {
        return new self(
            (array) config($context, 'subscriptions', []),
            $context,
            new SubscriptionPlanRepository(),
            $audience,
            $ownerTenantUuid,
        );
    }

    public function audience(): string
    {
        return $this->audience;
    }

    public function ownerTenantUuid(): string
    {
        return $this->ownerTenantUuid;
    }

    /**
     * Platform scope only ('tenant', ''); a workspace/user-scoped catalog has no
     * config-level notion of a "default plan" -- that concept only exists for the
     * tenant-facing 1.x/platform catalog.
     */
    public function defaultPlan(): string
    {
        if (!$this->isPlatformScope()) {
            throw new \LogicException(
                'PlanCatalog::defaultPlan() is only defined for the platform scope '
                . "(audience='" . self::PLATFORM_AUDIENCE . "', owner=''); called on scope "
                . "audience='{$this->audience()}' owner='{$this->ownerTenantUuid()}'."
            );
        }

        return (string) ($this->config['default_plan'] ?? 'free');
    }

    /** @return array<string,mixed> */
    public function entitlementsFor(string $planKey): array
    {
        $row = $this->resolvableDbPlan($planKey);
        if ($row === null) {
            return [];
        }

        $entitlements = $row['entitlements'] ?? [];

        return is_array($entitlements) ? $entitlements : [];
    }

    public function planExists(string $planKey): bool
    {
        return $this->dbPlan($planKey) !== null;
    }

    public function isAssignable(string $planKey): bool
    {
        $row = $this->dbPlan($planKey);

        return $row !== null && ($row['status'] ?? null) === 'active';
    }

    public function graceDays(): int
    {
        return max(0, (int) ($this->config['grace_days'] ?? 0));
    }

    public function providerPriceId(string $planKey): ?string
    {
        return $this->stringOrNull($this->resolvableDbPlan($planKey)['provider_price_id'] ?? null);
    }

    /** Key -> uuid, resolved within this catalog's scope (any status). */
    public function planUuidForKey(string $planKey): ?string
    {
        return $this->stringOrNull($this->dbPlan($planKey)['uuid'] ?? null);
    }

    /**
     * Uuid-first read: a plan uuid already identifies a specific row, so this
     * (and the other *Uuid() methods below) look it up directly -- no scope
     * filter. Callers that must not cross scopes check the returned row's
     * (audience, owner_tenant_uuid) themselves; SubscriptionService does exactly
     * that for subject/plan audience matching (spec §4).
     *
     * @return array<string,mixed>|null
     */
    public function planForUuid(string $planUuid): ?array
    {
        return $this->dbPlanByUuid($planUuid);
    }

    /** @return array<string,mixed> */
    public function entitlementsForUuid(string $planUuid): array
    {
        $row = $this->dbPlanByUuid($planUuid);
        if ($row === null) {
            return [];
        }

        $entitlements = $row['entitlements'] ?? [];

        return is_array($entitlements) ? $entitlements : [];
    }

    public function isAssignableUuid(string $planUuid): bool
    {
        $row = $this->dbPlanByUuid($planUuid);

        return $row !== null && ($row['status'] ?? null) === 'active';
    }

    public function providerPriceIdForUuid(string $planUuid): ?string
    {
        return $this->stringOrNull($this->dbPlanByUuid($planUuid)['provider_price_id'] ?? null);
    }

    /**
     * audience:owner:maxUpdatedAtInScope -- no config hash: with the overlay gone
     * the config plans cannot influence what this catalog resolves, so folding
     * them into the cache-invalidation signature would only produce spurious
     * misses (and would hide a real DB change behind an unchanged config).
     */
    public function version(): string
    {
        $dbVersion = $this->dbMaxUpdatedAt() ?? 'none';

        return "{$this->audience}:{$this->ownerTenantUuid}:{$dbVersion}";
    }

    private function isPlatformScope(): bool
    {
        return $this->audience === self::PLATFORM_AUDIENCE && $this->ownerTenantUuid === self::PLATFORM_OWNER;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** @return array<string,mixed>|null */
    private function dbPlan(string $planKey): ?array
    {
        if ($this->context === null || $this->plans === null) {
            return null;
        }

        try {
            return $this->plans->findByKeyInScope($this->context, $this->audience, $this->ownerTenantUuid, $planKey);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    private function resolvableDbPlan(string $planKey): ?array
    {
        if ($this->context === null || $this->plans === null) {
            return null;
        }

        try {
            return $this->plans->findResolvableByKeyInScope(
                $this->context,
                $this->audience,
                $this->ownerTenantUuid,
                $planKey
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    private function dbPlanByUuid(string $planUuid): ?array
    {
        if ($this->context === null || $this->plans === null) {
            return null;
        }

        try {
            return $this->plans->findByUuid($this->context, $planUuid);
        } catch (\Throwable) {
            return null;
        }
    }

    private function dbMaxUpdatedAt(): ?string
    {
        if ($this->context === null || $this->plans === null) {
            return null;
        }

        try {
            return $this->plans->maxUpdatedAtInScope($this->context, $this->audience, $this->ownerTenantUuid);
        } catch (\Throwable) {
            return null;
        }
    }
}
