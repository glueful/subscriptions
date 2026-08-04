<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Projection;

/**
 * The ONLY path into a provider-sourced `data` column -- both
 * `subscription_provider_event_receipts.data` and, on the accepted path,
 * provider-sourced `subscription_events.data` (spec §2/§8/Task 10 fix round 2).
 * Ownership-neutral name: the same closed projection is shared by both writers,
 * so it isn't named after either one. Manual/reconcile `subscription_events`
 * rows are UNCHANGED by this class -- their `data` is app-generated, never a raw
 * provider payload, so there is nothing to sanitize.
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
 *
 * Also used by migration 006 (`SubjectModel::sanitizeHistoricalProviderEventData()`)
 * to sanitize pre-existing provider-sourced `subscription_events.data` rows in
 * place during the upgrade, so migrating never leaves historical PII/secrets
 * sitting in the database.
 */
final class ProviderEventData
{
    // 'cancellation_mode' (design spec §3.7/§4.3, Task 11): Paystack's normalized
    // disable event carries this alongside `current_period_end` so a receipt
    // (and the historical subscription_events row) can be diagnosed after the
    // fact -- e.g. distinguishing a `stop_renewal` disable that was fed a
    // missing/invalid period end (and so fell closed to `canceled`) from an
    // ordinary immediate cancellation.
    private const TOP_LEVEL_ALLOW =
        ['gateway_subscription_id', 'status', 'current_period_end', 'cancellation_mode', 'metadata'];
    private const METADATA_ALLOW = ['tenant_uuid', 'subject_type', 'subject_uuid', 'plan_uuid', 'glueful_consumer'];

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
