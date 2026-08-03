<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Http;

use Glueful\Extensions\Subscriptions\Http\RequireMemberEntitlement;
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\EffectivePlanResolver;
use Glueful\Extensions\Subscriptions\Resolution\MemberEntitlementResolverFactory;
use Glueful\Extensions\Subscriptions\Tests\Support\PermissiveSubjectResolver;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Mirrors RequireEntitlementTest's request/middleware harness for the member
 * (workspace user) gate: both currentTenant() and currentUser() must resolve via
 * SubjectResolverInterface before the member map is even consulted -- either
 * missing is fail-closed (S4), same as the tenant gate's missing-tenant case.
 */
final class RequireMemberEntitlementTest extends SubscriptionsTestCase
{
    private const TENANT = 'tenantA';
    private const USER = 'userA';

    private bool $nextCalled = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection()->table('subscription_plans')->insert([
            'uuid' => 'planmemberpro01',
            'plan_key' => 'member-pro',
            'display_name' => 'Member Pro',
            'entitlements' => json_encode(['content.premium' => true], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'sort_order' => 0,
            'audience' => 'user',
            'owner_tenant_uuid' => self::TENANT,
        ]);
    }

    /**
     * Task 14 fix round: the middleware now takes a stateless
     * MemberEntitlementResolverFactory and builds a workspace-scoped resolver
     * itself, inside handle() -- so this factory is never pre-scoped to
     * self::TENANT here; it derives its scope from whatever tenantUuid
     * handle() actually resolves at call time.
     */
    private function resolverFactory(): MemberEntitlementResolverFactory
    {
        return new MemberEntitlementResolverFactory(
            new SubscriptionRepository(),
            new OverrideRepository(),
            new EffectivePlanResolver(),
            null,
            false,
            300
        );
    }

    private function middleware(?string $tenantUuid, ?string $userUuid): RequireMemberEntitlement
    {
        return new RequireMemberEntitlement(
            $this->resolverFactory(),
            new PermissiveSubjectResolver($tenantUuid, $userUuid),
            $this->appContext()
        );
    }

    private function next(): callable
    {
        $this->nextCalled = false;

        return function (Request $request): Response {
            $this->nextCalled = true;

            return new Response(['ok' => true]);
        };
    }

    /** @return array<string,mixed> */
    private function payload(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testMemberGrantedEntitlementPassesThrough(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => self::TENANT,
            'subject_type' => 'user',
            'subject_uuid' => self::USER,
            'plan_key' => 'member-pro',
            'status' => 'active',
        ]);
        $middleware = $this->middleware(self::TENANT, self::USER);

        $response = $middleware->handle(Request::create('/content'), $this->next(), 'content.premium');

        self::assertTrue($this->nextCalled);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testMemberDeniedEntitlementReturns403WithEntitlementCode(): void
    {
        // No membership row at all -- the member map is empty, so the key is
        // absent (never granted).
        $middleware = $this->middleware(self::TENANT, self::USER);

        $response = $middleware->handle(Request::create('/content'), $this->next(), 'content.premium');

        self::assertFalse($this->nextCalled);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('entitlement', $this->payload($response)['error']['details']['code']);
        self::assertSame('content.premium', $this->payload($response)['error']['details']['entitlement']);
    }

    public function testMissingCurrentTenantFailsClosedByDefault(): void
    {
        $middleware = $this->middleware(null, self::USER);

        $response = $middleware->handle(Request::create('/content'), $this->next(), 'content.premium');

        self::assertFalse($this->nextCalled);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('entitlement', $this->payload($response)['error']['details']['code']);
    }

    public function testMissingCurrentUserFailsClosedByDefault(): void
    {
        $middleware = $this->middleware(self::TENANT, null);

        $response = $middleware->handle(Request::create('/content'), $this->next(), 'content.premium');

        self::assertFalse($this->nextCalled);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('entitlement', $this->payload($response)['error']['details']['code']);
    }

    public function testBothMissingWithPermissiveFlagIsNoOpAllow(): void
    {
        $this->setConfig('subscriptions.permissive_middleware', true);
        $middleware = $this->middleware(null, null);

        $response = $middleware->handle(Request::create('/content'), $this->next(), 'content.premium');

        self::assertTrue($this->nextCalled);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testMissingTenantWithPermissiveFlagIsNoOpAllow(): void
    {
        $this->setConfig('subscriptions.permissive_middleware', true);
        $middleware = $this->middleware(null, self::USER);

        $response = $middleware->handle(Request::create('/content'), $this->next(), 'content.premium');

        self::assertTrue($this->nextCalled);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testMissingEntitlementParamIsMisconfiguration(): void
    {
        $middleware = $this->middleware(self::TENANT, self::USER);

        $response = $middleware->handle(Request::create('/content'), $this->next());

        self::assertFalse($this->nextCalled);
        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }
}
