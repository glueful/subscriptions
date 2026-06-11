<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Console\Plans;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'subscriptions:plans:list', description: 'List managed subscription plans')]
final class ListPlansCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        foreach (app($this->getContext(), PlanManagementService::class)->list() as $plan) {
            $rows[] = [
                (string) ($plan['plan_key'] ?? ''),
                (string) ($plan['display_name'] ?? ''),
                (string) ($plan['status'] ?? ''),
                (string) ($plan['payvia_priced_plan_uuid'] ?? ''),
                (string) ($plan['updated_at'] ?? ''),
            ];
        }

        $this->table(['Key', 'Name', 'Status', 'Payvia', 'Updated'], $rows);

        return self::SUCCESS;
    }
}
