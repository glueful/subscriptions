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
    /**
     * Every caller passes an EXPLICIT subject triple since the 2.0 activation: the
     * transitional tenant-only derivation (and the pre-006 hasColumn() sniff that
     * stripped the subject columns before the insert) are both retired -- the
     * subject columns always exist, and an event with no subject is a bug, not a
     * legacy shape.
     *
     * @param array<string,mixed> $event
     * @throws \InvalidArgumentException when the event's subject identity is incoherent
     *         (repository-boundary coherence check only -- host existence remains
     *         SubjectResolverInterface's job, spec §8).
     */
    public function insertOrThrow(ApplicationContext $context, array $event): void
    {
        $row = array_merge(['uuid' => Utils::generateNanoID(12)], $event);

        $this->assertCoherentIdentity($row);

        if (isset($row['data']) && is_array($row['data'])) {
            $row['data'] = json_encode($row['data'], JSON_THROW_ON_ERROR);
        }

        db($context)->table('subscription_events')->insert($row);
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
