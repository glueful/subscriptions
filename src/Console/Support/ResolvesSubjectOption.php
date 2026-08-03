<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Console\Support;

use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubjectType;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Shared --subject-type=/--subject-uuid= option wiring for the subscriptions:*
 * console commands (Task 13).
 *
 * Extracted after a coordinator review caught the three commands' independently
 * copy-pasted resolveSubject() implementations silently drifting apart on the
 * SAME nonsensical input (--subject-type=tenant, or omitted, plus an explicit
 * --subject-uuid that disagrees with --tenant): SetPlanCommand's tenant branch
 * discarded the mismatched uuid and wrote anyway, ReconcileCommand let an
 * InvalidArgumentException escape uncaught, and ShowSubscriptionCommand reported
 * a false-positive "not found" success. One shared implementation is what keeps
 * that class of bug from recurring -- every command using this trait rejects the
 * combination identically, before any facade/read/write path runs.
 *
 * Requires the using class to be a Glueful\Console\BaseCommand subclass (uses
 * $this->addOption() from Symfony's Command and $this->error() from BaseCommand).
 */
trait ResolvesSubjectOption
{
    private function configureSubjectOptions(): void
    {
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
            'Subject uuid; defaults to --tenant (required, non-empty, when --subject-type=user; '
            . 'must equal --tenant when --subject-type=tenant)'
        );
    }

    /**
     * Resolves the target subject from --subject-type/--subject-uuid.
     *
     * Tenant mode (default) defaults the subject uuid to --tenant, and REJECTS
     * an explicit --subject-uuid that disagrees with it -- rather than silently
     * discarding it (a write could then land on the wrong subject) or building
     * an incoherent Subject and letting some later, inconsistent failure mode
     * decide what happens. User mode requires an explicit, non-empty
     * --subject-uuid. On any invalid combination this emits exactly one error
     * line and returns null; the caller must fail closed (non-zero exit)
     * before touching any facade/read/write path.
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

        if ($uuid !== null && $uuid !== $tenant) {
            $this->error('--subject-uuid must equal --tenant when --subject-type=tenant.');
            return null;
        }

        return new Subject($tenant, SubjectType::TENANT, $tenant);
    }

    private function nullableStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
