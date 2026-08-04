<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Bridge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener;
use Glueful\Extensions\Payvia\Contracts\SubscriptionProjectionAcknowledger;
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
 * `PayviaSubscriptionEventBridge::projectInnerWithOutcome()` (Task 12; the void
 * `projectInner()` twin is what the ordinary bus lane's `__invoke()` uses instead),
 * the SAME DTO mapping and projection rules either way, so the two lanes cannot
 * drift apart.
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
 *
 * PAYVIA ACKNOWLEDGEMENT (design spec §3.6/§4.3, Task 12): `handle()` now calls
 * `PayviaSubscriptionEventBridge::projectInnerWithOutcome()` instead of the void
 * `projectInner()`, then -- for `subscription.created` ONLY, and only when the
 * event actually correlates to a Payvia checkout -- acknowledges the settled
 * outcome via payvia's `SubscriptionProjectionAcknowledger` contract AFTER the
 * receipt transaction has already committed (`projectInnerWithOutcome()` only
 * returns once that transaction is done). Scoped to `subscription.created`
 * deliberately: payvia's own `WebhookService::finalizeOrigination()` is itself
 * scoped to that one event type, so an origination is never still `provider_observed`
 * by the time a LATER event (subscription.updated, payment.succeeded, ...) for the
 * same subscription arrives -- acknowledging against it then would be refused
 * with a wrong-state error on every single follow-up webhook, forever.
 *
 * `interface_exists(SubscriptionProjectionAcknowledger::class)` gates the whole
 * acknowledgement attempt (mirrors `SubscriptionsServiceProvider::strictLaneMode()`'s
 * own `interface_exists()`-first idiom): payvia >=2.4 but <2.5 does not ship this
 * contract at all, so its absence is a silent no-op, not a failure -- there is
 * nothing to satisfy. Once the contract IS present, a missing/unresolvable
 * container binding is a hard failure (fail closed, event retryable): payvia's
 * finalizer throws `RequiredProjectionAcknowledgementMissing` and retries the
 * event when it cannot observe an acknowledgement, so silently swallowing our
 * own inability to acknowledge would just convert that into a permanently stuck
 * origination instead of a loud, diagnosable, retryable failure.
 */
final class StrictPayviaSubscriptionEventBridge implements StrictPaymentEventListener
{
    private const SUPPORTED_TYPES = [
        'subscription.created', 'subscription.updated', 'subscription.past_due',
        'subscription.canceled', 'payment.succeeded', 'invoice.paid',
    ];

    /**
     * The `required_projection_consumer` / `glueful_consumer` identity this
     * extension registers itself under with Payvia (design spec §4.1/§3.6) --
     * shared with the marker string `supports()` already checks below.
     */
    private const CONSUMER = 'subscriptions';

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

        return is_array($metadata) && ($metadata['glueful_consumer'] ?? null) === self::CONSUMER;
    }

    public function handle(PaymentProviderEventInterface $event): void
    {
        $outcome = $this->neutralBridge->projectInnerWithOutcome($event);

        if ($event->type() !== 'subscription.created') {
            // Only the activation-bearing event is ever finalized against an
            // origination -- see this class's own docblock ("PAYVIA ACKNOWLEDGEMENT").
            return;
        }

        if (!interface_exists(SubscriptionProjectionAcknowledger::class)) {
            return; // payvia < 2.5: the acknowledgement contract does not exist yet.
        }

        $originationUuid = $this->originationUuid($event);
        if ($originationUuid === null) {
            return; // not a Payvia-checkout-correlated event -- nothing to acknowledge.
        }

        $this->resolveAcknowledger()->acknowledge(
            $originationUuid,
            self::CONSUMER,
            $outcome->logicalEventKey,
            $outcome->outcome,
            $outcome->reason,
        );
    }

    /**
     * The opaque checkout correlation token (design spec §3.4/§3.5), read straight
     * off the event's own normalized metadata -- the SAME field
     * {@see \Glueful\Extensions\Subscriptions\Projection\SubscriptionEventProjector}'s
     * `origination_mismatch` guard reads. Absent for any event that never went
     * through a Payvia-managed checkout (e.g. a pre-2.2 operator-created subscription's
     * webhooks) -- there is no origination to acknowledge, so `handle()` skips
     * acknowledgement entirely rather than attempting one against an empty/unknown uuid.
     */
    private function originationUuid(PaymentProviderEventInterface $event): ?string
    {
        $metadata = $event->normalized()['metadata'] ?? null;
        if (!is_array($metadata)) {
            return null;
        }

        $value = $metadata['origination_uuid'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Lazily resolves payvia's durable acknowledgement contract from the container
     * (design spec §3.6, Task 12) -- ONLY ever called once `handle()` has already
     * confirmed both that the contract exists (`interface_exists()`, checked by the
     * caller) and that this delivery actually needs an acknowledgement (a correlated
     * origination_uuid is present). A present contract with no resolvable binding is
     * a hard, uncaught failure (fail closed): see this class's own docblock for why
     * silently skipping it here would be worse than a retried event.
     */
    private function resolveAcknowledger(): SubscriptionProjectionAcknowledger
    {
        if (!$this->context->hasContainer()) {
            throw new \RuntimeException(
                'Payvia\'s SubscriptionProjectionAcknowledger contract is installed but this '
                . 'application context has no container to resolve it from -- refusing to silently '
                . 'skip a required checkout projection acknowledgement.'
            );
        }

        $container = $this->context->getContainer();
        if (!$container->has(SubscriptionProjectionAcknowledger::class)) {
            throw new \RuntimeException(
                'Payvia\'s SubscriptionProjectionAcknowledger contract is installed but not bound in '
                . 'the container -- refusing to silently skip a required checkout projection '
                . 'acknowledgement (the event stays retryable until the binding is fixed).'
            );
        }

        $acknowledger = $container->get(SubscriptionProjectionAcknowledger::class);
        if (!$acknowledger instanceof SubscriptionProjectionAcknowledger) {
            throw new \RuntimeException(sprintf(
                'Container id %s did not resolve to a %s instance.',
                SubscriptionProjectionAcknowledger::class,
                SubscriptionProjectionAcknowledger::class
            ));
        }

        return $acknowledger;
    }
}
