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
            'past_due' => $this->withinGrace($subscription['grace_ends_at'] ?? null, $now) ? $plan : $defaultPlan,
            default => $defaultPlan,
        };
    }

    private function withinGrace(mixed $graceEndsAt, \DateTimeImmutable $now): bool
    {
        if (!is_scalar($graceEndsAt) || (string) $graceEndsAt === '') {
            return false;
        }

        try {
            return new \DateTimeImmutable((string) $graceEndsAt) > $now;
        } catch (\Throwable) {
            return false;
        }
    }
}
