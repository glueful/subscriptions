<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;

final class PlanCatalog
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly ?ApplicationContext $context = null,
        private readonly ?SubscriptionPlanRepository $plans = null,
    ) {
    }

    public static function fromContext(ApplicationContext $context): self
    {
        return new self(
            (array) config($context, 'subscriptions', []),
            $context,
            new SubscriptionPlanRepository(),
        );
    }

    public function defaultPlan(): string
    {
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

        $entitlements = $this->config['plans'][$planKey]['entitlements'] ?? [];

        return is_array($entitlements) ? $entitlements : [];
    }

    public function planExists(string $planKey): bool
    {
        $row = $this->dbPlan($planKey);
        if ($row !== null) {
            return true;
        }

        return isset($this->config['plans'][$planKey]) && is_array($this->config['plans'][$planKey]);
    }

    public function isAssignable(string $planKey): bool
    {
        $row = $this->dbPlan($planKey);
        if ($row !== null) {
            return ($row['status'] ?? null) === 'active';
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

        $value = $this->config['plans'][$planKey]['provider_price_id'] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public function version(): string
    {
        $algo = in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha256';
        $encoded = json_encode($this->config['plans'] ?? [], JSON_THROW_ON_ERROR);
        $dbVersion = $this->dbMaxUpdatedAt() ?? 'none';

        return substr(hash($algo, $encoded), 0, 16) . ':' . $dbVersion;
    }

    /** @return array<string,mixed>|null */
    private function dbPlan(string $planKey): ?array
    {
        if ($this->context === null || $this->plans === null) {
            return null;
        }

        try {
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
            return $this->plans->findResolvableByKey($this->context, $planKey);
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
            return $this->plans->maxUpdatedAt($this->context);
        } catch (\Throwable) {
            return null;
        }
    }
}
