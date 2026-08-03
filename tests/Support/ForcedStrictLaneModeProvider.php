<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Support;

use Glueful\Extensions\Subscriptions\Bridge\StrictLaneRegistration;
use Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider;

/**
 * Task 7 -- a config-registerable fixture provider whose `services()` forces
 * an EXPLICIT {@see StrictLaneRegistration} mode through the real
 * {@see SubscriptionsServiceProvider::serviceDefinitionsForMode()} helper,
 * instead of the real provider's own `strictLaneMode()` probe (which always
 * resolves STRICT in this repo, since payvia ^2.4 is a permanent
 * require-dev fixture -- see the Task-5 ledger note). This lets
 * `StrictLaneTagWiringTest` drive the REAL `ContainerFactory`/DSL-tag
 * pipeline for the bus/none branches too, without any runtime class fakery:
 * set {@see self::$mode} (and, since the fix round, {@see self::$payviaRuntimePresent}
 * -- `serviceDefinitionsForMode()` is now pure and takes it as an explicit
 * parameter instead of probing `class_exists()` itself) before building the
 * container.
 */
final class ForcedStrictLaneModeProvider
{
    public static string $mode = StrictLaneRegistration::STRICT;

    public static bool $payviaRuntimePresent = true;

    /** @return array<string, mixed> */
    public static function services(): array
    {
        return SubscriptionsServiceProvider::serviceDefinitionsForMode(self::$mode, self::$payviaRuntimePresent);
    }
}
