<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Bridge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener;
use Glueful\Extensions\Subscriptions\Lifecycle\TenantIntegration;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;

/**
 * The opt-in strict payvia lane adapter (spec §4): tagged with payvia's
 * `StrictPaymentEventListener::CONTAINER_TAG`, this runs inside payvia's
 * lease-governed composed dispatcher -- delivery is at-least-once and `handle()`
 * runs uncaught, so it must be idempotent (the projector's claim-first receipt
 * gate already guarantees that) and side-effect-free whenever `supports()`
 * returns false.
 *
 * `supports()` is an OWNERSHIP gate, not just a type filter: a closed six-type
 * set, a non-empty gateway subscription id, and proof this event actually
 * belongs to subscriptions -- either a local row already linked to that
 * (gateway, id) pair (found under system mode, since the target tenant isn't
 * known yet), or a strict `=== 'subscriptions'` metadata marker for the
 * checkout race where no local link exists yet. `handle()` delegates to
 * `PayviaSubscriptionEventBridge::projectInner()`, the SAME projection entry the
 * ordinary bus lane's `__invoke()` uses, so the two lanes cannot drift apart.
 */
final class StrictPayviaSubscriptionEventBridge implements StrictPaymentEventListener
{
    private const SUPPORTED_TYPES = [
        'subscription.created', 'subscription.updated', 'subscription.past_due',
        'subscription.canceled', 'payment.succeeded', 'invoice.paid',
    ];

    public function __construct(
        private readonly PayviaSubscriptionEventBridge $neutralBridge,
        private readonly SubscriptionRepository $subscriptions,
        private readonly ApplicationContext $context,
    ) {
    }

    public function supports(PaymentProviderEventInterface $event): bool
    {
        if (!in_array($event->type(), self::SUPPORTED_TYPES, true)) {
            return false;
        }

        $normalized = $event->normalized();
        $gwSubId = $normalized['gateway_subscription_id'] ?? null;
        if (!is_string($gwSubId) || $gwSubId === '') {
            return false;
        }

        $mapped = TenantIntegration::runAsSystemOr(
            $this->context,
            fn (): ?array => $this->subscriptions->findByProviderSubscription(
                $this->context,
                $event->gateway(),
                $gwSubId
            )
        );
        if ($mapped !== null) {
            return true;
        }

        $metadata = $normalized['metadata'] ?? null;

        return is_array($metadata) && ($metadata['glueful_consumer'] ?? null) === 'subscriptions';
    }

    public function handle(PaymentProviderEventInterface $event): void
    {
        $this->neutralBridge->projectInner($event);
    }
}
