<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Bridge;

use Glueful\Extensions\Subscriptions\Contracts\SubscriptionEventProjectorInterface;
use Glueful\Extensions\Subscriptions\Projection\ProjectionOutcome;
use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;

/**
 * Thin first-party adapter: maps payvia's PaymentProviderEvent (a wrapper whose
 * `->event` exposes gateway()/type()/logicalEventKey()/normalized()) into the
 * generic ProviderSubscriptionEvent and hands it to the projector. Owns NO
 * projection rules.
 *
 * Two classes in this namespace touch payvia, with deliberately different
 * couplings: THIS one is payvia-NEUTRAL -- it duck-types the event shape
 * (`->event` exposing gateway()/type()/logicalEventKey()/normalized()) and
 * names no payvia type at all, so the ordinary bus lane keeps working with
 * payvia absent. {@see StrictPayviaSubscriptionEventBridge} is the strict-lane
 * adapter and is the one class that carries the TYPED payvia dependency
 * (`StrictPaymentEventListener` / `PaymentProviderEventInterface`), which is
 * why it is only ever constructed when payvia >=2.4 is actually installed.
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

        $this->projectInner($inner);
    }

    /**
     * The one executable mapping authority shared by both the ordinary bus
     * `__invoke()` path (after unwrapping `->event`) and
     * `StrictPayviaSubscriptionEventBridge::handle()` (given payvia's
     * `PaymentProviderEventInterface` directly) -- so the two lanes cannot drift
     * apart into two copied DTO constructors held together only by a parity test.
     */
    public function projectInner(object $inner): void
    {
        $this->projector->project($this->toDto($inner));
    }

    /**
     * The outcome-returning twin of {@see projectInner()} (design spec §4.3, Task 12):
     * the SAME DTO mapping, but through `SubscriptionEventProjectorInterface::
     * projectWithOutcome()` so `StrictPayviaSubscriptionEventBridge::handle()` can
     * acknowledge Payvia's durable projection-acknowledgement contract afterward.
     * The ordinary bus lane has no acknowledger to satisfy, so its `__invoke()`
     * keeps calling the void `projectInner()` above unchanged.
     */
    public function projectInnerWithOutcome(object $inner): ProjectionOutcome
    {
        return $this->projector->projectWithOutcome($this->toDto($inner));
    }

    private function toDto(object $inner): ProviderSubscriptionEvent
    {
        return new ProviderSubscriptionEvent(
            gateway: (string) $inner->gateway(),
            type: (string) $inner->type(),
            logicalEventKey: (string) $inner->logicalEventKey(),
            normalized: (array) $inner->normalized(),
        );
    }
}
