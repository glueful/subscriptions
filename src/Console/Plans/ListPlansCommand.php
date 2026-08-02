<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Console\Plans;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'subscriptions:plans:list', description: 'List managed subscription plans')]
final class ListPlansCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption(
            'audience',
            null,
            InputOption::VALUE_REQUIRED,
            "Plan audience (tenant|user); defaults to the platform scope ('tenant')"
        );
        $this->addOption(
            'owner',
            null,
            InputOption::VALUE_REQUIRED,
            'Owner tenant UUID for workspace-owned plans (audience=user); defaults to the platform scope (empty)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $plans = app($this->getContext(), PlanManagementService::class);
        $audience = $this->nullableStringOption($input, 'audience');
        $owner = $this->nullableStringOption($input, 'owner');

        $list = ($audience === null && $owner === null)
            ? $plans->list()
            : $plans->listInScope($audience ?? 'tenant', $owner ?? '');

        $rows = [];
        foreach ($list as $plan) {
            $rows[] = [
                (string) ($plan['plan_key'] ?? ''),
                (string) ($plan['display_name'] ?? ''),
                (string) ($plan['status'] ?? ''),
                (string) ($plan['provider_price_id'] ?? ''),
                (string) ($plan['updated_at'] ?? ''),
            ];
        }

        $this->table(['Key', 'Name', 'Status', 'Provider', 'Updated'], $rows);

        return self::SUCCESS;
    }

    private function nullableStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
