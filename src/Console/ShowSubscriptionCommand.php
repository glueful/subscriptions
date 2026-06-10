<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `subscriptions:show --tenant=` -- print a tenant's subscription row.
 */
#[AsCommand(
    name: 'subscriptions:show',
    description: "Show a tenant's subscription"
)]
final class ShowSubscriptionCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant uuid');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenant = $input->getOption('tenant');
        if (!is_string($tenant) || $tenant === '') {
            $this->error('--tenant is required.');
            return self::FAILURE;
        }

        $row = (new SubscriptionRepository())->findByTenant($this->getContext(), $tenant);
        if ($row === null) {
            $this->info("No subscription for tenant '{$tenant}'.");
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($row as $field => $value) {
            $rows[] = [(string) $field, $value === null ? '' : (string) $value];
        }

        $this->table(['Field', 'Value'], $rows);
        return self::SUCCESS;
    }
}
