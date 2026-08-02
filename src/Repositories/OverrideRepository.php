<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Repositories;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Subject;

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

        return $this->collect($rows);
    }

    /**
     * Subject-aware finder (Task 6): triple match on (tenant_uuid, subject_type,
     * subject_uuid). Added alongside the byte-compatible activeForTenant() above --
     * Task 9 switches activeForTenant() to a Subject::tenant() delegate at the
     * coordinated activation boundary.
     *
     * @return array<string,mixed>
     */
    public function activeForSubject(ApplicationContext $context, Subject $subject): array
    {
        $now = db($context)->getDriver()->formatDateTime();
        $rows = db($context)->table('subscription_overrides')
            ->where('tenant_uuid', '=', $subject->tenantUuid)
            ->where('subject_type', '=', $subject->type)
            ->where('subject_uuid', '=', $subject->uuid)
            ->whereRaw('(expires_at IS NULL OR expires_at > ?)', [$now])
            ->get();

        return $this->collect($rows);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function collect(array $rows): array
    {
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
