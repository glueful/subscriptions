<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Console\Plans;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'subscriptions:plans:update', description: 'Update a managed subscription plan')]
final class UpdatePlanCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('key', null, InputOption::VALUE_REQUIRED, 'Plan key');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name');
        $this->addOption('description', null, InputOption::VALUE_REQUIRED, 'Description');
        $this->addOption('status', null, InputOption::VALUE_REQUIRED, 'Status');
        $this->addOption('payvia-priced-plan', null, InputOption::VALUE_REQUIRED, 'Payvia priced plan uuid');
        $this->addOption('sort-order', null, InputOption::VALUE_REQUIRED, 'Sort order');
        $this->addOption('entitlements', null, InputOption::VALUE_REQUIRED, 'Entitlements JSON object');
        $this->addOption('entitlements-file', null, InputOption::VALUE_REQUIRED, 'Path to entitlements JSON file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $key = $this->stringOption($input, 'key');
            $payload = [];

            foreach (
                [
                    'name' => 'display_name',
                    'description' => 'description',
                    'status' => 'status',
                    'payvia-priced-plan' => 'payvia_priced_plan_uuid',
                ] as $option => $field
            ) {
                $value = $this->nullableStringOption($input, $option);
                if ($value !== null) {
                    $payload[$field] = $value;
                }
            }

            $sortOrder = $this->nullableStringOption($input, 'sort-order');
            if ($sortOrder !== null) {
                $payload['sort_order'] = (int) $sortOrder;
            }

            if (
                $this->nullableStringOption($input, 'entitlements') !== null
                || $this->nullableStringOption($input, 'entitlements-file') !== null
            ) {
                $payload['entitlements'] = $this->entitlements($input);
            }

            $plan = $this->plans()->update($key, $payload);
            $this->info("Updated plan '{$plan['plan_key']}'.");

            return self::SUCCESS;
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function plans(): PlanManagementService
    {
        return app($this->getContext(), PlanManagementService::class);
    }

    private function stringOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return is_scalar($value) ? (string) $value : '';
    }

    private function nullableStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** @return array<string,mixed> */
    private function entitlements(InputInterface $input): array
    {
        $file = $this->nullableStringOption($input, 'entitlements-file');
        $json = $file !== null
            ? (string) @file_get_contents($file)
            : $this->nullableStringOption($input, 'entitlements');
        if ($json === null || $json === '') {
            throw new \InvalidArgumentException('entitlements JSON is required.');
        }

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('entitlements must decode to an object/map.');
        }

        return $decoded;
    }
}
