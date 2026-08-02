<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Unit;

use Glueful\Extensions\Subscriptions\Projection\ProviderReceiptData;
use PHPUnit\Framework\TestCase;

/**
 * Task 10: ProviderReceiptData::sanitize() is the ONLY path into
 * `receipts.data` -- a closed allowlist (never a denylist) of exactly the
 * fields diagnosis needs, plus a recursive secret-key rejection as defense
 * in depth against a hostile/careless provider payload.
 */
final class ProviderReceiptDataTest extends TestCase
{
    public function testKeepsOnlyTheAllowlistedTopLevelFields(): void
    {
        $sanitized = ProviderReceiptData::sanitize([
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
        $sanitized = ProviderReceiptData::sanitize([
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
        $sanitized = ProviderReceiptData::sanitize([
            'status' => 'active',
            'metadata' => ['billing_email' => 'someone@example.com'],
        ]);

        self::assertSame(['status' => 'active'], $sanitized);
        self::assertArrayNotHasKey('metadata', $sanitized);
    }

    public function testNonArrayMetadataIsDropped(): void
    {
        $sanitized = ProviderReceiptData::sanitize([
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
        $sanitized = ProviderReceiptData::sanitize([
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
        $sanitized = ProviderReceiptData::sanitize([
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
        self::assertSame([], ProviderReceiptData::sanitize([]));
    }

    public function testUnknownTopLevelKeysAreDroppedEvenWhenBenignLooking(): void
    {
        $sanitized = ProviderReceiptData::sanitize([
            'gateway_subscription_id' => 'sub_1',
            'plan_key' => 'pro', // not on the allowlist
            'provider_customer_id' => 'cus_1', // not on the allowlist
        ]);

        self::assertSame(['gateway_subscription_id' => 'sub_1'], $sanitized);
    }
}
