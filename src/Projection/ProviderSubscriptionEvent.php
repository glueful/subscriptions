<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Projection;

/**
 * Generic, provider-agnostic input to the subscription event projector.
 * Provider bridges adapt their provider's event into this DTO.
 */
final class ProviderSubscriptionEvent
{
    /** @param array<string,mixed> $normalized */
    public function __construct(
        public readonly string $gateway,
        public readonly string $type,
        public readonly string $logicalEventKey,
        public readonly array $normalized,
    ) {
    }
}
