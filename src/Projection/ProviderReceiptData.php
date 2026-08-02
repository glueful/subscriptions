<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Projection;

/**
 * The ONLY path into `subscription_provider_event_receipts.data` (spec §2/§8/Task 10).
 *
 * A raw provider webhook payload is never stored verbatim -- it commonly carries
 * customer PII (email, billing address) and, depending on the provider/integration,
 * can carry outright secrets (a signed callback token, an echoed API key). This is a
 * CLOSED allowlist, not a denylist: only the fields diagnosis actually needs survive,
 * everything else is dropped by omission.
 *
 * Layer 1 (the allowlist) is the real boundary. Layer 2 -- the recursive
 * `token|secret|password|authorization|signature|api_key|client_secret` key
 * rejection -- is defense in depth for the rare case where an allowlisted field's
 * VALUE is itself a hostile nested structure (a provider or a compromised
 * upstream echoing a credential back inside a field this class otherwise trusts).
 */
final class ProviderReceiptData
{
    private const TOP_LEVEL_ALLOW = ['gateway_subscription_id', 'status', 'current_period_end', 'metadata'];
    private const METADATA_ALLOW = ['tenant_uuid', 'subject_type', 'subject_uuid', 'plan_uuid'];

    private const SECRET_KEY_PATTERN =
        '/token|secret|password|authorization|signature|api_key|client_secret/i';

    /**
     * @param array<string,mixed> $providerPayload
     * @return array<string,mixed>
     */
    public static function sanitize(array $providerPayload): array
    {
        $out = [];

        foreach (self::TOP_LEVEL_ALLOW as $key) {
            if (!array_key_exists($key, $providerPayload)) {
                continue;
            }

            if ($key === 'metadata') {
                $metadata = self::sanitizeMetadata($providerPayload['metadata']);
                if ($metadata !== []) {
                    $out['metadata'] = $metadata;
                }
                continue;
            }

            $out[$key] = self::rejectSecretKeys($providerPayload[$key]);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function sanitizeMetadata(mixed $metadata): array
    {
        if (!is_array($metadata)) {
            return [];
        }

        $out = [];
        foreach (self::METADATA_ALLOW as $key) {
            if (array_key_exists($key, $metadata)) {
                $out[$key] = self::rejectSecretKeys($metadata[$key]);
            }
        }

        return $out;
    }

    /**
     * Recursively drops any array key (at any depth) matching the secret pattern.
     * Scalars pass through untouched.
     */
    private static function rejectSecretKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && preg_match(self::SECRET_KEY_PATTERN, $k) === 1) {
                continue;
            }
            $out[$k] = self::rejectSecretKeys($v);
        }

        return $out;
    }
}
