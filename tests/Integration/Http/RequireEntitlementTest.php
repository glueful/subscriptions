<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Http;

use Glueful\Entitlements\Contracts\EntitlementCheckerInterface;
use Glueful\Extensions\Subscriptions\Http\RequireEntitlement;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class RequireEntitlementTest extends SubscriptionsTestCase
{
    private bool $nextCalled = false;

    private function checker(bool $allows): EntitlementCheckerInterface
    {
        return new class ($allows) implements EntitlementCheckerInterface {
            public function __construct(private readonly bool $allows)
            {
            }

            public function allows(string $tenantUuid, string $entitlement, array $context = []): bool
            {
                return $this->allows;
            }

            public function limit(string $tenantUuid, string $entitlement, array $context = []): ?int
            {
                return $this->allows ? null : 0;
            }
        };
    }

    private function next(): callable
    {
        $this->nextCalled = false;

        return function (Request $request): Response {
            $this->nextCalled = true;

            return new Response(['ok' => true]);
        };
    }

    private function setTenant(string $uuid): void
    {
        $this->appContext()->setRequestState('tenancy.tenant', new class ($uuid) {
            public function __construct(public string $uuid)
            {
            }
        });
    }

    /** @return array<string,mixed> */
    private function payload(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testAllowedEntitlementPassesThrough(): void
    {
        $this->setTenant('tenantA');
        $middleware = new RequireEntitlement($this->checker(true), $this->appContext());

        $response = $middleware->handle(Request::create('/reports'), $this->next(), 'reports.export');

        self::assertTrue($this->nextCalled);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testDeniedEntitlementReturns403WithEntitlementCode(): void
    {
        $this->setTenant('tenantA');
        $middleware = new RequireEntitlement($this->checker(false), $this->appContext());

        $response = $middleware->handle(Request::create('/reports'), $this->next(), 'reports.export');

        self::assertFalse($this->nextCalled);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('entitlement', $this->payload($response)['error']['details']['code']);
    }

    public function testNoTenantFailsClosedByDefault(): void
    {
        // No tenant in request state + permissive_middleware=false (shipped default):
        // a wired paywall with a broken precondition is a misconfiguration, not an
        // open door (S4).
        $middleware = new RequireEntitlement($this->checker(true), $this->appContext());

        $response = $middleware->handle(Request::create('/reports'), $this->next(), 'reports.export');

        self::assertFalse($this->nextCalled);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('entitlement', $this->payload($response)['error']['details']['code']);
    }

    public function testNoTenantWithPermissiveFlagIsNoOpAllow(): void
    {
        $this->setConfig('subscriptions.permissive_middleware', true);
        $middleware = new RequireEntitlement($this->checker(false), $this->appContext());

        $response = $middleware->handle(Request::create('/reports'), $this->next(), 'reports.export');

        self::assertTrue($this->nextCalled);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testMissingEntitlementParamIsMisconfiguration(): void
    {
        $this->setTenant('tenantA');
        $middleware = new RequireEntitlement($this->checker(true), $this->appContext());

        $response = $middleware->handle(Request::create('/reports'), $this->next());

        self::assertFalse($this->nextCalled);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }
}
