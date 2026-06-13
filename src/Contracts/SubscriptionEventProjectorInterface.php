<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Contracts;

use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;

interface SubscriptionEventProjectorInterface
{
    public function project(ProviderSubscriptionEvent $event): void;
}
