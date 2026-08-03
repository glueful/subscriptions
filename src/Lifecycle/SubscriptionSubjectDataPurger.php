<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Lifecycle;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\QueryBuilder;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubjectType;

/**
 * Host-neutral subject/tenant data purge (spec §9). The extension owns the
 * mechanics; hosts own the decision to invoke it from their own tenancy/privacy
 * workflows. Nothing here runs automatically -- in particular, NO purge is ever
 * triggered merely because `SubjectResolverInterface::validate()` starts failing.
 *
 * `purgeSubject(Subject::user(...))` is the deleted-member privacy form: it removes
 * exactly that member's own subscription, overrides, and lifecycle events, plus any
 * `subscription_provider_event_receipts` row whose RESOLVED **or** CANDIDATE triple
 * names that exact subject. It never touches plans, a sibling user's rows, or the
 * workspace's own tenant-subject row.
 *
 * `purgeSubject(Subject::tenant(...))` is the hard workspace-deletion form: it
 * removes every subscription, override, event, and receipt (resolved-or-candidate)
 * under that `tenant_uuid` -- the workspace's own subject AND every one of its
 * members -- then removes only that workspace's OWN (`audience='user' AND
 * owner_tenant_uuid=<workspace>`) plans. Platform plans (`audience='tenant'`) and
 * every other workspace are never touched. Hard workspace-purge integrations MUST
 * use this form, never the user form repeated per member (a member added after
 * such a loop would survive it).
 *
 * Every call runs through {@see TenantIntegration::runAsSystemOr()} (spec §9): rows
 * must be located and removed BEFORE any tenant context can be established, exactly
 * like the projector's `project()`. Each form deletes inside ONE transaction, in a
 * fixed order (receipts, then events, then overrides, then the subscription row(s)
 * themselves, then -- tenant form only -- the workspace's member plans); no FOREIGN
 * KEY is declared anywhere in this schema, so nothing enforces this ordering, but
 * "children before the row they describe" is followed on purpose, mirroring
 * `CommerceTenantPurge`'s own discipline. Every predicate below is a plain equality
 * match rather than an existence-assuming mutation, so both forms are naturally
 * idempotent: a second call against an already-purged subject finds (and deletes)
 * nothing.
 *
 * {@see countSubjectRows()} is the non-mutating preview of the same operation: a
 * plain `SELECT COUNT(*)` per table, built from the EXACT SAME private predicate
 * methods `purgeSubject()` deletes with, so a count can never drift from what a
 * subsequent purge actually removes -- a host can show "this will delete N rows"
 * and trust the number.
 */
final class SubscriptionSubjectDataPurger
{
    private const SUBSCRIPTIONS = 'subscriptions';
    private const OVERRIDES = 'subscription_overrides';
    private const EVENTS = 'subscription_events';
    private const RECEIPTS = 'subscription_provider_event_receipts';
    private const PLANS = 'subscription_plans';

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /**
     * @return array<string,int> rows deleted, keyed by table name
     * @throws \InvalidArgumentException on an incoherent/empty subject (spec §4's
     *         coherence rule -- this purger does NOT itself call
     *         `SubjectResolverInterface::validate()`, so an empty tenant_uuid or
     *         subject_uuid would otherwise sweep every row that happens to share
     *         that same empty string rather than refusing outright).
     */
    public function purgeSubject(Subject $subject): array
    {
        $this->assertPurgeableSubject($subject);

        return TenantIntegration::runAsSystemOr(
            $this->context,
            fn (): array => $subject->type === SubjectType::TENANT
                ? $this->purgeTenant($subject->tenantUuid)
                : $this->purgeUser($subject)
        );
    }

    private function assertPurgeableSubject(Subject $subject): void
    {
        if ($subject->tenantUuid === '' || $subject->uuid === '') {
            throw new \InvalidArgumentException(
                'Refusing to purge a subject with an empty tenant_uuid or subject_uuid; '
                . 'purging it would be a programming error, not a real subject.'
            );
        }
    }

    /**
     * Non-mutating preview of `purgeSubject()`: a plain `SELECT COUNT(*)` per table
     * a subsequent purge of this exact subject would delete from -- built from the
     * SAME private predicate methods `purgeUser()`/`purgeTenant()` call, so counts
     * and deletes cannot drift apart. Keys mirror `purgeSubject()`'s return exactly
     * (`subscriptions`, `subscription_overrides`, `subscription_events`,
     * `subscription_provider_event_receipts`, plus `subscription_plans` for the
     * tenant form only). Runs through the same `runAsSystemOr()` wrap and the same
     * `assertPurgeableSubject()` guard as the mutating call -- an empty
     * tenant_uuid/subject_uuid is refused here too, for the identical reason: it
     * would otherwise happily COUNT every row sharing that empty string rather than
     * refusing outright.
     *
     * @return array<string,int> row counts, keyed by table name
     * @throws \InvalidArgumentException on an incoherent/empty subject
     */
    public function countSubjectRows(Subject $subject): array
    {
        $this->assertPurgeableSubject($subject);

        return TenantIntegration::runAsSystemOr(
            $this->context,
            fn (): array => $subject->type === SubjectType::TENANT
                ? $this->countTenant($subject->tenantUuid)
                : $this->countUser($subject)
        );
    }

    /** @return array<string,int> */
    private function purgeUser(Subject $subject): array
    {
        return db($this->context)->transaction(function () use ($subject): array {
            $counts = [];

            $counts[self::RECEIPTS] = $this->deleteReceiptsForSubject($subject);
            $counts[self::EVENTS] = (int) $this->eventsQuery($subject)->delete();
            $counts[self::OVERRIDES] = (int) $this->overridesQuery($subject)->delete();
            $counts[self::SUBSCRIPTIONS] = (int) $this->subscriptionsQuery($subject)->delete();

            return $counts;
        });
    }

    /** @return array<string,int> */
    private function purgeTenant(string $tenantUuid): array
    {
        return db($this->context)->transaction(function () use ($tenantUuid): array {
            $counts = [];

            $counts[self::RECEIPTS] = $this->deleteReceiptsForTenant($tenantUuid);
            $counts[self::EVENTS] = (int) $this->eventsQueryForTenant($tenantUuid)->delete();
            $counts[self::OVERRIDES] = (int) $this->overridesQueryForTenant($tenantUuid)->delete();
            $counts[self::SUBSCRIPTIONS] = (int) $this->subscriptionsQueryForTenant($tenantUuid)->delete();

            // Only the workspace's OWN member plans -- platform plans always carry
            // audience='tenant', so filtering on audience='user' alone already keeps
            // them safe even without the owner match, but both are asserted
            // explicitly (spec §9) rather than relying on that as an accident.
            $counts[self::PLANS] = (int) $this->plansQueryForTenant($tenantUuid)->delete();

            return $counts;
        });
    }

    /** @return array<string,int> */
    private function countUser(Subject $subject): array
    {
        return [
            self::RECEIPTS => $this->countReceiptsForSubject($subject),
            self::EVENTS => $this->eventsQuery($subject)->count(),
            self::OVERRIDES => $this->overridesQuery($subject)->count(),
            self::SUBSCRIPTIONS => $this->subscriptionsQuery($subject)->count(),
        ];
    }

    /** @return array<string,int> */
    private function countTenant(string $tenantUuid): array
    {
        return [
            self::RECEIPTS => $this->countReceiptsForTenant($tenantUuid),
            self::EVENTS => $this->eventsQueryForTenant($tenantUuid)->count(),
            self::OVERRIDES => $this->overridesQueryForTenant($tenantUuid)->count(),
            self::SUBSCRIPTIONS => $this->subscriptionsQueryForTenant($tenantUuid)->count(),
            self::PLANS => $this->plansQueryForTenant($tenantUuid)->count(),
        ];
    }

    // ===========================================
    // Shared per-table WHERE-builders -- purge (delete()/executeModification()) and
    // count (count()/executeRaw()) both call these, so the predicate that decides
    // what belongs to a subject/tenant is defined exactly once.
    // ===========================================

    private function subscriptionsQuery(Subject $subject): QueryBuilder
    {
        return $this->subjectScoped(self::SUBSCRIPTIONS, $subject);
    }

    private function eventsQuery(Subject $subject): QueryBuilder
    {
        return $this->subjectScoped(self::EVENTS, $subject);
    }

    private function overridesQuery(Subject $subject): QueryBuilder
    {
        return $this->subjectScoped(self::OVERRIDES, $subject);
    }

    private function subjectScoped(string $table, Subject $subject): QueryBuilder
    {
        return db($this->context)->table($table)
            ->where('tenant_uuid', '=', $subject->tenantUuid)
            ->where('subject_type', '=', $subject->type)
            ->where('subject_uuid', '=', $subject->uuid);
    }

    private function subscriptionsQueryForTenant(string $tenantUuid): QueryBuilder
    {
        return db($this->context)->table(self::SUBSCRIPTIONS)->where('tenant_uuid', '=', $tenantUuid);
    }

    private function eventsQueryForTenant(string $tenantUuid): QueryBuilder
    {
        return db($this->context)->table(self::EVENTS)->where('tenant_uuid', '=', $tenantUuid);
    }

    private function overridesQueryForTenant(string $tenantUuid): QueryBuilder
    {
        return db($this->context)->table(self::OVERRIDES)->where('tenant_uuid', '=', $tenantUuid);
    }

    private function plansQueryForTenant(string $tenantUuid): QueryBuilder
    {
        return db($this->context)->table(self::PLANS)
            ->where('audience', '=', SubjectType::USER)
            ->where('owner_tenant_uuid', '=', $tenantUuid);
    }

    /**
     * Resolved-or-candidate triple match for one subject: either the settled
     * identity columns (an accepted receipt) or the raw candidate columns (a
     * rejected/never-resolved receipt) name this exact (tenant_uuid, subject_type,
     * subject_uuid). The predicate/bindings are built once in
     * {@see receiptsPredicateForSubject()} and consumed here (DELETE, via
     * `executeModification()`) and by `countReceiptsForSubject()` (SELECT COUNT(*),
     * via `executeRaw()`) -- raw SQL rather than the query builder's `where()`
     * chain because the OR-of-two-triples shape it needs is not representable by
     * `delete()`'s condition array (it only supports AND-joined equality/comparison
     * predicates), mirroring CommerceTenantPurge's own child-table deletes.
     */
    private function deleteReceiptsForSubject(Subject $subject): int
    {
        $predicate = $this->receiptsPredicateForSubject($subject);

        return db($this->context)->table(self::RECEIPTS)->executeModification(
            'DELETE FROM ' . self::RECEIPTS . ' WHERE ' . $predicate['sql'],
            $predicate['bindings']
        );
    }

    private function countReceiptsForSubject(Subject $subject): int
    {
        $predicate = $this->receiptsPredicateForSubject($subject);

        $row = db($this->context)->table(self::RECEIPTS)->executeRawFirst(
            'SELECT COUNT(*) as count FROM ' . self::RECEIPTS . ' WHERE ' . $predicate['sql'],
            $predicate['bindings']
        );

        return (int) ($row['count'] ?? 0);
    }

    /** @return array{sql:string,bindings:list<mixed>} */
    private function receiptsPredicateForSubject(Subject $subject): array
    {
        return [
            'sql' => '(tenant_uuid = ? AND subject_type = ? AND subject_uuid = ?) OR '
                . '(candidate_tenant_uuid = ? AND candidate_subject_type = ? AND candidate_subject_uuid = ?)',
            'bindings' => [
                $subject->tenantUuid,
                $subject->type,
                $subject->uuid,
                $subject->tenantUuid,
                $subject->type,
                $subject->uuid,
            ],
        ];
    }

    /**
     * Resolved-or-candidate TENANT match only (no subject_type/subject_uuid filter):
     * a workspace purge sweeps every subject under that tenant, its own AND every
     * member's, so any receipt naming this tenant -- however its subject resolved --
     * goes with it. Same predicate shared by the delete and the count, for the same
     * reason as {@see receiptsPredicateForSubject()}.
     */
    private function deleteReceiptsForTenant(string $tenantUuid): int
    {
        $predicate = $this->receiptsPredicateForTenant($tenantUuid);

        return db($this->context)->table(self::RECEIPTS)->executeModification(
            'DELETE FROM ' . self::RECEIPTS . ' WHERE ' . $predicate['sql'],
            $predicate['bindings']
        );
    }

    private function countReceiptsForTenant(string $tenantUuid): int
    {
        $predicate = $this->receiptsPredicateForTenant($tenantUuid);

        $row = db($this->context)->table(self::RECEIPTS)->executeRawFirst(
            'SELECT COUNT(*) as count FROM ' . self::RECEIPTS . ' WHERE ' . $predicate['sql'],
            $predicate['bindings']
        );

        return (int) ($row['count'] ?? 0);
    }

    /** @return array{sql:string,bindings:list<mixed>} */
    private function receiptsPredicateForTenant(string $tenantUuid): array
    {
        return [
            'sql' => '(tenant_uuid = ?) OR (candidate_tenant_uuid = ?)',
            'bindings' => [$tenantUuid, $tenantUuid],
        ];
    }
}
