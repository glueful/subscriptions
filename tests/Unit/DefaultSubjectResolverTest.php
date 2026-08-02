<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Unit;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Resolution\DefaultSubjectResolver;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubjectType;
use PHPUnit\Framework\TestCase;

final class DefaultSubjectResolverTest extends TestCase
{
    private DefaultSubjectResolver $resolver;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new DefaultSubjectResolver();
        $this->context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
    }

    public function testValidateAcceptsTenantSelfSubject(): void
    {
        $subject = Subject::tenant('t-1');

        self::assertTrue($this->resolver->validate($this->context, $subject));
    }

    public function testValidateRejectsTenantNonSelfSubject(): void
    {
        $subject = new Subject('t-1', SubjectType::TENANT, 't-2');

        self::assertFalse($this->resolver->validate($this->context, $subject));
    }

    public function testValidateRejectsEmptyTenantUuid(): void
    {
        $subject = new Subject('', SubjectType::TENANT, 't-1');

        self::assertFalse($this->resolver->validate($this->context, $subject));
    }

    public function testValidateRejectsEmptyUuid(): void
    {
        $subject = new Subject('t-1', SubjectType::TENANT, '');

        self::assertFalse($this->resolver->validate($this->context, $subject));
    }

    public function testValidateRejectsAllUserSubjects(): void
    {
        $subject = Subject::user('t-1', 'u-1');

        self::assertFalse($this->resolver->validate($this->context, $subject));
    }

    public function testCurrentTenantReturnsNullWithoutTenantInRequestState(): void
    {
        self::assertNull($this->resolver->currentTenant($this->context));
    }

    public function testCurrentTenantReturnsTenantUuidFromRequestState(): void
    {
        $this->context->setRequestState('tenancy.tenant', new class {
            public string $uuid = 'tenantA12345';
        });

        self::assertSame('tenantA12345', $this->resolver->currentTenant($this->context));
    }

    public function testCurrentUserAlwaysReturnsNull(): void
    {
        self::assertNull($this->resolver->currentUser($this->context));
    }
}
