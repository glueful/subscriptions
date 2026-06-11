<?php

declare(strict_types=1);

use Glueful\Extensions\Subscriptions\Http\PlanController;
use Glueful\Routing\Router;

/** @var Router $router */

$router->group(['prefix' => '/subscriptions/plans'], function (Router $router): void {
    $middleware = ['auth', 'subscriptions_plans_manage'];

    $router->get('', [PlanController::class, 'index'])
        ->middleware($middleware)
        ->name('subscriptions.plans.index');

    $router->post('', [PlanController::class, 'store'])
        ->middleware($middleware)
        ->name('subscriptions.plans.store');

    $router->post('/import-config', [PlanController::class, 'importConfig'])
        ->middleware($middleware)
        ->name('subscriptions.plans.import_config');

    $router->get('/{key}', [PlanController::class, 'show'])
        ->where('key', '[a-z0-9._-]+')
        ->middleware($middleware)
        ->name('subscriptions.plans.show');

    $router->patch('/{key}', [PlanController::class, 'update'])
        ->where('key', '[a-z0-9._-]+')
        ->middleware($middleware)
        ->name('subscriptions.plans.update');

    $router->post('/{key}/archive', [PlanController::class, 'archive'])
        ->where('key', '[a-z0-9._-]+')
        ->middleware($middleware)
        ->name('subscriptions.plans.archive');
});
