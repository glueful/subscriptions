<?php

declare(strict_types=1);

use Glueful\Extensions\Subscriptions\Http\PlanController;
use Glueful\Routing\Router;

/** @var Router $router Router instance injected by RouteManifest::load() */

$router->group(['prefix' => '/subscriptions/plans', 'middleware' => ['auth', 'subscriptions_plans_manage']], function (
    Router $router
): void {
    /**
     * @route GET /subscriptions/plans
     * @summary List Subscription Plans
     * @description Lists managed subscription plans ordered by sort order, then plan key.
     *   Requires `subscriptions.plans.manage`.
     * @tag Subscriptions
     * @response 200 application/json "Plans retrieved"
     * @response 403 "Forbidden"
     */
    $router->get('', [PlanController::class, 'index'])
        ->name('subscriptions.plans.index');

    /**
     * @route POST /subscriptions/plans
     * @summary Create Subscription Plan
     * @description Creates a managed subscription plan. Entitlements must be a JSON object
     *   whose values are booleans, non-negative integers, or explicit null.
     *   Requires `subscriptions.plans.manage`.
     * @tag Subscriptions
     * @requestBody
     *   plan_key:string="Unique plan key: lowercase letters, numbers, dot, underscore, hyphen" {required=plan_key}
     *   display_name:string="Display name" {required=display_name}
     *   description:string="Optional description"
     *   entitlements:object="Entitlement map: bool|int>=0|null values" {required=entitlements}
     *   payvia_priced_plan_uuid:string="Optional Payvia priced-plan UUID"
     *   status:string="Plan status: draft|active|archived" {required=status}
     *   sort_order:int="Admin/UI ordering value"
     * @response 201 application/json "Plan created"
     * @response 403 "Forbidden"
     * @response 422 "Validation failed"
     */
    $router->post('', [PlanController::class, 'store'])
        ->name('subscriptions.plans.store');

    /**
     * @route POST /subscriptions/plans/import-config
     * @summary Import Config Plans
     * @description Seeds the managed DB catalog from `config/subscriptions.php`.
     *   Existing DB rows are preserved unless `force` is true. Registered before
     *   keyed routes so `import-config` is never captured as a plan key.
     *   Requires `subscriptions.plans.manage`.
     * @tag Subscriptions
     * @requestBody
     *   force:boolean="Overwrite existing DB rows from config"
     *   status:string="Status for imported plans: draft|active|archived"
     * @response 200 application/json "Config plans imported"
     * @response 403 "Forbidden"
     * @response 422 "Validation failed"
     */
    $router->post('/import-config', [PlanController::class, 'importConfig'])
        ->name('subscriptions.plans.import_config');

    /**
     * @route GET /subscriptions/plans/{key}
     * @summary Show Subscription Plan
     * @description Retrieves one managed subscription plan by plan key. Plan keys may
     *   contain dots, underscores, and hyphens. Requires `subscriptions.plans.manage`.
     * @tag Subscriptions
     * @response 200 application/json "Plan retrieved"
     * @response 403 "Forbidden"
     * @response 404 "Plan not found"
     */
    $router->get('/{key}', [PlanController::class, 'show'])
        ->where('key', '[a-z0-9._-]+')
        ->name('subscriptions.plans.show');

    /**
     * @route PATCH /subscriptions/plans/{key}
     * @summary Update Subscription Plan
     * @description Updates a managed subscription plan. `plan_key` is immutable.
     *   Active and archived plans cannot transition back to draft. Edits to active
     *   plans take effect immediately. Requires `subscriptions.plans.manage`.
     * @tag Subscriptions
     * @requestBody
     *   display_name:string="New display name"
     *   description:string="New description or null"
     *   entitlements:object="Replacement entitlement map: bool|int>=0|null values"
     *   payvia_priced_plan_uuid:string="New Payvia priced-plan UUID or null"
     *   status:string="New status: draft|active|archived"
     *   sort_order:int="New admin/UI ordering value"
     * @response 200 application/json "Plan updated"
     * @response 403 "Forbidden"
     * @response 404 "Plan not found"
     * @response 422 "Validation failed"
     */
    $router->patch('/{key}', [PlanController::class, 'update'])
        ->where('key', '[a-z0-9._-]+')
        ->name('subscriptions.plans.update');

    /**
     * @route POST /subscriptions/plans/{key}/archive
     * @summary Archive Subscription Plan
     * @description Archives a managed subscription plan. Existing tenants on the plan
     *   keep resolving it, but the plan is no longer assignable to new tenants.
     *   Requires `subscriptions.plans.manage`.
     * @tag Subscriptions
     * @response 200 application/json "Plan archived"
     * @response 403 "Forbidden"
     * @response 404 "Plan not found"
     */
    $router->post('/{key}/archive', [PlanController::class, 'archive'])
        ->where('key', '[a-z0-9._-]+')
        ->name('subscriptions.plans.archive');
});
