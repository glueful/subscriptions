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
        try {
            $key = $input->getOption('key');
            $key = is_scalar($key) ? (string) $key : '';

            $audience = $this->nullableStringOption($input, 'audience');
            $owner = $this->nullableStringOption($input, 'owner');

            $plans = app($this->getContext(), PlanManagementService::class);
            $plan = ($audience === null && $owner === null)
                ? $plans->archive($key)
                : $plans->archiveInScope($audience ?? 'tenant', $owner ?? '', $key);
            $this->info("Archived plan '{$plan['plan_key']}'.");

            return self::SUCCESS;
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function nullableStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
