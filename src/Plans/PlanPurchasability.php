<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Plans;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;
use Glueful\Extensions\Subscriptions\SubjectType;

/**
 * Per-gateway checkout-purchasability projection (design spec §4.2, Task 13).
 *
 * `subscription_plans.provider_identifiers` (migration `008`) is the ONE
 * declared authority for checkout purchasability: a closed
 * `{gateway_key: identifier}` map validated on every write path by
 * {@see PlanPayloadValidator}. The pre-existing scalar `provider_price_id`
 * remains compatibility-only (webhook correlation for pre-existing
 * provider-managed rows) and is deliberately NEVER read here -- a plan that
 * carries only the scalar is NOT purchasable through this projection, and
 * there is no automatic migration of the scalar into the map. A plan becomes
 * purchasable for a gateway only when an operator explicitly configures its
 * identifier.
 *
 * Scoped to the platform catalog (`audience='tenant'`, `owner_tenant_uuid=''`)
 * -- the only catalog self-serve checkout ever purchases against -- and
 * `status='active'`; a `draft` or `archived` row never appears here even if
 * it carries an identifier for the requested gateway. Thallo (and any other
 * host) consumes this typed projection only: raw plan metadata/columns are
 * not part of the host contract.
 */
final class PlanPurchasability
{
    private const AUDIENCE = SubjectType::TENANT;
    private const OWNER = '';
    private const RESOLVABLE_STATUS = 'active';

    /**
     * @return list<array{plan_uuid:string,plan_key:string,name:string,provider_identifier:string}>
     */
    public static function forGateway(ApplicationContext $context, string $gateway): array
    {
        $gateway = trim($gateway);
        if ($gateway === '') {
            return [];
        }

        $repository = new SubscriptionPlanRepository();
        $purchasable = [];

        foreach ($repository->listInScope($context, self::AUDIENCE, self::OWNER) as $plan) {
            if (($plan['status'] ?? null) !== self::RESOLVABLE_STATUS) {
                continue;
            }

            $identifiers = $plan['provider_identifiers'] ?? [];
            if (!is_array($identifiers)) {
                continue;
            }

            $identifier = $identifiers[$gateway] ?? null;
            if (!is_string($identifier) || $identifier === '') {
                continue;
            }

            $purchasable[] = [
                'plan_uuid' => (string) ($plan['uuid'] ?? ''),
                'plan_key' => (string) ($plan['plan_key'] ?? ''),
                'name' => (string) ($plan['display_name'] ?? ''),
                'provider_identifier' => $identifier,
            ];
        }

        return $purchasable;
    }
}
