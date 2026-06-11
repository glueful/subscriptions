<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Console\Plans;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'subscriptions:plans:archive', description: 'Archive a managed subscription plan')]
final class ArchivePlanCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('key', null, InputOption::VALUE_REQUIRED, 'Plan key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $key = $input->getOption('key');
            $key = is_scalar($key) ? (string) $key : '';
            $plan = app($this->getContext(), PlanManagementService::class)->archive($key);
            $this->info("Archived plan '{$plan['plan_key']}'.");

            return self::SUCCESS;
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
