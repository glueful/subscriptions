<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Http;

use Glueful\Auth\AuthenticationManager;
use Glueful\Extensions\Subscriptions\Http\PlanController;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Glueful\Extensions\Subscriptions\Plans\PlanPayloadValidator;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;
use Glueful\Extensions\Subscriptions\Tests\Support\V2SubscriptionsTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Task 8: PlanController stays pinned to the platform scope ('tenant', '') only --
 * it gains no new params, and no HTTP request can cause it to target a workspace
 * scope, even by smuggling 'audience'/'owner_tenant_uuid' into the write body. Its
 * index()/show()/update()/archive() actions still call the unscoped 1.x
 * PlanManagementService methods (byte-identical per this task's pin, and required
 * so the controller keeps working against pre-migration-006 schemas); those
 * unscoped reads not filtering by scope is a known, explicitly deferred limitation
 * that Task 9's coordinated cutover closes by switching them to platform-scope
 * delegates. Runs on the post-006 V2SubscriptionsTestCase harness.
 */
final class PlanControllerScopeTest extends V2SubscriptionsTestCase
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
}
