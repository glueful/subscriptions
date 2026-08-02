<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Projection;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface;
use Glueful\Extensions\Subscriptions\Contracts\SubscriptionEventProjectorInterface;
use Glueful\Extensions\Subscriptions\Repositories\ProviderEventReceiptRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubjectType;
use Glueful\Helpers\Utils;
use Psr\Log\LoggerInterface;

/**
 * Owns all provider-event projection rules: receipts-first claim/idempotency,
 * subject-triple validation on creation, subject cross-checks on later events,
 * tenant relink (unlinked-only, tenant subjects only), the status state machine,
 * period/grace handling. Provider-agnostic -- consumes a ProviderSubscriptionEvent DTO.
 *
 * Receipts-first idempotency (Task 10, spec §2/§8): the FIRST thing project() does
 * inside its one transaction is claim a `pending` row in
 * `subscription_provider_event_receipts` on (provider_gateway,
 * provider_logical_event_key) -- that unique index, not the subscription_events
 * insert, is the real idempotency gate. A duplicate/concurrent delivery that loses
 * the claim rolls the whole transaction back and never re-projects.
 *
 * Once claimed, resolution splits by DETERMINISM (spec ruling, fix round 2):
 * - Deterministic rejections (missing_subject, invalid_subject,
 *   plan_scope_mismatch, subject_mismatch) will fail the exact same way on
 *   every redelivery, so they are caught here as {@see RejectedProviderEventException},
 *   settle the receipt `rejected`, and COMMIT -- a rejection is a diagnosable
 *   outcome, not an error.
 * - "Unmapped" is NOT deterministic -- the local subscription may simply not
 *   exist YET -- so it is a {@see UnmappedProviderSubscriptionException}
 *   (retryable) that is left UNCAUGHT here: it propagates out of the
 *   transaction, rolling back the whole thing (the pending receipt claim
 *   included), and out of project() itself, so a caller that lets it surface
 *   as a retry-inducing webhook response gets a real retry once the local
 *   side catches up.
 * - Accepted events append the subscription_events row and the state-machine
 *   write, then `markAccepted()`, all atomically.
 * - Any OTHER genuine transient failure (not a unique violation, not one of
 *   the two exceptions above) also propagates out of the transaction
 *   uncaught, rolling everything back so the provider's retry of the same
 *   logical event can succeed later.
 */
final class SubscriptionEventProjector implements SubscriptionEventProjectorInterface
{
    private const SETTLEABLE = ['trialing', 'past_due'];
    private const KNOWN_STATUSES = ['active', 'trialing', 'past_due', 'canceled', 'incomplete', 'paused'];

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionEventRepository $events,
        private readonly ProviderEventReceiptRepository $receipts,
        private readonly PlanCatalog $catalog,
        private readonly ApplicationContext $context,
        private readonly SubjectResolverInterface $subjects,
    ) {
    }

    public function project(ProviderSubscriptionEvent $event): void
    {
        $gateway = $event->gateway;
        $type = $event->type;
        $logicalKey = $event->logicalEventKey;
        $normalized = $event->normalized;

        // Cheap read-side early-out ONLY -- the transactional claim below is the gate.
        if (
            $gateway !== ''
            && $logicalKey !== ''
            && $this->receipts->existsByLogicalKey($this->context, $gateway, $logicalKey)
        ) {
            return;
        }

        $receiptUuid = Utils::generateNanoID(12);
        $pendingRow = array_merge(
            [
                'uuid' => $receiptUuid,
                'provider_gateway' => $gateway,
                'provider_logical_event_key' => $logicalKey !== '' ? $logicalKey : null,
                'event_type' => $type,
                'data' => ProviderEventData::sanitize($normalized),
            ],
            $this->candidateIdentity($normalized)
        );

        try {
            db($this->context)->transaction(
                function () use ($receiptUuid, $pendingRow, $gateway, $type, $logicalKey, $normalized): void {
                    // (1) CLAIM -- throws on (provider_gateway, provider_logical_event_key) duplicate.
                    $this->receipts->insertPending($this->context, $pendingRow);

                    // (2) RESOLVE -- only the claim winner reaches here. A deterministic
                    // rejection is caught HERE (settles + commits); an unmapped
                    // subscription (RejectedProviderEventException's sibling,
                    // UnmappedProviderSubscriptionException) is NOT caught here and
                    // propagates out, rolling this whole transaction back.
                    try {
                        $sub = $this->resolveTarget($gateway, $type, $normalized);
                    } catch (RejectedProviderEventException $rejection) {
                        $this->receipts->markRejected($this->context, $receiptUuid, $rejection->rejectionCode);
                        return; // rejected receipts COMMIT -- nothing else changes.
                    }

                    // (3) PROJECT + ACCEPT, atomically.
                    $subject = $this->subjectOf($sub);
                    $changes = $this->computeChanges($type, $sub, $normalized) ?? [];
                    $from = isset($sub['status']) ? (string) $sub['status'] : null;
                    $to = isset($changes['status']) ? (string) $changes['status'] : $from;

                    $this->events->insertOrThrow($this->context, [
                        'tenant_uuid' => $subject->tenantUuid,
                        'subject_type' => $subject->type,
                        'subject_uuid' => $subject->uuid,
                        'type' => $type,
                        'from_status' => $from,
                        'to_status' => $to,
                        'source' => 'provider_event',
                        'provider_gateway' => $gateway !== '' ? $gateway : null,
                        'provider_logical_event_key' => $logicalKey !== '' ? $logicalKey : null,
                        'data' => ProviderEventData::sanitize($normalized),
                    ]);

                    if ($changes !== []) {
                        $this->subscriptions->updateBySubject($this->context, $subject, $changes);
                    }

                    $this->receipts->markAccepted($this->context, $receiptUuid, [
                        'tenant_uuid' => $subject->tenantUuid,
                        'subject_type' => $subject->type,
                        'subject_uuid' => $subject->uuid,
                        'plan_uuid' => $this->scalarOrNull($sub['plan_uuid'] ?? null),
                    ]);
                }
            );
        } catch (\Throwable $e) {
            if ($this->receipts->isUniqueViolation($e)) {
                // Observability on the swallow branch: a misclassified integrity
                // error would otherwise vanish silently. Debug-level by design.
                $this->resolveLogger()?->debug('Duplicate provider event claim skipped', [
                    'event' => 'subscriptions.duplicate_claim_skipped',
                    'provider_gateway' => $gateway,
                    'provider_logical_event_key' => $logicalKey,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);

                return; // a concurrent/duplicate delivery already owns this logical event
            }
            // Transient failure OR UnmappedProviderSubscriptionException: the whole
            // transaction rolled back (pending receipt included); propagate so the
            // caller can retry (unmapped) or surface the failure (transient).
            throw $e;
        }
    }

    /**
     * The raw, UNRESOLVED subject/plan hint straight off the provider's metadata --
     * stored on the receipt purely for diagnosis. Never trusted; resolveTarget()
     * is what actually establishes/validates identity.
     *
     * @param array<string,mixed> $normalized
     * @return array<string,mixed>
     */
    private function candidateIdentity(array $normalized): array
    {
        $metadata = is_array($normalized['metadata'] ?? null) ? $normalized['metadata'] : [];

        return [
            'candidate_tenant_uuid' => $this->scalarOrNull($metadata['tenant_uuid'] ?? null),
            'candidate_subject_type' => $this->scalarOrNull($metadata['subject_type'] ?? null),
            'candidate_subject_uuid' => $this->scalarOrNull($metadata['subject_uuid'] ?? null),
            'candidate_plan_uuid' => $this->scalarOrNull($metadata['plan_uuid'] ?? null),
        ];
    }

    /**
     * Locates the target subscription row and validates it's safe to project onto.
     *
     * - Already linked (found by provider_gateway/provider_subscription_id): any
     *   subject metadata present on the event is cross-checked against the row's
     *   stored triple; a mismatch throws RejectedProviderEventException('subject_mismatch').
     * - Not linked, non-created type: nothing to recover -> throws
     *   UnmappedProviderSubscriptionException (retryable).
     * - Not linked, `subscription.created`: attempted recovery via metadata,
     *   see recoverCreatedSubscription().
     *
     * @param array<string,mixed> $normalized
     * @return array<string,mixed> the resolved row.
     * @throws RejectedProviderEventException a deterministic, committed rejection.
     * @throws UnmappedProviderSubscriptionException retryable -- rolls the whole
     *         transaction back.
     */
    private function resolveTarget(string $gateway, string $type, array $normalized): array
    {
        $gwSubId = $this->scalarOrNull($normalized['gateway_subscription_id'] ?? null);

        $sub = ($gateway !== '' && $gwSubId !== null)
            ? $this->subscriptions->findByProviderSubscription($this->context, $gateway, $gwSubId)
            : null;

        if ($sub !== null) {
            $this->crossCheckMetadataSubject($sub, $normalized);

            return $sub;
        }

        if ($type !== 'subscription.created' || $gateway === '' || $gwSubId === null) {
            throw $this->unmapped();
        }

        return $this->recoverCreatedSubscription($gateway, $gwSubId, $normalized);
    }

    private function unmapped(): UnmappedProviderSubscriptionException
    {
        return new UnmappedProviderSubscriptionException(
            'Provider event does not map to any subscription yet; retry once the local '
            . 'subscription exists (or the relink target is unambiguous).'
        );
    }

    /**
     * Any subject field present in the event's metadata must agree with the
     * ALREADY-STORED triple on the row it maps to -- the row's own identity is
     * the trust anchor once linked, so metadata is only ever a corroborating
     * cross-check here, never a new source of trust (that's created-event
     * recovery's job, see recoverCreatedSubscription()).
     *
     * @param array<string,mixed> $sub
     * @param array<string,mixed> $normalized
     * @throws RejectedProviderEventException 'subject_mismatch' on disagreement.
     */
    private function crossCheckMetadataSubject(array $sub, array $normalized): void
    {
        $metadata = is_array($normalized['metadata'] ?? null) ? $normalized['metadata'] : [];

        $columns = ['tenant_uuid' => 'tenant_uuid', 'subject_type' => 'subject_type', 'subject_uuid' => 'subject_uuid'];
        foreach ($columns as $metaKey => $column) {
            // scalarOrNull() is the single definition of "not supplied" shared with
            // recoverCreatedSubscription() below -- an empty string or non-scalar
            // value is treated as ABSENT, not as an explicit empty claim to check.
            // Without this, a provider that echoes back an unset field as '' (or
            // null) would mismatch against any non-empty stored value and reject
            // every subsequent event for that subscription, permanently (the claim
            // is never retried).
            $given = $this->scalarOrNull($metadata[$metaKey] ?? null);
            if ($given === null) {
                continue;
            }

            $stored = isset($sub[$column]) ? (string) $sub[$column] : '';
            if ($given !== $stored) {
                throw new RejectedProviderEventException('subject_mismatch');
            }
        }
    }

    /**
     * The ONLY place a subscription.created event may establish trust in a subject
     * it hasn't already been linked to. Requires a complete triple (spec §2/§8):
     * tenant_uuid is mandatory; subject_type/subject_uuid are an ATOMIC PAIR --
     * mirroring SubscriptionEventRepository's own coherence rule -- so they may
     * BOTH be omitted for 1.x back-compat (defaults to the tenant self-subject),
     * but ONE present without the other is an incomplete triple, never a partial
     * default. Silently defaulting subject_type to 'tenant' while discarding a
     * caller-supplied subject_uuid would relink a WORKSPACE row using an event
     * that actually named a member -- a member's created event would then drive
     * workspace billing state. The resolved subject must then pass
     * SubjectResolverInterface::validate() -- the shipped DefaultSubjectResolver
     * rejects every user subject, so this recovery path is effectively tenant-only
     * out of the box, matching the 1.x relink recovery it supersedes.
     *
     * @param array<string,mixed> $normalized
     * @return array<string,mixed> the resolved (and now possibly relinked) row.
     * @throws RejectedProviderEventException missing_subject or invalid_subject.
     * @throws UnmappedProviderSubscriptionException retryable -- see relinkTenantSubscription().
     */
    private function recoverCreatedSubscription(string $gateway, string $gwSubId, array $normalized): array
    {
        $metadata = is_array($normalized['metadata'] ?? null) ? $normalized['metadata'] : [];

        $tenantUuid = $this->scalarOrNull($metadata['tenant_uuid'] ?? null) ?? '';
        if ($tenantUuid === '') {
            throw new RejectedProviderEventException('missing_subject');
        }

        $subjectType = $this->scalarOrNull($metadata['subject_type'] ?? null);
        $subjectUuid = $this->scalarOrNull($metadata['subject_uuid'] ?? null);

        if ($subjectType === null && $subjectUuid === null) {
            // 1.x shape: no subject fields at all -> a tenant self-subject.
            $subjectType = SubjectType::TENANT;
            $subjectUuid = $tenantUuid;
        } elseif ($subjectType === null || $subjectUuid === null) {
            // One of the pair given without the other: incomplete, never defaulted.
            throw new RejectedProviderEventException('missing_subject');
        }

        $subject = new Subject($tenantUuid, $subjectType, $subjectUuid);
        if (!$this->subjects->validate($this->context, $subject)) {
            throw new RejectedProviderEventException('invalid_subject');
        }

        // 1.x relink recovery survives ONLY for validated tenant subjects (spec's
        // scope for Task 10) -- a validated user subject has no recovery mechanism
        // here and is treated the same as any other unmapped subscription.
        if ($subject->type !== SubjectType::TENANT) {
            throw $this->unmapped();
        }

        return $this->relinkTenantSubscription($gateway, $gwSubId, $subject);
    }

    /**
     * The 1.x tenant-metadata relink recovery (unchanged rules, now gated on a
     * validated subject above): an UNLINKED row named by metadata's tenant_uuid may
     * be attached to this provider subscription. A row already linked to a
     * DIFFERENT provider subscription is NEVER moved -- refused and logged as an
     * anomaly, exactly as before.
     *
     * @return array<string,mixed>
     * @throws RejectedProviderEventException plan_scope_mismatch.
     * @throws UnmappedProviderSubscriptionException retryable: no row exists for
     *         this tenant (yet), or the row is linked to a different provider
     *         subscription and the link is refused.
     */
    private function relinkTenantSubscription(string $gateway, string $gwSubId, Subject $subject): array
    {
        $tenantUuid = $subject->tenantUuid;
        $existing = $this->subscriptions->findByTenant($this->context, $tenantUuid);
        if ($existing === null) {
            throw $this->unmapped();
        }

        $existingSubId = $this->scalarOrNull($existing['provider_subscription_id'] ?? null) ?? '';

        if ($existingSubId !== '') {
            $existingGateway = $this->scalarOrNull($existing['provider_gateway'] ?? null) ?? '';

            // Already linked to THIS exact (gateway, sub id): a no-op relink --
            // still gate it on plan-audience coherence before accepting.
            if ($existingGateway === $gateway && $existingSubId === $gwSubId) {
                return $this->requireCoherentPlan($existing, $subject);
            }

            // Already linked to a DIFFERENT provider subscription: refuse to move
            // the link. Log the anomaly (no payload) and reject gracefully.
            $this->resolveLogger()?->warning('Provider relink conflict skipped', [
                'event' => 'subscriptions.relink_conflict_skipped',
                'tenant_uuid' => $tenantUuid,
                'existing_gateway' => $existingGateway,
                'existing_subscription_id' => $existingSubId,
                'incoming_gateway' => $gateway,
                'incoming_subscription_id' => $gwSubId,
            ]);

            throw $this->unmapped();
        }

        $this->requireCoherentPlan($existing, $subject); // throws RejectedProviderEventException if not coherent

        $this->subscriptions->updateByTenant($this->context, $tenantUuid, [
            'provider_gateway' => $gateway,
            'provider_subscription_id' => $gwSubId,
        ]);

        $relinked = $this->subscriptions->findByTenant($this->context, $tenantUuid);
        if ($relinked === null) {
            // Invariant violation, not a normal outcome: the row we just updated,
            // inside this same transaction, must still be readable back.
            throw new \RuntimeException(
                "Subscription for tenant '{$tenantUuid}' vanished mid-transaction after relink update."
            );
        }

        return $relinked;
    }

    /**
     * Plan-audience coherence (spec §4, mirroring SubscriptionService::requireAssignablePlan()):
     * the row's plan must actually be assignable to the resolved subject's scope --
     * a tenant subject requires a platform ('tenant', '') plan. Guards against
     * relinking into a row whose plan is unresolvable or scoped to a different
     * audience/owner entirely.
     *
     * @param array<string,mixed> $sub
     * @return array<string,mixed> the given $sub, unchanged, when coherent.
     * @throws RejectedProviderEventException plan_scope_mismatch. NOTE: a
     *         genuinely transient plan-lookup failure (e.g. the plans table is
     *         momentarily unreachable) is NOT this -- PlanCatalog::planForUuid()
     *         no longer swallows \Throwable into a null "not found" result, so
     *         that kind of failure propagates past this method uncaught instead
     *         of being misreported as a deterministic, committed rejection.
     */
    private function requireCoherentPlan(array $sub, Subject $subject): array
    {
        $planUuid = $this->scalarOrNull($sub['plan_uuid'] ?? null);
        $plan = $planUuid !== null ? $this->catalog->planForUuid($planUuid) : null;

        [$audience, $owner] = $subject->type === SubjectType::USER
            ? [SubjectType::USER, $subject->tenantUuid]
            : [SubjectType::TENANT, ''];

        if (
            $plan === null
            || (string) ($plan['audience'] ?? '') !== $audience
            || (string) ($plan['owner_tenant_uuid'] ?? '') !== $owner
        ) {
            throw new RejectedProviderEventException('plan_scope_mismatch');
        }

        return $sub;
    }

    /**
     * The stored subject triple of a mapped subscription row. Read straight off the
     * row and never defaulted: since 2.0 the row itself is the authority on which
     * subject owns it, and re-deriving a missing value from tenant_uuid would file
     * the event under the wrong subject. A row that somehow lacks the triple
     * produces an empty one and is rejected at the event repository's coherence
     * boundary instead.
     *
     * @param array<string,mixed> $sub
     */
    private function subjectOf(array $sub): Subject
    {
        return new Subject(
            (string) ($sub['tenant_uuid'] ?? ''),
            (string) ($sub['subject_type'] ?? ''),
            (string) ($sub['subject_uuid'] ?? ''),
        );
    }

    private function scalarOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * Resolved DEFENSIVELY: the projector must never hard-depend on a logging
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
     * The spec's projection mapping. Null means "type not handled here"; the
     * caller intentionally coalesces null and [] to the same outcome (claim the
     * event, project no state change), so the distinction is informational only.
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
                // A late or replayed subscription.created (distinct logical key, so
                // idempotency does not suppress it) must never resurrect a terminal
                // canceled subscription. Record/claim the event but project nothing.
                if ($currentStatus === 'canceled') {
                    return [];
                }

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
