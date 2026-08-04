<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Repositories;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;

/**
 * The provider-event receipt repository (spec §2/§8) backing
 * `subscription_provider_event_receipts` (migration 006): every inbound provider
 * webhook is claimed as a pending receipt BEFORE resolution/projection runs (the
 * DB-enforced (provider_gateway, provider_logical_event_key) unique index is the
 * idempotency gate, mirroring SubscriptionEventRepository's claim pattern), then
 * settled to accepted or rejected once the subject/plan identity is resolved.
 *
 * Intentionally NON-final: the projector's test suite subclasses
 * existsByLogicalKey() to simulate the read-side race window and prove the
 * DB-enforced claim gate, mirroring SubscriptionEventRepository's own pattern.
 */
class ProviderEventReceiptRepository
{
    /**
     * Claims the (provider_gateway, provider_logical_event_key) slot for this webhook
     * before resolution completes. $row carries the candidate_* identity columns (as
     * read off the raw provider payload, unresolved) plus provider_gateway,
     * provider_logical_event_key, event_type, and optionally 'data'. The resolved
     * identity columns (tenant_uuid/subject_type/subject_uuid/plan_uuid) are left NULL
     * here; markAccepted() fills them in. Always inserted with outcome='pending',
     * regardless of what (if anything) the caller passed for that key.
     *
     * @param array<string,mixed> $row
     * @throws \Throwable propagates the DB unique-violation when the (gateway, logical
     *         key) slot is already claimed -- callers branch on isUniqueViolation().
     */
    public function insertPending(ApplicationContext $context, array $row): void
    {
        $row = array_merge(['uuid' => Utils::generateNanoID(12)], $row);
        $row['outcome'] = 'pending';

        if (isset($row['data']) && is_array($row['data'])) {
            $row['data'] = json_encode($row['data'], JSON_THROW_ON_ERROR);
        }

        db($context)->table('subscription_provider_event_receipts')->insert($row);
    }

    /**
     * Settles a pending receipt as accepted: writes outcome='accepted' plus the
     * resolved identity columns (tenant_uuid/subject_type/subject_uuid/plan_uuid, as
     * present in $resolved).
     *
     * @param array<string,mixed> $resolved
     */
    public function markAccepted(ApplicationContext $context, string $uuid, array $resolved): void
    {
        $changes = $resolved;
        $changes['outcome'] = 'accepted';

        db($context)->table('subscription_provider_event_receipts')
            ->where('uuid', '=', $uuid)
            ->update($changes);
    }

    /**
     * Settles a pending receipt as rejected: writes outcome='rejected' and the
     * rejection_code. The resolved identity columns are left as inserted (NULL from
     * insertPending()) -- a rejected provider attempt never resolves an identity.
     */
    public function markRejected(ApplicationContext $context, string $uuid, string $rejectionCode): void
    {
        db($context)->table('subscription_provider_event_receipts')
            ->where('uuid', '=', $uuid)
            ->update([
                'outcome' => 'rejected',
                'rejection_code' => $rejectionCode,
            ]);
    }

    public function existsByLogicalKey(ApplicationContext $context, string $gateway, string $key): bool
    {
        return db($context)->table('subscription_provider_event_receipts')
            ->where('provider_gateway', '=', $gateway)
            ->where('provider_logical_event_key', '=', $key)
            ->limit(1)
            ->first() !== null;
    }

    /**
     * The exact logical-key SETTLED-outcome read (design spec §4.3, Task 12):
     * the ONLY way `SubscriptionEventProjector::projectWithOutcome()` may recover
     * what a stored receipt actually decided on a duplicate delivery -- both the
     * early `existsByLogicalKey()` short-circuit and a caught `insertPending()`
     * unique-violation re-read through THIS method rather than fabricating a
     * generic "already handled" no-op outcome of their own.
     *
     * Deliberately refuses (returns null for) a `pending` row -- nothing has
     * settled yet, so there is no outcome to report -- and a missing row exactly
     * the same way. Callers must not construct a fake outcome for either case;
     * under this repository's own claim-then-settle invariant (the whole
     * claim+resolve+settle sequence commits atomically in one transaction) a row
     * visible to a SEPARATE read is always already settled, so "pending" here
     * signals an invariant violation, not a normal race window.
     *
     * @return array{outcome:string,reason:?string,logical_event_key:string}|null
     */
    public function findOutcomeByLogicalKey(ApplicationContext $context, string $gateway, string $key): ?array
    {
        $row = db($context)->table('subscription_provider_event_receipts')
            ->where('provider_gateway', '=', $gateway)
            ->where('provider_logical_event_key', '=', $key)
            ->limit(1)
            ->first();

        if ($row === null) {
            return null;
        }

        $outcome = (string) ($row['outcome'] ?? '');
        if ($outcome !== 'accepted' && $outcome !== 'rejected') {
            return null; // still 'pending' (or an unrecognized value) -- nothing settled
        }

        $rejectionCode = $row['rejection_code'] ?? null;

        return [
            'outcome' => $outcome,
            'reason' => is_scalar($rejectionCode) && (string) $rejectionCode !== '' ? (string) $rejectionCode : null,
            'logical_event_key' => (string) ($row['provider_logical_event_key'] ?? $key),
        ];
    }

    /**
     * Cross-driver unique-violation detection, delegated to the shared
     * UniqueViolations helper (spec §8) so this repository and
     * SubscriptionEventRepository share the exact same detection logic.
     */
    public function isUniqueViolation(\Throwable $e): bool
    {
        return UniqueViolations::isUniqueViolation($e);
    }
}
