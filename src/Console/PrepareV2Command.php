<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Console;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Glueful\Extensions\Subscriptions\Plans\PlanPayloadValidator;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;
use Glueful\Helpers\Utils;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Upgrade bridge: prepares a v1.x catalog for the v2 subject model.
 *
 * Protocol (spec S-3): the OLD `subject-model-v2` marker is deleted in its own
 * committed statement first, so an interrupted or failed run leaves NO
 * authority behind. Everything else -- config import, dangling-plan
 * synthesis, final verification, and inserting the replacement marker --
 * happens inside one outer transaction. Any failure rolls back every
 * imported/synthesized plan and leaves no marker; success messages are only
 * emitted once that outer transaction commits.
 *
 * PINNED TO THE PRE-006 SCHEMA. This command runs BEFORE migration 006 adds
 * `audience`/`owner_tenant_uuid`, so it deliberately does NOT reuse
 * PlanManagementService::importConfig() or PlanCatalog::version(): both are
 * scope-aware since the 2.0 activation and would query columns that do not
 * exist yet. The import below is the frozen 1.x create-missing behavior
 * (`importConfig(force: false, status: 'active')`) expressed against the 1.x
 * schema, where `plan_key` is still globally unique.
 */
#[AsCommand(
    name: 'subscriptions:prepare-v2',
    description: 'Prepare a v1.x catalog for the v2 subject model (import config plans, ' .
        'synthesize archived stand-ins for dangling plan keys, record a readiness marker).'
)]
final class PrepareV2Command extends BaseCommand
{
    private const MARKER_KEY = 'subject-model-v2';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->getContext();
        $plans = app($context, SubscriptionPlanRepository::class);

        // (1) Committed marker delete: an interrupted or failed run leaves NO authority.
        db($context)->table('subscription_v2_preparation')
            ->where('marker_key', '=', self::MARKER_KEY)
            ->delete();

        try {
            $result = db($context)->transaction(
                function () use ($context, $plans): array {
                    // (2) Import the config catalog (create-missing only).
                    $imported = $this->importConfigPlans($context, $plans);

                    // (3) Synthesize archived empty-entitlement plans for dangling keys.
                    $synthesized = [];
                    $keys = array_column(
                        db($context)->table('subscriptions')->select(['plan_key'])->distinct()->get(),
                        'plan_key'
                    );
                    foreach ($keys as $key) {
                        $key = (string) $key;
                        if ($key === '') {
                            throw new \RuntimeException('subscription with empty plan_key cannot be prepared');
                        }
                        if ($plans->findByKeyUnscoped($context, $key) === null) {
                            $plans->insert($context, [
                                'uuid' => Utils::generateNanoID(12),
                                'plan_key' => $key,
                                'display_name' => $key,
                                'entitlements' => [],
                                'status' => 'archived',
                                'sort_order' => 0,
                            ]);
                            $synthesized[] = $key;
                        }
                    }

                    // (4) Final verification under the same transaction.
                    foreach ($keys as $key) {
                        if ($plans->findByKeyUnscoped($context, (string) $key) === null) {
                            throw new \RuntimeException("verification failed: '{$key}' unresolved");
                        }
                    }

                    // (5) The marker is the final write in the same transaction.
                    db($context)->table('subscription_v2_preparation')->insert([
                        'marker_key' => self::MARKER_KEY,
                        'catalog_signature' => $this->legacyCatalogSignature($context, $plans),
                        'report' => json_encode([
                            'imported' => $imported,
                            'synthesized' => $synthesized,
                            'verified_keys' => count($keys),
                        ], JSON_THROW_ON_ERROR),
                    ]);

                    return compact('imported', 'synthesized', 'keys');
                }
            );
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }

        foreach ($result['synthesized'] as $key) {
            $output->writeln("<comment>synthesized archived plan '{$key}' (empty entitlements)</comment>");
        }
        $output->writeln('<info>v2 preparation complete.</info>');

        return self::SUCCESS;
    }

    /**
     * The frozen 1.x `importConfig(force: false, status: 'active')` behavior against
     * the pre-006 schema: every `subscriptions.plans.*` entry that has no DB row yet
     * becomes an active managed plan. Existing rows are left untouched (no --force
     * on the bridge: the upgrade must never silently rewrite an operator's catalog).
     *
     * @return list<string>
     */
    private function importConfigPlans(ApplicationContext $context, SubscriptionPlanRepository $plans): array
    {
        $validator = new PlanPayloadValidator();
        $now = db($context)->getDriver()->formatDateTime();
        $imported = [];

        foreach ((array) config($context, 'subscriptions.plans', []) as $planKey => $configPlan) {
            if (!is_string($planKey) || !is_array($configPlan)) {
                continue;
            }

            $payload = $validator->validateImportConfigPlan($planKey, $configPlan, 'active');

            if ($plans->findByKeyUnscoped($context, $planKey) !== null) {
                continue;
            }

            $plans->insert($context, array_merge($payload, [
                'uuid' => Utils::generateNanoID(12),
                'created_at' => $now,
                'updated_at' => $now,
            ]));
            $imported[] = $planKey;
        }

        return $imported;
    }

    /**
     * The 1.x catalog signature shape (config hash + max plan updated_at). Recorded
     * on the marker so the operator can tell whether the catalog moved between
     * preparation and migration.
     */
    private function legacyCatalogSignature(ApplicationContext $context, SubscriptionPlanRepository $plans): string
    {
        $algo = in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha256';
        $encoded = json_encode((array) config($context, 'subscriptions.plans', []), JSON_THROW_ON_ERROR);

        return substr(hash($algo, $encoded), 0, 16) . ':' . ($plans->maxUpdatedAtUnscoped($context) ?? 'none');
    }
}
