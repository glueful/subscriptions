<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;
use Glueful\Extensions\Subscriptions\SubjectType;

final class PlanCatalog
{
    private const PLATFORM_AUDIENCE = SubjectType::TENANT;
    private const PLATFORM_OWNER = '';

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly ?ApplicationContext $context = null,
        private readonly ?SubscriptionPlanRepository $plans = null,
        private readonly ?string $audience = null,
        private readonly ?string $ownerTenantUuid = null,
    ) {
    }

    /**
     * TRANSITIONAL (Task 7, until Task 9's coordinated cutover): keeps the 1.x
     * config-overlay behavior for existing callers (SubscriptionService,
     * EntitlementResolver, SubscriptionEventProjector, the console commands) --
     * a DB row (any status) takes precedence over config for resolution/existence,
     * but a missing/non-resolvable DB row still falls back to the config-defined
     * 'subscriptions.plans.*' entry. Task 9 deletes this branch entirely and makes
     * fromContext() return forScope($context, 'tenant', ''), the DB-authoritative
     * platform scope with no config overlay.
     */
    public static function fromContext(ApplicationContext $context): self
    {
        return new self(
            (array) config($context, 'subscriptions', []),
            $context,
            new SubscriptionPlanRepository(),
        );
    }

    /**
     * Scoped, DB-authoritative catalog (Task 7): resolves plans strictly within
     * (audience, ownerTenantUuid) via Task 6's scope-aware repository methods.
     * NO config overlay -- a config-only 'subscriptions.plans.*' entry with no
     * matching DB row in this scope resolves to "does not exist" everywhere.
     */
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
        return $this->audience ?? self::PLATFORM_AUDIENCE;
    }

    public function ownerTenantUuid(): string
    {
        return $this->ownerTenantUuid ?? self::PLATFORM_OWNER;
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
        if ($row !== null) {
            $entitlements = $row['entitlements'] ?? [];
            return is_array($entitlements) ? $entitlements : [];
        }

        if ($this->isScoped()) {
            return [];
        }

        $entitlements = $this->config['plans'][$planKey]['entitlements'] ?? [];

        return is_array($entitlements) ? $entitlements : [];
    }

    public function planExists(string $planKey): bool
    {
        $row = $this->dbPlan($planKey);
        if ($row !== null) {
            return true;
        }

        if ($this->isScoped()) {
            return false;
        }

        return isset($this->config['plans'][$planKey]) && is_array($this->config['plans'][$planKey]);
    }

    public function isAssignable(string $planKey): bool
    {
        $row = $this->dbPlan($planKey);
        if ($row !== null) {
            return ($row['status'] ?? null) === 'active';
        }

        if ($this->isScoped()) {
            return false;
        }

        return isset($this->config['plans'][$planKey]) && is_array($this->config['plans'][$planKey]);
    }

    public function graceDays(): int
    {
        return max(0, (int) ($this->config['grace_days'] ?? 0));
    }

    public function providerPriceId(string $planKey): ?string
    {
        $row = $this->resolvableDbPlan($planKey);
        if ($row !== null) {
            $value = $row['provider_price_id'] ?? null;
            return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
        }

        if ($this->isScoped()) {
            return null;
        }

        $value = $this->config['plans'][$planKey]['provider_price_id'] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** Key -> uuid, resolved within this catalog's scope (any status). */
    public function planUuidForKey(string $planKey): ?string
    {
        $row = $this->dbPlan($planKey);
        if ($row === null) {
            return null;
        }

        $value = $row['uuid'] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * Uuid-first read: a plan uuid already identifies a specific row, so this
     * (and the other *Uuid() methods below) look it up directly -- no scope
     * filter, no config overlay.
     *
     * @return array<string,mixed>
     */
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
        $row = $this->dbPlanByUuid($planUuid);
        if ($row === null) {
            return null;
        }

        $value = $row['provider_price_id'] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * audience:owner:maxUpdatedAtInScope for the scoped path (no config hash --
     * a scoped catalog has no config plans to hash). fromContext() keeps the
     * legacy config-hash:dbVersion shape until Task 9.
     */
    public function version(): string
    {
        if ($this->isScoped()) {
            $dbVersion = $this->dbMaxUpdatedAt() ?? 'none';

            return "{$this->audience()}:{$this->ownerTenantUuid()}:{$dbVersion}";
        }

        $algo = in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha256';
        $encoded = json_encode($this->config['plans'] ?? [], JSON_THROW_ON_ERROR);
        $dbVersion = $this->dbMaxUpdatedAt() ?? 'none';

        return substr(hash($algo, $encoded), 0, 16) . ':' . $dbVersion;
    }

    /** Whether this instance was built via forScope() (vs. the legacy fromContext()). */
    private function isScoped(): bool
    {
        return $this->audience !== null;
    }

    private function isPlatformScope(): bool
    {
        return $this->audience() === self::PLATFORM_AUDIENCE && $this->ownerTenantUuid() === self::PLATFORM_OWNER;
    }

    /** @return array<string,mixed>|null */
    private function dbPlan(string $planKey): ?array
    {
        if ($this->context === null || $this->plans === null) {
            return null;
        }

        try {
            if ($this->isScoped()) {
                return $this->plans->findByKeyInScope(
                    $this->context,
                    $this->audience(),
                    $this->ownerTenantUuid(),
                    $planKey
                );
            }

            return $this->plans->findByKey($this->context, $planKey);
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
            if ($this->isScoped()) {
                return $this->plans->findResolvableByKeyInScope(
                    $this->context,
                    $this->audience(),
                    $this->ownerTenantUuid(),
                    $planKey
                );
            }

            return $this->plans->findResolvableByKey($this->context, $planKey);
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
            if ($this->isScoped()) {
                return $this->plans->maxUpdatedAtInScope($this->context, $this->audience(), $this->ownerTenantUuid());
            }

            return $this->plans->maxUpdatedAt($this->context);
        } catch (\Throwable) {
            return null;
        }
    }
}
