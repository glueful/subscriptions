<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Listeners;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Psr\Log\LoggerInterface;

/**
 * Projects payvia PaymentProviderEvents onto tenant subscription state (S6/S7).
 *
 * Payvia is a SOFT dependency: this class imports NO payvia types. The event is
 * read duck-typed (`$payviaEvent->event` with gateway()/type()/logicalEventKey()/
 * normalized() -- shape verified against payvia's PaymentProviderEventInterface),
 * and registration happens in the provider only when payvia's event class exists.
 *
 * Concurrency-safe idempotency is claim-first: the subscription_events insert
 * (unique on (payvia_gateway, payvia_logical_event_key)) and the projection run
 * in ONE transaction, so a duplicate/concurrent delivery that loses the claim
 * rolls back and never re-projects (e.g. grace is never extended twice).
 */
final class PaymentProviderEventListener
{
    private const SETTLEABLE = ['trialing', 'past_due'];
    private const KNOWN_STATUSES = ['active', 'trialing', 'past_due', 'canceled', 'incomplete', 'paused'];

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionEventRepository $events,
        private readonly PlanCatalog $catalog,
        private readonly ApplicationContext $context,
    ) {
    }

    public function __invoke(object $payviaEvent): void
    {
        $inner = $payviaEvent->event ?? null;
        if (!is_object($inner)) {
            return;
        }

        $gateway = (string) $inner->gateway();
        $type = (string) $inner->type();
        $logicalKey = (string) $inner->logicalEventKey();
        /** @var array<string,mixed> $normalized */
        $normalized = (array) $inner->normalized();

        // Cheap read-side early-out ONLY -- the transactional claim below is the gate.
        if (
            $gateway !== ''
            && $logicalKey !== ''
            && $this->events->existsByLogicalKey($this->context, $gateway, $logicalKey)
        ) {
            return;
        }

        $sub = $this->mapToSubscription($gateway, $type, $normalized);
        if ($sub === null) {
            return; // unmapped provider subscription -> graceful no-op
        }

        $changes = $this->computeChanges($type, $sub, $normalized);
        if ($changes === null) {
            return; // event type this projection does not handle
        }

        $from = isset($sub['status']) ? (string) $sub['status'] : null;
        $to = isset($changes['status']) ? (string) $changes['status'] : $from;

        try {
            db($this->context)->transaction(
                function () use ($sub, $changes, $gateway, $logicalKey, $from, $to, $type, $normalized): void {
                    // (1) CLAIM -- throws on (payvia_gateway, payvia_logical_event_key) duplicate.
                    $this->events->insertOrThrow($this->context, [
                        'tenant_uuid' => (string) $sub['tenant_uuid'],
                        'type' => $type,
                        'from_status' => $from,
                        'to_status' => $to,
                        'source' => 'payvia_event',
                        'payvia_gateway' => $gateway !== '' ? $gateway : null,
                        'payvia_logical_event_key' => $logicalKey !== '' ? $logicalKey : null,
                        'data' => $normalized,
                    ]);

                    // (2) PROJECT -- only the claim winner reaches here.
                    if ($changes !== []) {
                        $this->subscriptions->updateByTenant($this->context, (string) $sub['tenant_uuid'], $changes);
                    }
                }
            );
        } catch (\Throwable $e) {
            if ($this->events->isUniqueViolation($e)) {
                // Observability on the swallow branch: a misclassified integrity
                // error would otherwise vanish silently. Debug-level by design.
                $this->resolveLogger()?->debug('Duplicate provider event claim skipped', [
                    'event' => 'subscriptions.duplicate_claim_skipped',
                    'payvia_gateway' => $gateway,
                    'payvia_logical_event_key' => $logicalKey,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);

                return; // a concurrent/duplicate delivery already owns this logical event
            }
            throw $e;
        }
    }

    /**
     * Resolved DEFENSIVELY: the listener must never hard-depend on a logging
     * service -- a missing binding would turn a debug line into a fatal.
     */
    private function resolveLogger(): ?LoggerInterface
    {
        if (!$this->context->hasContainer()) {
            return null;
        }

        $container = $this->context->getContainer();
        foreach (['logger', LoggerInterface::class] as $id) {
            if ($container->has($id)) {
                $logger = $container->get($id);
                if ($logger instanceof LoggerInterface) {
                    return $logger;
                }
            }
        }

        return null;
    }

    /**
     * Map provider (gateway, gateway_subscription_id) -> tenant subscription row.
     * On subscription.created an UNLINKED row can be recovered via the provider
     * metadata's tenant_uuid -- writing BOTH payvia_gateway and payvia_subscription_id.
     *
     * SECURITY: `metadata.tenant_uuid` flows verbatim from the provider webhook
     * payload (payvia passes provider metadata through unmodified), so it is NOT a
     * trust anchor. It is used here only as a RECOVERY HINT to attach an
     * as-yet-unlinked subscription to its tenant -- never to MOVE an existing link
     * from one provider subscription to another. A row that is already linked is
     * left untouched (a mismatch is logged as an anomaly and no-ops). The real
     * trust anchor would be a server-issued correlation token round-tripped through
     * the provider, which is an app-side concern and out of scope here.
     *
     * @param array<string,mixed> $normalized
     * @return array<string,mixed>|null
     */
    private function mapToSubscription(string $gateway, string $type, array $normalized): ?array
    {
        $gwSubId = $normalized['gateway_subscription_id'] ?? null;
        $gwSubId = is_scalar($gwSubId) && (string) $gwSubId !== '' ? (string) $gwSubId : null;

        $sub = ($gateway !== '' && $gwSubId !== null)
            ? $this->subscriptions->findByPayviaSubscription($this->context, $gateway, $gwSubId)
            : null;

        if ($sub !== null || $type !== 'subscription.created' || $gateway === '' || $gwSubId === null) {
            return $sub;
        }

        $metadata = $normalized['metadata'] ?? null;
        $tenantUuid = is_array($metadata) && isset($metadata['tenant_uuid']) && is_scalar($metadata['tenant_uuid'])
            ? (string) $metadata['tenant_uuid']
            : '';
        if ($tenantUuid === '') {
            return null;
        }

        $existing = $this->subscriptions->findByTenant($this->context, $tenantUuid);
        if ($existing === null) {
            return null;
        }

        // Only attach when the target row is NOT already linked. We must never
        // overwrite an existing provider link based on provider-echoed metadata.
        $existingSubId = $existing['payvia_subscription_id'] ?? null;
        $existingSubId = is_scalar($existingSubId) ? (string) $existingSubId : '';

        if ($existingSubId !== '') {
            $existingGateway = is_scalar($existing['payvia_gateway'] ?? null)
                ? (string) $existing['payvia_gateway']
                : '';

            // Already linked to THIS exact (gateway, sub id): a no-op relink --
            // return the row so projection proceeds normally. (Defensive: the
            // findByPayviaSubscription lookup above would already have matched it.)
            if ($existingGateway === $gateway && $existingSubId === $gwSubId) {
                return $existing;
            }

            // Already linked to a DIFFERENT provider subscription: refuse to move
            // the link. Log the anomaly (no payload) and no-op gracefully.
            $this->resolveLogger()?->warning('Provider relink conflict skipped', [
                'event' => 'subscriptions.relink_conflict_skipped',
                'tenant_uuid' => $tenantUuid,
                'existing_gateway' => $existingGateway,
                'existing_subscription_id' => $existingSubId,
                'incoming_gateway' => $gateway,
                'incoming_subscription_id' => $gwSubId,
            ]);

            return null;
        }

        $this->subscriptions->updateByTenant($this->context, $tenantUuid, [
            'payvia_gateway' => $gateway,
            'payvia_subscription_id' => $gwSubId,
        ]);

        return $this->subscriptions->findByTenant($this->context, $tenantUuid);
    }

    /**
     * The spec's projection mapping. Null means "type not handled here".
     *
     * @param array<string,mixed> $sub
     * @param array<string,mixed> $normalized
     * @return array<string,mixed>|null
     */
    private function computeChanges(string $type, array $sub, array $normalized): ?array
    {
        $currentStatus = (string) ($sub['status'] ?? '');

        switch ($type) {
            case 'subscription.created':
                $changes = [
                    'status' => $this->normalizedStatus($normalized) === 'trialing' ? 'trialing' : 'active',
                ];

                return $changes + $this->periodChanges($normalized);

            case 'subscription.updated':
                $changes = [];
                $status = $this->normalizedStatus($normalized);
                if ($status !== null) {
                    $changes['status'] = $status;
                    if ($status === 'active') {
                        $changes['grace_ends_at'] = null; // settled -> no stale grace
                    }
                }

                return $changes + $this->periodChanges($normalized);

            case 'subscription.past_due':
                return [
                    'status' => 'past_due',
                    'grace_ends_at' => $this->formatForDb(
                        new \DateTimeImmutable(sprintf('+%d days', $this->catalog->graceDays()))
                    ),
                ];

            case 'subscription.canceled':
                return [
                    'status' => 'canceled',
                    'canceled_at' => $this->formatForDb(new \DateTimeImmutable('now')),
                ];

            case 'payment.succeeded':
            case 'invoice.paid':
                if (in_array($currentStatus, self::SETTLEABLE, true)) {
                    return ['status' => 'active', 'grace_ends_at' => null] + $this->periodChanges($normalized);
                }

                return []; // nothing to project; the event is still claimed/recorded

            default:
                return null;
        }
    }

    /** @param array<string,mixed> $normalized */
    private function normalizedStatus(array $normalized): ?string
    {
        $status = $normalized['status'] ?? null;
        $status = is_scalar($status) ? strtolower((string) $status) : '';

        return in_array($status, self::KNOWN_STATUSES, true) ? $status : null;
    }

    /**
     * @param array<string,mixed> $normalized
     * @return array<string,mixed>
     */
    private function periodChanges(array $normalized): array
    {
        $value = $normalized['current_period_end'] ?? null;
        if (!is_scalar($value) || (string) $value === '') {
            return [];
        }

        try {
            return ['current_period_end' => $this->formatForDb(new \DateTimeImmutable((string) $value))];
        } catch (\Throwable) {
            return [];
        }
    }

    private function formatForDb(\DateTimeImmutable $dateTime): string
    {
        // The driver accepts \DateTime|string|null (not DateTimeImmutable).
        return db($this->context)->getDriver()->formatDateTime(\DateTime::createFromImmutable($dateTime));
    }
}
