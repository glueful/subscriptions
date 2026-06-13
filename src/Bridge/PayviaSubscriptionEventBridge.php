<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Bridge;

use Glueful\Extensions\Subscriptions\Contracts\SubscriptionEventProjectorInterface;
use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;

/**
 * Thin first-party adapter: maps payvia's PaymentProviderEvent (a wrapper whose
 * `->event` exposes gateway()/type()/logicalEventKey()/normalized()) into the
 * generic ProviderSubscriptionEvent and hands it to the projector. Owns NO
 * projection rules. The ONLY subscriptions class permitted to name payvia.
 */
final class PayviaSubscriptionEventBridge
{
    public function __construct(private readonly SubscriptionEventProjectorInterface $projector)
    {
    }

    public function __invoke(object $payviaEvent): void
    {
        $inner = $payviaEvent->event ?? null;
        if (!is_object($inner)) {
            return;
        }

        $this->projector->project(new ProviderSubscriptionEvent(
            gateway: (string) $inner->gateway(),
            type: (string) $inner->type(),
            logicalEventKey: (string) $inner->logicalEventKey(),
            normalized: (array) $inner->normalized(),
        ));
    }
}
