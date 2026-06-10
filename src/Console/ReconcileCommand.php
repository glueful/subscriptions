<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `subscriptions:reconcile [--tenant=]` -- pull authoritative provider state (S8).
 *
 * With --tenant, reconciles that one tenant; without, iterates every subscription
 * that carries a payvia_subscription_id. With no payvia installed each reconcile
 * is a safe no-op (soft dep). The S10 scheduler hook is opt-in: wire this command
 * into your scheduler when subscriptions.reconcile.schedule_enabled is true.
 */
#[AsCommand(
    name: 'subscriptions:reconcile',
    description: 'Reconcile subscription state against the payment provider'
)]
final class ReconcileCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Reconcile a single tenant uuid');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ctx = $this->getContext();
        /** @var SubscriptionService $service */
        $service = app($ctx, SubscriptionService::class);

        $tenant = $input->getOption('tenant');
        if (is_string($tenant) && $tenant !== '') {
            $row = $service->reconcile($tenant);
            if ($row === null) {
                $this->error("No subscription for tenant '{$tenant}'.");
                return self::FAILURE;
            }

            $this->info(sprintf(
                "Reconciled tenant '%s' (plan: %s, status: %s).",
                $tenant,
                (string) ($row['plan_key'] ?? ''),
                (string) ($row['status'] ?? '')
            ));
            return self::SUCCESS;
        }

        $count = 0;
        foreach ((new SubscriptionRepository())->allWithPayvia($ctx) as $subscription) {
            $service->reconcile((string) $subscription['tenant_uuid']);
            $count++;
        }

        $this->info("Reconciled {$count} payvia-linked subscription(s).");
        return self::SUCCESS;
    }
}
