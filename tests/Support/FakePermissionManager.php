<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Support;

use Glueful\Permissions\PermissionManager;

final class FakePermissionManager extends PermissionManager
{
    /** @var list<mixed> */
    public array $lastCall = [];

    public function __construct(private bool $allowed)
    {
    }

    /** @param array<string,mixed> $context */
    public function can(string $userUuid, string $permission, string $resource, array $context = []): bool
    {
        $this->lastCall = [$userUuid, $permission, $resource, $context];

        return $this->allowed;
    }
}
