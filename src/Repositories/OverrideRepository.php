<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Repositories;

use Glueful\Bootstrap\ApplicationContext;

final class OverrideRepository
{
    /** @return array<string,mixed> */
    public function activeForTenant(ApplicationContext $context, string $tenantUuid): array
    {
        $now = db($context)->getDriver()->formatDateTime();
        $rows = db($context)->table('subscription_overrides')
            ->where('tenant_uuid', '=', $tenantUuid)
            ->whereRaw('(expires_at IS NULL OR expires_at > ?)', [$now])
            ->get();

        $overrides = [];
        foreach ($rows as $row) {
            $entitlement = (string) ($row['entitlement'] ?? '');
            if ($entitlement === '') {
                continue;
            }
            $overrides[$entitlement] = $this->decode($row['value'] ?? null);
        }

        return $overrides;
    }

    private function decode(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
