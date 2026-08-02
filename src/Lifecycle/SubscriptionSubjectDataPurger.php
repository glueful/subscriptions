<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Lifecycle;

use Glueful\Bootstrap\ApplicationContext;
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

    /** @return array<string,int> rows deleted, keyed by table name */
    public function purgeSubject(Subject $subject): array
    {
        return TenantIntegration::runAsSystemOr(
            $this->context,
            fn (): array => $subject->type === SubjectType::TENANT
                ? $this->purgeTenant($subject->tenantUuid)
                : $this->purgeUser($subject)
        );
    }

    /** @return array<string,int> */
    private function purgeUser(Subject $subject): array
    {
        return db($this->context)->transaction(function () use ($subject): array {
            $counts = [];

            $counts[self::RECEIPTS] = $this->deleteReceiptsForSubject($subject);

            $counts[self::EVENTS] = (int) db($this->context)->table(self::EVENTS)
                ->where('tenant_uuid', '=', $subject->tenantUuid)
                ->where('subject_type', '=', $subject->type)
                ->where('subject_uuid', '=', $subject->uuid)
                ->delete();

            $counts[self::OVERRIDES] = (int) db($this->context)->table(self::OVERRIDES)
                ->where('tenant_uuid', '=', $subject->tenantUuid)
                ->where('subject_type', '=', $subject->type)
                ->where('subject_uuid', '=', $subject->uuid)
                ->delete();

            $counts[self::SUBSCRIPTIONS] = (int) db($this->context)->table(self::SUBSCRIPTIONS)
                ->where('tenant_uuid', '=', $subject->tenantUuid)
                ->where('subject_type', '=', $subject->type)
                ->where('subject_uuid', '=', $subject->uuid)
                ->delete();

            return $counts;
        });
    }

    /** @return array<string,int> */
    private function purgeTenant(string $tenantUuid): array
    {
        return db($this->context)->transaction(function () use ($tenantUuid): array {
            $counts = [];

            $counts[self::RECEIPTS] = $this->deleteReceiptsForTenant($tenantUuid);

            $counts[self::EVENTS] = (int) db($this->context)->table(self::EVENTS)
                ->where('tenant_uuid', '=', $tenantUuid)
                ->delete();

            $counts[self::OVERRIDES] = (int) db($this->context)->table(self::OVERRIDES)
                ->where('tenant_uuid', '=', $tenantUuid)
                ->delete();

            $counts[self::SUBSCRIPTIONS] = (int) db($this->context)->table(self::SUBSCRIPTIONS)
                ->where('tenant_uuid', '=', $tenantUuid)
                ->delete();

            // Only the workspace's OWN member plans -- platform plans always carry
            // audience='tenant', so filtering on audience='user' alone already keeps
            // them safe even without the owner match, but both are asserted
            // explicitly (spec §9) rather than relying on that as an accident.
            $counts[self::PLANS] = (int) db($this->context)->table(self::PLANS)
                ->where('audience', '=', SubjectType::USER)
                ->where('owner_tenant_uuid', '=', $tenantUuid)
                ->delete();

            return $counts;
        });
    }

    /**
     * Resolved-or-candidate triple match for one subject: either the settled
     * identity columns (an accepted receipt) or the raw candidate columns (a
     * rejected/never-resolved receipt) name this exact (tenant_uuid, subject_type,
     * subject_uuid). Raw SQL via executeModification() -- mirroring
     * CommerceTenantPurge's own child-table deletes -- because the query builder's
     * DELETE path does not support OR/raw WHERE conditions.
     */
    private function deleteReceiptsForSubject(Subject $subject): int
    {
        return db($this->context)->table(self::RECEIPTS)->executeModification(
            'DELETE FROM ' . self::RECEIPTS . ' WHERE '
            . '(tenant_uuid = ? AND subject_type = ? AND subject_uuid = ?) OR '
            . '(candidate_tenant_uuid = ? AND candidate_subject_type = ? AND candidate_subject_uuid = ?)',
            [
                $subject->tenantUuid,
                $subject->type,
                $subject->uuid,
                $subject->tenantUuid,
                $subject->type,
                $subject->uuid,
            ]
        );
    }

    /**
     * Resolved-or-candidate TENANT match only (no subject_type/subject_uuid filter):
     * a workspace purge sweeps every subject under that tenant, its own AND every
     * member's, so any receipt naming this tenant -- however its subject resolved --
     * goes with it.
     */
    private function deleteReceiptsForTenant(string $tenantUuid): int
    {
        return db($this->context)->table(self::RECEIPTS)->executeModification(
            'DELETE FROM ' . self::RECEIPTS . ' WHERE (tenant_uuid = ?) OR (candidate_tenant_uuid = ?)',
            [$tenantUuid, $tenantUuid]
        );
    }
}
