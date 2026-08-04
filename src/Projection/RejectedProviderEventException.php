<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Projection;

/**
 * Internal control-flow signal ONLY -- never escapes
 * SubscriptionEventProjector::project()/projectWithOutcome(). Carries one of the
 * allowlisted, DETERMINISTIC rejection codes (missing_subject, invalid_subject,
 * plan_scope_mismatch, subject_mismatch, origination_mismatch): a validation failure that would
 * fail identically on every retry, so -- unlike
 * UnmappedProviderSubscriptionException -- it is caught INSIDE the projection
 * transaction, settles the receipt `rejected` with this code, and the
 * transaction COMMITS: a rejection is a diagnosable outcome, not an error.
 *
 * A dedicated exception (rather than threading a nullable-string rejection
 * code through several private methods' return tuples) makes the code
 * non-null BY CONSTRUCTION wherever it's read -- there is no `@var string`
 * annotation standing in for an invariant the type checker can't otherwise
 * see, and no risk of a null code ever reaching
 * ProviderEventReceiptRepository::markRejected() (which would raise a
 * TypeError that -- unlike a normal \Throwable -- could escape the
 * transaction's own commit bookkeeping in an inconsistent state).
 */
final class RejectedProviderEventException extends \RuntimeException
{
    public function __construct(public readonly string $rejectionCode)
    {
        parent::__construct("Provider event rejected: {$rejectionCode}");
    }
}
