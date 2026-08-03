<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionEventsTable;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionOverridesTable;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionPlansTable;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionsTable;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateV2PreparationState;
use Glueful\Extensions\Subscriptions\Database\Migrations\SubjectModel;
use Glueful\Helpers\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The shipped 2.0 schema (migrations 001-006) plus the platform plan catalog the
 * DB-authoritative PlanCatalog now REQUIRES: since the coordinated activation
 * there is no config overlay, so a fixture that only declares
 * `subscriptions.plans.*` in config resolves to nothing.
 *
 * 006 is applied here while the tables are still empty -- the spec §3.3
 * fresh-install path, exempt from the preparation marker -- and the platform
 * plans are seeded BEFORE any subscription fixture so seedSubscription() can
 * resolve plan_key -> plan_uuid.
 *
 * Tests that must see a 1.x-shaped database (the upgrade bridge and migration
 * 006 itself) extend LegacySchemaTestCase instead.
 */
abstract class SubscriptionsTestCase extends TestCase
{
    protected ApplicationContext $context;
    protected Connection $connection;

    /** @var array<string,mixed> */
    protected array $bindings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);

        $this->applyMigrations($this->connection->getSchemaBuilder());

        $connection = $this->connection;
        $bindings = &$this->bindings;

        $container = new class ($connection, $bindings) implements ContainerInterface {
            /**
             * @param array<string,mixed> $bindings
             */
            public function __construct(
                private Connection $connection,
                private array &$bindings,
            ) {
            }

            public function get(string $id): mixed
            {
                if ($id === 'database' || $id === Connection::class) {
                    return $this->connection;
                }

                if (array_key_exists($id, $this->bindings)) {
                    return $this->bindings[$id];
                }

                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === 'database'
                    || $id === Connection::class
                    || array_key_exists($id, $this->bindings);
            }
        };

        $this->context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $this->context->setContainer($container);

        // config($context, 'subscriptions.*') resolves through the context's config
        // defaults (the same channel ServiceProvider::mergeConfig() uses), NOT the
        // container -- so seed the shipped catalog as defaults here.
        $this->context->mergeConfigDefaults(
            'subscriptions',
            require __DIR__ . '/../../config/subscriptions.php'
        );

        $this->seedPlatformPlans();
    }

    /** Overridden by LegacySchemaTestCase to stop at the 1.x schema (001-005). */
    protected function applyMigrations(SchemaBuilderInterface $schema): void
    {
        (new CreateSubscriptionsTable())->up($schema);
        (new CreateSubscriptionOverridesTable())->up($schema);
        (new CreateSubscriptionEventsTable())->up($schema);
        (new CreateSubscriptionPlansTable())->up($schema);
        (new CreateV2PreparationState())->up($schema);
        (new SubjectModel())->up($schema);
    }

    /**
     * The platform catalog the shipped config seeds ('free', 'pro'), as real DB
     * rows in the ('tenant', '') scope. Entitlements mirror config/subscriptions.php
     * exactly, so fixtures written against the 1.x config catalog keep resolving to
     * the same values. Overridden to a no-op by LegacySchemaTestCase.
     */
    protected function seedPlatformPlans(): void
    {
        $configPlans = (array) config($this->context, 'subscriptions.plans', []);
        $sortOrder = 0;

        foreach (['free', 'pro'] as $planKey) {
            $plan = is_array($configPlans[$planKey] ?? null) ? $configPlans[$planKey] : [];

            $this->connection->table('subscription_plans')->insert([
                'uuid' => $planKey === 'free' ? 'planv2free01' : 'planv2pro001',
                'plan_key' => $planKey,
                'display_name' => ucfirst($planKey),
                'description' => null,
                'entitlements' => json_encode(
                    is_array($plan['entitlements'] ?? null) ? $plan['entitlements'] : [],
                    JSON_THROW_ON_ERROR
                ),
                'provider_price_id' => $plan['provider_price_id'] ?? null,
                'status' => 'active',
                'sort_order' => $sortOrder++,
                'audience' => 'tenant',
                'owner_tenant_uuid' => '',
            ]);
        }
    }

    protected function appContext(): ApplicationContext
    {
        return $this->context;
    }

    protected function connection(): Connection
    {
        return $this->connection;
    }

    protected function bind(string $id, mixed $service): void
    {
        $this->bindings[$id] = $service;
    }

    /**
     * Override a config value for this test, dot-notation key rooted at the config
     * file name (e.g. 'subscriptions.permissive_middleware').
     */
    protected function setConfig(string $key, mixed $value): void
    {
        $parts = explode('.', $key);
        $root = array_shift($parts);

        if ($parts === []) {
            throw new \InvalidArgumentException('setConfig() needs a dotted key below the config root.');
        }

        $nested = $value;
        foreach (array_reverse($parts) as $part) {
            $nested = [$part => $nested];
        }

        $this->context->mergeConfigDefaults($root, $nested);
    }

    /**
     * Drops the seeded platform catalog. For suites that OWN the plan table --
     * catalog resolution, plan management, plan HTTP/console surfaces -- where a
     * pre-seeded 'free'/'pro' would collide with the fixture they build themselves.
     */
    protected function clearPlatformPlans(): void
    {
        $this->connection->table('subscription_plans')
            ->where('owner_tenant_uuid', '=', '')
            ->where('audience', '=', 'tenant')
            ->whereIn('plan_key', ['free', 'pro'])
            ->delete();
    }

    /**
     * Resolves plan_key -> plan_uuid inside the subject's own catalog scope and
     * writes a coherent subject triple. Defaults to a tenant self-subject
     * (subject_uuid = tenant_uuid), matching the 1.x fixture shape; pass
     * subject_type/subject_uuid overrides for a membership row.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    protected function seedSubscription(array $overrides = []): array
    {
        $tenantUuid = (string) ($overrides['tenant_uuid'] ?? 'tenantA');
        $planKey = (string) ($overrides['plan_key'] ?? 'free');
        $subjectType = (string) ($overrides['subject_type'] ?? 'tenant');
        $subjectUuid = (string) ($overrides['subject_uuid'] ?? $tenantUuid);
        $audience = $subjectType === 'user' ? 'user' : 'tenant';
        $ownerTenantUuid = $subjectType === 'user' ? $tenantUuid : '';

        $plan = $this->connection->table('subscription_plans')
            ->where('plan_key', '=', $planKey)
            ->where('audience', '=', $audience)
            ->where('owner_tenant_uuid', '=', $ownerTenantUuid)
            ->first();

        if ($plan === null) {
            throw new \RuntimeException(
                "SubscriptionsTestCase::seedSubscription(): no plan resolves for "
                . "plan_key='{$planKey}' audience='{$audience}' owner_tenant_uuid='{$ownerTenantUuid}'. "
                . 'Seed it first (or pass an explicit plan_uuid override).'
            );
        }

        $row = array_merge([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenantUuid,
            'subject_type' => $subjectType,
            'subject_uuid' => $subjectUuid,
            'plan_key' => $planKey,
            'plan_uuid' => $plan['uuid'],
            'status' => 'active',
        ], $overrides);

        $this->connection->table('subscriptions')->insert($row);

        return $row;
    }
}
