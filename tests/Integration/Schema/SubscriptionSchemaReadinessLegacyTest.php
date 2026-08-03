<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Schema;

use Glueful\Extensions\Subscriptions\Schema\SubscriptionSchemaReadiness;
use Glueful\Extensions\Subscriptions\Tests\Support\LegacySchemaTestCase;

/**
 * Task 3: a 1.x-shaped (pre-006) install -- no subject columns, no scoped plan
 * catalog columns, no provider-event receipts table -- must never be reported
 * ready. LegacySchemaTestCase applies migrations 001-005 (the shipped 1.x
 * schema plus the 1.4 upgrade-bridge preparation-state table); migration 006
 * (SubjectModel), which introduces every column/table this authority checks
 * for, is never run here.
 */
final class SubscriptionSchemaReadinessLegacyTest extends LegacySchemaTestCase
{
    public function testLegacyPreSubjectModelSchemaIsNotReady(): void
    {
        $readiness = new SubscriptionSchemaReadiness($this->appContext());

        self::assertFalse($readiness->isReady());
    }
}
