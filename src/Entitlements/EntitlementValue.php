<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Entitlements;

/**
 * The single shared truthiness interpretation of an entitlement map value (S3),
 * extracted so the tenant path (DefaultEntitlementChecker) and the member path
 * (RequireMemberEntitlement) can never silently diverge on what "granted" means.
 */
final class EntitlementValue
{
    public static function allows(mixed $value): bool
    {
        if ($value === null || $value === true) {
            return true;
        }

        if ($value === false) {
            return false;
        }

        if (is_numeric($value)) {
            return (int) $value > 0;
        }

        // Fail closed: plan values are validated (bool|int>=0|null) but override
        // values are JSON-decoded and unvalidated. An unrecognized type (string,
        // array, object) -- e.g. the JSON string "false" which (bool) would
        // coerce to true -- denies rather than wrongly grants.
        return false;
    }
}
