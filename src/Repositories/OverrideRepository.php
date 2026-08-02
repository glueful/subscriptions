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
        $rows = db($context)->table('subscription_overrides')
            ->where('tenant_uuid', '=', $subject->tenantUuid)
            ->where('subject_type', '=', $subject->type)
            ->where('subject_uuid', '=', $subject->uuid)
            ->whereRaw('(expires_at IS NULL OR expires_at > ?)', [$now])
            ->get();

        return $this->collect($rows);
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
     * upsert's probe/update and the delete so they can never drift apart.
     */
    private function scopedQuery(
        ApplicationContext $context,
        Subject $subject,
        string $entitlement
    ): QueryBuilder {
        return db($context)->table('subscription_overrides')
            ->where('tenant_uuid', '=', $subject->tenantUuid)
            ->where('subject_type', '=', $subject->type)
            ->where('subject_uuid', '=', $subject->uuid)
            ->where('entitlement', '=', $entitlement);
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
