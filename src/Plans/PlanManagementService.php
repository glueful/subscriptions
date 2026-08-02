<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Plans;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;
use Glueful\Helpers\Utils;
use Psr\Log\LoggerInterface;

final class PlanManagementService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SubscriptionPlanRepository $plans,
        private readonly PlanPayloadValidator $validator,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function create(array $payload): array
    {
        $validated = $this->validator->validateCreate($payload);

        if ($this->plans->exists($this->context, (string) $validated['plan_key'])) {
            throw new \InvalidArgumentException("Plan '{$validated['plan_key']}' already exists.");
        }

        $now = $this->now();
        $row = array_merge($validated, [
            'uuid' => Utils::generateNanoID(12),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            $this->plans->insert($this->context, $row);
        } catch (\Throwable $e) {
            if ($this->isUniqueViolation($e)) {
                throw new \InvalidArgumentException("Plan '{$validated['plan_key']}' already exists.", 0, $e);
            }

            throw $e;
        }

        $created = $this->findOrFail((string) $validated['plan_key']);
        $this->emitAudit($this->auditPayload((string) $validated['plan_key'], 'created', [], $created));

        return $created;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function update(string $planKey, array $payload): array
    {
        return $this->updateWithAction($planKey, $payload, 'updated');
    }

    /** @return array<string,mixed> */
    public function archive(string $planKey): array
    {
        return $this->updateWithAction($planKey, ['status' => 'archived'], 'archived');
    }

    /**
     * TRANSITIONAL (Task 8, until Task 9's coordinated cutover): the five scope-aware
     * host-facing methods below (createInScope/updateInScope/archiveInScope/
     * findInScope/listInScope) are ADDED alongside the byte-compatible 1.x
     * create/update/archive/find/list methods above, which keep their unscoped 1.x
     * behavior unchanged through this task. Task 9 switches the unscoped methods to
     * platform-scope delegates (`createInScope('tenant', '', ...)` etc.) at the
     * coordinated activation boundary; `importConfig()` stays platform-only forever
     * and never gains a scope parameter.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createInScope(string $audience, string $ownerTenantUuid, array $payload): array
    {
        $this->validator->validateScope($audience, $ownerTenantUuid);
        $validated = $this->validator->validateCreate($payload);
        $planKey = (string) $validated['plan_key'];

        if ($this->plans->findByKeyInScope($this->context, $audience, $ownerTenantUuid, $planKey) !== null) {
            throw new \InvalidArgumentException("Plan '{$planKey}' already exists.");
        }

        $now = $this->now();
        $row = array_merge($validated, [
            'uuid' => Utils::generateNanoID(12),
            'audience' => $audience,
            'owner_tenant_uuid' => $ownerTenantUuid,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            $this->plans->insert($this->context, $row);
        } catch (\Throwable $e) {
            if ($this->isUniqueViolation($e)) {
                throw new \InvalidArgumentException("Plan '{$planKey}' already exists.", 0, $e);
            }

            throw $e;
        }

        $created = $this->findInScopeOrFail($audience, $ownerTenantUuid, $planKey);
        $this->emitAudit($this->auditPayload($planKey, 'created', [], $created));

        return $created;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function updateInScope(
        string $audience,
        string $ownerTenantUuid,
        string $planKey,
        array $payload
    ): array {
        return $this->updateInScopeWithAction($audience, $ownerTenantUuid, $planKey, $payload, 'updated');
    }

    /** @return array<string,mixed> */
    public function archiveInScope(string $audience, string $ownerTenantUuid, string $planKey): array
    {
        return $this->updateInScopeWithAction(
            $audience,
            $ownerTenantUuid,
            $planKey,
            ['status' => 'archived'],
            'archived'
        );
    }

    /** @return array<string,mixed>|null */
    public function findInScope(string $audience, string $ownerTenantUuid, string $planKey): ?array
    {
        $this->validator->validateScope($audience, $ownerTenantUuid);

        return $this->plans->findByKeyInScope($this->context, $audience, $ownerTenantUuid, $planKey);
    }

    /** @return list<array<string,mixed>> */
    public function listInScope(string $audience, string $ownerTenantUuid): array
    {
        $this->validator->validateScope($audience, $ownerTenantUuid);

        return $this->plans->listInScope($this->context, $audience, $ownerTenantUuid);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function updateInScopeWithAction(
        string $audience,
        string $ownerTenantUuid,
        string $planKey,
        array $payload,
        string $action
    ): array {
        $this->validator->validateScope($audience, $ownerTenantUuid);

        $audit = null;

        $row = db($this->context)->transaction(
            function () use ($audience, $ownerTenantUuid, $planKey, $payload, $action, &$audit): array {
                $before = $this->findInScopeOrFail($audience, $ownerTenantUuid, $planKey);

                if (array_key_exists('plan_key', $payload) && $payload['plan_key'] !== $before['plan_key']) {
                    throw new \InvalidArgumentException('plan_key is immutable');
                }

                $changes = $this->validator->validatePatch($payload, $before);
                $changes['updated_at'] = $this->now();

                $this->plans->updateByKeyInScope($this->context, $audience, $ownerTenantUuid, $planKey, $changes);

                $after = $this->findInScopeOrFail($audience, $ownerTenantUuid, $planKey);
                $audit = $this->auditPayload($planKey, $action, $before, $after);

                return $after;
            }
        );

        if (is_array($audit)) {
            $this->emitAudit($audit);
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function findInScopeOrFail(string $audience, string $ownerTenantUuid, string $planKey): array
    {
        $row = $this->plans->findByKeyInScope($this->context, $audience, $ownerTenantUuid, $planKey);
        if ($row === null) {
            throw new \InvalidArgumentException("Plan '{$planKey}' does not exist.");
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function updateWithAction(string $planKey, array $payload, string $action): array
    {
        $audit = null;

        $row = db($this->context)->transaction(function () use ($planKey, $payload, $action, &$audit): array {
            $before = $this->findOrFail($planKey);
            $changes = $this->validator->validatePatch($payload, $before);
            $changes['updated_at'] = $this->now();

            $this->plans->updateByKey($this->context, $planKey, $changes);

            $after = $this->findOrFail($planKey);
            $audit = $this->auditPayload($planKey, $action, $before, $after);

            return $after;
        });

        if (is_array($audit)) {
            $this->emitAudit($audit);
        }

        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function importConfig(bool $force = false, string $status = 'active'): array
    {
        $plans = (array) config($this->context, 'subscriptions.plans', []);
        $imported = [];

        foreach ($plans as $planKey => $configPlan) {
            if (!is_string($planKey) || !is_array($configPlan)) {
                continue;
            }

            $payload = $this->validator->validateImportConfigPlan($planKey, $configPlan, $status);
            $existing = $this->plans->findByKey($this->context, $planKey);

            if ($existing !== null && !$force) {
                continue;
            }

            if ($existing !== null) {
                unset($payload['plan_key']);
                $imported[] = $this->update($planKey, $payload);
                continue;
            }

            $imported[] = $this->create($payload);
        }

        return $imported;
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return $this->plans->list($this->context);
    }

    /** @return array<string,mixed>|null */
    public function find(string $planKey): ?array
    {
        return $this->plans->findByKey($this->context, $planKey);
    }

    /** @return array<string,mixed> */
    private function findOrFail(string $planKey): array
    {
        $row = $this->find($planKey);
        if ($row === null) {
            throw new \InvalidArgumentException("Plan '{$planKey}' does not exist.");
        }

        return $row;
    }

    private function now(): string
    {
        return db($this->context)->getDriver()->formatDateTime();
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<string,mixed>
     */
    private function auditPayload(string $planKey, string $action, array $before, array $after): array
    {
        return [
            'plan_key' => $planKey,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'diff' => $this->diff($before, $after),
        ];
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<string,array{before:mixed,after:mixed}>
     */
    private function diff(array $before, array $after): array
    {
        $diff = [];
        foreach ($after as $key => $afterValue) {
            $beforeValue = $before[$key] ?? null;
            if ($beforeValue !== $afterValue) {
                $diff[$key] = ['before' => $beforeValue, 'after' => $afterValue];
            }
        }

        return $diff;
    }

    /**
     * Defer the emission to Connection::afterCommit(): outside a transaction it
     * runs immediately, but nested under a caller's outer transaction (e.g. the
     * subscriptions:prepare-v2 upgrade bridge) it queues until that outer
     * transaction commits, and is discarded entirely on rollback -- suppressing
     * false "plan changed" audits for changes that never actually persisted.
     *
     * @param array<string,mixed> $payload
     */
    private function emitAudit(array $payload): void
    {
        db($this->context)->afterCommit(function () use ($payload): void {
            $logger = $this->resolveLogger();
            if ($logger !== null) {
                $logger->info('subscriptions.plan_changed', $payload);
                return;
            }

            error_log('[Subscriptions] subscriptions.plan_changed ' . json_encode($payload, JSON_THROW_ON_ERROR));
        });
    }

    private function resolveLogger(): ?LoggerInterface
    {
        $container = $this->context->getContainer();

        foreach ([LoggerInterface::class, 'logger'] as $id) {
            try {
                if ($container->has($id)) {
                    $logger = $container->get($id);
                    if ($logger instanceof LoggerInterface) {
                        return $logger;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function isUniqueViolation(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $code = (string) $current->getCode();
            if ($code === '23000' || $code === '23505') {
                return true;
            }

            $message = strtolower($current->getMessage());
            if (str_contains($message, 'unique') || str_contains($message, 'duplicate')) {
                return true;
            }
        }

        return false;
    }
}
