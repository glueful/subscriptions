<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Entitlements\Contracts\EntitlementCheckerInterface;
use Glueful\Extensions\Subscriptions\Tenant\CurrentTenant;
use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fail-closed entitlement gate (S4).
 *
 * Supported API is the middleware-string form: ->middleware(['require_entitlement:reports.export']).
 * (The framework's attribute route loader has no generic attribute->middleware bridge for
 * extension attributes, so a #[RequireEntitlement] attribute is deferred -- plan blocker B1.)
 */
final class RequireEntitlement implements RouteMiddleware
{
    public function __construct(
        private readonly EntitlementCheckerInterface $checker,
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

        $tenantUuid = CurrentTenant::resolve($this->context);
        if ($tenantUuid === null) {
            if (config($this->context, 'subscriptions.permissive_middleware', false) === true) {
                return $next($request);
            }

            return Response::error('Entitlement check failed: no tenant context', Response::HTTP_FORBIDDEN, [
                'code' => 'entitlement',
            ]);
        }

        if ($this->checker->allows($tenantUuid, $entitlement)) {
            return $next($request);
        }

        return Response::error('Entitlement required', Response::HTTP_FORBIDDEN, [
            'code' => 'entitlement',
            'entitlement' => $entitlement,
        ]);
    }
}
