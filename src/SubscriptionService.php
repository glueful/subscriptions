<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Contracts\ProviderStatePullerInterface;
use Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface;
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
    private const KNOWN_STATUSES = ['active', 'trialing', 'past_due', 'canceled', 'incomplete', 'paused'];

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

        return $this->subscriptions->findBySubject($this->context, $subject);
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

    /** @return array<string,mixed> */
    public function changePlanFor(Subject $subject, string $planUuid): array
    {
        $this->assertValidSubject($subject);
        $plan = $this->requireAssignablePlan($subject, $planUuid);
        $planKey = (string) ($plan['plan_key'] ?? '');

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

    /** @return array<string,mixed> */
    public function cancelFor(Subject $subject, bool $atPeriodEnd = true): array
    {
        $this->assertValidSubject($subject);

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

        return $this->currentFor($subject);
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
