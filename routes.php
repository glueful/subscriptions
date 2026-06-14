<?php

declare(strict_types=1);

use Glueful\Extensions\Subscriptions\Http\PlanController;
use Glueful\Routing\Router;

/** @var Router $router Router instance injected by RouteManifest::load() */

$router->group(['prefix' => '/subscriptions/plans', 'middleware' => ['auth', 'subscriptions_plans_manage']], function (
    Router $router
): void {
    // Plan management
    $router->get('', [PlanController::class, 'index'])
        ->name('subscriptions.plans.index');

    $router->post('', [PlanController::class, 'store'])
        ->name('subscriptions.plans.store');

    // Registered before keyed routes so `import-config` is never captured as a plan key.
    $router->post('/import-config', [PlanController::class, 'importConfig'])
        ->name('subscriptions.plans.import_config');

    $router->get('/{key}', [PlanController::class, 'show'])
        ->where('key', '[a-z0-9._-]+')
        ->name('subscriptions.plans.show');

    $router->patch('/{key}', [PlanController::class, 'update'])
        ->where('key', '[a-z0-9._-]+')
        ->name('subscriptions.plans.update');

    $router->post('/{key}/archive', [PlanController::class, 'archive'])
        ->where('key', '[a-z0-9._-]+')
        ->name('subscriptions.plans.archive');
});
