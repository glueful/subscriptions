<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Schema;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Task 3 (2.1.0 seams, Phase A): the extension-owned schema readiness
 * authority Thallo's EngineGateway consults to distinguish schema_not_ready
 * from ready. `isReady()` is true ONLY when the complete minimum 2.x runtime
 * shape exists -- every table and column migration 006 (SubjectModel)
 * introduces, checked directly against the live database rather than
 * inferred from a migrations-ledger row, so a database that was hand-rolled,
 * partially migrated, or downgraded is caught the same as one that was never
 * migrated at all.
 *
 * A false positive here lets Thallo's EngineGateway treat a broken/partial
 * schema as ready and surface broken admin APIs; a thrown exception here
 * breaks Thallo's degraded-mode fallback. Both are unacceptable, so every
 * probe is wrapped: any thrown DB error (a lost connection, a locked
 * database, an unsupported driver) resolves to NOT ready, never propagates.
 */
final class SubscriptionSchemaReadiness
{
    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'subscriptions',
        'subscription_overrides',
        'subscription_events',
        'subscription_plans',
        'subscription_provider_event_receipts',
    ];

    /**
     * The subject-model columns migration 006 adds to each 1.x table, plus
     * the scoped-catalog columns on subscription_plans.
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED_COLUMNS = [
        // 'checkout_origination_uuid' is migration 007 (design spec §4.1, Task 10):
        // it is only meaningful for checkout-capable 2.2 hosts, but is still part of
        // the minimum 2.x runtime shape this class checks -- a database that ran
        // 001-006 but not 007 is a partial/downgraded 2.x install, not a legitimate
        // 2.0/2.1 one, so it must resolve to NOT ready exactly like a missing
        // subject-model column does.
        'subscriptions' => ['subject_type', 'subject_uuid', 'plan_uuid', 'checkout_origination_uuid'],
        'subscription_overrides' => ['subject_type', 'subject_uuid'],
        'subscription_events' => ['subject_type', 'subject_uuid'],
        // 'provider_identifiers' is migration 008 (design spec §4.2, Task 13): the
        // per-gateway checkout-purchasability map. Same reasoning as
        // checkout_origination_uuid above -- a database that ran 001-007 but not
        // 008 is a partial/downgraded 2.x install, not a legitimate 2.1 one.
        'subscription_plans' => ['audience', 'owner_tenant_uuid', 'provider_identifiers'],
        'subscription_provider_event_receipts' => [
            'uuid',
            'provider_gateway',
            'provider_logical_event_key',
            'event_type',
            'candidate_tenant_uuid',
            'candidate_subject_type',
            'candidate_subject_uuid',
            'candidate_plan_uuid',
            'tenant_uuid',
            'subject_type',
            'subject_uuid',
            'plan_uuid',
            'outcome',
            'rejection_code',
            'data',
            'created_at',
        ],
    ];

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /**
     * True only when every required table and column is present. A single
     * try/catch around the whole probe sequence, not one per call: whatever
     * throws first (resolving the connection, hasTable(), hasColumn()) is
     * caught the same way -- readiness is a probe, never a fatal.
     */
    public function isReady(): bool
    {
        try {
            $schema = db($this->context)->getSchemaBuilder();

            foreach (self::REQUIRED_TABLES as $table) {
                if (!$schema->hasTable($table)) {
                    return false;
                }
            }

            foreach (self::REQUIRED_COLUMNS as $table => $columns) {
                foreach ($columns as $column) {
                    if (!$schema->hasColumn($table, $column)) {
                        return false;
                    }
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
