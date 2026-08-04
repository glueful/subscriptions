<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Plans;

use Glueful\Extensions\Subscriptions\SubjectType;
use InvalidArgumentException;

final class PlanPayloadValidator
{
    private const PLAN_KEY_PATTERN = '/\A[a-z0-9._-]{1,64}\z/';
    private const ENTITLEMENT_KEY_MAX_LENGTH = 128;
    private const DESCRIPTION_MAX_LENGTH = 255;
    private const PROVIDER_PRICE_ID_MAX_LENGTH = 191;
    private const PROVIDER_IDENTIFIER_KEY_PATTERN = '/\A[a-z0-9_-]{1,50}\z/';
    private const PROVIDER_IDENTIFIER_VALUE_MAX_LENGTH = 191;

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
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
            'description' => $this->nullableString(
                $payload['description'] ?? null,
                'description',
                self::DESCRIPTION_MAX_LENGTH,
            ),
            'entitlements' => $this->validateEntitlements($payload['entitlements']),
            'provider_price_id' => $this->validateProviderPriceId(
                $payload['provider_price_id'] ?? null,
            ),
            'provider_identifiers' => $this->validateProviderIdentifiers(
                $payload['provider_identifiers'] ?? null,
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
            $validated['description'] = $this->nullableString(
                $payload['description'],
                'description',
                self::DESCRIPTION_MAX_LENGTH,
            );
        }

        if (array_key_exists('entitlements', $payload)) {
            $validated['entitlements'] = $this->validateEntitlements($payload['entitlements']);
        }

        if (array_key_exists('provider_price_id', $payload)) {
            $validated['provider_price_id'] = $this->validateProviderPriceId(
                $payload['provider_price_id'],
            );
        }

        if (array_key_exists('provider_identifiers', $payload)) {
            $validated['provider_identifiers'] = $this->validateProviderIdentifiers(
                $payload['provider_identifiers'],
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

    /**
     * Scope invariant (Task 8, spec §2, migration 006's `subscription_plans.audience`/
     * `owner_tenant_uuid`): `audience='tenant'` means the platform-owned catalog and
     * requires an empty owner; `audience='user'` means a workspace-owned membership
     * catalog entry and requires a non-empty owner. Called by every scope-aware
     * `PlanManagementService::*InScope()` method before it touches the repository.
     */
    public function validateScope(string $audience, string $ownerTenantUuid): void
    {
        if (!in_array($audience, [SubjectType::TENANT, SubjectType::USER], true)) {
            throw new InvalidArgumentException(
                "audience must be '" . SubjectType::TENANT . "' or '" . SubjectType::USER . "'."
            );
        }

        if ($audience === SubjectType::TENANT && $ownerTenantUuid !== '') {
            throw new InvalidArgumentException("audience 'tenant' requires an empty owner_tenant_uuid.");
        }

        if ($audience === SubjectType::USER && $ownerTenantUuid === '') {
            throw new InvalidArgumentException("audience 'user' requires a non-empty owner_tenant_uuid.");
        }
    }

    /**
     * @param array<string,mixed> $configPlan
     * @return array<string,mixed>
     */
    public function validateImportConfigPlan(string $planKey, array $configPlan, string $status): array
    {
        $providerPriceId = $configPlan['provider_price_id'] ?? null;

        return $this->validateCreate([
            'plan_key' => $planKey,
            'display_name' => $configPlan['display_name'] ?? $configPlan['name'] ?? $planKey,
            'description' => $configPlan['description'] ?? null,
            'entitlements' => $configPlan['entitlements'] ?? [],
            'provider_price_id' => $providerPriceId,
            'provider_identifiers' => $configPlan['provider_identifiers'] ?? null,
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

    private function nullableString(mixed $value, string $field, ?int $maxLength = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_scalar($value)) {
            throw new InvalidArgumentException("{$field} must be a string or null.");
        }

        $string = (string) $value;
        if ($maxLength !== null && strlen($string) > $maxLength) {
            throw new InvalidArgumentException("{$field} must be {$maxLength} characters or fewer.");
        }

        return $string;
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

            if (strlen($key) > self::ENTITLEMENT_KEY_MAX_LENGTH) {
                throw new InvalidArgumentException(
                    'entitlement keys must be 128 characters or fewer.'
                );
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

    private function validateProviderPriceId(mixed $value): ?string
    {
        return $this->nullableString($value, 'provider_price_id', self::PROVIDER_PRICE_ID_MAX_LENGTH);
    }

    /**
     * The per-gateway checkout-purchasability map (design spec §4.2): a closed
     * `{gateway_key: identifier}` map, validated on EVERY write path this
     * validator serves (create/update/import-config). `null`/absent normalizes
     * to `[]` -- no identifiers configured, so the plan is not purchasable
     * anywhere until an operator explicitly sets one (no automatic migration
     * from `provider_price_id`).
     *
     * @return array<string,string>
     */
    private function validateProviderIdentifiers(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException('provider_identifiers must be an object/map.');
        }

        $identifiers = [];
        foreach ($value as $gateway => $identifier) {
            if (!is_string($gateway) || preg_match(self::PROVIDER_IDENTIFIER_KEY_PATTERN, $gateway) !== 1) {
                throw new InvalidArgumentException(
                    'provider_identifiers keys must match [a-z0-9_-] and be 1-50 characters.'
                );
            }

            if (!is_string($identifier) || $identifier === '') {
                throw new InvalidArgumentException(
                    "provider_identifiers.{$gateway} must be a non-empty string."
                );
            }

            if (strlen($identifier) > self::PROVIDER_IDENTIFIER_VALUE_MAX_LENGTH) {
                throw new InvalidArgumentException(
                    "provider_identifiers.{$gateway} must be "
                    . self::PROVIDER_IDENTIFIER_VALUE_MAX_LENGTH . ' characters or fewer.'
                );
            }

            $identifiers[$gateway] = $identifier;
        }

        return $identifiers;
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
