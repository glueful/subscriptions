<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Unit\Bridge;

use Glueful\Extensions\Subscriptions\Bridge\StrictLaneRegistration;
use PHPUnit\Framework\TestCase;

/**
 * Task 7 -- spec §4: the three-branch truth table behind the single-lane
 * strict/bus/none decision. `decide()` is deliberately a pure function of two
 * booleans (see the class docblock) so every branch -- including the two
 * (BUS, NONE) that are otherwise unreachable in-process now that payvia ^2.4
 * is a permanent require-dev fixture -- is directly testable here without any
 * runtime class fakery.
 */
final class StrictLaneRegistrationTest extends TestCase
{
    public function testStrictWinsWhenTheStrictContractIsPresentRegardlessOfTheEvent(): void
    {
        self::assertSame(
            StrictLaneRegistration::STRICT,
            StrictLaneRegistration::decide(strictContractPresent: true, payviaEventPresent: true)
        );
        self::assertSame(
            StrictLaneRegistration::STRICT,
            StrictLaneRegistration::decide(strictContractPresent: true, payviaEventPresent: false)
        );
    }

    public function testBusIsTheFallbackWhenOnlyTheOrdinaryEventIsPresent(): void
    {
        self::assertSame(
            StrictLaneRegistration::BUS,
            StrictLaneRegistration::decide(strictContractPresent: false, payviaEventPresent: true)
        );
    }

    public function testNoneWhenNeitherPayviaSurfaceIsPresent(): void
    {
        self::assertSame(
            StrictLaneRegistration::NONE,
            StrictLaneRegistration::decide(strictContractPresent: false, payviaEventPresent: false)
        );
    }
}
