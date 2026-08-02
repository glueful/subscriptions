<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Support;

use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;

/**
 * Records every mode/tenant a caller ran through, then executes $fn directly
 * (no real tenant scoping machinery -- that lives in the tenancy extension).
 * Proves WHICH seam (spec §9) a given code path used: `SubscriptionService`'s
 * subject-scoped `…For()` methods must call `runAsTenant($subject->tenantUuid, ...)`,
 * while the projector's `project()` and `SubscriptionSubjectDataPurger::purgeSubject()`
 * -- both of which must find/remove rows before any tenant context exists -- must
 * call `runAsSystem(...)`.
 */
final class RecordingTenantContextRunner implements TenantContextRunner
{
    /** @var list<array{mode:string,tenantUuid:?string}> */
    private array $calls = [];

    public function runAsTenant(string $tenantUuid, callable $fn): mixed
    {
        $this->calls[] = ['mode' => 'tenant', 'tenantUuid' => $tenantUuid];

        return $fn();
    }

    public function runAsSystem(callable $fn): mixed
    {
        $this->calls[] = ['mode' => 'system', 'tenantUuid' => null];

        return $fn();
    }

    /** @param callable(string $tenantUuid): void $fn */
    public function forEachTenant(callable $fn): void
    {
        throw new \RuntimeException('RecordingTenantContextRunner::forEachTenant() is not exercised by these tests.');
    }

    /** @return list<array{mode:string,tenantUuid:?string}> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    public function lastMode(): ?string
    {
        $last = $this->calls[array_key_last($this->calls)] ?? null;

        return $last['mode'] ?? null;
    }

    public function lastTenantUuid(): ?string
    {
        $last = $this->calls[array_key_last($this->calls)] ?? null;

        return $last['tenantUuid'] ?? null;
    }
}
