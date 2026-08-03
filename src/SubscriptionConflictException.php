<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions;

/**
 * Stable domain conflict raised by SubscriptionService::startFor() when a
 * concurrent caller won the `UNIQUE (tenant_uuid, subject_type, subject_uuid)`
 * race with a DIFFERENT plan (spec §8).
 *
 * A lost race against the SAME plan_uuid is idempotent and returns the winner
 * row instead; only a genuine divergence raises this. `startFor()` never
 * silently becomes `changePlanFor()`, and the losing call changes nothing.
 */
final class SubscriptionConflictException extends \RuntimeException
{
    public static function forSubject(Subject $subject, string $requestedPlanUuid, string $winningPlanUuid): self
    {
        return new self(sprintf(
            "Subscription for subject '%s:%s' in tenant '%s' already exists on plan '%s'; "
            . "refusing to start it on plan '%s'.",
            $subject->type,
            $subject->uuid,
            $subject->tenantUuid,
            $winningPlanUuid,
            $requestedPlanUuid
        ));
    }
}
