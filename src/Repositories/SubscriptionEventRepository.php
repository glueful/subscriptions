<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Repositories;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\SubjectType;
use Glueful\Helpers\Utils;

/**
 * Intentionally NON-final: the listener suite subclasses existsByLogicalKey()
 * to simulate the read-side race window and prove the DB-enforced claim gate.
 */
class SubscriptionEventRepository
{
    private ?bool $eventsTableHasSubjectColumns = null;

    /**
     * @param array<string,mixed> $event
     * @throws \InvalidArgumentException when the event's subject identity is incoherent
     *         (repository-boundary coherence check only -- host existence remains
     *         SubjectResolverInterface's job, spec §8).
     */
    public function insertOrThrow(ApplicationContext $context, array $event): void
    {
        $row = array_merge(['uuid' => Utils::generateNanoID(12)], $event);

        // Legacy-compat (Task 6, until Task 9's coordinated cutover): the 1.x call sites
        // (SubscriptionService, SubscriptionEventProjector) and pre-v2 event fixtures
        // insert tenant-only events that carry NO subject_type/subject_uuid key at all --
        // both keys absent (or explicitly null) is read as "caller has no concept of
        // subjects yet" and gets a derived coherent tenant self-subject, applied as ONE
        // atomic pair. If only ONE of the two is absent/null -- e.g. an explicit
        // subject_type='user' with subject_uuid omitted -- that is a real, incoherent
        // shape, not a legacy-shaped event: it must NOT be partially derived (deriving
        // subject_uuid alone from tenant_uuid there would manufacture a coherent-looking
        // but wrong "user" identity that only the tenant/uuid-match branch below would
        // have caught). The row is left as-is so the coherence check rejects it, same as
        // an explicitly passed empty string.
        if (
            (!array_key_exists('subject_type', $row) || $row['subject_type'] === null)
            && (!array_key_exists('subject_uuid', $row) || $row['subject_uuid'] === null)
        ) {
            $row['subject_type'] = SubjectType::TENANT;
            $row['subject_uuid'] = $row['tenant_uuid'] ?? '';
        }

        $this->assertCoherentIdentity($row);

        // TRANSITIONAL (Task 6, until Task 9's coordinated cutover): the subject_type/
        // subject_uuid columns only exist once migration 006 has run (the shared 1.x
        // harness never applies it -- SubscriptionsTestCase stays on the pre-006 schema
        // by design). On that schema the derived/explicit subject values above exist
        // purely to satisfy the coherence check and must NOT be written -- the columns
        // don't exist and the insert would fail with "no such column".
        if (!$this->eventsTableHasSubjectColumns($context)) {
            unset($row['subject_type'], $row['subject_uuid']);
        }

        if (isset($row['data']) && is_array($row['data'])) {
            $row['data'] = json_encode($row['data'], JSON_THROW_ON_ERROR);
        }

        db($context)->table('subscription_events')->insert($row);
    }

    private function eventsTableHasSubjectColumns(ApplicationContext $context): bool
    {
        if ($this->eventsTableHasSubjectColumns === null) {
            $this->eventsTableHasSubjectColumns = db($context)->getSchemaBuilder()
                ->hasColumn('subscription_events', 'subject_type');
        }

        return $this->eventsTableHasSubjectColumns;
    }

    /**
     * Coherence-only validation of the static subject identity (spec §8): non-empty
     * tenant_uuid/subject_type/subject_uuid; subject_type exactly tenant|user; a tenant
     * subject requires subject_uuid === tenant_uuid. Does NOT check that the tenant or
     * subject actually exists -- that remains SubjectResolverInterface's job.
     *
     * @param array<string,mixed> $row
     */
    private function assertCoherentIdentity(array $row): void
    {
        $tenantUuid = (string) ($row['tenant_uuid'] ?? '');
        $subjectType = (string) ($row['subject_type'] ?? '');
        $subjectUuid = (string) ($row['subject_uuid'] ?? '');

        if ($tenantUuid === '') {
            throw new \InvalidArgumentException(
                'subscription event requires a non-empty tenant_uuid.'
            );
        }

        if ($subjectType !== SubjectType::TENANT && $subjectType !== SubjectType::USER) {
            throw new \InvalidArgumentException(
                "subscription event subject_type must be 'tenant' or 'user', got '{$subjectType}'."
            );
        }

        if ($subjectUuid === '') {
            throw new \InvalidArgumentException(
                'subscription event requires a non-empty subject_uuid.'
            );
        }

        if ($subjectType === SubjectType::TENANT && $subjectUuid !== $tenantUuid) {
            throw new \InvalidArgumentException(
                'subscription event with subject_type=tenant requires subject_uuid === tenant_uuid.'
            );
        }
    }

    /** @param array<string,mixed> $event */
    public function append(ApplicationContext $context, array $event): bool
    {
        try {
            $this->insertOrThrow($context, $event);
            return true;
        } catch (\Throwable $e) {
            if ($this->isUniqueViolation($e)) {
                return false;
            }
            throw $e;
        }
    }

    public function existsByLogicalKey(ApplicationContext $context, string $gateway, string $key): bool
    {
        return db($context)->table('subscription_events')
            ->where('provider_gateway', '=', $gateway)
            ->where('provider_logical_event_key', '=', $key)
            ->limit(1)
            ->first() !== null;
    }

    /**
     * Cross-driver unique-violation detection, delegated to the shared
     * UniqueViolations helper (spec §8) so ProviderEventReceiptRepository reuses the
     * exact same detection logic. Public because the listener branches on it around
     * its claim transaction.
     */
    public function isUniqueViolation(\Throwable $e): bool
    {
        return UniqueViolations::isUniqueViolation($e);
    }
}
