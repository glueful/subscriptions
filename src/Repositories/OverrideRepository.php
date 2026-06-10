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

    public function maxUpdatedAt(ApplicationContext $context, string $tenantUuid): ?string
    {
        $now = db($context)->getDriver()->formatDateTime();
        $rows = db($context)->table('subscription_overrides')
            ->where('tenant_uuid', '=', $tenantUuid)
            ->whereRaw('(expires_at IS NULL OR expires_at > ?)', [$now])
            ->orderBy(['updated_at' => 'DESC', 'created_at' => 'DESC'])
            ->limit(1)
            ->get();

        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return null;
        }

        $value = $row['updated_at'] ?? $row['created_at'] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
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
