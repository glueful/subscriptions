<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Support;

use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry;

/**
 * Records every batch of table names registered, so a test can assert exactly
 * which tables the provider registered (spec §9: `subscriptions`,
 * `subscription_overrides`, `subscription_events` -- never `subscription_plans`
 * or `subscription_provider_event_receipts`) without a real tenancy backstop.
 */
final class RecordingTenantTableRegistry implements TenantTableRegistry
{
    /** @var list<string> */
    private array $registered = [];

    /** @param list<string> $tables */
    public function register(array $tables): void
    {
        foreach ($tables as $table) {
            if (!in_array($table, $this->registered, true)) {
                $this->registered[] = $table;
            }
        }
    }

    /** @return list<string> */
    public function registered(): array
    {
        return $this->registered;
    }
}
