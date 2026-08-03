<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Bridge;

/**
 * Task 7 -- spec §4: decides which SINGLE payvia payment-event lane this install
 * registers into. Exactly one of the three lanes is ever wired at once:
 *
 *  - STRICT: payvia ^2.4+ is present (its `StrictPaymentEventListener` contract
 *    resolves) -- {@see StrictPayviaSubscriptionEventBridge} is tagged onto
 *    payvia's lease-governed composed dispatcher and NO `addListener()` call is
 *    made against the ordinary fault-isolated bus.
 *  - BUS: payvia is present but predates the strict contract (<=2.3) -- the
 *    existing lazy `'@'.PayviaSubscriptionEventBridge::class` listener is
 *    registered as a degraded, fault-isolated fallback. It works, but does not
 *    carry the strict lane's retryable-unmapped guarantee.
 *  - NONE: payvia is absent entirely -- neither lane is wired.
 *
 * `decide()` is a PURE function of two booleans precisely so it (and every
 * caller built on top of it) is testable without runtime class fakery: since
 * payvia ^2.4 has been a permanent require-dev fixture since Task 5,
 * `interface_exists(StrictPaymentEventListener::class)` is ALWAYS true
 * in-process, so the only way to exercise the BUS/NONE branches in a test is to
 * call this function (or {@see SubscriptionsServiceProvider::serviceDefinitionsForMode()})
 * directly with an explicit mode/booleans, never by trying to make the real
 * interface "disappear" at runtime.
 */
final class StrictLaneRegistration
{
    public const STRICT = 'strict';
    public const BUS = 'bus';
    public const NONE = 'none';

    /**
     * @param bool $strictContractPresent interface_exists() against payvia's
     *             \Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener
     * @param bool $payviaEventPresent class_exists() against payvia's
     *             \Glueful\Extensions\Payvia\Events\PaymentProviderEvent
     */
    public static function decide(bool $strictContractPresent, bool $payviaEventPresent): string
    {
        return $strictContractPresent ? self::STRICT : ($payviaEventPresent ? self::BUS : self::NONE);
    }
}
