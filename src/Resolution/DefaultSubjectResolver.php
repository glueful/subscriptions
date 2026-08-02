<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Resolution;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubjectType;
use Glueful\Extensions\Subscriptions\Tenant\CurrentTenant;

final class DefaultSubjectResolver implements SubjectResolverInterface
{
    public function currentTenant(ApplicationContext $context): ?string
    {
        return CurrentTenant::resolve($context);
    }

    public function currentUser(ApplicationContext $context): ?string
    {
        return null;
    }

    public function validate(ApplicationContext $context, Subject $subject): bool
    {
        if ($subject->tenantUuid === '' || $subject->uuid === '') {
            return false;
        }

        return $subject->type === SubjectType::TENANT && $subject->uuid === $subject->tenantUuid;
    }
}
