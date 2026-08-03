<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Console;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Console\Support\ResolvesSubjectOption;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubjectType;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `subscriptions:set-plan --tenant= --plan= [--subject-type=] [--subject-uuid=]`
 * -- start-or-change a subject's plan (Task 13).
 *
 * With no subject options this is the unchanged 1.x tenant-facade path,
 * resolving the plan in the platform catalog. `--subject-type=user` resolves
 * the plan within the workspace's OWN member catalog
 * (`PlanCatalog::forScope('user', $tenant)`) instead -- a platform-only plan
 * (e.g. 'pro') is never assignable to a user subject, and vice versa.
 */
#[AsCommand(
    name: 'subscriptions:set-plan',
    description: "Set a tenant's subscription plan (starts one if none exists)"
)]
final class SetPlanCommand extends BaseCommand
{
    use ResolvesSubjectOption;

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant uuid');
        $this->addOption('plan', null, InputOption::VALUE_REQUIRED, 'Plan key from the subscriptions catalog');
        $this->configureSubjectOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenant = $this->nullableStringOption($input, 'tenant');
        $plan = $this->nullableStringOption($input, 'plan');
        if ($tenant === null || $plan === null) {
            $this->error('--tenant and --plan are required.');
            return self::FAILURE;
        }

        $subject = $this->resolveSubject($input, $tenant);
        if ($subject === null) {
            return self::FAILURE;
        }

        $ctx = $this->getContext();

        if ($subject->type === SubjectType::USER) {
            return $this->setUserSubjectPlan($ctx, $subject, $plan);
        }

        // 1.x tenant facade path -- unchanged.
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

    /**
     * User-subject path (Task 13): the plan is resolved within the workspace's
     * OWN member catalog, never the platform catalog -- a platform-only plan
     * (audience='tenant') never resolves here even if the key matches.
     */
    private function setUserSubjectPlan(ApplicationContext $ctx, Subject $subject, string $plan): int
    {
        $catalog = PlanCatalog::forScope($ctx, SubjectType::USER, $subject->tenantUuid);
        if (!$catalog->isAssignable($plan)) {
            $this->error("Plan '{$plan}' is not assignable.");
            return self::FAILURE;
        }

        $planUuid = $catalog->planUuidForKey($plan);
        if ($planUuid === null) {
            $this->error("Plan '{$plan}' is not assignable.");
            return self::FAILURE;
        }

        /** @var SubscriptionService $service */
        $service = app($ctx, SubscriptionService::class);

        if ($service->currentFor($subject) === null) {
            $row = $service->startFor($subject, $planUuid);
            $this->info(sprintf(
                "Started subscription for subject '%s:%s' in tenant '%s' on plan '%s' (status: %s).",
                $subject->type,
                $subject->uuid,
                $subject->tenantUuid,
                $plan,
                (string) ($row['status'] ?? '')
            ));
            return self::SUCCESS;
        }

        $service->changePlanFor($subject, $planUuid);
        $this->info(sprintf(
            "Changed subject '%s:%s' in tenant '%s' to plan '%s'.",
            $subject->type,
            $subject->uuid,
            $subject->tenantUuid,
            $plan
        ));
        return self::SUCCESS;
    }
}
