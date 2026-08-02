<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface;
use Glueful\Extensions\Subscriptions\Entitlements\EntitlementValue;
use Glueful\Extensions\Subscriptions\Resolution\MemberEntitlementResolver;
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
 */
final class RequireMemberEntitlement implements RouteMiddleware
{
    public function __construct(
        private readonly MemberEntitlementResolver $resolver,
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

        $map = $this->resolver->resolveMap($this->context, $tenantUuid, $userUuid);

        if (array_key_exists($entitlement, $map) && EntitlementValue::allows($map[$entitlement])) {
            return $next($request);
        }

        return Response::error('Entitlement required', Response::HTTP_FORBIDDEN, [
            'code' => 'entitlement',
            'entitlement' => $entitlement,
        ]);
    }
}
