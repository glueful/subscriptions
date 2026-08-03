<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Unit;

use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubjectType;
use PHPUnit\Framework\TestCase;

final class SubjectTest extends TestCase
{
    public function testTenantFactoryProducesCoherentTenantSubject(): void
    {
        $subject = Subject::tenant('t-1');

        self::assertSame('t-1', $subject->tenantUuid);
        self::assertSame(SubjectType::TENANT, $subject->type);
        self::assertSame('t-1', $subject->uuid);
    }

    public function testUserFactoryProducesUserSubject(): void
    {
        $subject = Subject::user('t-1', 'u-1');

        self::assertSame('t-1', $subject->tenantUuid);
        self::assertSame(SubjectType::USER, $subject->type);
        self::assertSame('u-1', $subject->uuid);
    }

    public function testConstructorAllowsAnyTriple(): void
    {
        $subject = new Subject('t-1', SubjectType::TENANT, 't-2');

        self::assertSame('t-1', $subject->tenantUuid);
        self::assertSame(SubjectType::TENANT, $subject->type);
        self::assertSame('t-2', $subject->uuid);
    }

    public function testPropertiesAreReadonly(): void
    {
        $subject = Subject::tenant('t-1');

        $this->expectException(\Error::class);
        $subject->tenantUuid = 't-2';
    }
}
