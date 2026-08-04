<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Resolution;

final class EffectivePlanResolver
{
    /** @param array<string,mixed>|null $subscription */
    public function resolve(?array $subscription, string $defaultPlan, \DateTimeImmutable $now): string
    {
        if ($subscription === null) {
            return $defaultPlan;
        }

        $plan = (string) ($subscription['plan_key'] ?? $defaultPlan);

        return match ((string) ($subscription['status'] ?? '')) {
            'active', 'trialing' => $plan,
            'past_due' => $this->isFuture($subscription['grace_ends_at'] ?? null, $now) ? $plan : $defaultPlan,
            // 'non_renewing' (design spec §3.7/§4.3, Task 11): Paystack's
            // stop_renewal disable stops future charges but the already-paid
            // period runs to its end -- the plan stays entitled only while
            // `current_period_end` is still ahead of $now. Absent/invalid/past
            // fails closed to the default plan, exactly like past_due's grace
            // window above.
            'non_renewing' => $this->isFuture($subscription['current_period_end'] ?? null, $now) ? $plan : $defaultPlan,
            default => $defaultPlan,
        };
    }

    /**
     * Shared defensive-parse boundary check for both past_due's grace window
     * and non_renewing's period-end boundary: an absent, non-scalar, or
     * unparseable timestamp is simply "not in the future", never a thrown
     * error -- entitlement resolution must stay fail-closed-not-fatal.
     */
    private function isFuture(mixed $timestamp, \DateTimeImmutable $now): bool
    {
        if (!is_scalar($timestamp) || (string) $timestamp === '') {
            return false;
        }

        try {
            return new \DateTimeImmutable((string) $timestamp) > $now;
        } catch (\Throwable) {
            return false;
        }
    }
}
