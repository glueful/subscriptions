<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Plans;

use InvalidArgumentException;

final class PlanPayloadValidator
{
    private const PLAN_KEY_PATTERN = '/\A[a-z0-9._-]{1,64}\z/';

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function validateCreate(array $payload): array
    {
        foreach (['plan_key', 'display_name', 'entitlements', 'status'] as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new InvalidArgumentException("Missing required plan field: {$field}.");
            }
        }

        return [
            'plan_key' => $this->validatePlanKey((string) $payload['plan_key']),
            'display_name' => $this->validateDisplayName($payload['display_name']),
            'description' => $this->nullableString($payload['description'] ?? null, 'description'),
            'entitlements' => $this->validateEntitlements($payload['entitlements']),
            'payvia_priced_plan_uuid' => $this->nullableString(
                $payload['payvia_priced_plan_uuid'] ?? null,
                'payvia_priced_plan_uuid'
            ),
            'status' => $this->validateStatus($payload['status']),
            'sort_order' => $this->validateSortOrder($payload['sort_order'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    public function validatePatch(array $payload, array $current): array
    {
        if (array_key_exists('plan_key', $payload) && $payload['plan_key'] !== ($current['plan_key'] ?? null)) {
            throw new InvalidArgumentException('plan_key is immutable.');
        }

        $validated = [];

        if (array_key_exists('display_name', $payload)) {
            $validated['display_name'] = $this->validateDisplayName($payload['display_name']);
        }

        if (array_key_exists('description', $payload)) {
            $validated['description'] = $this->nullableString($payload['description'], 'description');
        }

        if (array_key_exists('entitlements', $payload)) {
            $validated['entitlements'] = $this->validateEntitlements($payload['entitlements']);
        }

        if (array_key_exists('payvia_priced_plan_uuid', $payload)) {
            $validated['payvia_priced_plan_uuid'] = $this->nullableString(
                $payload['payvia_priced_plan_uuid'],
                'payvia_priced_plan_uuid'
            );
        }

        if (array_key_exists('status', $payload)) {
            $from = $this->validateStatus($current['status'] ?? '');
            $to = $this->validateStatus($payload['status']);
            $this->validateTransition($from, $to);
            $validated['status'] = $to;
        }

        if (array_key_exists('sort_order', $payload)) {
            $validated['sort_order'] = $this->validateSortOrder($payload['sort_order']);
        }

        return $validated;
    }

    /** @param array<string,mixed> $configPlan @return array<string,mixed> */
    public function validateImportConfigPlan(string $planKey, array $configPlan, string $status): array
    {
        $pricedPlan = $configPlan['payvia_priced_plan_uuid']
            ?? $configPlan['payvia_priced_plan']
            ?? null;

        return $this->validateCreate([
            'plan_key' => $planKey,
            'display_name' => $configPlan['display_name'] ?? $configPlan['name'] ?? $planKey,
            'description' => $configPlan['description'] ?? null,
            'entitlements' => $configPlan['entitlements'] ?? [],
            'payvia_priced_plan_uuid' => $pricedPlan,
            'status' => $status,
            'sort_order' => $configPlan['sort_order'] ?? 0,
        ]);
    }

    private function validatePlanKey(string $planKey): string
    {
        if ($planKey === 'import-config') {
            throw new InvalidArgumentException('plan_key import-config is reserved.');
        }

        if (preg_match(self::PLAN_KEY_PATTERN, $planKey) !== 1) {
            throw new InvalidArgumentException('plan_key must match [a-z0-9._-] and be 1-64 characters.');
        }

        return $planKey;
    }

    private function validateDisplayName(mixed $value): string
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            throw new InvalidArgumentException('display_name must be a non-empty string.');
        }

        $displayName = trim((string) $value);
        if (strlen($displayName) > 120) {
            throw new InvalidArgumentException('display_name must be 120 characters or fewer.');
        }

        return $displayName;
    }

    private function nullableString(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_scalar($value)) {
            throw new InvalidArgumentException("{$field} must be a string or null.");
        }

        return (string) $value;
    }

    /** @return array<string,bool|int|null> */
    private function validateEntitlements(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('entitlements must be an object/map.');
        }

        $entitlements = [];
        foreach ($value as $key => $grant) {
            if (!is_string($key) || $key === '') {
                throw new InvalidArgumentException('entitlement keys must be non-empty strings.');
            }

            if (!is_bool($grant) && !is_int($grant) && $grant !== null) {
                throw new InvalidArgumentException("entitlement {$key} must be bool, non-negative int, or null.");
            }

            if (is_int($grant) && $grant < 0) {
                throw new InvalidArgumentException("entitlement {$key} must be a non-negative int.");
            }

            $entitlements[$key] = $grant;
        }

        return $entitlements;
    }

    private function validateStatus(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException('status must be draft, active, or archived.');
        }

        $status = strtolower((string) $value);
        if (!in_array($status, ['draft', 'active', 'archived'], true)) {
            throw new InvalidArgumentException('status must be draft, active, or archived.');
        }

        return $status;
    }

    private function validateSortOrder(mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1)) {
            throw new InvalidArgumentException('sort_order must be an integer.');
        }

        return (int) $value;
    }

    private function validateTransition(string $from, string $to): void
    {
        if (($from === 'active' || $from === 'archived') && $to === 'draft') {
            throw new InvalidArgumentException('Published plans cannot transition back to draft.');
        }
    }
}
