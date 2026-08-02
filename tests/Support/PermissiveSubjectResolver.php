<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubjectType;

/**
 * Stand-in for a HOST-bound resolver that can vouch for users (spec §4: "binding
 * the resolver is enabling memberships"). Accepts any coherent subject -- tenant
 * self-subjects and any non-empty user subject -- so the write-path tests can
 * exercise membership behavior that DefaultSubjectResolver deliberately refuses.
 *
 * Deliberately still coherence-checking: it must not be a blanket `return true`,
 * otherwise a test could pass on an identity the real contract forbids.
 */
final class PermissiveSubjectResolver implements SubjectResolverInterface
{
    public function __construct(
        private readonly ?string $currentTenant = null,
        private readonly ?string $currentUser = null,
    ) {
    }

    public function currentTenant(ApplicationContext $context): ?string
    {
        return $this->currentTenant;
    }

    public function currentUser(ApplicationContext $context): ?string
    {
        return $this->currentUser;
    }

    public function validate(ApplicationContext $context, Subject $subject): bool
    {
        if ($subject->tenantUuid === '' || $subject->uuid === '') {
            return false;
        }

        if ($subject->type === SubjectType::TENANT) {
            return $subject->uuid === $subject->tenantUuid;
        }

        return $subject->type === SubjectType::USER;
    }
}
