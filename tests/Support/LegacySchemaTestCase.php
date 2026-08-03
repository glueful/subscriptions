<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Support;

use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionEventsTable;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionOverridesTable;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionPlansTable;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionsTable;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateV2PreparationState;
use Glueful\Helpers\Utils;

/**
 * The 1.x-shaped database: migrations 001-005 only, no plan catalog seeded, and
 * 1.x-shaped subscription fixtures (no subject triple, no plan_uuid).
 *
 * Reserved for the two things that must observe a PRE-006 install: the
 * `subscriptions:prepare-v2` upgrade bridge, and migration 006 itself (which
 * every test here drives explicitly via `(new SubjectModel())->up(...)`).
 * Everything else runs on the shipped 2.0 schema via SubscriptionsTestCase.
 */
abstract class LegacySchemaTestCase extends SubscriptionsTestCase
{
    protected function applyMigrations(SchemaBuilderInterface $schema): void
    {
        (new CreateSubscriptionsTable())->up($schema);
        (new CreateSubscriptionOverridesTable())->up($schema);
        (new CreateSubscriptionEventsTable())->up($schema);
        (new CreateSubscriptionPlansTable())->up($schema);
        (new CreateV2PreparationState())->up($schema);
    }

    /** No catalog: a 1.x install starts with an empty subscription_plans table. */
    protected function seedPlatformPlans(): void
    {
    }

    /**
     * The 1.x fixture shape -- the columns a pre-006 `subscriptions` table has.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    protected function seedSubscription(array $overrides = []): array
    {
        $row = array_merge([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'free',
            'status' => 'active',
        ], $overrides);

        $this->connection->table('subscriptions')->insert($row);

        return $row;
    }
}
