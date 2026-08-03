<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Repositories;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\QueryBuilder;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Helpers\Utils;

final class OverrideRepository
{
    /**
     * 1.x facade: the workspace's OWN overrides (tenant self-subject). Delegating to
     * the subject finder is what keeps user-membership overrides out of the tenant
     * entitlement map -- spec §5, "two entry points, never crossed".
     *
     * @return array<string,mixed>
     */
    public function activeForTenant(ApplicationContext $context, string $tenantUuid): array
    {
        return $this->activeForSubject($context, Subject::tenant($tenantUuid));
    }

    /**
     * Subject-aware finder: triple match on (tenant_uuid, subject_type, subject_uuid).
     *
     * @return array<string,mixed>
     */
    public function activeForSubject(ApplicationContext $context, Subject $subject): array
    {
        $now = db($context)->getDriver()->formatDateTime();
        $rows = $this->subjectQuery($context, $subject)
            ->whereRaw('(expires_at IS NULL OR expires_at > ?)', [$now])
            ->get();

        return $this->collect($rows);
    }

    /**
     * The detailed, administrative read: EVERY row for the exact subject triple,
     * expired rows included -- the audit view `activeForSubject()`'s value-map
     * necessarily cannot give, since collapsing to `{entitlement: value}` throws
     * away `expires_at`/`reason`/timestamps and silently excludes anything expired.
     *
     * Ordered by `entitlement ASC`. Each row is projected to exactly
     * `{entitlement, value, expires_at, reason, created_at, updated_at}` -- `value`
     * decoded through the same {@see decode()} helper `activeForSubject()` uses, so
     * scalars/objects/booleans round-trip identically. No storage identity field
     * (id/uuid/tenant_uuid/subject_type/subject_uuid) is exposed: the caller already
     * knows the subject it asked for, and a listing meant for display/audit has no
     * business handing back primary-key material.
     *
     * This method does NOT perform host authorization or tenant switching itself --
     * it is a plain subject-scoped read, exactly like `activeForSubject()`. Callers
     * doing cross-workspace administration (an admin inspecting a tenant/user they
     * are not currently scoped to) MUST wrap the call in
     * {@see \Glueful\Extensions\Subscriptions\Lifecycle\TenantIntegration::runAsTenantOr()}
     * themselves; this repository has no opinion on tenancy.
     *
     * @return list<array{
     *     entitlement:string,
     *     value:mixed,
     *     expires_at:?string,
     *     reason:?string,
     *     created_at:?string,
     *     updated_at:?string
     * }>
     */
    public function listForSubject(ApplicationContext $context, Subject $subject): array
    {
        $rows = $this->subjectQuery($context, $subject)
            ->orderBy('entitlement', 'ASC')
            ->get();

        return array_map(
            fn (array $row): array => [
                'entitlement' => (string) ($row['entitlement'] ?? ''),
                'value' => $this->decode($row['value'] ?? null),
                'expires_at' => $row['expires_at'] ?? null,
                'reason' => $row['reason'] ?? null,
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ],
            $rows
        );
    }

    /**
     * The SUPPORTED writer for `subscription_overrides` (2.0). 1.x shipped no
     * writer at all, so hosts inserted into the table directly; after migration
     * `006` such an insert is only correct if it supplies the full subject triple
     * -- `subject_uuid` DEFAULTS to `''`, which matches no subject, so a 1.x-shaped
     * (tenant_uuid, entitlement, value) insert produces a row `activeForSubject()`
     * never returns. A deny-override written that way silently GRANTS. This method
     * exists so hosts never have to get that right by hand.
     *
     * Insert-or-update on the subject-scoped unique
     * (`uniq_override_subject_entitlement`: tenant_uuid, subject_type, subject_uuid,
     * entitlement) -- read-then-write rather than a dialect-specific UPSERT, matching
     * how every other write in this package stays driver-portable. `$value` is
     * json-encoded exactly like the rows `activeForSubject()`/`decode()` already
     * expect, so `true`, `42`, `"gold"` and `['a' => 1]` all round-trip.
     *
     * Idempotent: calling it twice with the same value leaves one row, updated.
     */
    public function upsertForSubject(
        ApplicationContext $context,
        Subject $subject,
        string $entitlement,
        mixed $value,
        ?string $expiresAt = null,
        ?string $reason = null,
    ): void {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR);
        $now = db($context)->getDriver()->formatDateTime();

        $existing = $this->scopedQuery($context, $subject, $entitlement)->limit(1)->first();

        if ($existing !== null) {
            $this->scopedQuery($context, $subject, $entitlement)->update([
                'value' => $encoded,
                'expires_at' => $expiresAt,
                'reason' => $reason,
                'updated_at' => $now,
            ]);

            return;
        }

        db($context)->table('subscription_overrides')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $subject->tenantUuid,
            'subject_type' => $subject->type,
            'subject_uuid' => $subject->uuid,
            'entitlement' => $entitlement,
            'value' => $encoded,
            'expires_at' => $expiresAt,
            'reason' => $reason,
        ]);
    }

    /**
     * Removes one subject-scoped override. A no-op (never an error) when the row
     * does not exist, so revoke paths are safely re-runnable.
     */
    public function deleteForSubject(ApplicationContext $context, Subject $subject, string $entitlement): void
    {
        $this->scopedQuery($context, $subject, $entitlement)->delete();
    }

    /**
     * The exact predicate of `uniq_override_subject_entitlement` -- the single
     * definition of "this subject's override for this entitlement", shared by the
     * upsert's probe/update and the delete so they can never drift apart. Built on
     * top of {@see subjectQuery()}, adding only the entitlement predicate.
     */
    private function scopedQuery(
        ApplicationContext $context,
        Subject $subject,
        string $entitlement
    ): QueryBuilder {
        return $this->subjectQuery($context, $subject)->where('entitlement', '=', $entitlement);
    }

    /**
     * The shared triple predicate -- (tenant_uuid, subject_type, subject_uuid) --
     * used by every subject-scoped read: `activeForSubject()`, `listForSubject()`,
     * and (via `scopedQuery()`) the write paths' entitlement-scoped lookups. One
     * definition means the triple match can never drift between callers.
     */
    private function subjectQuery(ApplicationContext $context, Subject $subject): QueryBuilder
    {
        return db($context)->table('subscription_overrides')
            ->where('tenant_uuid', '=', $subject->tenantUuid)
            ->where('subject_type', '=', $subject->type)
            ->where('subject_uuid', '=', $subject->uuid);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function collect(array $rows): array
    {
        $overrides = [];
        foreach ($rows as $row) {
            $entitlement = (string) ($row['entitlement'] ?? '');
            if ($entitlement === '') {
                continue;
            }
            $overrides[$entitlement] = $this->decode($row['value'] ?? null);
        }

        return $overrides;
    }

    private function decode(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
