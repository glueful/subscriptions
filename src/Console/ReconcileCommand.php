<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubjectType;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `subscriptions:reconcile [--tenant=] [--subject-type=] [--subject-uuid=]` --
 * pull authoritative provider state (S8).
 *
 * With --tenant, reconciles that one subject (tenant self-subject by default,
 * or the subject named by --subject-type/--subject-uuid); without --tenant, it
 * iterates every subscription that carries a provider_subscription_id,
 * RECONSTRUCTING each row's EXACT subject triple (tenant_uuid, subject_type,
 * subject_uuid) and reconciling that -- never the tenant facade, so a user
 * membership row is never mistaken for (or cross-targeted onto) its
 * workspace's own tenant subscription (Task 13). With no provider installed
 * each reconcile is a safe no-op (soft dep). The S10 scheduler hook is opt-in:
 * wire this command into your scheduler when
 * subscriptions.reconcile.schedule_enabled is true.
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
        $this->addOption(
            'subject-type',
            null,
            InputOption::VALUE_REQUIRED,
            'Subject type (tenant|user) for --tenant',
            SubjectType::TENANT
        );
        $this->addOption(
            'subject-uuid',
            null,
            InputOption::VALUE_REQUIRED,
            'Subject uuid for --tenant; defaults to --tenant '
            . '(required, non-empty, when --subject-type=user)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ctx = $this->getContext();
        /** @var SubscriptionService $service */
        $service = app($ctx, SubscriptionService::class);

        $tenant = $this->nullableStringOption($input, 'tenant');
        if ($tenant !== null) {
            $subject = $this->resolveSubject($input, $tenant);
            if ($subject === null) {
                return self::FAILURE;
            }

            $row = $service->reconcileFor($subject);
            if ($row === null) {
                $this->error($this->notFoundMessage($subject));
                return self::FAILURE;
            }

            $this->info($this->reconciledMessage($subject, $row));
            return self::SUCCESS;
        }

        $count = 0;
        foreach ((new SubscriptionRepository())->allWithProvider($ctx) as $row) {
            $subject = new Subject(
                (string) $row['tenant_uuid'],
                (string) $row['subject_type'],
                (string) $row['subject_uuid']
            );
            $service->reconcileFor($subject);
            $count++;
        }

        $this->info("Reconciled {$count} provider-linked subscription(s).");
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

    /** @param array<string,mixed> $row */
    private function reconciledMessage(Subject $subject, array $row): string
    {
        if ($subject->type === SubjectType::TENANT && $subject->uuid === $subject->tenantUuid) {
            return sprintf(
                "Reconciled tenant '%s' (plan: %s, status: %s).",
                $subject->tenantUuid,
                (string) ($row['plan_key'] ?? ''),
                (string) ($row['status'] ?? '')
            );
        }

        return sprintf(
            "Reconciled subject '%s:%s' in tenant '%s' (plan: %s, status: %s).",
            $subject->type,
            $subject->uuid,
            $subject->tenantUuid,
            (string) ($row['plan_key'] ?? ''),
            (string) ($row['status'] ?? '')
        );
    }

    private function nullableStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
