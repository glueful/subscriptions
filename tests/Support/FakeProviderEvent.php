<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Support;

/**
 * Duck-typed stand-in for payvia's PaymentProviderEventInterface (same method
 * shape, verified against ../payvia/src/Contracts/PaymentProviderEventInterface.php).
 * It deliberately does NOT `implements` the payvia interface so the suite runs
 * with no payvia installed -- the listener reads via these methods only.
 */
final class FakeProviderEvent
{
    /**
     * @param array<string,mixed> $normalized
     * @param array<string,mixed> $raw
     */
    public function __construct(
        private readonly string $gateway,
        private readonly string $type,
        private readonly string $logicalEventKey,
        private readonly array $normalized = [],
        private readonly array $raw = [],
        private readonly ?string $providerEventId = null,
        private readonly string $deliveryKey = 'delivery-1',
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
        return new \DateTimeImmutable('now');
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
