<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Schema;

use Glueful\Database\Connection;
use Glueful\Extensions\Schema\StructuralVerifierInterface;

/**
 * Structural verifier for glueful/subscriptions (schema policy spec B7): each migration's
 * receipt may be adopted only when THAT migration's observable effect exists — created tables
 * for 001-005, the subject-model columns AND receipts table for 006, the checkout-reservation
 * column/index for 007, the provider-identifiers column for 008. Parent-table existence never
 * certifies the later subject-model or ALTER work. Unknown basenames are never adoptable.
 */
final class SubscriptionsSchemaVerifier implements StructuralVerifierInterface
{
    public function source(): string
    {
        return 'glueful/subscriptions';
    }

    /** @return list<string> */
    public function migrationBasenames(): array
    {
        return [
            '001_CreateSubscriptionsTable.php',
            '002_CreateSubscriptionOverridesTable.php',
            '003_CreateSubscriptionEventsTable.php',
            '004_CreateSubscriptionPlansTable.php',
            '005_CreateV2PreparationState.php',
            '006_SubjectModel.php',
            '007_CheckoutReservations.php',
            '008_PlanProviderIdentifiers.php',
        ];
    }

    public function verify(Connection $db, string $migrationBasename): bool
    {
        return match ($migrationBasename) {
            '001_CreateSubscriptionsTable.php' => $this->tablesWithColumns($db, [
                'subscriptions' => ['uuid', 'tenant_uuid', 'plan_key', 'provider_gateway', 'provider_subscription_id', 'status'],
            ]),
            '002_CreateSubscriptionOverridesTable.php' => $this->tablesWithColumns($db, [
                'subscription_overrides' => ['uuid', 'tenant_uuid', 'entitlement', 'reason'],
            ]),
            '003_CreateSubscriptionEventsTable.php' => $this->tablesWithColumns($db, [
                'subscription_events' => ['uuid', 'type', 'from_status', 'to_status', 'provider_logical_event_key'],
            ]),
            '004_CreateSubscriptionPlansTable.php' => $this->tablesWithColumns($db, [
                'subscription_plans' => ['uuid', 'plan_key', 'display_name', 'provider_price_id', 'status'],
            ]),
            '005_CreateV2PreparationState.php' => $this->tablesWithColumns($db, [
                'subscription_v2_preparation' => ['marker_key', 'catalog_signature', 'prepared_at'],
            ]),
            '006_SubjectModel.php' => $this->tablesWithColumns($db, [
                'subscriptions' => ['subject_type', 'subject_uuid', 'plan_uuid'],
                'subscription_overrides' => ['subject_type'],
                'subscription_provider_event_receipts' => ['uuid', 'provider_gateway', 'event_type', 'candidate_subject_type'],
            ]),
            '007_CheckoutReservations.php' => $this->tablesWithColumns($db, [
                'subscriptions' => ['checkout_origination_uuid'],
            ]),
            '008_PlanProviderIdentifiers.php' => $this->tablesWithColumns($db, [
                'subscription_plans' => ['provider_identifiers'],
            ]),
            default => false,
        };
    }

    /** @param array<string, list<string>> $expectations */
    private function tablesWithColumns(Connection $db, array $expectations): bool
    {
        $schema = $db->getSchemaBuilder();
        foreach ($expectations as $table => $columns) {
            if (!$schema->hasTable($table)) {
                return false;
            }
            foreach ($columns as $column) {
                if (!$schema->hasColumn($table, $column)) {
                    return false;
                }
            }
        }
        return true;
    }
}
