<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Support;

use Glueful\Extensions\Subscriptions\Contracts\ProviderStatePullerInterface;

final class CallablePuller implements ProviderStatePullerInterface
{
    /** @param callable(string,string):(array<string,mixed>|null) $fn */
    public function __construct(private $fn)
    {
    }

    public function pull(string $gateway, string $providerSubscriptionId): ?array
    {
        return ($this->fn)($gateway, $providerSubscriptionId);
    }
}
