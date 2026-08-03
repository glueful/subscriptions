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
 * `supports()` is a ROUTING gate over a closed six-type set plus a non-empty
 * gateway subscription id, and -- for the five NON-created types -- an ownership
 * proof: either a local row already linked to that (gateway, id) pair (found
 * under system mode, since the target tenant isn't known yet), or a strict
 * `=== 'subscriptions'` metadata marker. `handle()` delegates to
 * `PayviaSubscriptionEventBridge::projectInner()`, the SAME projection entry the
 * ordinary bus lane's `__invoke()` uses, so the two lanes cannot drift apart.
 *
 * SPEC-OWNER RULING -- `subscription.created` carries NO ownership proof
 * requirement. A created event with a non-empty `gateway_subscription_id` is
 * supported outright; the marker/local-mapping proof is dropped FOR CREATED
 * ONLY. The reason is recovery: at creation time there is by definition no
 * local (gateway, id) link yet, and the legacy tenant-metadata relink flow
 * (`metadata.tenant_uuid`, no `glueful_consumer`) is the exact shape the
 * neutral bus lane always accepted -- requiring proof here would silently
 * strand every 1.x-shaped checkout the moment the strict lane is enabled.
 *
 * What that does NOT change: the projector remains the SOLE authority for
 * subject resolution, scope/plan-audience validation, receipt settlement,
 * rejection codes and retry semantics after routing. `supports()` decides only
 * whether this listener looks at the event at all; every ownership decision
 * that matters is still made downstream, and a created event that is not ours
 * resolves to no subject / no local row and is rejected or thrown back as
 * retryable-unmapped by the projector, never mis-projected.
 *
 * `glueful_consumer` stays useful ownership evidence for the five non-created
 * types and for diagnostics, but it is NOT required for created-event recovery.
 * The accepted cost is that FOREIGN created events (another payvia consumer's
 * subscriptions) now enter this listener and can surface the projector's
 * retryable `UnmappedProviderSubscriptionException`, i.e. foreign created-event
 * retries. That is deliberate: a future cryptographic correlation token minted
 * at checkout and echoed by the provider is the only clean way to distinguish
 * ownership at created time, and it is out of scope for this release.
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

        // Created events are routed on shape alone -- see the class docblock's
        // spec-owner ruling. No ownership proof; the projector decides.
        if ($event->type() === 'subscription.created') {
            return true;
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
