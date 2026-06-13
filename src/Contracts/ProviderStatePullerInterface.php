<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Contracts;

interface ProviderStatePullerInterface
{
    /**
     * Pull authoritative provider state for a subscription.
     *
     * @return array<string,mixed>|null Normalized state, or null when unavailable.
     */
    public function pull(string $gateway, string $providerSubscriptionId): ?array;
}
