<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Projection;

/**
 * Thrown when an inbound provider event cannot be mapped to (or safely
 * recovered onto) any subscription row: there is genuinely nothing to project
 * onto YET, and that is expected to change -- the local subscription hasn't
 * been created yet, or a relink target is momentarily ambiguous -- rather than
 * a permanent identity/authorization failure.
 *
 * Deliberately RETRYABLE, not a committed rejection (spec ruling, Task 10 fix
 * round 2): unlike the deterministic rejection codes (missing_subject,
 * invalid_subject, plan_scope_mismatch, subject_mismatch), which will fail the
 * exact same way on every redelivery and so are settled `rejected` and
 * committed, "unmapped" can resolve itself with time. SubscriptionEventProjector
 * ::project() lets this exception propagate UNCAUGHT out of its transaction,
 * rolling back the whole thing -- including the just-claimed pending receipt
 * -- so the SAME logical event can be retried once the local side catches up
 * and succeed.
 *
 * IMPORTANT for callers: any webhook endpoint or bridge that invokes the
 * projector MUST let this exception surface as a retry-inducing response (a
 * 5xx, or whatever status the provider treats as "redeliver this event").
 * Catching it here and still returning a 2xx would acknowledge the webhook
 * while silently discarding the event -- the receipt claim (the only durable
 * memory of having seen it) was just rolled back along with everything else,
 * so nothing remembers this delivery happened at all.
 */
final class UnmappedProviderSubscriptionException extends \RuntimeException
{
}
