<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Contracts;

use Glueful\Extensions\Subscriptions\Projection\ProjectionOutcome;
use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;

interface SubscriptionEventProjectorInterface
{
    public function project(ProviderSubscriptionEvent $event): void;

    /**
     * Additive outcome-returning entry point (design spec §4.3, Task 12): identical
     * projection rules to project(), but returns the settled {@see ProjectionOutcome}
     * (accepted, or a deterministic rejection) instead of discarding it. Unmapped and
     * transient failures are NOT outcomes -- both still throw, uncaught, exactly as
     * project() lets them.
     */
    public function projectWithOutcome(ProviderSubscriptionEvent $event): ProjectionOutcome;
}
