<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubjectType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `subscriptions:show --tenant= [--subject-type=] [--subject-uuid=]` -- print a
 * subject's subscription row (Task 13). With no subject options this is the
 * unchanged 1.x tenant self-subject lookup.
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
        $this->addOption(
            'subject-type',
            null,
            InputOption::VALUE_REQUIRED,
            'Subject type (tenant|user)',
            SubjectType::TENANT
        );
        $this->addOption(
            'subject-uuid',
            null,
            InputOption::VALUE_REQUIRED,
            'Subject uuid; defaults to --tenant (required, non-empty, when --subject-type=user)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenant = $this->nullableStringOption($input, 'tenant');
        if ($tenant === null) {
            $this->error('--tenant is required.');
            return self::FAILURE;
        }

        $subject = $this->resolveSubject($input, $tenant);
        if ($subject === null) {
            return self::FAILURE;
        }

        $row = (new SubscriptionRepository())->findBySubject($this->getContext(), $subject);
        if ($row === null) {
            $this->info($this->notFoundMessage($subject));
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($row as $field => $value) {
            $rows[] = [(string) $field, $value === null ? '' : (string) $value];
        }

        $this->table(['Field', 'Value'], $rows);
        return self::SUCCESS;
    }

    /**
     * Resolves the target subject from --subject-type/--subject-uuid.
     * Tenant mode (default) defaults the subject uuid to --tenant; user mode
     * requires an explicit, non-empty --subject-uuid. On any invalid
     * combination this emits a one-line error and returns null.
     */
    private function resolveSubject(InputInterface $input, string $tenant): ?Subject
    {
        $type = $this->nullableStringOption($input, 'subject-type') ?? SubjectType::TENANT;
        if ($type !== SubjectType::TENANT && $type !== SubjectType::USER) {
            $this->error("--subject-type must be 'tenant' or 'user'.");
            return null;
        }

        $uuid = $this->nullableStringOption($input, 'subject-uuid');

        if ($type === SubjectType::USER) {
            if ($uuid === null) {
                $this->error('--subject-uuid is required when --subject-type=user.');
                return null;
            }

            return Subject::user($tenant, $uuid);
        }

        return new Subject($tenant, SubjectType::TENANT, $uuid ?? $tenant);
    }

    private function notFoundMessage(Subject $subject): string
    {
        if ($subject->type === SubjectType::TENANT && $subject->uuid === $subject->tenantUuid) {
            return "No subscription for tenant '{$subject->tenantUuid}'.";
        }

        return "No subscription for subject '{$subject->type}:{$subject->uuid}' in tenant '{$subject->tenantUuid}'.";
    }

    private function nullableStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
