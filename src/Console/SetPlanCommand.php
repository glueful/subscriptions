<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `subscriptions:set-plan --tenant= --plan=` -- start-or-change a tenant's plan.
 */
#[AsCommand(
    name: 'subscriptions:set-plan',
    description: "Set a tenant's subscription plan (starts one if none exists)"
)]
final class SetPlanCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant uuid');
        $this->addOption('plan', null, InputOption::VALUE_REQUIRED, 'Plan key from the subscriptions catalog');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenant = $input->getOption('tenant');
        $plan = $input->getOption('plan');
        if (!is_string($tenant) || $tenant === '' || !is_string($plan) || $plan === '') {
            $this->error('--tenant and --plan are required.');
            return self::FAILURE;
        }

        $ctx = $this->getContext();

        if (!PlanCatalog::fromContext($ctx)->isAssignable($plan)) {
            $this->error("Plan '{$plan}' is not assignable.");
            return self::FAILURE;
        }

        /** @var SubscriptionService $service */
        $service = app($ctx, SubscriptionService::class);

        if ($service->current($tenant) === null) {
            $row = $service->start($tenant, $plan);
            $this->info(sprintf(
                "Started subscription for tenant '%s' on plan '%s' (status: %s).",
                $tenant,
                $plan,
                (string) ($row['status'] ?? '')
            ));
            return self::SUCCESS;
        }

        $service->changePlan($tenant, $plan);
        $this->info("Changed tenant '{$tenant}' to plan '{$plan}'.");
        return self::SUCCESS;
    }
}
