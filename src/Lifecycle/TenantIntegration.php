<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Lifecycle;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;

/**
 * The one seam through which subscriptions runs work under an explicit tenant or
 * system context (spec §9). `glueful/extension-contracts` is a require-dev-only
 * dependency (never a hard one -- the extension must keep working for hosts that
 * never install a tenancy package), so every lookup here is a SOFT probe:
 * `interface_exists()` first (the contracts package may not even be autoloadable),
 * then the container's own `has()` before ever calling `get()`.
 *
 * `runAsTenantOr()` is what `SubscriptionService`'s subject-aware `…For()` methods
 * wrap their repository work in, keyed on `$subject->tenantUuid` -- reads/writes for
 * that one workspace (or member) run scoped/stamped to it when a real
 * `TenantContextRunner` is bound.
 *
 * `runAsSystemOr()` is for trusted work that must find or remove rows BEFORE any
 * tenant context can be known: `SubscriptionEventProjector::project()` (the target
 * subscription is discovered by provider identifiers, not a known tenant) and
 * `SubscriptionSubjectDataPurger::purgeSubject()` (a purge sweeps rows across
 * subjects/tenants by design).
 *
 * With no runner bound -- no tenancy package installed at all, or one installed but
 * not wired to this contract -- both helpers degrade to calling `$fn` directly, so
 * every existing call site behaves exactly as it did before this contract existed.
 */
final class TenantIntegration
{
    public static function runAsTenantOr(ApplicationContext $context, string $tenantUuid, callable $fn): mixed
    {
        $runner = self::runner($context);

        return $runner !== null ? $runner->runAsTenant($tenantUuid, $fn) : $fn();
    }

    public static function runAsSystemOr(ApplicationContext $context, callable $fn): mixed
    {
        $runner = self::runner($context);

        return $runner !== null ? $runner->runAsSystem($fn) : $fn();
    }

    private static function runner(ApplicationContext $context): ?TenantContextRunner
    {
        if (!interface_exists(TenantContextRunner::class) || !$context->hasContainer()) {
            return null;
        }

        $container = $context->getContainer();
        if (!$container->has(TenantContextRunner::class)) {
            return null;
        }

        $candidate = $container->get(TenantContextRunner::class);

        return $candidate instanceof TenantContextRunner ? $candidate : null;
    }
}
