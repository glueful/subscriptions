<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Unit;

use Glueful\Extensions\Subscriptions\Projection\ProviderEventData;
use PHPUnit\Framework\TestCase;

/**
 * Task 10 (renamed in fix round 2): ProviderEventData::sanitize() is the ONLY
 * path into a provider-sourced `data` column -- receipts.data AND, on the
 * accepted path, provider-sourced subscription_events.data -- a closed
 * allowlist (never a denylist) of exactly the fields diagnosis needs, plus a
 * recursive secret-key rejection as defense in depth against a
 * hostile/careless provider payload.
 */
final class ProviderEventDataTest extends TestCase
{
    public function testKeepsOnlyTheAllowlistedTopLevelFields(): void
    {
        $sanitized = ProviderEventData::sanitize([
            'gateway_subscription_id' => 'sub_1',
            'status' => 'active',
            'current_period_end' => '2030-01-01 00:00:00',
            'customer_email' => 'attacker@example.com',
            'raw_payload' => ['anything' => 'goes'],
        ]);

        self::assertSame([
            'gateway_subscription_id' => 'sub_1',
            'status' => 'active',
            'current_period_end' => '2030-01-01 00:00:00',
        ], $sanitized);
    }

    public function testMetadataIsRestrictedToTheFourIdentityFields(): void
    {
        $sanitized = ProviderEventData::sanitize([
            'metadata' => [
                'tenant_uuid' => 'tenantA',
                'subject_type' => 'tenant',
                'subject_uuid' => 'tenantA',
                'plan_uuid' => 'planv2pro001',
                'billing_email' => 'someone@example.com',
                'internal_note' => 'do not store this',
            ],
        ]);

        self::assertSame([
            'metadata' => [
                'tenant_uuid' => 'tenantA',
                'subject_type' => 'tenant',
                'subject_uuid' => 'tenantA',
                'plan_uuid' => 'planv2pro001',
            ],
        ], $sanitized);
    }

    public function testMetadataKeyIsOmittedEntirelyWhenNothingAllowlistedSurvives(): void
    {
        $sanitized = ProviderEventData::sanitize([
            'status' => 'active',
            'metadata' => ['billing_email' => 'someone@example.com'],
        ]);

        self::assertSame(['status' => 'active'], $sanitized);
        self::assertArrayNotHasKey('metadata', $sanitized);
    }

    public function testNonArrayMetadataIsDropped(): void
    {
        $sanitized = ProviderEventData::sanitize([
            'status' => 'active',
            'metadata' => 'not-an-array',
        ]);

        self::assertSame(['status' => 'active'], $sanitized);
    }

    /**
     * Hostile nested secrets: even inside an allowlisted field's value, any key
     * matching the secret pattern is stripped recursively, at any depth --
     * defense in depth beyond the closed top-level/metadata allowlists.
     */
    public function testRecursivelyRejectsHostileNestedSecretKeys(): void
    {
        $sanitized = ProviderEventData::sanitize([
            'gateway_subscription_id' => [
                'value' => 'sub_1',
                'api_key' => 'sk_live_xxx',
                'nested' => [
                    'client_secret' => 'shh',
                    'authorization' => 'Bearer xxx',
                    'signature' => 'abc123',
                    'password' => 'hunter2',
                    'token' => 'zzz',
                    'safe' => 'kept',
                ],
            ],
            'metadata' => [
                'tenant_uuid' => [
                    'value' => 'tenantA',
                    'secret' => 'should-not-survive',
                ],
            ],
        ]);

        self::assertSame([
            'gateway_subscription_id' => [
                'value' => 'sub_1',
                'nested' => ['safe' => 'kept'],
            ],
            'metadata' => [
                'tenant_uuid' => ['value' => 'tenantA'],
            ],
        ], $sanitized);
    }

    public function testSecretKeyMatchingIsCaseInsensitive(): void
    {
        $sanitized = ProviderEventData::sanitize([
            'status' => [
                'API_KEY' => 'x',
                'Client_Secret' => 'y',
                'ok' => 'z',
            ],
        ]);

        self::assertSame(['status' => ['ok' => 'z']], $sanitized);
    }

    public function testEmptyPayloadSanitizesToEmptyArray(): void
    {
        self::assertSame([], ProviderEventData::sanitize([]));
    }

    /**
     * Task 11 (design spec §3.7/§4.3): `cancellation_mode` joins the top-level
     * allowlist so a stop_renewal disable's receipt/event row survives
     * sanitization for later diagnosis.
     */
    public function testCancellationModeSurvivesSanitization(): void
    {
        $sanitized = ProviderEventData::sanitize([
            'gateway_subscription_id' => 'sub_1',
            'cancellation_mode' => 'stop_renewal',
            'current_period_end' => '2030-01-01 00:00:00',
        ]);

        self::assertSame([
            'gateway_subscription_id' => 'sub_1',
            'current_period_end' => '2030-01-01 00:00:00',
            'cancellation_mode' => 'stop_renewal',
        ], $sanitized);
    }

    public function testHostileNestedValueUnderCancellationModeIsRejected(): void
    {
        $sanitized = ProviderEventData::sanitize([
            'cancellation_mode' => [
                'value' => 'stop_renewal',
                'api_key' => 'sk_live_hostile',
            ],
        ]);

        self::assertSame(['cancellation_mode' => ['value' => 'stop_renewal']], $sanitized);
    }

    public function testUnknownTopLevelKeysAreDroppedEvenWhenBenignLooking(): void
    {
        $sanitized = ProviderEventData::sanitize([
            'gateway_subscription_id' => 'sub_1',
            'plan_key' => 'pro', // not on the allowlist
            'provider_customer_id' => 'cus_1', // not on the allowlist
        ]);

        self::assertSame(['gateway_subscription_id' => 'sub_1'], $sanitized);
    }

    /**
     * Task 6: glueful_consumer marker must survive sanitization even when
     * the payload carries hostile nested secrets, so the strict adapter
     * (Task 5) can verify ownership through normalized()['metadata']['glueful_consumer'].
     */
    public function testRetainsGluefulConsumerOwnershipMarkerThroughSanitization(): void
    {
        $sanitized = ProviderEventData::sanitize([
            'gateway_subscription_id' => 'sub_1',
            'metadata' => [
                'tenant_uuid' => 'tenantA',
                'subject_type' => 'tenant',
                'subject_uuid' => 'tenantA',
                'plan_uuid' => 'planv2pro001',
                'glueful_consumer' => 'subscriptions',
                'api_key' => 'sk_live_hostile',
                'secret' => 'should-not-survive',
            ],
        ]);

        self::assertSame([
            'gateway_subscription_id' => 'sub_1',
            'metadata' => [
                'tenant_uuid' => 'tenantA',
                'subject_type' => 'tenant',
                'subject_uuid' => 'tenantA',
                'plan_uuid' => 'planv2pro001',
                'glueful_consumer' => 'subscriptions',
            ],
        ], $sanitized);
    }

    public function testGluefulConsumerSurvivesWithHostileNestedSecretsInOtherMetadata(): void
    {
        $sanitized = ProviderEventData::sanitize([
            'metadata' => [
                'glueful_consumer' => 'subscriptions',
                'tenant_uuid' => [
                    'value' => 'tenantA',
                    'api_key' => 'sk_live_hostile',
                    'token' => 'secret_token',
                ],
                'secret' => 'top_level_secret',
            ],
        ]);

        self::assertSame([
            'metadata' => [
                'tenant_uuid' => ['value' => 'tenantA'],
                'glueful_consumer' => 'subscriptions',
            ],
        ], $sanitized);
    }
}
