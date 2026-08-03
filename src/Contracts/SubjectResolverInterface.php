<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Contracts;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Subject;

interface SubjectResolverInterface
{
    public function currentTenant(ApplicationContext $context): ?string;

    public function currentUser(ApplicationContext $context): ?string;

    public function validate(ApplicationContext $context, Subject $subject): bool;
}
