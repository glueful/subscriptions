<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Contracts\ProviderStatePullerInterface;
use Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface;
use Glueful\Extensions\Subscriptions\Lifecycle\TenantIntegration;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Repositories\UniqueViolations;
use Glueful\Helpers\Utils;

/**
 * Subject-aware subscription lifecycle (spec §7) -- works fully with NO payment
 * provider installed (free/trial/comp subscriptions never touch a payment object).
 *
 * The `…For(Subject …)` methods are the core. The 1.x tenant methods are
 * PRESERVED FACADES (not deprecated: they are the supported API for the
 * workspace-billing product) that delegate with `Subject::tenant($tenantUuid)`
 * and a platform-catalog planKey -> planUuid lookup.
 *
 * Every state change commits together with its lifecycle event in one
 * transaction (spec §8), and every write is gated on
 * SubjectResolverInterface::validate() plus a plan-audience match.
 *
 * Holds the ApplicationContext from construction, so methods take no $ctx.
 */
final class SubscriptionService
{
    // 'non_renewing' (design spec §4.3, Task 11) is added to the allowlist here in
    // Task 10 ONLY so fixtures can seed it directly -- reserveCheckoutFor()'s own
    // already_subscribed guard checks for it explicitly (see guardAgainstEntitledSubject()
    // below); the rest of the vocabulary (grace/entitlement semantics) lands in Task 11.
    private const KNOWN_STATUSES = [
        'active', 'trialing', 'past_due', 'canceled', 'incomplete', 'paused', 'non_renewing',
    ];

    /** Bulk-read batch bound (spec §6.1), measured POST-normalization/dedup. */
    public const MAX_TENANT_BATCH = 100;

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionEventRepository $events,
        private readonly PlanCatalog $catalog,
        private readonly ApplicationContext $context,
        private readonly SubjectResolverInterface $subjects,
        private readonly ?ProviderStatePullerInterface $puller = null,
    ) {
    }

    // ===========================================
    // Subject-aware core (spec §7)
    // ===========================================

    /** @return array<string,mixed>|null */
    public function currentFor(Subject $subject): ?array
    {
        $this->assertValidSubject($subject);

        return TenantIntegration::runAsTenantOr(
            $this->context,
            $subject->tenantUuid,
            fn (): ?array => $this->subscriptions->findBySubject($this->context, $subject)
        );
    }

    /**
     * Bulk trusted administrative projection (spec §6.1): unlike `currentFor()`,
     * this does NOT call `SubjectResolverInterface::validate()` once per UUID,
     * because that would recreate the N+1 through host existence checks. Its
     * contract requires a normalized, deduplicated list obtained from the host's
     * authoritative tenant directory AFTER platform authorization -- it must
     * never be mounted directly as an HTTP batch-by-UUID endpoint. The
     * repository call runs inside `TenantIntegration::runAsSystemOr()` so that
     * tenancy interception cannot narrow the administrative projection down to
     * whatever tenant happens to be ambient.
     *
     * Input is normalized (string-cast, trimmed, empties dropped, deduped)
     * BEFORE the `MAX_TENANT_BATCH` bound is measured and BEFORE any query is
     * issued -- the bound is checked against the normalized/deduplicated count,
     * not the raw input length, so `['t-1', 't-1', ..., 't-1']` (101 copies of
     * the same UUID) is one tenant, not a rejected batch.
     *
     * One query total, `WHERE subject_type='tenant' AND tenant_uuid IN (...)`,
     * regardless of how many UUIDs are requested (up to the bound).
     *
     * @param list<string> $tenantUuids
     * @return array<string,array<string,mixed>> keyed by tenant UUID; an absent
     *         key means that tenant has no subscription (never a null value)
     */
    public function currentForTenants(array $tenantUuids): array
    {
        $normalized = [];
        foreach ($tenantUuids as $uuid) {
            $uuid = trim((string) $uuid);
            if ($uuid !== '') {
                $normalized[$uuid] = true;
            }
        }

        if (count($normalized) > self::MAX_TENANT_BATCH) {
            throw new \InvalidArgumentException(sprintf(
                'At most %d tenant UUIDs may be read at once.',
                self::MAX_TENANT_BATCH
            ));
        }

        if ($normalized === []) {
            return [];
        }

        $rows = TenantIntegration::runAsSystemOr(
            $this->context,
            fn (): array => $this->subscriptions->findTenantSubjectsAmong($this->context, array_keys($normalized)),
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['tenant_uuid']] = $row;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $opts status, trial_ends_at, current_period_end, provider_* keys, metadata
     * @return array<string,mixed>
     * @throws SubscriptionConflictException when a concurrent caller won the subject
     *         unique with a DIFFERENT plan (spec §8); this call then changes nothing.
     */
    public function startFor(Subject $subject, string $planUuid, array $opts = []): array
    {
        $this->assertValidSubject($subject);
        $plan = $this->requireAssignablePlan($subject, $planUuid);

        $status = (string) ($opts['status'] ?? 'active');
        $planKey = (string) ($plan['plan_key'] ?? '');

        $row = [
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $subject->tenantUuid,
            'subject_type' => $subject->type,
            'subject_uuid' => $subject->uuid,
            // Both references are written: plan_uuid is authoritative, plan_key is
            // the denormalized copy read off the plan row (never off the caller).
            'plan_uuid' => $planUuid,
            'plan_key' => $planKey,
            'status' => $status,
            'trial_ends_at' => $opts['trial_ends_at'] ?? null,
            'current_period_end' => $opts['current_period_end'] ?? null,
            'provider_gateway' => $opts['provider_gateway'] ?? null,
            'provider_customer_id' => $opts['provider_customer_id'] ?? null,
            'provider_subscription_id' => $opts['provider_subscription_id'] ?? null,
            'provider_price_id' => $opts['provider_price_id']
                ?? $this->stringOrNull($plan['provider_price_id'] ?? null),
            'metadata' => $opts['metadata'] ?? null,
        ];

        return TenantIntegration::runAsTenantOr(
            $this->context,
            $subject->tenantUuid,
            function () use ($row, $subject, $planUuid, $planKey, $status): array {
                $winner = db($this->context)->transaction(
                    function () use ($row, $subject, $planUuid, $planKey, $status): ?array {
                        try {
                            // NESTED transaction => SAVEPOINT (spec §8): a unique violation
                            // rolls back only to the savepoint, so the surrounding
                            // transaction stays usable and the re-read below can run --
                            // on PostgreSQL an un-isolated violation would poison it.
                            db($this->context)->transaction(function () use ($row): void {
                                $this->subscriptions->insert($this->context, $row);
                            });
                        } catch (\Throwable $e) {
                            if (!UniqueViolations::isUniqueViolation($e)) {
                                throw $e;
                            }

                            return $this->resolveLostRace($subject, $planUuid, $e);
                        }

                        $this->appendEvent($subject, [
                            'type' => 'created',
                            'from_status' => null,
                            'to_status' => $status,
                            'source' => 'manual',
                            'data' => ['plan_key' => $planKey],
                        ]);

                        return null;
                    }
                );

                return $winner ?? $this->requireCurrentFor($subject);
            }
        );
    }

    /** @return array<string,mixed> */
    public function changePlanFor(Subject $subject, string $planUuid): array
    {
        $this->assertValidSubject($subject);
        $plan = $this->requireAssignablePlan($subject, $planUuid);
        $planKey = (string) ($plan['plan_key'] ?? '');

        return TenantIntegration::runAsTenantOr(
            $this->context,
            $subject->tenantUuid,
            function () use ($subject, $planUuid, $plan, $planKey): array {
                db($this->context)->transaction(function () use ($subject, $planUuid, $plan, $planKey): void {
                    $current = $this->requireCurrentFor($subject);
                    $fromPlan = (string) ($current['plan_key'] ?? '');

                    $this->subscriptions->updateBySubject($this->context, $subject, [
                        'plan_uuid' => $planUuid,
                        'plan_key' => $planKey,
                        'provider_price_id' => $this->stringOrNull($plan['provider_price_id'] ?? null),
                    ]);

                    $this->appendEvent($subject, [
                        'type' => 'plan_changed',
                        'from_status' => $current['status'] ?? null,
                        'to_status' => $current['status'] ?? null,
                        'source' => 'manual',
                        'data' => ['from_plan' => $fromPlan, 'to_plan' => $planKey],
                    ]);
                });

                return $this->requireCurrentFor($subject);
            }
        );
    }

    /** @return array<string,mixed> */
    public function cancelFor(Subject $subject, bool $atPeriodEnd = true): array
    {
        $this->assertValidSubject($subject);

        return TenantIntegration::runAsTenantOr(
            $this->context,
            $subject->tenantUuid,
            function () use ($subject, $atPeriodEnd): array {
                db($this->context)->transaction(function () use ($subject, $atPeriodEnd): void {
                    $current = $this->requireCurrentFor($subject);
                    $fromStatus = (string) ($current['status'] ?? '');

                    if ($atPeriodEnd) {
                        // Keep the status until the period ends -- just flag the intent.
                        $metadata = $this->decodeMetadata($current['metadata'] ?? null);
                        $metadata['cancel_at_period_end'] = true;

                        $this->subscriptions->updateBySubject($this->context, $subject, ['metadata' => $metadata]);
                        $toStatus = $fromStatus;
                    } else {
                        $this->subscriptions->updateBySubject($this->context, $subject, [
                            'status' => 'canceled',
                            'canceled_at' => $this->now(),
                        ]);
                        $toStatus = 'canceled';
                    }

                    $this->appendEvent($subject, [
                        'type' => 'canceled',
                        'from_status' => $fromStatus,
                        'to_status' => $toStatus,
                        'source' => 'manual',
                        'data' => ['at_period_end' => $atPeriodEnd],
                    ]);
                });

                return $this->requireCurrentFor($subject);
            }
        );
    }

    /**
     * Pull authoritative provider state and re-derive local status (S8).
     *
     * The provider is a SOFT dependency: with no puller injected this is a
     * safe no-op returning the current row. Drift (status/period) is applied
     * and recorded as a `reconciled` event (source `reconcile`, NULL logical
     * key -- multiple NULLs are allowed) in ONE transaction.
     *
     * @return array<string,mixed>|null
     */
    public function reconcileFor(Subject $subject): ?array
    {
        $current = $this->currentFor($subject);
        if ($current === null) {
            return null;
        }

        $gateway = (string) ($current['provider_gateway'] ?? '');
        $gwSubId = (string) ($current['provider_subscription_id'] ?? '');
        if ($gwSubId === '') {
            return $current; // free/comp -- nothing to pull
        }

        $state = $this->pullProviderState($gateway, $gwSubId);
        if ($state === null) {
            return $current; // no puller, or provider unreachable -> no-op
        }

        $changes = $this->driftChanges($current, $state);
        if ($changes === []) {
            return $current; // in sync -- no drift, no event
        }

        $fromStatus = (string) ($current['status'] ?? '');
        $toStatus = (string) ($changes['status'] ?? $fromStatus);

        return TenantIntegration::runAsTenantOr(
            $this->context,
            $subject->tenantUuid,
            function () use ($subject, $changes, $fromStatus, $toStatus, $gateway, $state): ?array {
                db($this->context)->transaction(
                    function () use ($subject, $changes, $fromStatus, $toStatus, $gateway, $state): void {
                        $this->subscriptions->updateBySubject($this->context, $subject, $changes);

                        $this->appendEvent($subject, [
                            'type' => 'reconciled',
                            'from_status' => $fromStatus,
                            'to_status' => $toStatus,
                            'source' => 'reconcile',
                            'provider_gateway' => $gateway !== '' ? $gateway : null,
                            'provider_logical_event_key' => null,
                            'data' => $state,
                        ]);
                    }
                );

                return $this->subscriptions->findBySubject($this->context, $subject);
            }
        );
    }

    /**
     * The origination-bound checkout reservation seam (design spec §4.1, Task 10):
     * the ONLY way self-serve checkout may create a local `status = 'incomplete'`
     * row for the projector to later relink a provider subscription onto --
     * `SubscriptionEventProjector` only relinks EXISTING rows, it never creates one.
     *
     * Always attempts the insert FIRST (optimistic, mirroring `startFor()`'s own
     * insert-then-resolve shape) rather than reading `findBySubject()` up front:
     * `uniq_subscriptions_subject` guarantees the insert fails with a unique
     * violation whenever a row already exists for this subject, so EVERY "already
     * has a row" case -- idempotent replay, an ad-hoc replace attempt, a flagged
     * replace, a genuinely concurrent two-writer race, or an already_subscribed
     * refusal -- flows through the exact same `resolveLostReservationRace()`
     * decision, instead of duplicating it behind a separate up-front read.
     *
     * `$opts['actor']` (nullable) is audit-stamped into the `checkout_reserved`
     * event's `data.actor` -- there is no dedicated `actor` column on
     * `subscription_events` (mirrors how `startFor()`/`changePlanFor()` fold their
     * own extra context into `data` rather than adding columns).
     *
     * @param array<string,mixed> $opts 'replace' (bool, default false), 'actor' (mixed, default null)
     * @return array<string,mixed>
     * @throws CheckoutReservationException 'already_subscribed' when the subject already
     *         has an entitling subscription (active/trialing/past_due, or an unexpired
     *         non_renewing row); 'checkout_reservation_replace_refused' when an existing
     *         `incomplete` reservation differs by origination/plan and $opts['replace']
     *         was not passed as true.
     */
    public function reserveCheckoutFor(
        Subject $subject,
        string $planUuid,
        string $originationUuid,
        array $opts = [],
    ): array {
        $this->assertValidSubject($subject);

        $originationUuid = trim($originationUuid);
        if ($originationUuid === '') {
            throw new \InvalidArgumentException('reserveCheckoutFor() requires a non-empty origination UUID.');
        }

        $plan = $this->requireAssignablePlan($subject, $planUuid);
        $planKey = (string) ($plan['plan_key'] ?? '');
        $replace = (bool) ($opts['replace'] ?? false);
        $actor = $opts['actor'] ?? null;

        $row = array_merge(
            [
                'uuid' => Utils::generateNanoID(12),
                'tenant_uuid' => $subject->tenantUuid,
                'subject_type' => $subject->type,
                'subject_uuid' => $subject->uuid,
            ],
            $this->reservationChanges($planUuid, $planKey, $originationUuid)
        );

        return TenantIntegration::runAsTenantOr(
            $this->context,
            $subject->tenantUuid,
            function () use ($row, $subject, $planUuid, $planKey, $originationUuid, $replace, $actor): array {
                $winner = db($this->context)->transaction(
                    function () use (
                        $row,
                        $subject,
                        $planUuid,
                        $planKey,
                        $originationUuid,
                        $replace,
                        $actor
                    ): ?array {
                        try {
                            // NESTED transaction => SAVEPOINT, exactly like startFor(): a unique
                            // violation rolls back only to the savepoint so the surrounding
                            // transaction stays usable for the re-read/decision below.
                            db($this->context)->transaction(function () use ($row): void {
                                $this->subscriptions->insert($this->context, $row);
                            });
                        } catch (\Throwable $e) {
                            if (!UniqueViolations::isUniqueViolation($e)) {
                                throw $e;
                            }

                            return $this->resolveLostReservationRace(
                                $subject,
                                $planUuid,
                                $planKey,
                                $originationUuid,
                                $replace,
                                $actor,
                                $e
                            );
                        }

                        $this->recordReservationEvent($subject, null, $originationUuid, $planKey, $actor);

                        return null;
                    }
                );

                return $winner ?? $this->requireCurrentFor($subject);
            }
        );
    }

    /**
     * Reservation cleanup (design spec §4.1, Task 10): releases a reservation whose
     * origination reached a terminal, non-dispatched state (e.g. Payvia's
     * `failed`/`expired`/`abandoned`, or an operator resolution). A pure
     * compare-and-delete guarded by the exact origination, `status = 'incomplete'`,
     * and no provider field present.
     *
     * @return bool true when a row was actually deleted; false when nothing matched.
     *         `false` is deliberately overloaded -- it means EITHER "no such
     *         reservation exists" (already released, wrong origination, no row at
     *         all) OR "refused: the row carries provider fields" (the projector has
     *         since settled it -- releasing it now would delete a real subscription).
     *         This method does not distinguish the two cases itself (that would be a
     *         second, TOCTOU-prone read outside the CAS), so callers that need to
     *         react differently -- Payvia's `CheckoutReconciliationService`
     *         continuation, Thallo's abandon flow -- must branch on the boolean
     *         result of THIS call, never re-query state before/after to infer which
     *         `false` case occurred.
     */
    public function releaseCheckoutReservation(Subject $subject, string $originationUuid): bool
    {
        $this->assertValidSubject($subject);

        return TenantIntegration::runAsTenantOr(
            $this->context,
            $subject->tenantUuid,
            fn (): bool => db($this->context)->transaction(
                fn (): bool => $this->subscriptions->deleteIncompleteReservation(
                    $this->context,
                    $subject,
                    $originationUuid
                ) > 0
            )
        );
    }

    // ===========================================
    // Preserved 1.x tenant facade (spec §7)
    // ===========================================

    /** @return array<string,mixed>|null */
    public function current(string $tenantUuid): ?array
    {
        return $this->currentFor(Subject::tenant($tenantUuid));
    }

    /**
     * @param array<string,mixed> $opts status, trial_ends_at, current_period_end, provider_* keys, metadata
     * @return array<string,mixed>
     */
    public function start(string $tenantUuid, string $planKey, array $opts = []): array
    {
        return $this->startFor(Subject::tenant($tenantUuid), $this->platformPlanUuid($planKey), $opts);
    }

    /** @return array<string,mixed> */
    public function changePlan(string $tenantUuid, string $planKey): array
    {
        return $this->changePlanFor(Subject::tenant($tenantUuid), $this->platformPlanUuid($planKey));
    }

    /** @return array<string,mixed> */
    public function cancel(string $tenantUuid, bool $atPeriodEnd = true): array
    {
        return $this->cancelFor(Subject::tenant($tenantUuid), $atPeriodEnd);
    }

    /** @return array<string,mixed>|null */
    public function reconcile(string $tenantUuid): ?array
    {
        return $this->reconcileFor(Subject::tenant($tenantUuid));
    }

    // ===========================================
    // Internals
    // ===========================================

    /**
     * planKey -> planUuid in the platform catalog (spec §7). An unknown key can
     * never be assignable, so it fails with the 1.x message rather than a
     * uuid-shaped one the facade's callers have never seen.
     */
    private function platformPlanUuid(string $planKey): string
    {
        $uuid = $this->catalog->planUuidForKey($planKey);
        if ($uuid === null) {
            throw new \InvalidArgumentException("Plan '{$planKey}' is not assignable.");
        }

        return $uuid;
    }

    private function assertValidSubject(Subject $subject): void
    {
        if (!$this->subjects->validate($this->context, $subject)) {
            throw new \InvalidArgumentException('invalid subject');
        }
    }

    /**
     * Assignability + subject/plan audience match (spec §4): a `tenant` subject may
     * only hold a platform plan ('tenant', ''); a `user` subject only an
     * `audience='user'` plan owned by that same workspace.
     *
     * @return array<string,mixed>
     */
    private function requireAssignablePlan(Subject $subject, string $planUuid): array
    {
        $plan = $this->catalog->planForUuid($planUuid);
        if ($plan === null || ($plan['status'] ?? null) !== 'active') {
            $label = $plan !== null ? (string) ($plan['plan_key'] ?? $planUuid) : $planUuid;
            throw new \InvalidArgumentException("Plan '{$label}' is not assignable.");
        }

        [$audience, $owner] = $subject->type === SubjectType::USER
            ? [SubjectType::USER, $subject->tenantUuid]
            : [SubjectType::TENANT, ''];

        if (
            (string) ($plan['audience'] ?? '') !== $audience
            || (string) ($plan['owner_tenant_uuid'] ?? '') !== $owner
        ) {
            throw new \InvalidArgumentException(sprintf(
                "Plan '%s' (audience '%s', owner '%s') is not available to subject '%s:%s' in tenant '%s'.",
                (string) ($plan['plan_key'] ?? $planUuid),
                (string) ($plan['audience'] ?? ''),
                (string) ($plan['owner_tenant_uuid'] ?? ''),
                $subject->type,
                $subject->uuid,
                $subject->tenantUuid
            ));
        }

        return $plan;
    }

    /**
     * A lost insert race: re-read the winner through the SAME subject triple.
     * Same plan_uuid -> return it idempotently; a different plan_uuid -> stable
     * domain conflict (spec §8). A unique violation that is NOT the subject
     * unique (e.g. the provider-subscription unique) finds no winner row and is
     * rethrown untouched rather than being misreported as a subject conflict.
     *
     * @return array<string,mixed>
     */
    private function resolveLostRace(Subject $subject, string $planUuid, \Throwable $violation): array
    {
        $winner = $this->subscriptions->findBySubject($this->context, $subject);
        if ($winner === null) {
            throw $violation;
        }

        $winningPlanUuid = (string) ($winner['plan_uuid'] ?? '');
        if ($winningPlanUuid !== $planUuid) {
            throw SubscriptionConflictException::forSubject($subject, $planUuid, $winningPlanUuid);
        }

        return $winner;
    }

    /**
     * `reserveCheckoutFor()`'s lost-insert-race resolution (design spec §4.1): re-reads
     * the row that now exists for this subject and applies the SAME decision every
     * caller must go through, whether the row got there via a genuinely concurrent
     * writer or a prior sequential call:
     *  1. already_subscribed guard first -- an entitling row is never touched.
     *  2. same origination + same plan -> idempotent no-op, returns the row unchanged.
     *  3. an `incomplete` row on a DIFFERENT origination/plan without `$replace` ->
     *     refused (this is the two-writer race the spec calls out: the losing plan
     *     request must never silently steal the reservation).
     *  4. otherwise (a non-entitling, non-`incomplete` row -- canceled, or an expired
     *     non_renewing row -- OR an `incomplete` row with `$replace = true`) -> the
     *     row is overwritten onto the new reservation.
     *
     * @return array<string,mixed>
     * @throws CheckoutReservationException see reserveCheckoutFor()'s own docblock.
     */
    private function resolveLostReservationRace(
        Subject $subject,
        string $planUuid,
        string $planKey,
        string $originationUuid,
        bool $replace,
        mixed $actor,
        \Throwable $violation
    ): array {
        $current = $this->subscriptions->findBySubject($this->context, $subject);
        if ($current === null) {
            // The unique violation was not the subject unique (e.g. the provider-
            // subscription unique) -- there is no winner row to reconcile against,
            // so this is a genuine unexpected error, not a reservation race.
            throw $violation;
        }

        $this->guardAgainstEntitledSubject($subject, $current);

        if ($this->isSameReservation($current, $planUuid, $originationUuid)) {
            return $current;
        }

        if ((string) ($current['status'] ?? '') === 'incomplete' && !$replace) {
            throw CheckoutReservationException::replaceRequiresFlag($subject);
        }

        $this->subscriptions->updateBySubject(
            $this->context,
            $subject,
            $this->reservationChanges($planUuid, $planKey, $originationUuid)
        );

        $fromStatus = (string) ($current['status'] ?? '');
        $this->recordReservationEvent($subject, $fromStatus, $originationUuid, $planKey, $actor);

        return $this->requireCurrentFor($subject);
    }

    /**
     * `already_subscribed` guard (design spec §4.1): active/trialing/past_due are
     * always entitling. `non_renewing` is entitling ONLY while its period end is
     * still in the future -- an expired one is non-entitling and never refused here.
     * Every other status (incomplete, canceled, paused, unknown) is non-entitling and
     * falls through.
     *
     * @param array<string,mixed> $current
     */
    private function guardAgainstEntitledSubject(Subject $subject, array $current): void
    {
        $status = (string) ($current['status'] ?? '');

        if (in_array($status, ['active', 'trialing', 'past_due'], true)) {
            throw CheckoutReservationException::alreadySubscribed($subject);
        }

        if ($status === 'non_renewing' && $this->periodEndInFuture($current['current_period_end'] ?? null)) {
            throw CheckoutReservationException::alreadySubscribed($subject);
        }
    }

    /**
     * Mirrors EffectivePlanResolver::withinGrace()'s parse-defensively shape: an
     * absent/unparseable period end is treated as NOT in the future -- i.e. NOT
     * blocking -- rather than raising or fail-closing the other way, matching the
     * spec's literal "current_period_end in future" test.
     */
    private function periodEndInFuture(mixed $periodEnd): bool
    {
        if (!is_scalar($periodEnd) || (string) $periodEnd === '') {
            return false;
        }

        try {
            return new \DateTimeImmutable((string) $periodEnd) > new \DateTimeImmutable('now');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $current
     */
    private function isSameReservation(array $current, string $planUuid, string $originationUuid): bool
    {
        return (string) ($current['status'] ?? '') === 'incomplete'
            && (string) ($current['plan_uuid'] ?? '') === $planUuid
            && (string) ($current['checkout_origination_uuid'] ?? '') === $originationUuid;
    }

    /**
     * The reservation row shape (design spec §4.1): NON-ENTITLING, no provider
     * fields, no trial/period/cancellation timestamps carried over from whatever the
     * row previously represented (a prior incomplete reservation, a canceled
     * subscription, or an expired non_renewing one).
     *
     * @return array<string,mixed>
     */
    private function reservationChanges(string $planUuid, string $planKey, string $originationUuid): array
    {
        return [
            'plan_uuid' => $planUuid,
            'plan_key' => $planKey,
            'status' => 'incomplete',
            'checkout_origination_uuid' => $originationUuid,
            'provider_gateway' => null,
            'provider_customer_id' => null,
            'provider_subscription_id' => null,
            'provider_price_id' => null,
            'trial_ends_at' => null,
            'current_period_end' => null,
            'canceled_at' => null,
            'grace_ends_at' => null,
        ];
    }

    private function recordReservationEvent(
        Subject $subject,
        ?string $fromStatus,
        string $originationUuid,
        string $planKey,
        mixed $actor
    ): void {
        $data = ['origination_uuid' => $originationUuid, 'plan_key' => $planKey];
        if ($actor !== null) {
            $data['actor'] = $actor;
        }

        $this->appendEvent($subject, [
            'type' => 'checkout_reserved',
            'from_status' => $fromStatus,
            'to_status' => 'incomplete',
            'source' => 'checkout_reservation',
            'data' => $data,
        ]);
    }

    /**
     * Every lifecycle event carries the subject triple explicitly -- the repository's
     * transitional tenant-only derivation is gone as of the 2.0 activation.
     *
     * @param array<string,mixed> $event
     */
    private function appendEvent(Subject $subject, array $event): void
    {
        $this->events->insertOrThrow($this->context, array_merge([
            'tenant_uuid' => $subject->tenantUuid,
            'subject_type' => $subject->type,
            'subject_uuid' => $subject->uuid,
        ], $event));
    }

    /**
     * Resolve authoritative provider state through the injected puller seam.
     * Absent a puller (no provider installed), this is a safe null no-op.
     *
     * @return array<string,mixed>|null
     */
    private function pullProviderState(string $gateway, string $providerSubscriptionId): ?array
    {
        return $this->puller?->pull($gateway, $providerSubscriptionId);
    }

    /**
     * Diff the authoritative provider state against the local row.
     *
     * @param array<string,mixed> $current
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function driftChanges(array $current, array $state): array
    {
        $changes = [];

        $status = $state['status'] ?? null;
        $status = is_scalar($status) ? strtolower((string) $status) : '';
        if (in_array($status, self::KNOWN_STATUSES, true) && $status !== (string) ($current['status'] ?? '')) {
            $changes['status'] = $status;
            if ($status === 'active') {
                $changes['grace_ends_at'] = null; // settled -> no stale grace
            }
            if ($status === 'past_due') {
                // Entering past_due grants the SAME dunning grace as the webhook
                // path (SubscriptionEventProjector). Already-past_due rows never
                // reach this branch (status unchanged), so grace is never re-extended.
                $changes['grace_ends_at'] = $this->formatForDb(
                    new \DateTimeImmutable(sprintf('+%d days', $this->catalog->graceDays()))
                );
            }
        }

        $periodEnd = $state['current_period_end'] ?? null;
        if (
            is_scalar($periodEnd)
            && (string) $periodEnd !== ''
            && (string) $periodEnd !== (string) ($current['current_period_end'] ?? '')
        ) {
            $changes['current_period_end'] = (string) $periodEnd;
        }

        return $changes;
    }

    /** @return array<string,mixed> */
    private function requireCurrentFor(Subject $subject): array
    {
        $row = $this->subscriptions->findBySubject($this->context, $subject);
        if ($row === null) {
            if ($subject->type === SubjectType::TENANT) {
                throw new \RuntimeException("No subscription for tenant '{$subject->tenantUuid}'.");
            }

            throw new \RuntimeException(
                "No subscription for subject '{$subject->type}:{$subject->uuid}' "
                . "in tenant '{$subject->tenantUuid}'."
            );
        }

        return $row;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** @return array<string,mixed> */
    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }
        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function now(): string
    {
        return db($this->context)->getDriver()->formatDateTime();
    }

    private function formatForDb(\DateTimeImmutable $dateTime): string
    {
        // The driver accepts \DateTime|string|null (not DateTimeImmutable).
        return db($this->context)->getDriver()->formatDateTime(\DateTime::createFromImmutable($dateTime));
    }
}
