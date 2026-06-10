<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Unit\Tenant;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Tenant\CurrentTenant;
use PHPUnit\Framework\TestCase;

final class CurrentTenantTest extends TestCase
{
    private function context(): ApplicationContext
    {
        return new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
    }

    public function testReturnsNullWithoutTenancyAndDoesNotFatal(): void
    {
        // The tenancy package is not installed in this suite -- the class_exists
        // guard must degrade to null, never fatal.
        self::assertFalse(class_exists(\Glueful\Extensions\Tenancy\Context\TenantContext::class));
        self::assertNull(CurrentTenant::resolve($this->context()));
    }

    public function testReturnsUuidWhenTenantContextStateIsPresent(): void
    {
        if (class_exists(\Glueful\Extensions\Tenancy\Context\TenantContext::class)) {
            self::markTestSkipped('tenancy installed: the request-state fallback path is not reachable.');
        }

        // Tenancy's TenantContext stores the active tenant in
        // ApplicationContext::requestState under 'tenancy.tenant' (verified in
        // tenancy source); simulate it with a fake tenant exposing a public uuid.
        $context = $this->context();
        $context->setRequestState('tenancy.tenant', new class {
            public string $uuid = 'tenantA12345';
        });

        self::assertSame('tenantA12345', CurrentTenant::resolve($context));
    }

    public function testReturnsNullForMalformedTenantState(): void
    {
        $context = $this->context();
        $context->setRequestState('tenancy.tenant', 'not-an-object');

        self::assertNull(CurrentTenant::resolve($context));
    }
}
