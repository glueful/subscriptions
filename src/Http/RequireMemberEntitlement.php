<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface;
use Glueful\Extensions\Subscriptions\Entitlements\EntitlementValue;
use Glueful\Extensions\Subscriptions\Resolution\MemberEntitlementResolverFactory;
use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fail-closed entitlement gate for a workspace MEMBER subject (spec §11),
 * mirroring RequireEntitlement's tenant gate: middleware-string form only
 * (->middleware(['require_member_entitlement:content.premium'])); alias wiring is
 * a separate task.
 *
 * Both currentTenant() AND currentUser() must resolve via SubjectResolverInterface
 * before the member map is even consulted -- either missing is the same
 * misconfigured-paywall case the tenant gate treats as fail-closed (S4), not an
 * open door, unless `subscriptions.permissive_middleware` opts back in.
 *
 * Deliberately takes a {@see MemberEntitlementResolverFactory}, not a
 * ready-built `MemberEntitlementResolver`: the resolver's catalog must be
 * scoped to the CURRENT workspace, which is only known once `currentTenant()`
 * is read below, inside `handle()` -- never at DI-construction time (see the
 * factory's own docblock for the pre-pass timing bug this avoids). This
 * class is therefore safe to register as an ordinary SHARED service, exactly
 * like `RequireEntitlement`: it holds no tenant-scoped state of its own.
 */
final class RequireMemberEntitlement implements RouteMiddleware
{
    public function __construct(
        private readonly MemberEntitlementResolverFactory $resolvers,
        private readonly SubjectResolverInterface $subjects,
        private readonly ApplicationContext $context,
    ) {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $entitlement = isset($params[0]) && is_scalar($params[0]) ? (string) $params[0] : '';
        if ($entitlement === '') {
            return Response::error('Entitlement gate misconfigured', Response::HTTP_INTERNAL_SERVER_ERROR, [
                'code' => 'entitlement',
            ]);
        }

        $tenantUuid = $this->subjects->currentTenant($this->context);
        $userUuid = $this->subjects->currentUser($this->context);

        if ($tenantUuid === null || $userUuid === null) {
            if (config($this->context, 'subscriptions.permissive_middleware', false) === true) {
                return $next($request);
            }

            return Response::error('Entitlement check failed: no member context', Response::HTTP_FORBIDDEN, [
                'code' => 'entitlement',
            ]);
        }

        // Built HERE, from the $tenantUuid just read above -- the same value
        // that drives both the catalog scope and the resolveMap() call below,
        // so no stale/mismatched scope is structurally possible.
        $resolver = $this->resolvers->forWorkspace($this->context, $tenantUuid);
        $map = $resolver->resolveMap($this->context, $tenantUuid, $userUuid);

        if (array_key_exists($entitlement, $map) && EntitlementValue::allows($map[$entitlement])) {
            return $next($request);
        }

        return Response::error('Entitlement required', Response::HTTP_FORBIDDEN, [
            'code' => 'entitlement',
            'entitlement' => $entitlement,
        ]);
    }
}
