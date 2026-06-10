<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Entitlements\Contracts\EntitlementCheckerInterface;
use Glueful\Extensions\Subscriptions\Resolution\EntitlementResolver;

final class DefaultEntitlementChecker implements EntitlementCheckerInterface
{
    public function __construct(
        private readonly EntitlementResolver $resolver,
        private readonly ApplicationContext $context,
    ) {
    }

    public function allows(string $tenantUuid, string $entitlement, array $context = []): bool
    {
        $map = $this->resolver->resolveMap($this->context, $tenantUuid);
        if (!array_key_exists($entitlement, $map)) {
            return false;
        }

        return $this->mapAllows($map[$entitlement]);
    }

    public function limit(string $tenantUuid, string $entitlement, array $context = []): ?int
    {
        $map = $this->resolver->resolveMap($this->context, $tenantUuid);
        if (!array_key_exists($entitlement, $map)) {
            return 0;
        }

        return $this->mapLimit($map[$entitlement]);
    }

    private function mapAllows(mixed $value): bool
    {
        if ($value === null || $value === true) {
            return true;
        }

        if ($value === false) {
            return false;
        }

        if (is_numeric($value)) {
            return (int) $value > 0;
        }

        return (bool) $value;
    }

    private function mapLimit(mixed $value): ?int
    {
        if ($value === null || $value === true) {
            return null;
        }

        if ($value === false) {
            return 0;
        }

        // S3 consistency with mapAllows(): n > 0 is the limit; n <= 0 denies,
        // so the limit reads 0 -- never a raw negative number.
        return is_numeric($value) ? max(0, (int) $value) : null;
    }
}
