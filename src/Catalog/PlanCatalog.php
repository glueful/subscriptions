<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Catalog;

use Glueful\Bootstrap\ApplicationContext;

final class PlanCatalog
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public static function fromContext(ApplicationContext $context): self
    {
        return new self((array) config($context, 'subscriptions', []));
    }

    public function defaultPlan(): string
    {
        return (string) ($this->config['default_plan'] ?? 'free');
    }

    /** @return array<string,mixed> */
    public function entitlementsFor(string $planKey): array
    {
        $entitlements = $this->config['plans'][$planKey]['entitlements'] ?? [];

        return is_array($entitlements) ? $entitlements : [];
    }

    public function planExists(string $planKey): bool
    {
        return isset($this->config['plans'][$planKey]) && is_array($this->config['plans'][$planKey]);
    }

    public function graceDays(): int
    {
        return max(0, (int) ($this->config['grace_days'] ?? 0));
    }

    public function pricedPlanUuid(string $planKey): ?string
    {
        $value = $this->config['plans'][$planKey]['payvia_priced_plan'] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public function version(): string
    {
        $algo = in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha256';
        $encoded = json_encode($this->config['plans'] ?? [], JSON_THROW_ON_ERROR);

        return substr(hash($algo, $encoded), 0, 16);
    }
}
