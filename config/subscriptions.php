<?php

declare(strict_types=1);

// Configuration for glueful/subscriptions.
//
// Since 2.0 (the subject model, spec §10), NO config keys were added, removed,
// or renamed here -- only the semantics of a few of them changed, documented
// inline below. The database is now the single source of truth for plans:
// everything under 'plans' is SEED data only, imported once via
// `php glueful subscriptions:plans:import-config` (fresh installs) or the
// 1.4.0 upgrade bridge's `subscriptions:prepare-v2` (existing installs). There
// is no runtime config overlay -- a config plan with no matching DB row does
// not exist as far as resolution, existence, or assignment is concerned.
// Memberships (workspace-scoped user subscriptions) are enabled by a HOST
// binding `SubjectResolverInterface` to a resolver that can vouch for users
// (see README "Upgrading to 2.0" / "binding the resolver enables
// memberships") -- there is no config flag for this.
return [
    // Platform ('tenant', '') catalog only (spec §3). Resolved key -> uuid at
    // runtime. A workspace/user membership catalog has no implicit default
    // plan -- an absent membership resolves to an empty entitlement map
    // (explicit overrides may still grant complimentary access), never this
    // value.
    'default_plan' => 'free',
    // SEEDS for the platform catalog only -- import them with
    // `subscriptions:plans:import-config`. Workspace-owned membership plans
    // (audience='user') are never seeded from here; they are created through
    // PlanManagementService's scoped API by host-integrated tooling (the
    // extension ships no workspace-staff HTTP routes for them in 2.0).
    'plans' => [
        'free' => [
            'entitlements' => [
                'reports.export' => false,
                'projects.limit' => 3,
                'team.limit' => 1,
            ],
        ],
        'pro' => [
            'provider_price_id' => null,
            'entitlements' => [
                'reports.export' => true,
                'projects.limit' => 50,
                'team.limit' => 20,
                'api.monthly' => 100000,
            ],
        ],
    ],
    // Ordered HIGHEST-first: the paid tiers that carry a boolean rate.tier.{tier}
    // entitlement flag (S-rl). Lower tiers (free/anonymous) are left to the
    // framework's default TierResolver; TierManager config owns the numbers.
    // Rate tiers are TENANT-ONLY (spec §5) -- EntitlementTierResolver reads
    // only the tenant (workspace) resolver. The member/workspace-user
    // entitlement resolver strips any rate.tier.* key from its output, so a
    // membership plan can never influence API rate limiting even if one
    // happens to carry such a key.
    'rate_tiers' => ['enterprise', 'pro'],
    'grace_days' => 3,
    'cache' => [
        'enabled' => true,
        'ttl' => 300,
    ],
    // Fail-open escape hatch. Since 2.0 this governs BOTH route middlewares:
    // `require_entitlement` (the tenant/workspace gate) and
    // `require_member_entitlement` (the new workspace-member gate). Each
    // fails closed (403) when its required subject context cannot be
    // resolved; setting this to true lets the request through instead, for
    // both gates alike.
    'permissive_middleware' => false,
    'reconcile' => [
        'schedule_enabled' => false,
    ],
];
