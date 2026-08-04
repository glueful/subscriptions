<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Projection;

/**
 * The durable, diagnosable verdict `SubscriptionEventProjector::projectWithOutcome()`
 * (design spec §4.3, Task 12) returns for every event that reaches a settled receipt --
 * i.e. every path except the two that stay retryable and therefore never produce one:
 * an unmapped subscription or a genuinely transient failure both still THROW, uncaught.
 *
 * `outcome` mirrors `subscription_provider_event_receipts.outcome` exactly
 * ('accepted'|'rejected'); `reason` mirrors `rejection_code` (null for an accepted
 * outcome, one of the deterministic rejection codes -- subject_mismatch,
 * plan_scope_mismatch, origination_mismatch, missing_subject, invalid_subject --
 * otherwise). `logicalEventKey` is echoed back verbatim (already clamped to the
 * receipt column's width) so a caller such as {@see \Glueful\Extensions\Subscriptions\
 * Bridge\StrictPayviaSubscriptionEventBridge} can acknowledge against the SAME key the
 * receipt was settled under, whether this instance came from a fresh projection or a
 * re-read of an already-stored duplicate.
 */
final class ProjectionOutcome
{
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $reason,
        public readonly string $logicalEventKey,
    ) {
    }

    public static function accepted(string $logicalEventKey): self
    {
        return new self('accepted', null, $logicalEventKey);
    }

    public static function rejected(string $logicalEventKey, string $reason): self
    {
        return new self('rejected', $reason, $logicalEventKey);
    }

    /**
     * Reconstructs whatever a receipt row already settled to (design spec §4.3): the
     * SOLE constructor used for a duplicate-delivery replay, so a stored 'accepted'
     * (reason always NULL on that outcome) or 'rejected' (reason the stored
     * rejection_code) round-trips byte-identically regardless of which branch of
     * projectWithOutcome() is doing the re-reading.
     */
    public static function fromStoredOutcome(string $outcome, ?string $reason, string $logicalEventKey): self
    {
        return new self($outcome, $reason, $logicalEventKey);
    }
}
