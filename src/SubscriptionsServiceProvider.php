<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\ServiceProvider;

final class SubscriptionsServiceProvider extends ServiceProvider
{
    private static ?string $cachedVersion = null;

    public static function composerVersion(): string
    {
        if (self::$cachedVersion === null) {
            $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
            self::$cachedVersion = (string) ($composer['extra']['glueful']['version'] ?? '0.0.0');
        }

        return self::$cachedVersion;
    }

    public static function services(): array
    {
        return [];
    }

    public function getName(): string
    {
        return 'Subscriptions';
    }

    public function getVersion(): string
    {
        return self::composerVersion();
    }

    public function getDescription(): string
    {
        return 'Tenant subscriptions and entitlement resolution for Glueful SaaS apps.';
    }

    public function register(ApplicationContext $context): void
    {
        $this->mergeConfig('subscriptions', require __DIR__ . '/../config/subscriptions.php');
        $this->loadMigrationsFrom(
            __DIR__ . '/../migrations',
            MigrationPriority::DEPENDENT,
            'glueful/subscriptions'
        );
    }

    public function boot(ApplicationContext $context): void
    {
    }
}
