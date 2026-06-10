<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tenant;

use Glueful\Bootstrap\ApplicationContext;

final class CurrentTenant
{
    public static function resolve(ApplicationContext $context): ?string
    {
        try {
            if (class_exists(\Glueful\Extensions\Tenancy\Context\TenantContext::class)) {
                return (new \Glueful\Extensions\Tenancy\Context\TenantContext($context))->currentTenantUuid();
            }

            $tenant = $context->getRequestState('tenancy.tenant');
            if (is_object($tenant) && isset($tenant->uuid) && is_scalar($tenant->uuid)) {
                return (string) $tenant->uuid;
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }
}
