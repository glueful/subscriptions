<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionEventsTable;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionOverridesTable;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionPlansTable;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionsTable;
use Glueful\Helpers\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

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

        $schema = $this->connection->getSchemaBuilder();
        (new CreateSubscriptionsTable())->up($schema);
        (new CreateSubscriptionOverridesTable())->up($schema);
        (new CreateSubscriptionEventsTable())->up($schema);
        (new CreateSubscriptionPlansTable())->up($schema);

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

    /** @param array<string,mixed> $overrides */
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
