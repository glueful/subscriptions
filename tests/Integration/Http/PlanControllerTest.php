<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Http;

use Glueful\Auth\AuthenticationManager;
use Glueful\Auth\UserIdentity;
use Glueful\Extensions\Subscriptions\Http\PlanController;
use Glueful\Extensions\Subscriptions\Http\RequirePlanManagementPermission;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Glueful\Extensions\Subscriptions\Plans\PlanPayloadValidator;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;
use Glueful\Extensions\Subscriptions\Tests\Support\FakePermissionManager;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Glueful\Permissions\PermissionManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PlanControllerTest extends SubscriptionsTestCase
{
    private PlanManagementService $plans;
    private PlanController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bind(AuthenticationManager::class, new AuthenticationManager());
        $this->bind(Request::class, Request::create('/'));

        $this->plans = new PlanManagementService(
            $this->appContext(),
            new SubscriptionPlanRepository(),
            new PlanPayloadValidator()
        );
        $this->controller = new PlanController($this->appContext(), $this->plans);
    }

    public function testListReturnsPlansOrderedBySortOrderThenPlanKey(): void
    {
        $this->plans->create($this->payload('team', sortOrder: 20));
        $this->plans->create($this->payload('basic', sortOrder: 10));
        $this->plans->create($this->payload('alpha', sortOrder: 10));

        $data = $this->json($this->controller->index(Request::create('/subscriptions/plans')));

        self::assertSame(['alpha', 'basic', 'team'], array_column($data['data']['plans'], 'plan_key'));
    }

    public function testShowReturnsDottedPlanKey(): void
    {
        $this->plans->create($this->payload('team.pro'));

        $data = $this->json($this->controller->show(Request::create('/subscriptions/plans/team.pro'), 'team.pro'));

        self::assertSame('team.pro', $data['data']['plan']['plan_key']);
    }

    public function testCreateValidatesPayloadAndReturnsCreatedRow(): void
    {
        $response = $this->controller->store(
            $this->jsonRequest('POST', '/subscriptions/plans', $this->payload('team'))
        );
        $data = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('team', $data['data']['plan']['plan_key']);
    }

    public function testStoreIgnoresQueryStringAndUsesBodyValues(): void
    {
        $request = Request::create(
            '/subscriptions/plans?status=draft&entitlements[hijacked]=1',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($this->payload('team'), JSON_THROW_ON_ERROR)
        );

        $response = $this->controller->store($request);
        $data = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        // Body status ('active') wins; query-string status ('draft') is ignored.
        self::assertSame('active', $data['data']['plan']['status']);
        // Query-string entitlements are not merged into the body.
        self::assertArrayNotHasKey('hijacked', $data['data']['plan']['entitlements']);
    }

    public function testPatchRejectsActiveToDraft(): void
    {
        $this->plans->create($this->payload('team'));

        $response = $this->controller->update(
            $this->jsonRequest('PATCH', '/subscriptions/plans/team', ['status' => 'draft']),
            'team'
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function testPatchRejectsArchivedToDraft(): void
    {
        $this->plans->create($this->payload('team'));
        $this->plans->archive('team');

        $response = $this->controller->update(
            $this->jsonRequest('PATCH', '/subscriptions/plans/team', ['status' => 'draft']),
            'team'
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function testArchiveKeepsTenantSubscriptionsUnchanged(): void
    {
        $this->plans->create($this->payload('team'));
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'team']);

        $response = $this->controller->archive(Request::create('/subscriptions/plans/team/archive', 'POST'), 'team');

        self::assertSame(200, $response->getStatusCode());
        $subscription = $this->connection()->table('subscriptions')->where('tenant_uuid', 'tenantA')->first();
        self::assertSame('team', $subscription['plan_key']);
    }

    public function testImportConfigRouteIsRegisteredBeforeKeyedRoute(): void
    {
        $routes = (string) file_get_contents(__DIR__ . '/../../../routes.php');

        self::assertLessThan(strpos($routes, '/{key}'), strpos($routes, '/import-config'));
        self::assertStringContainsString("where('key', '[a-z0-9._-]+')", $routes);
    }

    public function testPermissionMiddlewareReturns403WithoutAuthenticatedUser(): void
    {
        $middleware = new RequirePlanManagementPermission($this->appContext());

        $response = $middleware->handle(Request::create('/'), static fn (): string => 'next');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testPermissionMiddlewareReturns403WhenManagerUnavailable(): void
    {
        $middleware = new RequirePlanManagementPermission($this->appContext());
        $request = Request::create('/');
        $request->attributes->set('auth.user', new UserIdentity('user-1'));

        $response = $middleware->handle($request, static fn (): string => 'next');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testPermissionMiddlewareReturns403WithRealManagerAndNoProvider(): void
    {
        $manager = new PermissionManager();
        $manager->clearProvider();
        $this->bind(PermissionManager::class, $manager);

        $middleware = new RequirePlanManagementPermission($this->appContext());
        $request = Request::create('/');
        $request->attributes->set('auth.user', new UserIdentity('user-1'));

        $response = $middleware->handle($request, static fn (): string => 'next');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testPermissionMiddlewareReturns403WhenPermissionDenied(): void
    {
        $this->bind(PermissionManager::class, new FakePermissionManager(false));
        $middleware = new RequirePlanManagementPermission($this->appContext());
        $request = Request::create('/');
        $request->attributes->set('auth.user', new UserIdentity('user-1'));

        $response = $middleware->handle($request, static fn (): string => 'next');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testPermissionMiddlewareCallsNextOnlyWhenAllowed(): void
    {
        $manager = new FakePermissionManager(true);
        $this->bind(PermissionManager::class, $manager);
        $middleware = new RequirePlanManagementPermission($this->appContext());
        $request = Request::create('/');
        $request->attributes->set('auth.user', new UserIdentity('user-1'));

        $called = false;
        $response = $middleware->handle($request, function (Request $request) use (&$called): JsonResponse {
            $called = true;
            return new JsonResponse(['ok' => true], 200);
        });

        self::assertTrue($called);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'user-1',
            'subscriptions.plans.manage',
            'subscriptions.plans',
        ], array_slice($manager->lastCall, 0, 3));
        self::assertArrayHasKey('roles', $manager->lastCall[3]);
    }

    /** @return array<string,mixed> */
    private function payload(string $key, int $sortOrder = 10): array
    {
        return [
            'plan_key' => $key,
            'display_name' => ucfirst(str_replace('.', ' ', $key)),
            'entitlements' => ['projects.limit' => 10],
            'status' => 'active',
            'sort_order' => $sortOrder,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function jsonRequest(string $method, string $uri, array $payload): Request
    {
        return Request::create(
            $uri,
            $method,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
    }

    /** @return array<string,mixed> */
    private function json(JsonResponse|\Glueful\Http\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
