<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions;

final class Subject
{
    public function __construct(
        public readonly string $tenantUuid,
        public readonly string $type,
        public readonly string $uuid,
    ) {
    }

    public static function tenant(string $tenantUuid): self
    {
        return new self($tenantUuid, SubjectType::TENANT, $tenantUuid);
    }

    public static function user(string $tenantUuid, string $userUuid): self
    {
        return new self($tenantUuid, SubjectType::USER, $userUuid);
    }
}
