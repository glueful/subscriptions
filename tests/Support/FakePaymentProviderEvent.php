<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Support;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Mirrors payvia's PaymentProviderEvent (a BaseEvent with a public readonly
 * $event payload) so the listener can read `$payviaEvent->event` without payvia
 * being installed at test time.
 */
final class FakePaymentProviderEvent extends BaseEvent
{
    public function __construct(public readonly FakeProviderEvent $event)
    {
        parent::__construct();
    }
}
