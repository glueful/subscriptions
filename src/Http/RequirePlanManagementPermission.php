<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Http;

use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Permissions\PermissionManager;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

final class RequirePlanManagementPermission implements RouteMiddleware
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $user = $request->attributes->get('auth.user');
        if (!$user instanceof UserIdentity) {
            return $this->forbidden();
        }

        $permissions = $this->permissionManager();
        if ($permissions === null) {
            return $this->forbidden();
        }

        $context = [
            'roles' => $user->roles(),
            'scopes' => $user->scopes(),
            'tenant_id' => $request->attributes->get('tenant.id'),
            'route_params' => (array) $request->attributes->get('route.params'),
            'jwt_claims' => (array) $request->attributes->get('jwt.claims'),
        ];

        if (!$permissions->can($user->id(), 'subscriptions.plans.manage', 'subscriptions.plans', $context)) {
            return $this->forbidden();
        }

        return $next($request);
    }

    private function permissionManager(): ?PermissionManager
    {
        $container = $this->context->getContainer();

        foreach ([PermissionManager::class, 'permission.manager'] as $id) {
            try {
                if ($container->has($id)) {
                    $manager = $container->get($id);
                    if ($manager instanceof PermissionManager) {
                        return $manager;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function forbidden(): Response
    {
        return new Response([
            'success' => false,
            'message' => 'Forbidden',
            'code' => 403,
            'error_code' => 'FORBIDDEN',
        ], 403);
    }
}
