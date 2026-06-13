<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Bridge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Contracts\ProviderStatePullerInterface;

/**
 * Payvia-backed default puller. Bound to ProviderStatePullerInterface only when
 * payvia's GatewaySubscriptionService exists. The ONLY reconcile class that
 * names payvia.
 */
final class PayviaProviderStatePuller implements ProviderStatePullerInterface
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function pull(string $gateway, string $providerSubscriptionId): ?array
    {
        try {
            // Payvia is a soft dependency: the class is absent in a plain checkout.
            /** @phpstan-ignore class.notFound */
            $service = app($this->context, \Glueful\Extensions\Payvia\Services\GatewaySubscriptionService::class);

            $state = $service->reconcile($gateway, $providerSubscriptionId);

            return is_array($state) ? $state : null;
        } catch (\Throwable) {
            return null; // provider/service failure degrades to "no drift applied"
        }
    }
}
