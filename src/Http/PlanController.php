<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\BaseController;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\Attributes\QueryParam;
use Symfony\Component\HttpFoundation\Request;

/**
 * Deliberately pinned to the platform scope ('tenant', '') only: every action
 * below calls the unqualified 1.x PlanManagementService methods
 * (create/update/archive/find/list), which are themselves platform-scope
 * delegates of their `*InScope` siblings, and accepts no audience/owner
 * input, so no HTTP request can target a workspace scope. Workspace
 * membership plans (`audience='user'`) are managed via
 * PlanManagementService::*InScope() by host-integrated surfaces, not this
 * controller (spec §3 "Administrative authority").
 */
final class PlanController extends BaseController
{
    public function __construct(
        ApplicationContext $context,
        private ?PlanManagementService $plans = null,
    ) {
        parent::__construct($context);
        $this->plans = $this->plans ?? app($context, PlanManagementService::class);
    }

    /**
     * List managed subscription plans.
     */
    #[ApiOperation(
        summary: 'List Subscription Plans',
        description: 'Lists managed subscription plans ordered by sort order, then plan key. '
            . 'Requires the `subscriptions.plans.manage` permission.',
        tags: ['Subscriptions'],
    )]
    #[ApiResponse(200, description: 'Plans retrieved')]
    #[ApiResponse(403, description: 'Forbidden')]
    public function index(Request $request): Response
    {
        return $this->success(['plans' => $this->plans->list()], 'Plans retrieved');
    }

    /**
     * Retrieve one managed subscription plan by plan key.
     */
    #[ApiOperation(
        summary: 'Show Subscription Plan',
        description: 'Retrieves one managed subscription plan by plan key. Plan keys may contain dots, '
            . 'underscores, and hyphens. Requires the `subscriptions.plans.manage` permission.',
        tags: ['Subscriptions'],
    )]
    #[ApiResponse(200, description: 'Plan retrieved')]
    #[ApiResponse(403, description: 'Forbidden')]
    #[ApiResponse(404, description: 'Plan not found')]
    public function show(Request $request, string $key): Response
    {
        $plan = $this->plans->find($key);
        if ($plan === null) {
            return $this->notFound('Plan not found');
        }

        return $this->success(['plan' => $plan], 'Plan retrieved');
    }

    /**
     * Create a managed subscription plan.
     */
    #[ApiOperation(
        summary: 'Create Subscription Plan',
        description: 'Creates a managed subscription plan. Entitlements must be a JSON object whose values '
            . 'are booleans, non-negative integers, or explicit null. Body: `plan_key` (required; unique key '
            . 'of lowercase letters, numbers, dot, underscore, hyphen), `display_name` (required), '
            . '`description`, `entitlements` (required; entitlement map of bool|int>=0|null values), '
            . '`provider_price_id`, `status` (required; one of draft, active, archived), `sort_order`. '
            . 'Requires the `subscriptions.plans.manage` permission.',
        tags: ['Subscriptions'],
    )]
    #[ApiResponse(201, description: 'Plan created')]
    #[ApiResponse(403, description: 'Forbidden')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function store(Request $request): Response
    {
        try {
            return $this->created(['plan' => $this->plans->create($this->normalizeBody($request))], 'Plan created');
        } catch (\InvalidArgumentException $e) {
            return $this->validationError(['plan' => $e->getMessage()]);
        } catch (\Throwable) {
            return $this->serverError('Failed to create plan');
        }
    }

    /**
     * Update a managed subscription plan.
     */
    #[ApiOperation(
        summary: 'Update Subscription Plan',
        description: 'Updates a managed subscription plan. `plan_key` is immutable. Active and archived plans '
            . 'cannot transition back to draft. Edits to active plans take effect immediately. Body: '
            . '`display_name`, `description` (new description or null), `entitlements` (replacement '
            . 'entitlement map of bool|int>=0|null values), `provider_price_id`, `status` (one of draft, '
            . 'active, archived), `sort_order`. Requires the `subscriptions.plans.manage` permission.',
        tags: ['Subscriptions'],
    )]
    #[ApiResponse(200, description: 'Plan updated')]
    #[ApiResponse(403, description: 'Forbidden')]
    #[ApiResponse(404, description: 'Plan not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function update(Request $request, string $key): Response
    {
        try {
            return $this->success(
                ['plan' => $this->plans->update($key, $this->normalizeBody($request))],
                'Plan updated'
            );
        } catch (\InvalidArgumentException $e) {
            if ($this->plans->find($key) === null) {
                return $this->notFound('Plan not found');
            }

            return $this->validationError(['plan' => $e->getMessage()]);
        } catch (\Throwable) {
            return $this->serverError('Failed to update plan');
        }
    }

    /**
     * Archive a managed subscription plan.
     */
    #[ApiOperation(
        summary: 'Archive Subscription Plan',
        description: 'Archives a managed subscription plan. Existing tenants on the plan keep resolving it, '
            . 'but the plan is no longer assignable to new tenants. '
            . 'Requires the `subscriptions.plans.manage` permission.',
        tags: ['Subscriptions'],
    )]
    #[ApiResponse(200, description: 'Plan archived')]
    #[ApiResponse(403, description: 'Forbidden')]
    #[ApiResponse(404, description: 'Plan not found')]
    public function archive(Request $request, string $key): Response
    {
        try {
            return $this->success(['plan' => $this->plans->archive($key)], 'Plan archived');
        } catch (\InvalidArgumentException) {
            return $this->notFound('Plan not found');
        } catch (\Throwable) {
            return $this->serverError('Failed to archive plan');
        }
    }

    /**
     * Seed the managed DB catalog from config.
     */
    #[ApiOperation(
        summary: 'Import Config Plans',
        description: 'Seeds the managed DB catalog from `config/subscriptions.php`. Existing DB rows are '
            . 'preserved unless `force` is true. Registered before keyed routes so `import-config` is never '
            . 'captured as a plan key. Body: `force` (overwrite existing DB rows from config), `status` '
            . '(status for imported plans: draft|active|archived). '
            . 'Requires the `subscriptions.plans.manage` permission.',
        tags: ['Subscriptions'],
    )]
    #[QueryParam('force', 'boolean', description: 'Overwrite existing DB rows from config')]
    #[QueryParam('status', description: 'Status for imported plans', enum: ['draft', 'active', 'archived'])]
    #[ApiResponse(200, description: 'Config plans imported')]
    #[ApiResponse(403, description: 'Forbidden')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function importConfig(Request $request): Response
    {
        try {
            $data = $this->normalizeBody($request);
            $force = (bool) ($data['force'] ?? $request->query->getBoolean('force', false));
            $status = isset($data['status']) && is_string($data['status'])
                ? $data['status']
                : (string) $request->query->get('status', 'active');

            return $this->success([
                'plans' => $this->plans->importConfig($force, $status),
            ], 'Config plans imported');
        } catch (\InvalidArgumentException $e) {
            return $this->validationError(['plan' => $e->getMessage()]);
        } catch (\Throwable) {
            return $this->serverError('Failed to import config plans');
        }
    }

    /**
     * Build the write payload from the JSON body and POST form only.
     *
     * Query-string params are intentionally NOT merged in: for write actions
     * they would otherwise carry plan fields (entitlements/status) into access
     * logs. `importConfig` reads its `force`/`status` query params explicitly, so
     * it is unaffected by this exclusion.
     *
     * @return array<string,mixed>
     */
    private function normalizeBody(Request $request): array
    {
        $content = $request->getContent();
        $data = is_string($content) && $content !== '' ? json_decode($content, true) : [];
        if (!is_array($data)) {
            $data = [];
        }

        return array_merge($request->request->all(), $data);
    }
}
