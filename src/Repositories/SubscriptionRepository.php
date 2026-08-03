<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Repositories;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubjectType;

/**
 * Intentionally NON-final: the receipts-first projector's test suite subclasses
 * updateBySubject() to simulate a transient (non-unique-violation) database
 * failure, proving the whole projection transaction -- including the just-claimed
 * pending receipt -- rolls back on a genuine error rather than swallowing it.
 */
class SubscriptionRepository
{
    /**
     * 1.x facade: the workspace's OWN subscription, i.e. the tenant self-subject
     * (tenant_uuid, 'tenant', tenant_uuid). Since 2.0 a tenant can also hold user
     * membership rows, so an unscoped `WHERE tenant_uuid = ?` would be ambiguous --
     * this delegates to the subject finder instead.
     *
     * @return array<string,mixed>|null
     */
    public function findByTenant(ApplicationContext $context, string $tenantUuid): ?array
    {
        return $this->findBySubject($context, Subject::tenant($tenantUuid));
    }

    /**
     * Subject-aware finder: triple match on (tenant_uuid, subject_type, subject_uuid),
     * which is exactly the `uniq_subscriptions_subject` unique -- at most one row.
     *
     * @return array<string,mixed>|null
     */
    public function findBySubject(ApplicationContext $context, Subject $subject): ?array
    {
        return db($context)->table('subscriptions')
            ->where('tenant_uuid', '=', $subject->tenantUuid)
            ->where('subject_type', '=', $subject->type)
            ->where('subject_uuid', '=', $subject->uuid)
            ->limit(1)
            ->first();
    }

    /**
     * Bulk trusted read (spec §6.1): ONE `whereIn` query over
     * `subject_type='tenant' AND tenant_uuid IN (...)`, rows returned exactly as
     * `findBySubject()` returns its single row -- no per-row decode step exists on
     * that path (JSON columns like `metadata` come back as the raw driver value),
     * so none is introduced here either. The caller (SubscriptionService::
     * currentForTenants()) is responsible for normalization/dedup and the
     * empty-input short-circuit; this method only guards against issuing a
     * `whereIn` with no values.
     *
     * @param list<string> $tenantUuids
     * @return list<array<string,mixed>>
     */
    public function findTenantSubjectsAmong(ApplicationContext $context, array $tenantUuids): array
    {
        if ($tenantUuids === []) {
            return [];
        }

        return db($context)->table('subscriptions')
            ->where('subject_type', '=', SubjectType::TENANT)
            ->whereIn('tenant_uuid', $tenantUuids)
            ->get();
    }

    /** @return array<string,mixed>|null */
    public function findByProviderSubscription(
        ApplicationContext $context,
        string $gateway,
        string $providerSubscriptionId,
    ): ?array {
        return db($context)->table('subscriptions')
            ->where('provider_gateway', '=', $gateway)
            ->where('provider_subscription_id', '=', $providerSubscriptionId)
            ->limit(1)
            ->first();
    }

    /** @param array<string,mixed> $data */
    public function insert(ApplicationContext $context, array $data): void
    {
        db($context)->table('subscriptions')->insert($this->normalizeJson($data));
    }

    /**
     * 1.x facade: updates the workspace's OWN subscription only. Delegating to the
     * subject updater is what keeps a workspace write from sweeping every user
     * membership row that shares its tenant_uuid.
     *
     * @param array<string,mixed> $changes
     */
    public function updateByTenant(ApplicationContext $context, string $tenantUuid, array $changes): void
    {
        $this->updateBySubject($context, Subject::tenant($tenantUuid), $changes);
    }

    /**
     * Subject-aware updater: triple match, so a write can only ever touch the one
     * row identified by `uniq_subscriptions_subject`.
     *
     * @param array<string,mixed> $changes
     */
    public function updateBySubject(ApplicationContext $context, Subject $subject, array $changes): void
    {
        $changes['updated_at'] = $this->now($context);

        db($context)->table('subscriptions')
            ->where('tenant_uuid', '=', $subject->tenantUuid)
            ->where('subject_type', '=', $subject->type)
            ->where('subject_uuid', '=', $subject->uuid)
            ->update($this->normalizeJson($changes));
    }

    /** @return list<array<string,mixed>> */
    public function allWithProvider(ApplicationContext $context): array
    {
        return db($context)->table('subscriptions')
            ->whereRaw('provider_subscription_id IS NOT NULL')
            ->orderBy(['created_at' => 'ASC'])
            ->get();
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeJson(array $row): array
    {
        foreach (['metadata'] as $column) {
            if (isset($row[$column]) && is_array($row[$column])) {
                $row[$column] = json_encode($row[$column], JSON_THROW_ON_ERROR);
            }
        }

        return $row;
    }

    private function now(ApplicationContext $context): string
    {
        return db($context)->getDriver()->formatDateTime();
    }
}
