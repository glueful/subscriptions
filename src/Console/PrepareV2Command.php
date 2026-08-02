<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
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
 * imported/synthesized plan and leaves no marker; success messages and plan
 * audit records (deferred via Connection::afterCommit()) are only emitted
 * once that outer transaction commits.
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
        $planManagement = app($context, PlanManagementService::class);

        // (1) Committed marker delete: an interrupted or failed run leaves NO authority.
        db($context)->table('subscription_v2_preparation')
            ->where('marker_key', '=', self::MARKER_KEY)
            ->delete();

        try {
            $result = db($context)->transaction(
                function () use ($context, $plans, $planManagement): array {
                    // (2) Nested service transactions become savepoints under this authority.
                    $importedRows = $planManagement->importConfig(false, 'active');
                    $imported = array_values(array_map(
                        static fn (array $row): string => (string) $row['plan_key'],
                        $importedRows
                    ));

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
                        if ($plans->findByKey($context, $key) === null) {
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
                        if ($plans->findByKey($context, (string) $key) === null) {
                            throw new \RuntimeException("verification failed: '{$key}' unresolved");
                        }
                    }

                    // (5) The marker is the final write in the same transaction.
                    db($context)->table('subscription_v2_preparation')->insert([
                        'marker_key' => self::MARKER_KEY,
                        'catalog_signature' => PlanCatalog::fromContext($context)->version(),
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
}
