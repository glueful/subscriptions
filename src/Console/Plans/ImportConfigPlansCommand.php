<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Console\Plans;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'subscriptions:plans:import-config', description: 'Import config plans into the managed catalog')]
final class ImportConfigPlansCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing DB plans');
        $this->addOption('status', null, InputOption::VALUE_REQUIRED, 'Imported plan status', 'active');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $status = $input->getOption('status');
            $plans = app($this->getContext(), PlanManagementService::class)->importConfig(
                (bool) $input->getOption('force'),
                is_scalar($status) ? (string) $status : 'active'
            );
            $this->info(sprintf('Imported %d config plan(s).', count($plans)));

            return self::SUCCESS;
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
