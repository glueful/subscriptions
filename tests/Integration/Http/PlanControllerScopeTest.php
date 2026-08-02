<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Http;

use Glueful\Auth\AuthenticationManager;
use Glueful\Extensions\Subscriptions\Http\PlanController;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Glueful\Extensions\Subscriptions\Plans\PlanPayloadValidator;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * PlanController stays pinned to the platform scope ('tenant', '') only -- it
 * gains no new params, and no HTTP request can cause it to target a workspace
 * scope, either by smuggling 'audience'/'owner_tenant_uuid' into a write body or
 * by naming a plan key that also exists in a workspace catalog. The latter WAS a
 * real hole: index()/show()/update()/archive() call the unqualified
 * PlanManagementService methods, which were unscoped before the 2.0 activation
 * turned them into platform-scope delegates.
 */
final class PlanControllerScopeTest extends SubscriptionsTestCase
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

    public function testControllerActionsAcceptNoScopeParameters(): void
    {
        $reflection = new \ReflectionClass(PlanController::class);

        foreach (['index', 'show', 'store', 'update', 'archive', 'importConfig'] as $method) {
            $params = array_map(
                static fn (\ReflectionParameter $p): string => strtolower($p->getName()),
                $reflection->getMethod($method)->getParameters()
            );

            self::assertNotContains('audience', $params, "{$method}() must not accept an audience parameter.");
            self::assertNotContains('owner', $params, "{$method}() must not accept an owner parameter.");
            self::assertNotContains(
                'ownertenantuuid',
                $params,
                "{$method}() must not accept an ownerTenantUuid parameter."
            );
        }
    }

    public function testStoreAlwaysWritesPlatformScopeEvenIfBodySmugglesScopeFields(): void
    {
        $request = Request::create(
            '/subscriptions/plans',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'plan_key' => 'team',
                'display_name' => 'Team',
                'entitlements' => ['projects.limit' => 10],
                'status' => 'active',
                'audience' => 'user',
                'owner_tenant_uuid' => 'workspace-1',
            ], JSON_THROW_ON_ERROR)
        );

        $response = $this->controller->store($request);
        self::assertSame(201, $response->getStatusCode());

        $stored = $this->connection()->table('subscription_plans')->where('plan_key', '=', 'team')->first();
        self::assertSame('tenant', $stored['audience']);
        self::assertSame('', $stored['owner_tenant_uuid']);
    }

    /** @return array<string,mixed> */
    private function json(JsonResponse|\Glueful\Http\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * REGRESSION (carried finding). `PATCH /subscriptions/plans/pro` used to reach
     * the unscoped `updateByKey()` and mutate EVERY row keyed 'pro' -- including a
     * workspace-owned membership plan the platform administrator has no authority
     * over. The controller is unchanged; the delegation underneath it is what
     * closes this.
     */
    public function testPatchNeverReachesASameKeyedWorkspacePlan(): void
    {
        $workspace = $this->plans->createInScope('user', 'workspace-1', [
            'plan_key' => 'pro',
            'display_name' => 'Workspace Pro',
            'entitlements' => ['posts.premium' => true],
            'status' => 'active',
        ]);

        $request = Request::create(
            '/subscriptions/plans/pro',
            'PATCH',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['display_name' => 'Platform Pro', 'status' => 'archived'], JSON_THROW_ON_ERROR)
        );

        $response = $this->controller->update($request, 'pro');
        self::assertSame(200, $response->getStatusCode());

        $platform = $this->plans->findInScope('tenant', '', 'pro');
        self::assertSame('Platform Pro', $platform['display_name']);
        self::assertSame('archived', $platform['status']);

        $untouched = $this->plans->findInScope('user', 'workspace-1', 'pro');
        self::assertSame($workspace['uuid'], $untouched['uuid']);
        self::assertSame('Workspace Pro', $untouched['display_name']);
        self::assertSame('active', $untouched['status']);
        self::assertSame(['posts.premium' => true], $untouched['entitlements']);
    }

    public function testIndexAndShowNeverExposeWorkspacePlans(): void
    {
        $this->plans->createInScope('user', 'workspace-1', [
            'plan_key' => 'members-only',
            'display_name' => 'Members Only',
            'entitlements' => ['posts.premium' => true],
            'status' => 'active',
        ]);

        $listed = array_column(
            $this->json($this->controller->index(Request::create('/subscriptions/plans')))['data']['plans'],
            'plan_key'
        );
        self::assertNotContains('members-only', $listed);

        $show = $this->controller->show(Request::create('/subscriptions/plans/members-only'), 'members-only');
        self::assertSame(404, $show->getStatusCode());
    }
}
