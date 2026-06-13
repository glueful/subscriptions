<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\BaseController;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class PlanController extends BaseController
{
    public function __construct(
        ApplicationContext $context,
        private ?PlanManagementService $plans = null,
    ) {
        parent::__construct($context);
        $this->plans = $this->plans ?? app($context, PlanManagementService::class);
    }

    public function index(Request $request): Response
    {
        return $this->success(['plans' => $this->plans->list()], 'Plans retrieved');
    }

    public function show(Request $request, string $key): Response
    {
        $plan = $this->plans->find($key);
        if ($plan === null) {
            return $this->notFound('Plan not found');
        }

        return $this->success(['plan' => $plan], 'Plan retrieved');
    }

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
