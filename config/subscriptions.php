<?php

declare(strict_types=1);

return [
    'default_plan' => 'free',
    'plans' => [
        'free' => [
            'entitlements' => [
                'reports.export' => false,
                'projects.limit' => 3,
                'team.limit' => 1,
            ],
        ],
        'pro' => [
            'payvia_priced_plan' => null,
            'entitlements' => [
                'reports.export' => true,
                'projects.limit' => 50,
                'team.limit' => 20,
                'api.monthly' => 100000,
            ],
        ],
    ],
    'grace_days' => 3,
    'cache' => [
        'enabled' => true,
        'ttl' => 300,
    ],
    'permissive_middleware' => false,
    'reconcile' => [
        'schedule_enabled' => false,
    ],
];
