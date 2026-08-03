<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Support;

use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;

/**
 * A REAL implementation of payvia's PaymentProviderEventInterface (unlike the
 * deliberately payvia-neutral FakeProviderEvent, which duck-types the same shape
 * but does NOT `implements` it, keeping the ordinary-bus suite payvia-absent
 * capable). StrictPayviaSubscriptionEventBridge is typed against the real
 * interface -- payvia is a require-dev fixture since Task 5 -- so its tests need
 * a fake that actually satisfies it, with explicit constructor values for all
 * eight interface methods (no silent defaults hiding a missed field).
 */
final class StrictFakeProviderEvent implements PaymentProviderEventInterface
{
    /**
     * @param array<string,mixed> $normalized
     * @param array<string,mixed> $raw
     */
    public function __construct(
        private readonly string $gateway,
        private readonly string $type,
        private readonly ?string $providerEventId,
        private readonly string $deliveryKey,
        private readonly string $logicalEventKey,
        private readonly \DateTimeImmutable $occurredAt,
        private readonly array $normalized,
        private readonly array $raw,
    ) {
    }

    public function gateway(): string
    {
        return $this->gateway;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function providerEventId(): ?string
    {
        return $this->providerEventId;
    }

    public function deliveryKey(): string
    {
        return $this->deliveryKey;
    }

    public function logicalEventKey(): string
    {
        return $this->logicalEventKey;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /** @return array<string,mixed> */
    public function normalized(): array
    {
        return $this->normalized;
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->raw;
    }
}
