<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Unit;

use Glueful\Extensions\ServiceProvider;
use Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class ServiceProviderShapeTest extends TestCase
{
    public function testProviderShape(): void
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
        $provider = new SubscriptionsServiceProvider($container);

        self::assertInstanceOf(ServiceProvider::class, $provider);
        self::assertIsArray(SubscriptionsServiceProvider::services());
        self::assertNotSame([], SubscriptionsServiceProvider::services());
        self::assertSame('Subscriptions', $provider->getName());
        self::assertSame('1.1.0', $provider->getVersion());
    }
}
